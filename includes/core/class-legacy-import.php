<?php
/**
 * Imports a legacy Tranzly (2.x) site's translations and settings, automatically on upgrade, with
 * a dry run and an undo.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacement lists are array_merge()d to match their placeholders, which the sniff cannot count; a one-off migration over postmeta and the plugin's own staging table; nothing here is cacheable.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The legacy import (tz-f2).
 *
 * ⭐ HOW IT RUNS, AND WHY IT HAS NO CAP (CLAUDE.md §2.10). The import is a sequence of STEPS, each
 * bounded by TIME, never by a count of posts: collect reads legacy meta rows by `meta_id` cursor
 * into a staging table; apply builds the groups (Legacy_Graph) and writes them, resuming at the
 * first group not yet written. A step that runs out of time schedules the next one and returns,
 * so a site with ten posts finishes in one request and a site with a hundred thousand in as many
 * as it takes — every row is reached, and a killed request resumes where the last one stopped.
 *
 * ⭐ IT NEVER CHANGES THE LEGACY DATA. The `cn_*` meta stays exactly as it was, so the legacy
 * plugin (or a second import) still reads it, and undo only has to remove what this class wrote.
 * The one legacy value that is REMOVED is `tranzly_options`, because it holds the DeepL key in
 * plain text; undo restores it from an encrypted snapshot.
 */
final class Legacy_Import {

	/** State option (not autoloaded: it carries the report). */
	public const OPTION = 'tranzly_legacy_import';

	/**
	 * The status alone, AUTOLOADED, so the check made on every request costs no query (the speed
	 * promise, tz-r14: the full state is read only while an import is running).
	 */
	public const STATUS_OPTION = 'tranzly_legacy_import_status';

	/** The cron hook that runs the next step. */
	public const HOOK = 'tranzly_legacy_import_step';

	/** Origin marker on every relation row this import writes. */
	public const ORIGIN = 'legacy';

	/** Post meta marking a post the legacy plugin produced as a translation (see `apply_group()`). */
	public const STATUS_META = '_tranzly_translation_status';

	/** The legacy meta keys that carry language data. */
	public const KEYS = array( 'cn_mylang', 'translated_from', 'translated_to', 'cn_post_translated_to', 'cn_post_translated_to_from', 'deepl_translated' );

	/** Rows read per collect query: a bound on ONE query's size, not on the work (see above). */
	private const BATCH = 500;

	/** Longest list of individual dangling/conflict entries kept in the report (the COUNTS are always complete). */
	private const REPORT_SAMPLE = 200;

	/**
	 * Hook the step runner and the automatic start.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run_scheduled_step' ) );
		add_action( 'plugins_loaded', array( self::class, 'maybe_start' ), 20 );
	}

	/**
	 * Start the import the first time the new version runs on a site with legacy data.
	 *
	 * @return void
	 */
	public static function maybe_start(): void {
		$status = get_option( self::STATUS_OPTION, false );
		if ( in_array( $status, array( 'none', 'done', 'undone' ), true ) ) {
			return;
		}
		$state = self::state();
		if ( '' !== $state['status'] ) {
			if ( in_array( $state['status'], array( 'collecting', 'applying' ), true ) && false === wp_next_scheduled( self::HOOK ) ) {
				self::schedule(); // A lost event (cron cleared, site restored) must not strand an import.
			}
			return;
		}
		if ( ! self::detect() ) {
			self::save_state( array( 'status' => 'none' ) + $state );
			return;
		}
		self::begin();
		self::schedule();
	}

	/**
	 * Is there any legacy data on this site?
	 *
	 * @return bool
	 */
	public static function detect(): bool {
		global $wpdb;
		if ( false !== get_option( 'tranzly_options', false ) ) {
			return true;
		}

		return null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s,%s,%s,%s,%s) OR meta_key LIKE %s LIMIT 1",
				array_merge( self::KEYS, array( $wpdb->esc_like( '_tranzly_post_translated_to_' ) . '%' ) )
			)
		);
	}

	/**
	 * The current state.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );

		return ( is_array( $state ) ? $state : array() ) + array(
			'status'  => '',
			'cursor'  => 0,
			'applied' => 0,
			'report'  => array(),
		);
	}

	/**
	 * Begin (or restart) an import.
	 *
	 * @return void
	 */
	public static function begin(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Schema::tables()['staging'] ) );
		self::save_state(
			array(
				'status'      => 'collecting',
				'cursor'      => 0,
				'applied'     => 0,
				'report'      => array(),
				'started_gmt' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function run_scheduled_step(): void {
		$state = self::step( 20.0 );
		if ( in_array( $state['status'], array( 'collecting', 'applying' ), true ) ) {
			self::schedule();
		}
	}

	/**
	 * Run the import until it finishes or the time budget is spent.
	 *
	 * @param float $budget Seconds.
	 * @return array<string, mixed> The state afterwards.
	 */
	public static function step( float $budget ): array {
		$deadline = microtime( true ) + $budget;
		$state    = self::state();

		while ( microtime( true ) < $deadline ) {
			if ( 'collecting' === $state['status'] ) {
				$rows = self::read_batch( (int) $state['cursor'] );
				if ( array() === $rows ) {
					$state['status'] = 'applying';
				} else {
					self::stage( $rows );
					$state['cursor'] = (int) end( $rows )['meta_id'];
				}
				self::save_state( $state );
				continue;
			}
			if ( 'applying' === $state['status'] ) {
				$state = self::apply( $state, $deadline );
				self::save_state( $state );
				continue;
			}
			break;
		}

		return $state;
	}

	/**
	 * What an import WOULD do, without writing anything.
	 *
	 * @return array<string, mixed> The report.
	 */
	public static function dry_run(): array {
		$facts  = array();
		$cursor = 0;
		do {
			$rows = self::read_batch( $cursor );
			foreach ( $rows as $row ) {
				$facts[ (int) $row['post_id'] ] = Legacy_Graph::absorb( $facts[ (int) $row['post_id'] ] ?? array(), (string) $row['meta_key'], self::value( (string) $row['meta_value'] ) );
				$cursor                         = (int) $row['meta_id'];
			}
		} while ( array() !== $rows );

		$plan = self::plan( $facts );

		return self::report( $plan, $facts ) + array( 'options' => self::options_report( get_option( 'tranzly_options', false ) ) );
	}

	/**
	 * Undo everything the import wrote. The legacy data was never changed, so the site returns to
	 * exactly its pre-import state (the DeepL key goes back into `tranzly_options`).
	 *
	 * @return array<string, mixed> What was removed.
	 */
	public static function undo(): array {
		global $wpdb;
		$state = self::state();
		$t     = Schema::tables();

		$removed = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE origin = %s', $t['relations'], self::ORIGIN ) );
		$wpdb->query( $wpdb->prepare( 'DELETE g FROM %i g LEFT JOIN %i r ON r.group_id = g.id WHERE r.group_id IS NULL', $t['groups'], $t['relations'] ) );
		delete_post_meta_by_key( self::STATUS_META );
		wp_cache_flush_group( Relations::CACHE_GROUP );
		Relations::reset_memo();

		$restored_options = false;
		if ( ! empty( $state['options_snapshot'] ) ) {
			$plain = Secrets::decrypt( (string) $state['options_snapshot'] );
			$value = null === $plain ? false : self::value( $plain );
			if ( is_array( $value ) ) {
				update_option( 'tranzly_options', $value, false );
				$restored_options = true;
				if ( ! empty( $state['imported_key'] ) && Secrets::get( 'deepl' ) === ( $value['deepl_api_key'] ?? null ) ) {
					Secrets::put( 'deepl', '' );
				}
			}
		}
		if ( isset( $state['languages_before'] ) && is_array( $state['languages_before'] ) ) {
			Settings::save( array( 'languages' => $state['languages_before'] ) );
		}
		if ( ! empty( $state['display_before_absent'] ) ) {
			delete_option( Options::OPTION );
		}
		wp_clear_scheduled_hook( self::HOOK );
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $t['staging'] ) );

		self::save_state(
			array(
				'status'     => 'undone',
				'undone_gmt' => gmdate( 'c' ),
				'report'     => $state['report'],
			)
		);

		return array(
			'relations_removed' => $removed,
			'options_restored'  => $restored_options,
		);
	}

	/**
	 * One batch of legacy meta rows after the cursor, oldest first. Revisions are left out: the
	 * legacy plugin never wrote to them, and core copies meta onto them only by accident.
	 *
	 * @param int $cursor The last meta_id already read.
	 * @return array<int, array<string, mixed>>
	 */
	private static function read_batch( int $cursor ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_id, m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_id > %d AND p.post_type <> 'revision' AND ( m.meta_key IN (%s,%s,%s,%s,%s,%s) OR m.meta_key LIKE %s ) ORDER BY m.meta_id ASC LIMIT %d",
				array_merge( array( $cursor ), self::KEYS, array( $wpdb->esc_like( '_tranzly_post_translated_to_' ) . '%', self::BATCH ) )
			),
			ARRAY_A
		);
	}

	/**
	 * Merge a batch into the staging table.
	 *
	 * @param array<int, array<string, mixed>> $rows Meta rows.
	 * @return void
	 */
	private static function stage( array $rows ): void {
		global $wpdb;
		$table = Schema::tables()['staging'];
		$ids   = array_values( array_unique( array_map( static fn( $r ) => (int) $r['post_id'], $rows ) ) );

		$facts = array();
		$have  = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one %d per ID.
				'SELECT post_id, peers FROM %i WHERE post_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( array( $table ), $ids )
			),
			ARRAY_A
		);
		foreach ( $have as $row ) {
			$decoded                        = json_decode( (string) $row['peers'], true );
			$facts[ (int) $row['post_id'] ] = is_array( $decoded ) ? $decoded : array();
		}
		foreach ( $rows as $row ) {
			$facts[ (int) $row['post_id'] ] = Legacy_Graph::absorb( $facts[ (int) $row['post_id'] ] ?? array(), (string) $row['meta_key'], self::value( (string) $row['meta_value'] ) );
		}
		foreach ( $facts as $post_id => $fact ) {
			$wpdb->replace(
				$table,
				array(
					'post_id' => $post_id,
					'lang'    => (string) ( $fact['lang'] ?? '' ),
					'peers'   => (string) wp_json_encode( $fact ),
				)
			);
		}
	}

	/**
	 * The apply phase: options first (once), then the groups from `applied` onwards.
	 *
	 * @param array<string, mixed> $state    The state.
	 * @param float                $deadline microtime() to stop at.
	 * @return array<string, mixed>
	 */
	private static function apply( array $state, float $deadline ): array {
		if ( empty( $state['options_done'] ) ) {
			$state                 = self::import_options( $state );
			$state['options_done'] = true;
		}

		$facts = array();
		foreach ( (array) $GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( 'SELECT post_id, peers FROM %i ORDER BY post_id', Schema::tables()['staging'] ), ARRAY_A ) as $row ) {
			$decoded                        = json_decode( (string) $row['peers'], true );
			$facts[ (int) $row['post_id'] ] = is_array( $decoded ) ? $decoded : array();
		}
		$plan = self::plan( $facts );

		if ( empty( $state['languages_done'] ) ) {
			$state['languages_before'] = Languages::all();
			$listed                    = $state['languages_before'];
			$codes                     = array_map( static fn( $l ) => strtolower( $l['code'] ), $listed );
			foreach ( $plan['languages'] as $code ) {
				if ( ! in_array( strtolower( $code ), $codes, true ) && Settings::is_valid_code( $code ) ) {
					$listed[] = array(
						'code' => $code,
						'name' => '',
					);
				}
			}
			if ( count( $listed ) !== count( $state['languages_before'] ) ) {
				Settings::save( array( 'languages' => $listed ) );
			}
			$state['languages_done'] = true;
		}

		$groups = $plan['groups'];
		$total  = count( $groups );
		$i      = (int) $state['applied'];
		$skips  = (array) ( $state['skipped'] ?? array() );
		while ( $i < $total && microtime( true ) < $deadline ) {
			$skip = self::apply_group( $groups[ $i ] );
			if ( null !== $skip ) {
				$skips[] = $skip;
			}
			++$i;
		}
		$state['applied'] = $i;
		$state['skipped'] = array_slice( $skips, 0, self::REPORT_SAMPLE );

		if ( $i >= $total ) {
			$state['status']       = 'done';
			$state['finished_gmt'] = gmdate( 'c' );
			$state['report']       = self::report( $plan, $facts ) + array(
				'options'         => $state['options_report'] ?? array(),
				'already_present' => count( $skips ),
			);
			$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'TRUNCATE TABLE %i', Schema::tables()['staging'] ) );
			wp_cache_flush_group( Relations::CACHE_GROUP );
			Relations::reset_memo();
		}

		return $state;
	}

	/**
	 * Write one group. Idempotent: a resumed step that repeats a group writes nothing twice, and a
	 * post that already belongs to a group made since the upgrade is left alone and reported.
	 *
	 * @param array{source: int, members: array<int, string>} $group The planned group.
	 * @return array{post: int, reason: string}|null A skipped member, if any.
	 */
	private static function apply_group( array $group ): ?array {
		$source      = (int) $group['source'];
		$source_lang = (string) $group['members'][ $source ];
		$skipped     = null;

		if ( array() === Relations::translations( 'post', $source ) ) {
			Relations::set_language( 'post', $source, $source_lang, self::ORIGIN );
		} elseif ( Relations::language_of( 'post', $source ) !== $source_lang ) {
			return array(
				'post'   => $source,
				'reason' => 'already in a group',
			);
		}

		foreach ( $group['members'] as $post => $lang ) {
			if ( $post === $source ) {
				continue;
			}
			$result = Relations::link( 'post', $source, $source_lang, (int) $post, (string) $lang, self::ORIGIN );
			if ( is_wp_error( $result ) ) {
				$skipped = array(
					'post'   => (int) $post,
					'reason' => $result->get_error_code(),
				);
				continue;
			}
			// Machine output the legacy plugin wrote. Unknown whether a person edited it since, so it
			// is marked `legacy`, which the editor (T3) treats as protected, never as re-translatable.
			update_post_meta( (int) $post, self::STATUS_META, 'legacy' );
		}

		return $skipped;
	}

	/**
	 * Move `tranzly_options` over: the DeepL key into the encrypted store, the switcher settings
	 * into `tranzly_display`, the credit link off. Then delete the plain-text original, keeping an
	 * ENCRYPTED snapshot for undo.
	 *
	 * @param array<string, mixed> $state The state.
	 * @return array<string, mixed>
	 */
	private static function import_options( array $state ): array {
		$legacy                  = get_option( 'tranzly_options', false );
		$state['options_report'] = self::options_report( $legacy );
		if ( ! is_array( $legacy ) ) {
			return $state;
		}

		$key = trim( (string) ( $legacy['deepl_api_key'] ?? '' ) );
		if ( '' !== $key && ! Secrets::has( 'deepl' ) ) {
			Secrets::put( 'deepl', $key );
			$state['imported_key'] = true;
		}

		$state['display_before_absent'] = false === get_option( Options::OPTION, false );
		$switcher                       = array( 'new_tab' => 'newtab' === ( $legacy['selector_tab'] ?? '' ) );
		if ( in_array( $legacy['selector_position'] ?? '', array( 'before', 'after' ), true ) ) {
			$switcher['position'] = $legacy['selector_position'];
		}
		$switcher['style'] = 'flags' === ( $legacy['selector_mode'] ?? '' ) ? 'flags' : 'names';
		Options::save(
			array(
				'switcher' => $switcher,
				'credit'   => false,
			)
		);

		$state['options_snapshot'] = Secrets::encrypt( serialize( $legacy ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the exact legacy option value, encrypted, for undo.
		delete_option( 'tranzly_options' );

		return $state;
	}

	/**
	 * What the options import does / did, with no secret in it.
	 *
	 * @param mixed $legacy The legacy option value.
	 * @return array<string, mixed>
	 */
	private static function options_report( $legacy ): array {
		if ( ! is_array( $legacy ) ) {
			return array( 'found' => false );
		}

		return array(
			'found'          => true,
			'deepl_key'      => '' !== trim( (string) ( $legacy['deepl_api_key'] ?? '' ) ),
			'switcher'       => array(
				'position' => (string) ( $legacy['selector_position'] ?? '' ),
				'mode'     => (string) ( $legacy['selector_mode'] ?? '' ),
			),
			'credit_was_on'  => ! empty( $legacy['enable_affiliates'] ),
			'credit_now'     => false,
			'dropped_fields' => array_values( array_diff( array_keys( $legacy ), array( 'deepl_api_key', 'selector_mode', 'selector_position', 'selector_tab', 'enable_affiliates' ) ) ),
		);
	}

	/**
	 * The groups for a set of facts, against the site as it is now.
	 *
	 * @param array<int, array<string, mixed>> $facts post ID => facts.
	 * @return array<string, mixed>
	 */
	private static function plan( array $facts ): array {
		$ids = array_keys( $facts );
		foreach ( $facts as $fact ) {
			$ids = array_merge( $ids, array_keys( (array) ( $fact['children'] ?? array() ) ), array_keys( (array) ( $fact['parents'] ?? array() ) ) );
		}

		$listed = array_map( static fn( $l ) => $l['code'], Languages::all() );

		return Legacy_Graph::build( $facts, self::existing( $ids ), $listed, Settings::site_locale(), Languages::default_code() );
	}

	/**
	 * Which of these posts still exist (any status; revisions excluded).
	 *
	 * @param array<int, int> $ids Post IDs.
	 * @return array<int, bool>
	 */
	private static function existing( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array();
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			$found = (array) $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one %d per ID.
					"SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'revision' AND ID IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
					$chunk
				)
			);
			foreach ( $found as $id ) {
				$out[ (int) $id ] = true;
			}
		}

		return $out;
	}

	/**
	 * The report: complete counts, a sample of individual entries.
	 *
	 * @param array<string, mixed>             $plan  Legacy_Graph::build() output.
	 * @param array<int, array<string, mixed>> $facts post ID => facts.
	 * @return array<string, mixed>
	 */
	private static function report( array $plan, array $facts ): array {
		$linked = 0;
		foreach ( $plan['groups'] as $group ) {
			$linked += count( $group['members'] );
		}
		$flags = 0;
		foreach ( $facts as $fact ) {
			$flags += count( (array) ( $fact['flags'] ?? array() ) );
		}

		return array(
			'posts_with_legacy_data' => count( $facts ),
			'groups'                 => count( $plan['groups'] ),
			'posts_linked'           => $linked,
			'languages'              => $plan['languages'],
			'status_flags_read'      => $flags,
			'dangling_count'         => count( $plan['dangling'] ),
			'dangling'               => array_slice( $plan['dangling'], 0, self::REPORT_SAMPLE ),
			'conflict_count'         => count( $plan['conflicts'] ),
			'conflicts'              => array_slice( $plan['conflicts'], 0, self::REPORT_SAMPLE ),
			'unresolved_count'       => count( $plan['unresolved'] ),
			'unresolved'             => array_slice( $plan['unresolved'], 0, self::REPORT_SAMPLE ),
		);
	}

	/**
	 * A stored meta value, unserialised WITHOUT allowing objects (the legacy data went through
	 * handlers with no validation at all, so it is treated as untrusted).
	 *
	 * @param string $raw The raw database value.
	 * @return mixed
	 */
	private static function value( string $raw ) {
		if ( ! is_serialized( $raw ) ) {
			return $raw;
		}
		// Objects are refused (allowed_classes false), so no class can be instantiated from this data:
		// that is the fact the semgrep suppression rests on — re-check it if that option ever goes.
		$value = @unserialize( trim( $raw ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- objects refused; a malformed legacy value becomes false and is skipped. nosemgrep: php.lang.security.unserialize-use.unserialize-use

		return false === $value && 'b:0;' !== trim( $raw ) ? '' : $value;
	}

	/**
	 * Schedule the next step now.
	 *
	 * @return void
	 */
	private static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * Save the state.
	 *
	 * @param array<string, mixed> $state The state.
	 * @return void
	 */
	private static function save_state( array $state ): void {
		update_option( self::OPTION, $state, false );
		update_option( self::STATUS_OPTION, (string) ( $state['status'] ?? '' ), true );
	}
}
