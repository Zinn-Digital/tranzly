<?php
/**
 * One-time back-fill of the current source hash on originals translated before T7.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Workflow;

use ZinnDigital\Tranzly\Core\Queue;
use ZinnDigital\Tranzly\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ WHY THIS EXISTS. "Out of date" compares a translation's recorded hash with its original's
 * current hash (Staleness). Originals translated before this version never had the current hash
 * stored, so without a back-fill every one of them would read "up to date" until somebody edits it —
 * a false all-clear (CLAUDE.md §2.44). The summary reports `backfill` so the screen can say the
 * figures are still being worked out rather than show a reassuring zero.
 *
 * ⭐ NO CAP, AND IT RESUMES (CLAUDE.md §2.10). Each run hashes originals in ID order from a stored
 * cursor for up to BUDGET seconds, saves the cursor, and schedules the next run until none is left;
 * a run killed half-way resumes from the last cursor it saved, never from the top.
 */
final class Backfill {

	/** Action Scheduler hook. */
	public const HOOK = 'tranzly_hash_backfill';

	/** Option: the cursor (last post ID done), or `done`. */
	public const STATE = 'tranzly_hash_backfill';

	/** Seconds per run. */
	private const BUDGET = 20.0;

	/** Posts read per query. */
	private const BATCH = 200;

	/**
	 * `admin_init`: start the back-fill once.
	 *
	 * @return void
	 */
	public static function maybe_start(): void {
		if ( false !== get_option( self::STATE, false ) || ! Queue::available() ) {
			return;
		}
		update_option( self::STATE, '0', false );
		as_enqueue_async_action( self::HOOK, array(), Queue::GROUP );
	}

	/**
	 * Is the back-fill still running?
	 *
	 * @return bool
	 */
	public static function pending(): bool {
		$state = get_option( self::STATE, false );

		return false !== $state && 'done' !== $state;
	}

	/**
	 * One run.
	 *
	 * @return void
	 */
	public static function run(): void {
		$cursor = get_option( self::STATE, '0' );
		if ( 'done' === $cursor ) {
			return;
		}
		$after = (int) $cursor;
		$start = microtime( true );
		global $wpdb;
		$t = Schema::tables()['relations'];
		do {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT r.object_id FROM %i r LEFT JOIN {$wpdb->postmeta} m ON m.post_id = r.object_id AND m.meta_key = %s WHERE r.object_type = 'post' AND r.is_source = 1 AND r.object_id > %d AND m.meta_id IS NULL ORDER BY r.object_id ASC LIMIT %d", $t, Staleness::CURRENT_META, $after, self::BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a cursor page of a one-time job.
			foreach ( (array) $ids as $id ) {
				$after = (int) $id;
				$post  = get_post( $after );
				if ( $post instanceof \WP_Post ) {
					update_post_meta( $after, Staleness::CURRENT_META, Staleness::current_hash( $post ) );
				}
			}
			// The cursor is saved after every batch, so a killed run resumes here.
			update_option( self::STATE, (string) $after, false );
			$got = count( (array) $ids );
		} while ( self::BATCH === $got && microtime( true ) - $start < self::BUDGET );

		if ( $got < self::BATCH ) {
			update_option( self::STATE, 'done', false );
			return;
		}
		as_enqueue_async_action( self::HOOK, array(), Queue::GROUP );
	}
}
