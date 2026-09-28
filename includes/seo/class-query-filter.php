<?php
/**
 * Front-end queries show the visitor's language only: archives, the blog, search, widgets.
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
 * ⭐ POST-PER-LANGUAGE MEANS EVERY LANGUAGE'S POSTS SHARE ONE TABLE, so without this the English
 * blog lists the German translations beside their originals, and a German search returns English
 * pages (tz-r13). Every front-end `WP_Query` over translated post types gets one LEFT JOIN on the
 * relations table's primary key: a post with no row is in the default language, which is what
 * keeps a site with no translations at zero extra cost.
 *
 * - A query that asks for particular posts (`p`, `page_id`, `post__in`) is left alone: the caller
 *   named what it wants.
 * - A query may opt out with `tranzly_lang => 'all'`, or ask for a language with a code.
 * - Sitemaps see every language (each URL carries its own language, tz-s5).
 * - Two posts in DIFFERENT languages may share a slug (/contact and /de/contact): WordPress's own
 *   duplicate check only counts the post's own language, and a page path is resolved within the
 *   language of the address.
 */
final class Query_Filter {

	/** The query variable. */
	public const VAR = 'tranzly_lang';

	/** Query variable: read each post's language in the same query. */
	public const SELECT = 'tranzly_select_lang';

	/** The property a post or term object carries its language in (null = the default language). */
	public const PROP = 'tranzly_lang';

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'pre_get_posts', array( self::class, 'pre_get_posts' ), 20 );
		add_filter( 'posts_clauses', array( self::class, 'posts_clauses' ), 20, 2 );
		add_filter( 'request', array( self::class, 'resolve_page_path' ), 20 );
		add_filter( 'the_posts', array( self::class, 'prime' ), 5, 1 );
		add_filter( 'get_pages', array( self::class, 'filter_pages' ), 20, 1 );
		add_filter( 'terms_clauses', array( self::class, 'terms_clauses' ), 20, 3 );
		add_filter( 'wp_unique_post_slug', array( self::class, 'unique_slug' ), 20, 6 );
		foreach ( array( 'previous', 'next' ) as $dir ) {
			add_filter( "get_{$dir}_post_join", array( self::class, 'adjacent_join' ), 20, 1 );
			add_filter( "get_{$dir}_post_where", array( self::class, 'adjacent_where' ), 20, 1 );
		}
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'all_languages' ), 20, 1 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( self::class, 'all_languages' ), 20, 1 );
	}

	/**
	 * Is language filtering on for this request? The front end, except sitemaps.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		if ( ! Router::is_front() || count( Languages::all() ) < 2 ) {
			return false;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched, never printed.

		return 1 !== preg_match( '#(?:sitemap[^/]*\.xml|sitemap\.xsl|/sitemap(?:_index)?/?|\.xsl)(?:\?|$)#i', $uri );
	}

	/**
	 * `pre_get_posts`: mark the query with the language it should return.
	 *
	 * @param \WP_Query $query The query.
	 * @return void
	 */
	public static function pre_get_posts( $query ): void {
		if ( ! $query instanceof \WP_Query || ! Router::is_front() || count( Languages::all() ) < 2 ) {
			return;
		}
		$types = $query->get( 'post_type' );
		if ( is_string( $types ) && '' !== $types && 'any' !== $types && ! Content::is_translatable_type( $types ) ) {
			return;
		}
		if ( is_array( $types ) && array() === array_intersect( $types, Content::post_types() ) ) {
			return;
		}
		// ⭐ Every front-end query over translated types reads each post's language in the SAME query
		// (one LEFT JOIN on the relations primary key), so the links printed for its posts — each in
		// its own language — cost no query of their own (the speed promise, tz-r14).
		$query->set( self::SELECT, 1 );
		if ( '' !== (string) $query->get( self::VAR ) || ! self::active() ) {
			return;
		}
		// A posts page is asked for by its page ID and still lists posts: that list is filtered.
		if ( $query->get( 'p' ) || ( $query->get( 'page_id' ) && ! $query->is_posts_page ) || $query->get( 'attachment_id' ) || ! empty( $query->get( 'post__in' ) ) ) {
			return;
		}
		$query->set( self::VAR, Languages::current() );
	}

	/**
	 * `posts_clauses`: the language join and condition.
	 *
	 * @param array<string, string> $clauses The clauses.
	 * @param \WP_Query             $query   The query.
	 * @return array<string, string>
	 */
	public static function posts_clauses( $clauses, $query ) {
		if ( ! is_array( $clauses ) || ! $query instanceof \WP_Query ) {
			return $clauses;
		}
		$lang   = Languages::resolve( (string) $query->get( self::VAR ) );
		$select = (bool) $query->get( self::SELECT );
		if ( null === $lang && ! $select ) {
			return $clauses;
		}
		global $wpdb;
		$sql             = self::sql( $lang ?? Languages::default_code() );
		$clauses['join'] = ( $clauses['join'] ?? '' ) . $sql['join'];
		if ( null !== $lang ) {
			$clauses['where'] = ( $clauses['where'] ?? '' ) . $sql['where'];
		}
		if ( $select && str_contains( (string) ( $clauses['fields'] ?? '' ), "{$wpdb->posts}.*" ) ) {
			$clauses['fields'] .= ', tzl.lang AS ' . self::PROP;
		}

		return $clauses;
	}

	/**
	 * The JOIN and WHERE fragments that keep one language (the pure half).
	 *
	 * @param string $lang A language code.
	 * @return array{join: string, where: string}
	 */
	public static function sql( string $lang ): array {
		global $wpdb;
		$table = Schema::tables()['relations'];
		$types = Content::post_types();
		$in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$cond  = Languages::default_code() === $lang
			? $wpdb->prepare( '(tzl.lang IS NULL OR tzl.lang = %s)', $lang )
			: $wpdb->prepare( 'tzl.lang = %s', $lang );

		return array(
			'join'  => " LEFT JOIN {$table} AS tzl ON tzl.object_type = 'post' AND tzl.object_id = {$wpdb->posts}.ID",
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the IN list is placeholders built above; $cond is prepared.
			'where' => ' AND (' . ( array() === $types ? '' : $wpdb->prepare( "{$wpdb->posts}.post_type NOT IN ({$in})", ...$types ) . ' OR ' ) . $cond . ')',
		);
	}

	/**
	 * `request`: a page path (`/de/kontakt/`) resolves to the page in the address's language when
	 * two languages share the slug. It runs BEFORE WP_Query decides what kind of page this is, so a
	 * shared-slug posts page (/de/blog/) is still recognised as the blog.
	 *
	 * @param array<string, mixed> $vars The parsed query variables.
	 * @return array<string, mixed>
	 */
	public static function resolve_page_path( $vars ) {
		if ( ! is_array( $vars ) || empty( $vars['pagename'] ) || ! is_string( $vars['pagename'] ) || ! self::active() ) {
			return $vars;
		}
		$lang  = Languages::current();
		$found = get_page_by_path( $vars['pagename'] );
		if ( ! $found instanceof \WP_Post || Router::post_language( $found->ID ) === $lang ) {
			return $vars;
		}
		$better = self::page_by_path( $vars['pagename'], $lang, $found->post_type );
		if ( null !== $better ) {
			unset( $vars['pagename'] );
			$vars['page_id'] = $better;
		}

		return $vars;
	}

	/**
	 * A hierarchical post in a language by its path (`parent/child`), or null.
	 *
	 * @param string $path      The path.
	 * @param string $lang      A language code.
	 * @param string $post_type The post type.
	 * @return int|null
	 */
	public static function page_by_path( string $path, string $lang, string $post_type = 'page' ): ?int {
		global $wpdb;
		$parts = array_values( array_filter( array_map( 'sanitize_title_for_query', explode( '/', rawurldecode( trim( $path, '/' ) ) ) ) ) );
		if ( array() === $parts ) {
			return null;
		}
		$in    = implode( ', ', array_fill( 0, count( $parts ), '%s' ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup per page view, only when two languages share a slug.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the IN list is spread in.
				"SELECT p.ID, p.post_name, p.post_parent, r.lang FROM {$wpdb->posts} p LEFT JOIN %i r ON r.object_type = 'post' AND r.object_id = p.ID WHERE p.post_type = %s AND p.post_status IN ('publish','private') AND p.post_name IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is placeholders.
				Schema::tables()['relations'],
				$post_type,
				...$parts
			)
		);
		$by_id = array();
		foreach ( (array) $rows as $row ) {
			$by_id[ (int) $row->ID ] = $row;
		}
		$leaf = end( $parts );
		foreach ( $by_id as $id => $row ) {
			$row_lang = null === $row->lang ? Languages::default_code() : (string) $row->lang;
			if ( $row->post_name !== $leaf || $row_lang !== $lang ) {
				continue;
			}
			// Walk up: each ancestor must match the path word before it.
			$ok     = true;
			$parent = (int) $row->post_parent;
			for ( $i = count( $parts ) - 2; $i >= 0; $i-- ) {
				if ( ! isset( $by_id[ $parent ] ) || $by_id[ $parent ]->post_name !== $parts[ $i ] ) {
					$ok = false;
					break;
				}
				$parent = (int) $by_id[ $parent ]->post_parent;
			}
			if ( $ok && 0 === $parent ) {
				return (int) $id;
			}
		}

		return null;
	}

	/**
	 * `the_posts`: remember each post's language, read in the same query (see pre_get_posts); a
	 * post with no language row is in the default language and in no group, which Relations is told
	 * so it never asks. Posts from a query that did not read languages are loaded in ONE query.
	 *
	 * @param array<int, \WP_Post|int> $posts The posts.
	 * @return array<int, \WP_Post|int>
	 */
	public static function prime( $posts ) {
		if ( ! is_array( $posts ) || array() === $posts || count( Languages::all() ) < 2 || ! Router::is_front() ) {
			return $posts;
		}
		$unknown   = array();
		$ungrouped = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( property_exists( $post, self::PROP ) ) {
				Router::know( 'post', $post->ID, $post->{self::PROP} );
				if ( null === $post->{self::PROP} ) {
					$ungrouped[] = $post->ID;
				}
			} else {
				$unknown[] = $post->ID;
			}
		}
		Relations::note_ungrouped( 'post', $ungrouped );
		if ( count( $unknown ) > 1 ) {
			Relations::prime( 'post', $unknown );
		}

		return $posts;
	}

	/**
	 * `get_pages` (wp_list_pages, the page-list widget): only the visitor's language.
	 *
	 * @param array<int, \WP_Post> $pages The pages.
	 * @return array<int, \WP_Post>
	 */
	public static function filter_pages( $pages ) {
		if ( ! is_array( $pages ) || array() === $pages || ! self::active() ) {
			return $pages;
		}
		self::prime( $pages );
		$lang = Languages::current();

		return array_values( array_filter( $pages, static fn( $p ) => ! $p instanceof \WP_Post || Router::post_language( $p->ID ) === $lang ) );
	}

	/**
	 * `terms_clauses`: category lists, tag clouds and term widgets show the visitor's language.
	 * Lookups of particular terms (by slug, ID, or a post's own terms) are left alone.
	 *
	 * @param array<string, string> $clauses    The clauses.
	 * @param array<int, string>    $taxonomies The taxonomies.
	 * @param array<string, mixed>  $args       The query args.
	 * @return array<string, string>
	 */
	public static function terms_clauses( $clauses, $taxonomies, $args ) {
		if ( ! is_array( $clauses ) || ! is_array( $args ) || ! Router::is_front() || count( Languages::all() ) < 2 ) {
			return $clauses;
		}
		$translated = array_filter( (array) $taxonomies, array( Content::class, 'is_translatable_taxonomy' ) );
		if ( array() === $translated ) {
			return $clauses;
		}
		global $wpdb;
		$table           = Schema::tables()['relations'];
		$clauses['join'] = ( $clauses['join'] ?? '' ) . " LEFT JOIN {$table} AS tzt ON tzt.object_type = 'term' AND tzt.object_id = t.term_id";
		// Each term's language comes with it, so its link costs no query of its own (tz-r14).
		if ( str_contains( (string) ( $clauses['fields'] ?? '' ), 't.*' ) ) {
			$clauses['fields'] .= ', tzt.lang AS ' . self::PROP;
		}
		if ( ! self::active() ) {
			return $clauses;
		}
		foreach ( array( 'include', 'slug', 'name', 'object_ids', 'term_taxonomy_id' ) as $specific ) {
			if ( ! empty( $args[ $specific ] ) ) {
				return $clauses;
			}
		}
		$lang = Languages::current();
		$cond = Languages::default_code() === $lang
			? $wpdb->prepare( '(tzt.lang IS NULL OR tzt.lang = %s)', $lang )
			: $wpdb->prepare( 'tzt.lang = %s', $lang );
		$in   = implode( ', ', array_fill( 0, count( $translated ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above; $cond is prepared.
		$clauses['where'] = ( $clauses['where'] ?? '' ) . ' AND (' . $wpdb->prepare( "tt.taxonomy NOT IN ({$in})", ...array_values( $translated ) ) . ' OR ' . $cond . ')';

		return $clauses;
	}

	/**
	 * `wp_unique_post_slug`: a slug already used by a post in ANOTHER language is not a clash, so
	 * /contact and /de/contact can both exist (translated slugs, tz-s3).
	 *
	 * @param string $slug          The unique slug WordPress chose.
	 * @param int    $post_id       The post.
	 * @param string $post_status   Its status.
	 * @param string $post_type     Its type.
	 * @param int    $post_parent   Its parent.
	 * @param string $original_slug The slug asked for.
	 * @return string
	 */
	public static function unique_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		if ( $slug === $original_slug || ! Content::is_translatable_type( (string) $post_type ) || count( Languages::all() ) < 2 || 'query' === Router::mode() ) {
			return $slug;
		}
		global $wpdb;
		$lang       = Router::post_language( (int) $post_id );
		$check      = Languages::default_code() === $lang ? '(r.lang IS NULL OR r.lang = %s)' : 'r.lang = %s';
		$parent_sql = is_post_type_hierarchical( (string) $post_type ) ? $wpdb->prepare( ' AND p.post_parent = %d', (int) $post_parent ) : '';
		$taken      = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- runs on save only.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one placeholder in $check.
				"SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN %i r ON r.object_type = 'post' AND r.object_id = p.ID WHERE p.post_name = %s AND p.post_type = %s AND p.ID != %d AND p.post_status NOT IN ('trash','auto-draft','inherit') AND {$check}{$parent_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $check is a constant pair of placeholders, $parent_sql is prepared.
				Schema::tables()['relations'],
				(string) $original_slug,
				(string) $post_type,
				(int) $post_id,
				$lang
			)
		);

		return 0 === $taken ? (string) $original_slug : $slug;
	}

	/**
	 * Previous/next post links stay in the post's language: the JOIN.
	 *
	 * @param string $join The JOIN clause.
	 * @return string
	 */
	public static function adjacent_join( $join ) {
		if ( ! self::active() ) {
			return $join;
		}

		return $join . ' LEFT JOIN ' . Schema::tables()['relations'] . " AS tzl ON tzl.object_type = 'post' AND tzl.object_id = p.ID";
	}

	/**
	 * Previous/next: the WHERE.
	 *
	 * @param string $where The WHERE clause.
	 * @return string
	 */
	public static function adjacent_where( $where ) {
		if ( ! self::active() ) {
			return $where;
		}
		global $wpdb;
		$lang = Languages::current();

		return $where . ' AND ' . ( Languages::default_code() === $lang ? $wpdb->prepare( '(tzl.lang IS NULL OR tzl.lang = %s)', $lang ) : $wpdb->prepare( 'tzl.lang = %s', $lang ) );
	}

	/**
	 * Sitemap queries see every language.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public static function all_languages( $args ) {
		if ( is_array( $args ) ) {
			$args[ self::VAR ] = 'all';
		}

		return $args;
	}
}
