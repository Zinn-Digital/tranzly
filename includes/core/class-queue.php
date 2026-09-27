<?php
/**
 * Background translation jobs: translate many posts into many languages with nobody waiting.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

use ZinnDigital\Tranzly\Engines\Failure;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own job tables, read and written only by the queue; replacement lists are array_merge()d to match their placeholders.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background translation (tz-f3): big jobs run in a background queue, so closing the browser does not stop them (the legacy
 * plugin translated one post per AJAX request, driven by the open browser tab).
 *
 * ⭐ HOW IT STAYS CORRECT WITH NO CAP (CLAUDE.md §2.10, the "resumes, never truncates" test):
 *
 * - A job is one row per (post, language) to do. Workers are Action Scheduler async actions; each
 *   runs for a TIME budget, claiming items a few at a time, and before it ends it schedules its own
 *   successor while work remains. Nothing stops at a number of items.
 * - Claiming is one `UPDATE … LIMIT` with a random claim token and a LEASE: a worker killed
 *   mid-item (a PHP worker restart, a deploy, a fatal) leaves its items `running` with a lease in
 *   the past, and the next worker takes them back. A worker never does an item another holds.
 * - A watchdog (a recurring action every five minutes) restarts the workers of any job that still
 *   has work but no scheduled worker — the case where every worker died at once.
 * - Each worker runs AS THE USER WHO STARTED THE JOB, so permissions are checked per item exactly
 *   as the editor checks them, never bypassed by running in the background.
 * - Concurrency is bounded (`tranzly_queue_workers`, default 2 per job) to protect the translation
 *   service, not the amount of work: a bound on workers, never on items.
 */
final class Queue {

	/** Action Scheduler hook for a worker. */
	public const WORK = 'tranzly_job_work';

	/** Action Scheduler hook for the watchdog. */
	public const WATCHDOG = 'tranzly_queue_watchdog';

	/** Action Scheduler group. */
	public const GROUP = 'tranzly';

	/** Items claimed at a time by one worker. */
	private const CLAIM = 5;

	/** A claimed item's lease, in seconds. */
	private const LEASE = 300;

	/** Attempts before a retryable failure (outage, rate limit) is reported as failed. */
	private const MAX_ATTEMPTS = 5;

	/**
	 * Hook the worker and the watchdog.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::WORK, array( self::class, 'work' ), 10, 1 );
		add_action( self::WATCHDOG, array( self::class, 'watchdog' ) );
		add_action( 'action_scheduler_init', array( self::class, 'schedule_watchdog' ) );
		add_action( 'init', array( self::class, 'close_async_runner' ), PHP_INT_MAX );
	}

	/**
	 * Close the Action Scheduler loopback endpoint when the copy running is the one Tranzly ships.
	 *
	 * The library answers `admin-ajax.php?action=as_async_request_queue_runner` for anyone, logged
	 * in or not, with no capability check. The queue does not need it: WP-Cron and the watchdog
	 * drive the workers. When another plugin (WooCommerce) supplies the copy that is running, its
	 * endpoint is that plugin's to keep, so nothing is removed.
	 *
	 * @return void
	 */
	public static function close_async_runner(): void {
		$dir = wp_normalize_path( dirname( __DIR__, 2 ) . '/vendor/' );
		foreach ( array( 'wp_ajax_as_async_request_queue_runner', 'wp_ajax_nopriv_as_async_request_queue_runner' ) as $hook ) {
			foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? array() as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					if ( is_array( $fn ) && is_object( $fn[0] ) && str_starts_with( wp_normalize_path( (string) ( new \ReflectionObject( $fn[0] ) )->getFileName() ), $dir ) ) {
						remove_action( $hook, $fn, $priority );
						add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
					}
				}
			}
		}
	}

	/**
	 * Is the queue runner (Action Scheduler) loaded?
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Keep the watchdog scheduled.
	 *
	 * @return void
	 */
	public static function schedule_watchdog(): void {
		// ⛔ Never on a visitor's page: the check is two queries, and the speed promise (tz-r14)
		// allows Tranzly two in total (measured by wp/tests/tranzly/wp-integration.sh). Admin,
		// cron and WP-CLI requests keep it scheduled, and creating a job schedules it too.
		if ( ! ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
			return;
		}
		self::ensure_watchdog();
	}

	/**
	 * Schedule the watchdog if it is not.
	 *
	 * @return void
	 */
	public static function ensure_watchdog(): void {
		if ( self::available() && false === as_has_scheduled_action( self::WATCHDOG, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 300, 300, self::WATCHDOG, array(), self::GROUP );
		}
	}

	/**
	 * Start a job as the current user.
	 *
	 * @param array<int, int>      $post_ids The originals.
	 * @param array<int, string>   $langs    Target languages.
	 * @param string               $engine   An engine id; empty for each language's own chain.
	 * @param array<string, mixed> $options  `status` (`publish`/`draft`), `force` (bool).
	 * @return array{id: int, items: int, not_allowed: int}|\WP_Error
	 */
	public static function create( array $post_ids, array $langs, string $engine = '', array $options = array() ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'tranzly_no_queue', __( 'The background queue is not available on this site.', 'tranzly' ), array( 'status' => 500 ) );
		}
		if ( get_current_user_id() <= 0 ) {
			return new \WP_Error( 'tranzly_forbidden', __( 'A translation job runs as the person who starts it, so a user must be signed in.', 'tranzly' ), array( 'status' => 403 ) );
		}
		$codes = array();
		foreach ( $langs as $lang ) {
			$code = \ZinnDigital\Tranzly\Languages::resolve( (string) $lang );
			if ( null === $code ) {
				return new \WP_Error( 'tranzly_unknown_language', __( 'That language is not one of this site\'s languages.', 'tranzly' ), array( 'status' => 400 ) );
			}
			$codes[] = $code;
		}
		if ( '' !== $engine ) {
			$check = Translator::engine( $engine );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$allowed     = array();
		$not_allowed = 0;
		foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $id ) {
			if ( Content::can_translate_post( $id ) ) {
				$allowed[] = $id;
			} else {
				++$not_allowed;
			}
		}
		if ( array() === $allowed || array() === $codes ) {
			return new \WP_Error( 'tranzly_empty_job', __( 'There is nothing you are allowed to translate in that selection.', 'tranzly' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$t = Schema::tables();
		$wpdb->insert(
			$t['jobs'],
			array(
				'status'      => 'running',
				'engine'      => $engine,
				'created_by'  => get_current_user_id(),
				'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'options'     => (string) wp_json_encode(
					array(
						'status' => in_array( $options['status'] ?? '', array( 'publish', 'draft' ), true ) ? $options['status'] : '',
						'force'  => ! empty( $options['force'] ),
					)
				),
			)
		);
		$job = (int) $wpdb->insert_id;

		$rows = array();
		foreach ( $allowed as $id ) {
			foreach ( $codes as $code ) {
				$rows[] = array( $id, $code );
			}
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$args = array( $t['items'] );
			foreach ( $chunk as $row ) {
				array_push( $args, $job, $row[0], $row[1], $now );
			}
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one fixed placeholder tuple per row of $chunk, built from literals only.
					'INSERT IGNORE INTO %i (job_id, object_type, object_id, lang, status, error_message, updated_gmt) VALUES ' . implode( ',', array_fill( 0, count( $chunk ), "(%d, 'post', %d, %s, 'queued', '', %s)" ) ),
					$args
				)
			);
		}

		self::start_workers( $job );
		self::ensure_watchdog();

		/**
		 * Fires after a translation job is created.
		 *
		 * @param int $job   The job ID.
		 * @param int $items How many (post, language) items it holds.
		 */
		do_action( 'tranzly_job_created', $job, count( $rows ) );

		return array(
			'id'          => $job,
			'items'       => count( $rows ),
			'not_allowed' => $not_allowed,
		);
	}

	/**
	 * A worker: claim and translate items until the time budget is spent, then hand over.
	 *
	 * @param int|array<string, int> $job The job ID (Action Scheduler passes the args array).
	 * @return void
	 */
	public static function work( $job ): void {
		$job_id = is_array( $job ) ? (int) ( $job['job'] ?? 0 ) : (int) $job;
		$row    = self::job( $job_id );
		if ( null === $row || 'running' !== $row['status'] ) {
			return;
		}
		$previous = get_current_user_id();
		wp_set_current_user( (int) $row['created_by'] );

		$options = (array) json_decode( (string) $row['options'], true );

		/**
		 * Filters how long one worker runs before handing over, in seconds.
		 *
		 * @param int $seconds 20.
		 */
		$deadline = microtime( true ) + (int) apply_filters( 'tranzly_queue_worker_seconds', 20 );
		while ( microtime( true ) < $deadline ) {
			$items = self::claim( $job_id );
			if ( array() === $items ) {
				break;
			}
			foreach ( $items as $item ) {
				// ⛔ A claim covers several items but they are worked one at a time, so the lease of
				// the last one would run out while it waits. Renew each lease just before working
				// the item, and skip it if another worker took it meanwhile — otherwise two workers
				// translate the same item at once (measured: a published translation left as a draft).
				if ( self::renew( $item ) ) {
					self::process( $item, (string) $row['engine'], $options );
				}
			}
		}

		wp_set_current_user( $previous );
		self::after_work( $job_id );
	}

	/**
	 * The watchdog: restart the workers of any running job that has work and no worker.
	 *
	 * @return void
	 */
	public static function watchdog(): void {
		global $wpdb;
		$t    = Schema::tables();
		$jobs = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'running'", $t['jobs'] ) );
		foreach ( $jobs as $job_id ) {
			self::after_work( (int) $job_id );
		}
	}

	/**
	 * Progress of a job.
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, mixed>|null
	 */
	public static function progress( int $job_id ): ?array {
		$row = self::job( $job_id );
		if ( null === $row ) {
			return null;
		}
		global $wpdb;
		$counts = array_fill_keys( array( 'queued', 'running', 'done', 'failed', 'skipped', 'manual' ), 0 );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE job_id = %d GROUP BY status', Schema::tables()['items'], $job_id ), ARRAY_A ) as $count ) {
			$counts[ (string) $count['status'] ] = (int) $count['n'];
		}

		return array(
			'id'         => $job_id,
			'status'     => (string) $row['status'],
			'engine'     => (string) $row['engine'],
			'created_by' => (int) $row['created_by'],
			'created'    => (string) $row['created_gmt'],
			'finished'   => $row['finished_gmt'],
			'total'      => array_sum( $counts ),
			'counts'     => $counts,
		);
	}

	/**
	 * The failed items of a job, with the plain-English reason (tz-r2).
	 *
	 * @param int $job_id Job ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function failures( int $job_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, object_id, lang, engine_used, error_code, error_message, attempts FROM %i WHERE job_id = %d AND status = 'failed' ORDER BY id", Schema::tables()['items'], $job_id ),
			ARRAY_A
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$detail = json_decode( (string) $row['error_message'], true );
			$detail = is_array( $detail ) ? $detail : array( 'message' => (string) $row['error_message'] );
			$out[]  = array(
				'item'     => (int) $row['id'],
				'post'     => (int) $row['object_id'],
				'title'    => get_the_title( (int) $row['object_id'] ),
				'lang'     => (string) $row['lang'],
				'engine'   => (string) $row['engine_used'],
				'class'    => (string) $row['error_code'],
				'message'  => (string) ( $detail['message'] ?? '' ),
				'detail'   => (string) ( $detail['detail'] ?? '' ),
				'link'     => (string) ( $detail['link'] ?? '' ),
				'attempts' => (int) $row['attempts'],
			);
		}

		return $out;
	}

	/**
	 * Put a failed item back in the queue, optionally with another engine (tz-r2).
	 *
	 * @param int    $item_id Item ID.
	 * @param string $engine  Engine id, or empty for the job's own.
	 * @return true|\WP_Error
	 */
	public static function retry( int $item_id, string $engine = '' ) {
		global $wpdb;
		$t    = Schema::tables();
		$item = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t['items'], $item_id ), ARRAY_A );
		if ( ! is_array( $item ) ) {
			return new \WP_Error( 'tranzly_no_item', __( 'That item is not part of a translation job.', 'tranzly' ), array( 'status' => 404 ) );
		}
		if ( '' !== $engine ) {
			$check = Translator::engine( $engine );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		$wpdb->update(
			$t['items'],
			array(
				'status'        => 'queued',
				'engine'        => $engine,
				'attempts'      => 0,
				'claim'         => '',
				'lease_until'   => null,
				'error_code'    => '',
				'error_message' => '',
				'updated_gmt'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $item_id )
		);
		$wpdb->update(
			$t['jobs'],
			array(
				'status'       => 'running',
				'finished_gmt' => null,
			),
			array( 'id' => (int) $item['job_id'] )
		);
		self::start_workers( (int) $item['job_id'] );

		return true;
	}

	/**
	 * "Translate by hand": make sure the translation exists (as a draft copy when it does not), mark
	 * the item as handled by a person, and return where to edit it.
	 *
	 * @param int $item_id Item ID.
	 * @return array{id: int, edit: string}|\WP_Error
	 */
	public static function by_hand( int $item_id ) {
		global $wpdb;
		$t    = Schema::tables();
		$item = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t['items'], $item_id ), ARRAY_A );
		if ( ! is_array( $item ) ) {
			return new \WP_Error( 'tranzly_no_item', __( 'That item is not part of a translation job.', 'tranzly' ), array( 'status' => 404 ) );
		}
		$target = Relations::translations( 'post', (int) $item['object_id'] )[ (string) $item['lang'] ] ?? null;
		if ( null === $target ) {
			$made = Content::create_post_translation( (int) $item['object_id'], (string) $item['lang'] );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$target = $made;
		}
		if ( ! current_user_can( 'edit_post', $target ) ) {
			return new \WP_Error( 'tranzly_forbidden', __( 'You are not allowed to change that translation.', 'tranzly' ), array( 'status' => 403 ) );
		}
		update_post_meta( (int) $target, Translator::STATUS_META, 'human' );
		$wpdb->update(
			$t['items'],
			array(
				'status'      => 'manual',
				'result_id'   => (int) $target,
				'updated_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $item_id )
		);

		return array(
			'id'   => (int) $target,
			'edit' => (string) get_edit_post_link( (int) $target, 'raw' ),
		);
	}

	/**
	 * Stop a job: queued items are left undone, running ones finish.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public static function cancel( int $job_id ): void {
		global $wpdb;
		$wpdb->update(
			Schema::tables()['jobs'],
			array(
				'status'       => 'cancelled',
				'finished_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $job_id )
		);
		if ( self::available() ) {
			as_unschedule_all_actions( self::WORK, array( 'job' => $job_id ), self::GROUP );
		}
	}

	/**
	 * One job row.
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, mixed>|null
	 */
	public static function job( int $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::tables()['jobs'], $job_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Claim up to CLAIM items: queued and due, or running with an expired lease.
	 *
	 * @param int $job_id Job ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function claim( int $job_id ): array {
		global $wpdb;
		$t     = Schema::tables();
		$token = wp_generate_password( 32, false, false );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'running', claim = %s, lease_until = %s, attempts = attempts + 1, updated_gmt = %s WHERE job_id = %d AND ( ( status = 'queued' AND ( lease_until IS NULL OR lease_until <= %s ) ) OR ( status = 'running' AND lease_until < %s ) ) ORDER BY id LIMIT %d",
				$t['items'],
				$token,
				gmdate( 'Y-m-d H:i:s', time() + self::lease() ),
				$now,
				$job_id,
				$now,
				$now,
				self::CLAIM
			)
		);

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE claim = %s', $t['items'], $token ), ARRAY_A );
	}

	/**
	 * Extend an item's lease from now, if this worker still holds its claim.
	 *
	 * @param array<string, mixed> $item The claimed item row.
	 * @return bool False when another worker has taken the item back.
	 */
	private static function renew( array $item ): bool {
		global $wpdb;
		$table = Schema::tables()['items'];
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET lease_until = %s WHERE id = %d AND claim = %s AND status = 'running'",
				$table,
				gmdate( 'Y-m-d H:i:s', time() + self::lease() ),
				(int) $item['id'],
				(string) $item['claim']
			)
		);

		// Ownership is READ back: MySQL reports 0 affected rows when a renewal writes the value
		// already there (same second as the claim), which would make a held claim look lost.
		return 1 === (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE id = %d AND claim = %s AND status = 'running'", $table, (int) $item['id'], (string) $item['claim'] )
		);
	}

	/**
	 * How long a claimed item is held before another worker may take it back, in seconds.
	 *
	 * @return int
	 */
	private static function lease(): int {
		/**
		 * Filters the lease on a claimed item: after it, a worker that died mid-item gives the item
		 * back to the others. Longer than the slowest single translation.
		 *
		 * @param int $seconds 300.
		 */
		return max( 1, (int) apply_filters( 'tranzly_queue_lease_seconds', self::LEASE ) );
	}

	/**
	 * Translate one claimed item and record the outcome.
	 *
	 * @param array<string, mixed> $item    The item row.
	 * @param string               $engine  The job's engine (empty: each language's chain).
	 * @param array<string, mixed> $options The job's options.
	 * @return void
	 */
	private static function process( array $item, string $engine, array $options ): void {
		$use    = '' !== (string) $item['engine'] ? (string) $item['engine'] : $engine;
		$result = Translator::translate_post(
			(int) $item['object_id'],
			(string) $item['lang'],
			$use,
			array(
				'force'  => ! empty( $options['force'] ),
				'status' => (string) ( $options['status'] ?? '' ),
			)
		);

		$update = array(
			'claim'       => '',
			'updated_gmt' => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( ! is_wp_error( $result ) ) {
			$update += array(
				'status'      => 'done',
				'result_id'   => (int) $result,
				'engine_used' => (string) get_post_meta( (int) $result, Translator::ENGINE_META, true ),
				'lease_until' => null,
			);
		} else {
			$code = $result->get_error_code();
			if ( 'tranzly_memory_busy' === $code ) {
				// Another worker is paying for the same text; come back when it has the answer.
				$update += array(
					'status'      => 'queued',
					'attempts'    => max( 0, (int) $item['attempts'] - 1 ),
					'lease_until' => gmdate( 'Y-m-d H:i:s', time() + 15 ),
				);
			} elseif ( in_array( $code, array( 'tranzly_protected', 'tranzly_same_language' ), true ) ) {
				$update += array(
					'status'        => 'skipped',
					'error_code'    => $code,
					'error_message' => (string) wp_json_encode( array( 'message' => $result->get_error_message() ) ),
					'lease_until'   => null,
				);
			} else {
				$data      = (array) $result->get_error_data();
				$class     = Failure::class_of( $result );
				$retryable = ! empty( $data['retryable'] ) && (int) $item['attempts'] < self::MAX_ATTEMPTS;
				$update   += array(
					'status'        => $retryable ? 'queued' : 'failed',
					'engine_used'   => (string) ( $data['engine'] ?? $use ),
					'error_code'    => Failure::UNKNOWN === $class ? $code : $class,
					'error_message' => (string) wp_json_encode(
						array(
							'message' => $result->get_error_message(),
							'detail'  => (string) ( $data['detail'] ?? '' ),
							'link'    => (string) ( $data['link'] ?? '' ),
						)
					),
					'lease_until'   => $retryable ? gmdate( 'Y-m-d H:i:s', time() + 60 * (int) $item['attempts'] ) : null,
				);
			}
		}
		global $wpdb;
		// Only the worker that still holds the claim may record the outcome.
		$wpdb->update(
			Schema::tables()['items'],
			$update,
			array(
				'id'    => (int) $item['id'],
				'claim' => (string) $item['claim'],
			)
		);
	}

	/**
	 * After a worker (or the watchdog): finish the job when nothing is left, otherwise make sure
	 * its workers are scheduled.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	private static function after_work( int $job_id ): void {
		global $wpdb;
		$t    = Schema::tables();
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE job_id = %d AND status IN ('queued','running')", $t['items'], $job_id ) );
		if ( 0 === $left ) {
			$wpdb->update(
				$t['jobs'],
				array(
					'status'       => 'done',
					'finished_gmt' => gmdate( 'Y-m-d H:i:s' ),
				),
				array(
					'id'     => $job_id,
					'status' => 'running',
				)
			);

			/**
			 * Fires when a translation job has no work left.
			 *
			 * @param int $job_id The job ID.
			 */
			do_action( 'tranzly_job_finished', $job_id );
			return;
		}
		self::start_workers( $job_id );
	}

	/**
	 * Schedule workers for a job, up to the concurrency bound.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	private static function start_workers( int $job_id ): void {
		if ( ! self::available() ) {
			return;
		}

		/**
		 * Filters how many workers one job runs at once. A bound on WORKERS, to be gentle with the
		 * translation service; never a bound on how many items get done.
		 *
		 * @param int $workers 2.
		 * @param int $job_id  The job.
		 */
		$want    = max( 1, (int) apply_filters( 'tranzly_queue_workers', 2, $job_id ) );
		$pending = count(
			as_get_scheduled_actions(
				array(
					'hook'     => self::WORK,
					'args'     => array( 'job' => $job_id ),
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					// ⭐ Only UNCLAIMED pending workers count. A queue runner killed mid-batch leaves
					// its actions pending AND claimed until Action Scheduler resets stale claims
					// (five minutes); counting those would start no replacement and stall the job.
					'claimed'  => false,
					'per_page' => $want,
				),
				'ids'
			)
		);
		for ( $i = $pending; $i < $want; $i++ ) {
			as_enqueue_async_action( self::WORK, array( 'job' => $job_id ), self::GROUP );
		}
	}
}
