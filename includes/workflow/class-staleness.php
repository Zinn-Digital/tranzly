<?php
/**
 * Knowing when a translation is out of date (tz-w3, tz-w9).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Workflow;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Schema;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ HOW "OUT OF DATE" IS DECIDED, WITHOUT READING A SINGLE TRANSLATION. Every translation already
 * records the hash of the source segments it was made from (`Translator::SOURCE_HASH_META`). This
 * class keeps the source's CURRENT hash beside it (`CURRENT_META`), so a translation is out of date
 * exactly when the two differ — one comparison the status dashboard makes in SQL, for every post
 * and every language at once, with no parsing on the read path.
 *
 * The current hash is computed once per saved source per request, at `shutdown`, after every plugin
 * has written its meta (ACF saves on `save_post` priority 10, a page builder after the post row), so
 * a change to a custom field or to builder data counts as a change of the page.
 */
final class Staleness {

	/** Post meta on an original: the hash of its segments now. */
	public const CURRENT_META = '_tranzly_source_hash_now';

	/**
	 * Originals saved during this request: ID => true when it was just published.
	 *
	 * @var array<int, bool>
	 */
	private static array $dirty = array();

	/**
	 * Hook it.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'save_post', array( self::class, 'on_save' ), 100, 2 );
		add_action( 'transition_post_status', array( self::class, 'on_transition' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( self::class, 'on_meta' ), 10, 3 );
		add_action( 'shutdown', array( self::class, 'flush' ), 5 );
	}

	/**
	 * `save_post`: remember an original that changed.
	 *
	 * @param int           $post_id The post.
	 * @param \WP_Post|null $post    The post.
	 * @return void
	 */
	public static function on_save( $post_id, $post = null ): void {
		if ( ! Schema::live() ) {
			return; // Not installed on this site (multisite: another site's code switched here).
		}

		$post_id = (int) $post_id;
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! Content::is_translatable_type( (string) $post->post_type ) || ! self::is_original( $post_id ) ) {
			return;
		}
		self::$dirty[ $post_id ] = self::$dirty[ $post_id ] ?? false;
	}

	/**
	 * `transition_post_status`: an original that has just been published (tz-w3 "new content").
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       The post.
	 * @return void
	 */
	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( ! Schema::live() ) {
			return; // Not installed on this site (multisite: another site's code switched here).
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! Content::is_translatable_type( (string) $post->post_type ) || ! self::is_original( (int) $post->ID ) ) {
			return;
		}
		self::$dirty[ (int) $post->ID ] = true;
	}

	/**
	 * `updated_post_meta` / `added_post_meta`: a translated meta key (a custom field, builder data)
	 * changed without the post row being saved.
	 *
	 * @param int    $meta_id  Meta row ID (unused).
	 * @param int    $post_id  The post.
	 * @param string $meta_key The key.
	 * @return void
	 */
	public static function on_meta( $meta_id, $post_id, $meta_key ): void {
		if ( ! Schema::live() ) {
			return; // Not installed on this site (multisite: another site's code switched here).
		}

		unset( $meta_id );
		$post_id = (int) $post_id;
		if ( isset( self::$dirty[ $post_id ] ) || str_starts_with( (string) $meta_key, '_tranzly' ) || '_edit_lock' === $meta_key || '_edit_last' === $meta_key ) {
			return;
		}

		/**
		 * Filters the meta keys whose change makes a page's translations out of date. Integrations
		 * add the keys they translate (builder data, SEO fields, custom fields).
		 *
		 * @param array<int, string> $keys    Meta keys.
		 * @param int                $post_id The post.
		 */
		$keys = (array) apply_filters( 'tranzly_source_meta_keys', array(), $post_id );
		if ( ! in_array( (string) $meta_key, $keys, true ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post ) {
			self::on_save( $post_id, $post );
		}
	}

	/**
	 * `shutdown`: hash every original saved in this request and announce the changed ones.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$dirty       = self::$dirty;
		self::$dirty = array();
		foreach ( $dirty as $post_id => $published ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$before = (string) get_post_meta( (int) $post_id, self::CURRENT_META, true );
			$now    = self::current_hash( $post );
			if ( $now !== $before ) {
				update_post_meta( (int) $post_id, self::CURRENT_META, $now );
			}
			if ( $published ) {
				/**
				 * Fires once when an original is first published (after its meta is saved).
				 *
				 * @param int $post_id The original.
				 */
				do_action( 'tranzly_source_published', (int) $post_id );
			} elseif ( '' !== $before && $now !== $before ) {
				/**
				 * Fires when an original's translatable content changed, so its translations are
				 * now out of date.
				 *
				 * @param int    $post_id The original.
				 * @param string $now     The new hash.
				 * @param string $before  The previous hash.
				 */
				do_action( 'tranzly_source_changed', (int) $post_id, $now, $before );
			}
		}
	}

	/**
	 * The hash of an original's segments as they are now (the same function the translator stamps
	 * on every translation).
	 *
	 * @param \WP_Post $post The original.
	 * @return string
	 */
	public static function current_hash( \WP_Post $post ): string {
		return Translator::hash( Translator::post_segments( $post ) );
	}

	/**
	 * Is a translation out of date? Unknown (no hash on either side) is NOT out of date: a
	 * translation a person wrote by hand before Tranzly tracked hashes is not flagged on a guess.
	 *
	 * @param int $source_id The original.
	 * @param int $target_id The translation.
	 * @return bool
	 */
	public static function is_stale( int $source_id, int $target_id ): bool {
		$made = (string) get_post_meta( $target_id, Translator::SOURCE_HASH_META, true );
		$now  = (string) get_post_meta( $source_id, self::CURRENT_META, true );
		if ( '' === $now ) {
			$post = get_post( $source_id );
			$now  = $post instanceof \WP_Post ? self::current_hash( $post ) : '';
		}

		return '' !== $made && '' !== $now && $made !== $now;
	}

	/**
	 * Is this post an original (the source of its group, or in no group)?
	 *
	 * @param int $post_id The post.
	 * @return bool
	 */
	public static function is_original( int $post_id ): bool {
		$members = Relations::translations( 'post', $post_id );
		if ( array() === $members ) {
			return true;
		}
		global $wpdb;
		$source = $wpdb->get_var( $wpdb->prepare( 'SELECT r2.object_id FROM %i r1 INNER JOIN %i r2 ON r2.group_id = r1.group_id AND r2.object_type = r1.object_type AND r2.is_source = 1 WHERE r1.object_type = %s AND r1.object_id = %d', Schema::tables()['relations'], Schema::tables()['relations'], 'post', $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed read on save only.
		if ( null === $source ) {
			return Languages::default_code() === Relations::language_of( 'post', $post_id );
		}

		return (int) $source === $post_id;
	}
}
