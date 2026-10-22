<?php
/**
 * The posts list in wp-admin: a view per language, and "not translated into …".
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Schema;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Seo\Query_Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts → All Posts (and every translated post type's list) gains:
 *
 * - **a view per language** above the table, beside All / Published / Drafts: "English (12) |
 *   Deutsch (4)", each counting that type's posts in the language (one GROUP BY query per screen);
 * - **"Not translated into …"** beside the date and category filters: the posts that have NO
 *   version in that language yet — the to-do list for a translator. A post written in that
 *   language is not on it.
 *
 * Both are ordinary `GET` parameters on `edit.php`, so a filtered list can be bookmarked and the
 * bulk actions work on it. Language comes from the relations table, where a post with no row is
 * in the default language (the same rule the front end uses, {@see Query_Filter::sql()}).
 */
final class List_Table {

	/** The language view's parameter. */
	public const LANG = 'tranzly_lang';

	/** The "not translated into" filter's parameter. */
	public const MISSING = 'tranzly_missing';

	/**
	 * Hook the list screen.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'load-edit.php', array( self::class, 'load' ) );
	}

	/**
	 * `load-edit.php`: only on a translated post type, on a site with two or more languages.
	 *
	 * @return void
	 */
	public static function load(): void {
		global $typenow;
		$type = is_string( $typenow ) && '' !== $typenow ? $typenow : 'post';
		if ( ! Content::is_translatable_type( $type ) || count( Languages::all() ) < 2 ) {
			return;
		}
		add_filter( 'views_edit-' . $type, array( self::class, 'views' ) );
		add_action( 'restrict_manage_posts', array( self::class, 'dropdown' ), 20, 1 );
		add_action( 'pre_get_posts', array( self::class, 'query' ) );
		add_filter( 'posts_clauses', array( self::class, 'clauses' ), 20, 2 );
	}

	/**
	 * A language code from the request, or null (only one of the site's languages is accepted).
	 *
	 * @param string $param The parameter.
	 * @return string|null
	 */
	public static function requested( string $param ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter, like WordPress's own post_status and m.
		$raw = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';

		return '' === $raw ? null : Languages::resolve( $raw );
	}

	/**
	 * `pre_get_posts`: the language view keeps one language, through the front end's own filter.
	 *
	 * @param \WP_Query $query The query.
	 * @return void
	 */
	public static function query( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return;
		}
		$lang = self::requested( self::LANG );
		if ( null !== $lang ) {
			$query->set( Query_Filter::VAR, $lang );
		}
		$missing = self::requested( self::MISSING );
		if ( null !== $missing ) {
			$query->set( self::MISSING, $missing );
		}
	}

	/**
	 * `posts_clauses`: "not translated into" as one LEFT JOIN and a NOT EXISTS on the group.
	 *
	 * @param array<string, string> $clauses The clauses.
	 * @param \WP_Query             $query   The query.
	 * @return array<string, string>
	 */
	public static function clauses( $clauses, $query ) {
		if ( ! is_array( $clauses ) || ! $query instanceof \WP_Query ) {
			return $clauses;
		}
		$missing = (string) $query->get( self::MISSING );
		if ( '' === $missing ) {
			return $clauses;
		}
		global $wpdb;
		$sql              = self::missing_sql( $missing, Languages::default_code(), Schema::tables()['relations'], $wpdb->posts );
		$clauses['join']  = ( $clauses['join'] ?? '' ) . $sql['join'];
		$clauses['where'] = ( $clauses['where'] ?? '' ) . $wpdb->prepare( $sql['where'], ...$sql['args'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- built by missing_sql() from fixed text; the language is a placeholder.

		return $clauses;
	}

	/**
	 * The JOIN and WHERE (with its placeholders) for "no version in `$lang` yet" (the pure half).
	 *
	 * A post is listed when it is not itself in `$lang` and no post of its translation group is.
	 * A post with no relations row is in the default language and has no translations.
	 *
	 * @param string $lang      The language the posts are missing.
	 * @param string $site_default The site's default language.
	 * @param string $relations The relations table.
	 * @param string $posts     The posts table.
	 * @return array{join: string, where: string, args: array<int, string>}
	 */
	public static function missing_sql( string $lang, string $site_default, string $relations, string $posts ): array {
		$self = $lang === $site_default
			? '(tzm.lang IS NOT NULL AND tzm.lang <> %s)'
			: '(tzm.lang IS NULL OR tzm.lang <> %s)';

		return array(
			'join'  => " LEFT JOIN {$relations} AS tzm ON tzm.object_type = 'post' AND tzm.object_id = {$posts}.ID",
			// A version in the bin or an unsaved auto-draft is not a translation.
			'where' => " AND {$self} AND NOT EXISTS (SELECT 1 FROM {$relations} AS tzm2 INNER JOIN {$posts} AS tzp2 ON tzp2.ID = tzm2.object_id WHERE tzm2.object_type = 'post' AND tzm2.group_id = tzm.group_id AND tzm2.lang = %s AND tzp2.post_status NOT IN ('trash', 'auto-draft'))",
			'args'  => array( $lang, $lang ),
		);
	}

	/**
	 * `views_edit-{type}`: a view per language, with its count.
	 *
	 * @param array<string, string> $views WordPress's views.
	 * @return array<string, string>
	 */
	public static function views( $views ) {
		if ( ! is_array( $views ) ) {
			return $views;
		}
		global $typenow;
		$type = is_string( $typenow ) && '' !== $typenow ? $typenow : 'post';

		return $views + self::view_links( Languages::all(), self::counts( $type ), self::requested( self::LANG ), admin_url( 'edit.php' ), $type );
	}

	/**
	 * Posts of a type per language (trash and auto-drafts left out, as WordPress's "All" does).
	 *
	 * @param string $type A post type.
	 * @return array<string, int> language => posts.
	 */
	public static function counts( string $type ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one grouped count per list screen.
			$wpdb->prepare(
				"SELECT COALESCE(r.lang, %s) AS lang, COUNT(*) AS n FROM {$wpdb->posts} p LEFT JOIN %i r ON r.object_type = 'post' AND r.object_id = p.ID WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft') GROUP BY COALESCE(r.lang, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the core posts table.
				Languages::default_code(),
				Schema::tables()['relations'],
				$type,
				Languages::default_code()
			)
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->lang ] = (int) $row->n;
		}

		return $out;
	}

	/**
	 * The view links (the pure half).
	 *
	 * @param array<int, array{code: string, name: string}> $languages The site's languages.
	 * @param array<string, int>                            $counts    language => posts.
	 * @param string|null                                   $current   The language shown, or null.
	 * @param string                                        $base      `…/wp-admin/edit.php`.
	 * @param string                                        $type      The post type.
	 * @return array<string, string> view key => link.
	 */
	public static function view_links( array $languages, array $counts, ?string $current, string $base, string $type ): array {
		$out = array();
		foreach ( $languages as $language ) {
			$code  = (string) $language['code'];
			$url   = add_query_arg(
				array(
					'post_type' => $type,
					self::LANG  => $code,
				),
				$base
			);
			$is_on = $current === $code;
			$out[ 'tranzly-lang-' . sanitize_key( $code ) ] = '<a href="' . esc_url( $url ) . '"' . ( $is_on ? ' class="current" aria-current="page"' : '' ) . ' lang="' . esc_attr( str_replace( '_', '-', $code ) ) . '">'
				. esc_html( (string) $language['name'] ) . ' <span class="count">(' . esc_html( number_format_i18n( (int) ( $counts[ $code ] ?? 0 ) ) ) . ')</span></a>';
		}

		return $out;
	}

	/**
	 * `restrict_manage_posts`: the "Not translated into …" filter.
	 *
	 * @param string $type The list's post type.
	 * @return void
	 */
	public static function dropdown( $type ): void {
		if ( ! is_string( $type ) || ! Content::is_translatable_type( $type ) ) {
			return;
		}
		echo self::dropdown_html( Languages::all(), self::requested( self::MISSING ), self::requested( self::LANG ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- dropdown_html() escapes every value.
	}

	/**
	 * The filter's markup (the pure half).
	 *
	 * @param array<int, array{code: string, name: string}> $languages The site's languages.
	 * @param string|null                                   $current   The language chosen, or null.
	 * @param string|null                                   $view      The language view shown, kept when filtering.
	 * @return string
	 */
	public static function dropdown_html( array $languages, ?string $current, ?string $view = null ): string {
		$out = ( null === $view ? '' : '<input type="hidden" name="' . esc_attr( self::LANG ) . '" value="' . esc_attr( $view ) . '">' )
			. '<label class="screen-reader-text" for="tranzly-missing">' . esc_html__( 'Filter by missing translation', 'tranzly' ) . '</label>'
			. '<select name="' . esc_attr( self::MISSING ) . '" id="tranzly-missing">'
			. '<option value="">' . esc_html__( 'All translation states', 'tranzly' ) . '</option>';
		foreach ( $languages as $language ) {
			$code = (string) $language['code'];
			/* translators: %s: a language's name, e.g. Deutsch. */
			$out .= '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( sprintf( __( 'Not translated into %s', 'tranzly' ), (string) $language['name'] ) ) . '</option>';
		}

		return $out . '</select>';
	}
}
