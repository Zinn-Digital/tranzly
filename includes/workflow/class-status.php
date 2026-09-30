<?php
/**
 * The translation status dashboard (tz-w9): what is translated, missing or out of date, per
 * language, with one-click fixes.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Workflow;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Queue;
use ZinnDigital\Tranzly\Core\Schema;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `tranzly/v1/status`, `status/items`, `status/fix`.
 *
 * ⭐ SCALE. Every number is ONE grouped SQL query over the relations table and three meta joins —
 * no post is loaded, no content is parsed — so the page costs the same on a site with a hundred
 * pages and on one with a hundred thousand. Lists are cursor-paged by post ID (no OFFSET, no cap on
 * how far you can page), and a one-click fix walks every matching post in pages of 1,000 and hands
 * them all to ONE background job: nothing is left out because a list was long.
 *
 * An "original" is the source of its translation group, or a post in no group at all.
 */
final class Status {

	/** Posts per page of the list. */
	public const PAGE = 50;

	/** Posts per page when a fix collects its whole population. */
	private const FIX_PAGE = 1000;

	/** Post meta (Pro, tz-r6): the AI quality score, 0-100. Read here for the list and filters. */
	public const QUALITY_SCORE_META = '_tranzly_quality_score';

	/** Post meta (Pro, tz-w6): a translation's review state (`pending`, `approved`, `changes`). */
	public const REVIEW_META = '_tranzly_review';

	/** States a list can be filtered to. */
	public const STATES = array( 'all', 'missing', 'stale', 'translated', 'human', 'machine', 'review', 'quality' );

	/**
	 * Hook the routes, and the one-time back-fill of current hashes.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( Backfill::HOOK, array( Backfill::class, 'run' ) );
		add_action( 'admin_init', array( Backfill::class, 'maybe_start' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$filters = array(
			'post_type' => array(
				'type'    => 'string',
				'default' => '',
			),
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_summary' ),
				'permission_callback' => array( self::class, 'can_view' ),
				'args'                => $filters,
			)
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/status/items',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_items' ),
				'permission_callback' => array( self::class, 'can_view' ),
				'args'                => $filters + array(
					'lang'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'state' => array(
						'type'    => 'string',
						'enum'    => self::STATES,
						'default' => 'all',
					),
					'after' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/status/fix',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'fix' ),
				'permission_callback' => array( self::class, 'can_view' ),
				'args'                => $filters + array(
					'lang'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'state' => array(
						'type'     => 'string',
						'enum'     => array( 'missing', 'stale', 'quality' ),
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Anyone who may edit posts may see the status (every fix still checks each post).
	 *
	 * @return bool
	 */
	public static function can_view(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * `GET status`: the numbers per language.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_summary( \WP_REST_Request $request ) {
		$types = self::types( (string) $request->get_param( 'post_type' ) );
		if ( is_wp_error( $types ) ) {
			return $types;
		}

		return new \WP_REST_Response( self::summary( $types ) );
	}

	/**
	 * The summary for some post types.
	 *
	 * @param array<int, string> $types Post types.
	 * @return array<string, mixed>
	 */
	public static function summary( array $types ): array {
		global $wpdb;
		$t       = Schema::tables()['relations'];
		$default = Languages::default_code();

		// Originals by their own language (a post in no group is in the default language).
		$by_lang = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- an aggregate over the whole site; nothing to cache between screens.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per post type, built from literals only.
				"SELECT COALESCE(r.lang, %s) AS lang, COUNT(*) AS n FROM {$wpdb->posts} p LEFT JOIN %i r ON r.object_type = 'post' AND r.object_id = p.ID WHERE p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ") AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') AND ( r.object_id IS NULL OR r.is_source = 1 ) GROUP BY COALESCE(r.lang, %s)",
				array_merge( array( $default, $t ), $types, array( $default ) )
			),
			ARRAY_A
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- an aggregate over the whole site; nothing to cache between screens.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per post type, built from literals only.
				"SELECT t.lang AS lang, COUNT(*) AS translated, SUM(CASE WHEN hs.meta_value IS NOT NULL AND ht.meta_value IS NOT NULL AND hs.meta_value <> ht.meta_value THEN 1 ELSE 0 END) AS stale, SUM(CASE WHEN st.meta_value IN ('human', 'legacy') THEN 1 ELSE 0 END) AS human, SUM(CASE WHEN rv.meta_value = 'pending' THEN 1 ELSE 0 END) AS review, SUM(CASE WHEN qs.meta_value IS NOT NULL AND CAST(qs.meta_value AS UNSIGNED) < %d THEN 1 ELSE 0 END) AS quality_low, AVG(CAST(qs.meta_value AS UNSIGNED)) AS quality_avg FROM {$wpdb->posts} p INNER JOIN %i r ON r.object_type = 'post' AND r.object_id = p.ID AND r.is_source = 1 INNER JOIN %i t ON t.object_type = 'post' AND t.group_id = r.group_id AND t.object_id <> p.ID LEFT JOIN {$wpdb->postmeta} hs ON hs.post_id = p.ID AND hs.meta_key = %s LEFT JOIN {$wpdb->postmeta} ht ON ht.post_id = t.object_id AND ht.meta_key = %s LEFT JOIN {$wpdb->postmeta} st ON st.post_id = t.object_id AND st.meta_key = %s LEFT JOIN {$wpdb->postmeta} rv ON rv.post_id = t.object_id AND rv.meta_key = %s LEFT JOIN {$wpdb->postmeta} qs ON qs.post_id = t.object_id AND qs.meta_key = %s WHERE p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ") AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') GROUP BY t.lang",
				array_merge( array( self::threshold(), $t, $t, Staleness::CURRENT_META, Translator::SOURCE_HASH_META, Translator::STATUS_META, self::REVIEW_META, self::QUALITY_SCORE_META ), $types )
			),
			ARRAY_A
		);

		$originals = array();
		foreach ( (array) $by_lang as $row ) {
			$originals[ (string) $row['lang'] ] = (int) $row['n'];
		}
		$total = (int) array_sum( $originals );
		$per   = array();
		foreach ( (array) $rows as $row ) {
			$per[ (string) $row['lang'] ] = $row;
		}
		$out = array();
		foreach ( Languages::all() as $language ) {
			$code       = $language['code'];
			$row        = $per[ $code ] ?? array();
			$translated = (int) ( $row['translated'] ?? 0 );
			$own        = $originals[ $code ] ?? 0;
			$out[]      = array(
				'code'        => $code,
				'name'        => $language['name'],
				'default'     => $code === $default,
				'originals'   => $own,
				'translated'  => $translated,
				'missing'     => max( 0, $total - $own - $translated ),
				'stale'       => (int) ( $row['stale'] ?? 0 ),
				'human'       => (int) ( $row['human'] ?? 0 ),
				'machine'     => max( 0, $translated - (int) ( $row['human'] ?? 0 ) ),
				'review'      => (int) ( $row['review'] ?? 0 ),
				'quality_low' => (int) ( $row['quality_low'] ?? 0 ),
				'quality_avg' => isset( $row['quality_avg'] ) && null !== $row['quality_avg'] ? (int) round( (float) $row['quality_avg'] ) : null,
			);
		}

		return array(
			'originals'  => $total,
			'languages'  => $out,
			'post_types' => array_values(
				array_map(
					static function ( string $type ): array {
						$object = get_post_type_object( $type );
						return array(
							'slug' => $type,
							'name' => null !== $object ? (string) $object->labels->name : $type,
						);
					},
					Content::post_types()
				)
			),
			'threshold'  => self::threshold(),
			'backfill'   => Backfill::pending(),
		);
	}

	/**
	 * `GET status/items`: one page of originals and their translation in a language.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_items( \WP_REST_Request $request ) {
		$types = self::types( (string) $request->get_param( 'post_type' ) );
		if ( is_wp_error( $types ) ) {
			return $types;
		}
		$lang = Languages::resolve( (string) $request->get_param( 'lang' ) );
		if ( null === $lang || Languages::default_code() === $lang ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'Choose one of your other languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$state = (string) $request->get_param( 'state' );
		$rows  = self::page( $types, $lang, $state, (int) $request->get_param( 'after' ), self::PAGE );
		$items = array();
		foreach ( $rows as $row ) {
			$source = (int) $row['ID'];
			// ⛔ The list is open to anyone who may edit posts, and the page query reads every
			// status: a contributor must not learn the titles of other people's drafts or private
			// pages (L07 adversarial sweep, wp/tests/e2e/tranzly/endpoints.spec.mjs). The cursor
			// still advances past a skipped row, so paging is unchanged.
			if ( ! current_user_can( 'read_post', $source ) ) {
				continue;
			}
			$target = null === $row['target'] ? 0 : (int) $row['target'];
			$score  = null === $row['score'] ? null : (int) $row['score'];
			$state  = 'missing';
			if ( $target > 0 ) {
				$state = null !== $row['hs'] && null !== $row['ht'] && $row['hs'] !== $row['ht'] ? 'stale' : 'translated';
			}
			$items[] = array(
				'id'          => $source,
				'title'       => '' === (string) $row['post_title'] ? __( '(no title)', 'tranzly' ) : wp_strip_all_tags( (string) $row['post_title'] ),
				'type'        => (string) $row['post_type'],
				'edit'        => current_user_can( 'edit_post', $source ) ? (string) get_edit_post_link( $source, 'raw' ) : '',
				'translation' => $target > 0 ? array(
					'id'      => $target,
					'status'  => (string) get_post_status( $target ),
					'made_by' => '' === (string) $row['made_by'] ? 'machine' : (string) $row['made_by'],
					'review'  => (string) $row['review'],
					'quality' => $score,
					'edit'    => current_user_can( 'edit_post', $target ) ? (string) get_edit_post_link( $target, 'raw' ) : '',
					'compare' => current_user_can( 'edit_post', $target ) ? admin_url( 'admin.php?page=tranzly-compare&post=' . $target ) : '',
				) : null,
				'state'       => $state,
			);
		}

		return new \WP_REST_Response(
			array(
				'lang'  => $lang,
				'items' => $items,
				'next'  => count( $rows ) === self::PAGE ? (int) end( $rows )['ID'] : null,
			)
		);
	}

	/**
	 * `POST status/fix`: translate every missing (or re-translate every out-of-date, or every
	 * low-scoring) post of a language in ONE background job, running as the current user.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function fix( \WP_REST_Request $request ) {
		$types = self::types( (string) $request->get_param( 'post_type' ) );
		if ( is_wp_error( $types ) ) {
			return $types;
		}
		$lang = Languages::resolve( (string) $request->get_param( 'lang' ) );
		if ( null === $lang || Languages::default_code() === $lang ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'Choose one of your other languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$ids = self::collect( $types, $lang, (string) $request->get_param( 'state' ) );
		if ( array() === $ids ) {
			return new \WP_Error( 'tranzly_empty_job', __( 'There is nothing to fix here.', 'tranzly' ), array( 'status' => 400 ) );
		}
		// Out of date: the person already has a translation, so re-translating it replaces a
		// machine one; a protected one is refused per item (and shown in the job's failures).
		$job = Queue::create( $ids, array( $lang ) );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return new \WP_REST_Response( $job, 201 );
	}

	/**
	 * Every original of a state in a language, walked in pages until the end (no cap).
	 *
	 * @param array<int, string> $types Post types.
	 * @param string             $lang  Language.
	 * @param string             $state `missing`, `stale` or `quality`.
	 * @return array<int, int>
	 */
	public static function collect( array $types, string $lang, string $state ): array {
		$ids   = array();
		$after = 0;
		do {
			$rows = self::page( $types, $lang, $state, $after, self::FIX_PAGE );
			foreach ( $rows as $row ) {
				$ids[] = (int) $row['ID'];
			}
			$after = array() === $rows ? 0 : (int) end( $rows )['ID'];
			$got   = count( $rows );
		} while ( self::FIX_PAGE === $got );

		return $ids;
	}

	/**
	 * One page of originals of some types, with their `$lang` translation, filtered to a state.
	 *
	 * @param array<int, string> $types Post types.
	 * @param string             $lang  Target language.
	 * @param string             $state One of STATES.
	 * @param int                $after Cursor: the last post ID of the previous page.
	 * @param int                $limit Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public static function page( array $types, string $lang, string $state, int $after, int $limit ): array {
		global $wpdb;
		$t       = Schema::tables()['relations'];
		$default = Languages::default_code();
		$state   = in_array( $state, self::STATES, true ) ? $state : 'all';
		$rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a cursor page; each page is read once.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per post type, built from literals only.
				"SELECT p.ID, p.post_title, p.post_type, t.object_id AS target, hs.meta_value AS hs, ht.meta_value AS ht, st.meta_value AS made_by, rv.meta_value AS review, qs.meta_value AS score FROM {$wpdb->posts} p LEFT JOIN %i r ON r.object_type = 'post' AND r.object_id = p.ID LEFT JOIN %i t ON t.object_type = 'post' AND t.group_id = r.group_id AND t.lang = %s AND t.object_id <> p.ID LEFT JOIN {$wpdb->postmeta} hs ON hs.post_id = p.ID AND hs.meta_key = %s LEFT JOIN {$wpdb->postmeta} ht ON ht.post_id = t.object_id AND ht.meta_key = %s LEFT JOIN {$wpdb->postmeta} st ON st.post_id = t.object_id AND st.meta_key = %s LEFT JOIN {$wpdb->postmeta} rv ON rv.post_id = t.object_id AND rv.meta_key = %s LEFT JOIN {$wpdb->postmeta} qs ON qs.post_id = t.object_id AND qs.meta_key = %s WHERE p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ") AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') AND ( r.object_id IS NULL OR r.is_source = 1 ) AND COALESCE(r.lang, %s) <> %s AND p.ID > %d AND ( %s = 'all' OR ( %s = 'missing' AND t.object_id IS NULL ) OR ( %s = 'stale' AND t.object_id IS NOT NULL AND hs.meta_value IS NOT NULL AND ht.meta_value IS NOT NULL AND hs.meta_value <> ht.meta_value ) OR ( %s = 'translated' AND t.object_id IS NOT NULL ) OR ( %s = 'human' AND t.object_id IS NOT NULL AND st.meta_value IN ('human', 'legacy') ) OR ( %s = 'machine' AND t.object_id IS NOT NULL AND ( st.meta_value IS NULL OR st.meta_value NOT IN ('human', 'legacy') ) ) OR ( %s = 'review' AND rv.meta_value = 'pending' ) OR ( %s = 'quality' AND qs.meta_value IS NOT NULL AND CAST(qs.meta_value AS UNSIGNED) < %d ) ) ORDER BY p.ID ASC LIMIT %d",
				array_merge( array( $t, $t, $lang, Staleness::CURRENT_META, Translator::SOURCE_HASH_META, Translator::STATUS_META, self::REVIEW_META, self::QUALITY_SCORE_META ), $types, array( $default, $lang, $after ), array_fill( 0, 8, $state ), array( self::threshold(), $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The score under which a translation "needs a human look" (tz-r6; Pro sets it).
	 *
	 * @return int
	 */
	public static function threshold(): int {
		return Workflow::settings()['quality_threshold'];
	}

	/**
	 * The post types asked for: one translatable type, or all of them.
	 *
	 * @param string $type A post type or empty.
	 * @return array<int, string>|\WP_Error
	 */
	public static function types( string $type ) {
		$all = Content::post_types();
		if ( '' === $type ) {
			return array() === $all ? new \WP_Error( 'tranzly_no_types', __( 'No content type is translated on this site.', 'tranzly' ), array( 'status' => 400 ) ) : $all;
		}
		if ( ! in_array( $type, $all, true ) ) {
			return new \WP_Error( 'tranzly_bad_type', __( 'That content type is not translated on this site.', 'tranzly' ), array( 'status' => 400 ) );
		}

		return array( $type );
	}
}
