<?php
/**
 * One query per page for every language fact the page will print (the speed promise, tz-r14).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Schema;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ A translated page prints, besides its own content: hreflang links to every version of itself
 * (each version's group row AND its post row), and links to its categories and tags (each term's
 * language). Asked for one at a time that is one query per version and one per term. At `wp`, once
 * the main query is known, this reads ALL of it in one UNION query:
 *
 *   1. the queried post's group, with every member's post row (so no get_post() later);
 *   2. the queried term's group (a category archive);
 *   3. the language of every term attached to the main query's posts.
 *
 * and seeds the caches the rest of the plugin already reads (Relations' memo, the post cache,
 * Router's language map), so nothing downstream changed.
 */
final class Primer {

	/** The wp_posts columns, listed so the term half of the UNION can match them with NULLs. */
	private const POST_COLUMNS = array( 'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count' );

	/**
	 * Hook the primer.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp', array( self::class, 'prime' ), 1 );
	}

	/**
	 * `wp`: prime.
	 *
	 * @return void
	 */
	public static function prime(): void {
		if ( ! Router::is_front() || count( Languages::all() ) < 2 ) {
			return;
		}
		global $wp_query;
		$post_ids = array();
		$term_ids = array();
		$queried  = get_queried_object();
		if ( $queried instanceof \WP_Post ) {
			$post_ids[] = $queried->ID;
		} elseif ( $queried instanceof \WP_Term && Content::is_translatable_taxonomy( $queried->taxonomy ) ) {
			$term_ids[] = $queried->term_id;
		}
		$listed = array();
		foreach ( (array) ( $wp_query->posts ?? array() ) as $post ) {
			if ( $post instanceof \WP_Post && Content::is_translatable_type( $post->post_type ) ) {
				$listed[] = $post->ID;
			}
		}
		if ( array() === $post_ids && array() === $term_ids && array() === $listed ) {
			return;
		}
		global $wpdb;
		$rel = Schema::tables()['relations'];
		// An empty list asks for ID 0, which no row has: the query's SHAPE never changes, so every
		// value in it is a placeholder (Plugin Check: no variable SQL reaches $wpdb).
		$p    = array() === $post_ids ? array( 0 ) : array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		$t    = array() === $term_ids ? array( 0 ) : array_values( array_unique( array_map( 'intval', $term_ids ) ) );
		$l    = array() === $listed ? array( 0 ) : array_values( array_unique( array_map( 'intval', $listed ) ) );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- it IS the cache (seed() fills the object cache).
			$wpdb->prepare(
				"(SELECT 'post' AS kind, r1.object_id AS asked, r2.lang AS lang, r2.object_id AS member, p.ID, p.post_author, p.post_date, p.post_date_gmt, p.post_content, p.post_title, p.post_excerpt, p.post_status, p.comment_status, p.ping_status, p.post_password, p.post_name, p.to_ping, p.pinged, p.post_modified, p.post_modified_gmt, p.post_content_filtered, p.post_parent, p.guid, p.menu_order, p.post_type, p.post_mime_type, p.comment_count FROM %i r1 INNER JOIN %i r2 ON r2.group_id = r1.group_id AND r2.object_type = r1.object_type LEFT JOIN {$wpdb->posts} p ON p.ID = r2.object_id WHERE r1.object_type = 'post' AND r1.object_id IN (" . implode( ',', array_fill( 0, count( $p ), '%d' ) ) . '))'
				. " UNION ALL (SELECT 'term' AS kind, r1.object_id AS asked, r2.lang AS lang, r2.object_id AS member, NULL AS ID, NULL AS post_author, NULL AS post_date, NULL AS post_date_gmt, NULL AS post_content, NULL AS post_title, NULL AS post_excerpt, NULL AS post_status, NULL AS comment_status, NULL AS ping_status, NULL AS post_password, NULL AS post_name, NULL AS to_ping, NULL AS pinged, NULL AS post_modified, NULL AS post_modified_gmt, NULL AS post_content_filtered, NULL AS post_parent, NULL AS guid, NULL AS menu_order, NULL AS post_type, NULL AS post_mime_type, NULL AS comment_count FROM %i r1"
				. '  INNER JOIN %i r2 ON r2.group_id = r1.group_id AND r2.object_type = r1.object_type WHERE r1.object_type = \'term\' AND r1.object_id IN (' . implode( ',', array_fill( 0, count( $t ), '%d' ) ) . '))'
				. " UNION ALL (SELECT 'tlang' AS kind, tt.term_id AS asked, r1.lang AS lang, 0 AS member, NULL AS ID, NULL AS post_author, NULL AS post_date, NULL AS post_date_gmt, NULL AS post_content, NULL AS post_title, NULL AS post_excerpt, NULL AS post_status, NULL AS comment_status, NULL AS ping_status, NULL AS post_password, NULL AS post_name, NULL AS to_ping, NULL AS pinged, NULL AS post_modified, NULL AS post_modified_gmt, NULL AS post_content_filtered, NULL AS post_parent, NULL AS guid, NULL AS menu_order, NULL AS post_type, NULL AS post_mime_type, NULL AS comment_count FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id LEFT JOIN %i r1 ON r1.object_type = 'term' AND r1.object_id = tt.term_id WHERE tr.object_id IN (" . implode( ',', array_fill( 0, count( $l ), '%d' ) ) . '))',
				array_merge( array( $rel, $rel ), $p, array( $rel, $rel ), $t, array( $rel ), $l )
			),
			ARRAY_A
		);
		self::seed( (array) $rows, $post_ids, $term_ids );
	}

	/**
	 * Put the rows where the plugin looks for them.
	 *
	 * @param array<int, array<string, mixed>> $rows     Query rows.
	 * @param array<int, int>                  $post_ids Posts asked for.
	 * @param array<int, int>                  $term_ids Terms asked for.
	 * @return void
	 */
	public static function seed( array $rows, array $post_ids, array $term_ids ): void {
		$maps  = array(
			'post' => array_fill_keys( $post_ids, array() ),
			'term' => array_fill_keys( $term_ids, array() ),
		);
		$posts = array();
		foreach ( $rows as $row ) {
			$kind = (string) $row['kind'];
			if ( 'tlang' === $kind ) {
				Router::know( 'term', (int) $row['asked'], null === $row['lang'] ? null : (string) $row['lang'] );
				continue;
			}
			$maps[ $kind ][ (int) $row['asked'] ][ (string) $row['lang'] ] = (int) $row['member'];
			if ( 'post' === $kind && null !== $row['ID'] ) {
				$post                      = array_intersect_key( $row, array_flip( self::POST_COLUMNS ) );
				$post['tranzly_lang']      = (string) $row['lang'];
				$posts[ (int) $row['ID'] ] = (object) $post;
			}
		}
		foreach ( $maps as $type => $by_id ) {
			foreach ( $by_id as $id => $map ) {
				Relations::seed( $type, $map, (int) $id );
			}
		}
		foreach ( $posts as $id => $post ) {
			Router::know( 'post', $id, $post->tranzly_lang );
			if ( false === wp_cache_get( $id, 'posts' ) ) {
				wp_cache_add( $id, sanitize_post( $post, 'raw' ), 'posts' );
			}
		}
	}
}
