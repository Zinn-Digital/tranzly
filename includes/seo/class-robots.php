<?php
/**
 * A translation is indexed exactly when its original is: the SEO plugin's robots settings follow
 * the original onto every translation.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Schema;
use ZinnDigital\Tranzly\Workflow\Staleness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔ WHY THIS EXISTS. A translation is a post of its own, and the SEO plugins keep "noindex" as
 * post meta, which Tranzly never copied: live 2026-10-04 on pagebuildersandwich.com, the English
 * licence, invoice and purchase pages were `noindex` while their 57 translations each said
 * `index` and sat in the sitemap (`/de/ihre-lizenz/`, `/vi/giao-dich-that-bai/`). A page the
 * owner hid from search must stay hidden in every language, and one they show must be shown.
 *
 * So the robots meta of an original is copied onto each translation when the translation is
 * written, and again whenever the original's robots meta changes; a one-time pass brings sites
 * translated before this version in line ({@see sync_pending()}, resuming from a cursor).
 * Only robots meta moves: titles and descriptions are TRANSLATED (Seo_Fields), never copied.
 */
final class Robots {

	/** Option: the last original ID the one-time pass reached, or `done`. */
	public const STATE = 'tranzly_robots_sync';

	/** Originals per pass. */
	private const BATCH = 100;

	/**
	 * Re-entrancy guard: the copy itself updates meta on translations, which must not echo back.
	 *
	 * @var bool
	 */
	private static $copying = false;

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'tranzly_post_translated', array( self::class, 'on_translated' ), 10, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'on_meta' ), 10, 3 );
		}
		add_action( 'admin_init', array( self::class, 'sync_pending' ) );
	}

	/**
	 * The robots meta keys of the SEO plugins, filterable.
	 *
	 * @return array<int, string>
	 */
	public static function keys(): array {
		/**
		 * Filters the post meta keys that hold robots settings; each follows the original onto
		 * its translations.
		 *
		 * @param array<int, string> $keys Rank Math, Yoast SEO and SEOPress keys.
		 */
		return array_values(
			array_map(
				'strval',
				(array) apply_filters(
					'tranzly_robots_meta_keys',
					array(
						'rank_math_robots',
						'rank_math_advanced_robots',
						'_yoast_wpseo_meta-robots-noindex',
						'_yoast_wpseo_meta-robots-nofollow',
						'_seopress_robots_index',
						'_seopress_robots_follow',
					)
				)
			)
		);
	}

	/**
	 * After a translation is written.
	 *
	 * @param int $target    The translation.
	 * @param int $source_id The original.
	 * @return void
	 */
	public static function on_translated( $target, $source_id ): void {
		self::copy( (int) $source_id, array( (int) $target ) );
	}

	/**
	 * An original's robots meta changed: its translations follow.
	 *
	 * @param int|array<int, int> $meta_id  Meta ID(s) (unused).
	 * @param int                 $post_id  The post.
	 * @param string              $meta_key The key.
	 * @return void
	 */
	public static function on_meta( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id );
		if ( self::$copying || ! in_array( (string) $meta_key, self::keys(), true ) || ! Schema::live() ) {
			return;
		}
		$post_id = (int) $post_id;
		$group   = Relations::translations( 'post', $post_id );
		if ( ! self::is_original( $post_id, $group ) ) {
			return;
		}
		self::copy( $post_id, array_values( array_diff( array_map( 'intval', $group ), array( $post_id ) ) ) );
	}

	/**
	 * Copy the original's robots meta onto translations (a key the original lacks is removed).
	 *
	 * @param int             $source  The original.
	 * @param array<int, int> $targets Translations.
	 * @return int Translations changed.
	 */
	public static function copy( int $source, array $targets ): int {
		$changed       = 0;
		self::$copying = true;
		try {
			foreach ( $targets as $target ) {
				if ( $target <= 0 || $target === $source ) {
					continue;
				}
				foreach ( self::keys() as $key ) {
					$want = metadata_exists( 'post', $source, $key ) ? get_post_meta( $source, $key, true ) : null;
					$have = metadata_exists( 'post', $target, $key ) ? get_post_meta( $target, $key, true ) : null;
					if ( $want === $have ) {
						continue;
					}
					if ( null === $want ) {
						delete_post_meta( $target, $key );
					} else {
						update_post_meta( $target, $key, is_string( $want ) ? wp_slash( $want ) : $want );
					}
					++$changed;
				}
			}
		} finally {
			self::$copying = false;
		}

		return $changed;
	}

	/**
	 * The one-time pass for sites translated before this version: originals with robots meta, in
	 * ID order from the saved cursor, BATCH per admin request until none is left.
	 *
	 * @return int Translations changed.
	 */
	public static function sync_pending(): int {
		if ( 'done' === get_option( self::STATE ) || ! Schema::live() ) {
			return 0;
		}
		global $wpdb;
		$after   = (int) get_option( self::STATE, 0 );
		$keys    = self::keys();
		$holders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$ids     = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-time, cursor-driven pass.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the values are passed as one array.
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one %s per key, built from literals only.
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ($holders) AND post_id > %d ORDER BY post_id ASC LIMIT %d",
				array_merge( $keys, array( $after, self::BATCH ) )
			)
		);
		$changed = 0;
		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$group = Relations::translations( 'post', $id );
			if ( self::is_original( $id, $group ) ) {
				$changed += self::copy( $id, array_values( array_diff( array_map( 'intval', $group ), array( $id ) ) ) );
			}
			$after = $id;
		}
		update_option( self::STATE, count( $ids ) < self::BATCH ? 'done' : $after, false );

		return $changed;
	}

	/**
	 * Is the post the original of its group (or in no group)?
	 *
	 * @param int                $post_id The post.
	 * @param array<string, int> $group   Language => post ID, from Relations::translations().
	 * @return bool
	 */
	private static function is_original( int $post_id, array $group ): bool {
		// No translations: nothing to copy to. Otherwise the group's source (Staleness's test).
		return count( $group ) > 1 && Staleness::is_original( $post_id );
	}
}
