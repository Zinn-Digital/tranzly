<?php
/**
 * Links (tz-c9): on a page in another language, a link to one of this site's pages leads to that
 * page's translation in the same language.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Seo\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A translation keeps every link of the original exactly as it was (only words are translated),
 * so an Arabic page's buttons, its menu's custom links and the links inside page-builder blocks
 * still led to the English pages. Here, on a front-end page in a language other than the default,
 * every `<a href>` in the page that names one of this site's posts in the DEFAULT language form
 * (no language folder, host or query var) is pointed at that post's public translation in the
 * visitor's language — its own address, with its own translated slug (any script: /sr/…/ in
 * Cyrillic is just another slug).
 *
 * Left exactly as they are:
 * - links to other sites, to files, to the admin, feeds and REST;
 * - links that already name a language (a link the author wrote to /de/… means German);
 * - language-switcher links (they carry `hreflang`, as Tranzly's, WPML's and Polylang's do);
 * - links to a page that has no public translation in this language (they still work, in the
 *   original language, rather than leading nowhere).
 *
 * ⭐ ONE PASS PER PAGE, BOUNDED QUERIES (the speed promise, tz-r14): the whole page is read once
 * (an output buffer started at `template_redirect`, so content, classic and block menus, widgets,
 * template parts and every page-builder block are covered by one mechanism), every candidate path
 * is resolved in ONE posts query by slug and ONE relations query (`Relations::prime`), and a page
 * without a candidate link costs nothing. The answer is kept with the page — post meta of the post
 * being viewed (already loaded with it, so a repeat view costs ZERO queries) or, on archives, the
 * object cache — stamped with `tranzly_links_version`, an autoloaded counter bumped whenever a
 * post, a translation group, the permalinks or the URL settings change.
 */
final class Links {

	/** Object cache group for resolutions on pages that are not a single post. */
	public const CACHE_GROUP = 'tranzly_links';

	/** Option (autoloaded int): bumped whenever any stored resolution may be stale. */
	public const VERSION_OPTION = 'tranzly_links_version';

	/** Post meta on a translated post: its page's links, resolved. */
	public const META = '_tranzly_links';

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		// After Router::redirects (priority 1): a redirected request is never buffered.
		add_action( 'template_redirect', array( self::class, 'start' ), 99 );
		// Anything that can change where a link leads makes every stored answer stale.
		add_action( 'tranzly_relations_changed', array( self::class, 'forget' ), 20, 0 );
		add_action( 'save_post', array( self::class, 'forget_post' ), 20, 1 );
		add_action( 'deleted_post', array( self::class, 'forget_post' ), 20, 1 );
		add_action( 'update_option_permalink_structure', array( self::class, 'forget' ), 20, 0 );
		add_action( 'update_option_' . \ZinnDigital\Tranzly\Seo\Url_Settings::OPTION, array( self::class, 'forget' ), 20, 0 );
	}

	/**
	 * Every stored resolution is stale.
	 *
	 * @return void
	 */
	public static function forget(): void {
		update_option( self::VERSION_OPTION, self::version() + 1, true );
	}

	/**
	 * `save_post` / `deleted_post`: a translatable post changed (its address, status or slug).
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function forget_post( $post_id ): void {
		$type = get_post_type( (int) $post_id );
		if ( false !== $type && ! wp_is_post_revision( (int) $post_id ) && ! wp_is_post_autosave( (int) $post_id ) && in_array( $type, Content::post_types(), true ) ) {
			self::forget();
		}
	}

	/**
	 * The current resolution version.
	 *
	 * @return int
	 */
	public static function version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * `template_redirect`: buffer the page when it is in a language other than the default.
	 *
	 * @return void
	 */
	public static function start(): void {
		if ( ! self::active() ) {
			return;
		}
		$lang = Languages::current();
		if ( false === get_option( self::VERSION_OPTION ) ) {
			// Autoloaded from now on, so reading it never costs a query.
			add_option( self::VERSION_OPTION, 1, '', true );
		}
		$post = is_singular() ? (int) get_queried_object_id() : 0;
		ob_start(
			static function ( string $html ) use ( $lang, $post ): string {
				return self::localize_html( $html, $lang, $post );
			}
		);
	}

	/**
	 * Does this request get its links localised?
	 *
	 * @return bool
	 */
	public static function active(): bool {
		if ( is_admin() || is_feed() || is_robots() || is_trackback() || wp_doing_ajax() || wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( ! Router::is_front() || Languages::default_code() === Languages::current() ) {
			return false;
		}

		/**
		 * Filters whether Tranzly points this page's internal links at their translations.
		 *
		 * @param bool   $localize Whether to localise the links (default true).
		 * @param string $lang     The page's language.
		 */
		return (bool) apply_filters( 'tranzly_localize_links', true, Languages::current() );
	}

	/**
	 * Every internal link in some HTML, in a language. Markup other than the `href` values it
	 * changes is returned byte for byte.
	 *
	 * @param string $html    HTML.
	 * @param string $lang    Language code.
	 * @param int    $post_id The post the page shows (its meta keeps the answer), 0 for none.
	 * @return string
	 */
	public static function localize_html( string $html, string $lang, int $post_id = 0 ): string {
		if ( Languages::default_code() === $lang || false === stripos( $html, '<a' ) ) {
			return $html;
		}
		$found = array();
		$scan  = new \WP_HTML_Tag_Processor( $html );
		while ( $scan->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$href = self::candidate_href( $scan );
			if ( null !== $href && ! isset( $found[ $href ] ) && null !== self::parse( $href ) ) {
				$found[ $href ] = true;
			}
		}
		if ( array() === $found ) {
			return $html;
		}
		$map = self::lookup( array_keys( $found ), $lang, $post_id );
		if ( array() === $map ) {
			return $html;
		}
		$edit = new \WP_HTML_Tag_Processor( $html );
		while ( $edit->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$href = self::candidate_href( $edit );
			if ( null !== $href && isset( $map[ $href ] ) ) {
				$edit->set_attribute( 'href', esc_url( $map[ $href ] ) );
			}
		}

		return $edit->get_updated_html();
	}

	/**
	 * The answer of resolve(), taken from what the page kept when it can be: the post's meta (loaded with
	 * the post) or the object cache, both stamped with the version. Only links the page did not
	 * have last time are looked up, and the page then keeps exactly this render's links (so a link
	 * that changes on every request, a nonce, never makes it grow).
	 *
	 * @param array<int, string> $hrefs   Candidate links.
	 * @param string             $lang    Language code.
	 * @param int                $post_id The page's post, or 0.
	 * @return array<string, string> href => localised href (only the ones that change).
	 */
	private static function lookup( array $hrefs, string $lang, int $post_id ): array {
		$version = self::version();
		$kept    = array();
		$key     = '';
		if ( $post_id > 0 ) {
			$meta = get_post_meta( $post_id, self::META, true );
			if ( is_array( $meta ) && ( $meta['v'] ?? null ) === $version && ( $meta['lang'] ?? null ) === $lang && is_array( $meta['map'] ?? null ) ) {
				$kept = $meta['map'];
			}
		} else {
			$key    = $version . ':' . $lang . ':' . md5( implode( "\n", $hrefs ) );
			$cached = wp_cache_get( $key, self::CACHE_GROUP );
			$kept   = is_array( $cached ) ? $cached : array();
		}
		$missing = array_values( array_filter( $hrefs, static fn( string $h ): bool => ! array_key_exists( $h, $kept ) ) );
		if ( array() !== $missing ) {
			$new = self::resolve( $missing, $lang );
			foreach ( $missing as $href ) {
				$kept[ $href ] = $new[ $href ] ?? '';
			}
			$keep = array_intersect_key( $kept, array_flip( $hrefs ) );
			if ( $post_id > 0 ) {
				update_post_meta(
					$post_id,
					self::META,
					array(
						'v'    => $version,
						'lang' => $lang,
						'map'  => $keep,
					)
				);
			} else {
				wp_cache_set( $key, $keep, self::CACHE_GROUP, DAY_IN_SECONDS );
			}
		}
		$out = array();
		foreach ( $hrefs as $href ) {
			if ( '' !== (string) ( $kept[ $href ] ?? '' ) ) {
				$out[ $href ] = (string) $kept[ $href ];
			}
		}

		return $out;
	}

	/**
	 * The link's href when it may be localised, else null (no href, a switcher link).
	 *
	 * @param \WP_HTML_Tag_Processor $tag An A tag.
	 * @return string|null
	 */
	private static function candidate_href( \WP_HTML_Tag_Processor $tag ): ?string {
		$href = $tag->get_attribute( 'href' );
		if ( ! is_string( $href ) || '' === $href || null !== $tag->get_attribute( 'hreflang' ) ) {
			return null;
		}
		$rel = $tag->get_attribute( 'rel' );
		if ( is_string( $rel ) && in_array( 'alternate', (array) preg_split( '/\s+/', strtolower( $rel ) ), true ) ) {
			return null;
		}

		return $href;
	}

	/**
	 * Hrefs → their address in a language, for the ones that have one. Pure apart from the
	 * lookups; never more than one posts query and one relations query.
	 *
	 * @param array<int, string> $hrefs Link targets as written.
	 * @param string             $lang  Language code.
	 * @return array<string, string> href => localised href (only the ones that change).
	 */
	public static function resolve( array $hrefs, string $lang ): array {
		$paths = array();
		$out   = array();
		foreach ( $hrefs as $href ) {
			$parsed = self::parse( $href );
			if ( null === $parsed ) {
				continue;
			}
			if ( '' === $parsed['path'] ) {
				$home = Router::home_for( $lang );
				$new  = self::with_suffix( $home, $parsed );
				if ( $new !== $href ) {
					$out[ $href ] = $new;
				}
				continue;
			}
			$paths[ $parsed['path'] ][] = array( $href, $parsed );
		}
		if ( array() === $paths ) {
			return $out;
		}
		$posts = self::posts_by_path( array_keys( $paths ) );
		Relations::prime( 'post', array_values( array_filter( $posts ) ) );
		foreach ( $paths as $path => $links ) {
			$source = (int) ( $posts[ $path ] ?? 0 );
			$target = $source > 0 ? Languages::translation( $source, $lang ) : null;
			if ( null === $target || $target === $source || ! Router::is_public_post( $target ) ) {
				continue;
			}
			$url = (string) get_permalink( $target );
			if ( '' === $url ) {
				continue;
			}
			foreach ( $links as [ $href, $parsed ] ) {
				$out[ $href ] = self::with_suffix( $url, $parsed );
			}
		}

		return $out;
	}

	/**
	 * A link's path on this site in the default-language form, or null when it is not one: another
	 * host, a language already named, a file, the admin, a fragment or a scheme like mailto:.
	 * `path` is '' for the home page; otherwise the decoded path below home, without slashes at
	 * either end.
	 *
	 * @param string $href A link target.
	 * @return array{path: string, query: string, fragment: string}|null
	 */
	public static function parse( string $href ): ?array {
		$href = trim( $href );
		if ( '' === $href || '#' === $href[0] || '?' === $href[0] ) {
			return null;
		}
		$home   = wp_parse_url( Router::raw_home() );
		$home   = is_array( $home ) ? $home : array();
		$origin = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( str_starts_with( $href, '//' ) ) {
			$href = ( $home['scheme'] ?? 'https' ) . ':' . $href;
		} elseif ( '/' === $href[0] ) {
			$href = $origin . $href;
		}
		$url = wp_parse_url( $href );
		if ( ! is_array( $url ) || ! in_array( strtolower( (string) ( $url['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return null; // A scheme other than http(s), or a relative path.
		}
		$host = strtolower( (string) ( $url['host'] ?? '' ) . ( isset( $url['port'] ) ? ':' . $url['port'] : '' ) );
		$own  = strtolower( (string) ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) );
		if ( '' === $host || $host !== $own ) {
			return null;
		}
		$path = (string) ( $url['path'] ?? '/' );
		// A link that already names a language (its folder or its query var) is left as written.
		$query = array();
		wp_parse_str( (string) ( $url['query'] ?? '' ), $query );
		if ( isset( $query[ Languages::query_var() ] ) || null !== Router::detect( $host, $path ) ) {
			return null;
		}
		$home_path = rtrim( (string) ( $home['path'] ?? '' ), '/' );
		if ( '' !== $home_path ) {
			if ( $path !== $home_path && ! str_starts_with( $path, $home_path . '/' ) ) {
				return null;
			}
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = trim( rawurldecode( $path ), '/' );
		$top  = strtolower( explode( '/', $path )[0] );
		if ( in_array( $top, array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed' ), true ) || str_starts_with( $top, 'wp-' ) || str_ends_with( $top, '.php' ) ) {
			return null;
		}
		// A file (image, PDF, sitemap): its last segment has an extension other than .html.
		if ( 1 === preg_match( '/\.(?!html?$)[a-z0-9]{1,5}$/i', $path ) ) {
			return null;
		}

		return array(
			'path'     => $path,
			'query'    => isset( $url['query'] ) ? (string) $url['query'] : '',
			'fragment' => isset( $url['fragment'] ) ? (string) $url['fragment'] : '',
		);
	}

	/**
	 * An address with the link's own query string and fragment put back.
	 *
	 * @param string                                               $url    Address.
	 * @param array{path: string, query: string, fragment: string} $parsed The link.
	 * @return string
	 */
	private static function with_suffix( string $url, array $parsed ): string {
		if ( '' !== $parsed['query'] ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . $parsed['query'];
		}
		if ( '' !== $parsed['fragment'] ) {
			$url .= '#' . $parsed['fragment'];
		}

		return $url;
	}

	/**
	 * Paths → the published post in the default language whose address each one is (0 = none).
	 * One query for all of them: the posts whose slug is the last segment of a
	 * path (their parents' slugs too, so a child page's address is built from the cache), then each
	 * candidate's own address is compared with the path.
	 *
	 * @param array<int, string> $paths Decoded paths, no slashes at either end.
	 * @return array<string, int>
	 */
	public static function posts_by_path( array $paths ): array {
		$out   = array();
		$leafs = array();
		$names = array();
		foreach ( $paths as $path ) {
			$segments       = array_map( array( self::class, 'slug' ), explode( '/', $path ) );
			$leafs[ $path ] = (string) end( $segments );
			foreach ( $segments as $segment ) {
				$names[ $segment ] = true;
			}
		}
		if ( array() === $leafs ) {
			return $out;
		}
		$types = Content::post_types();
		$rows  = array();
		if ( array() !== $types ) {
			global $wpdb;
			$name_list = array_keys( $names );
			$in_names  = implode( ',', array_fill( 0, count( $name_list ), '%s' ) );
			$in_types  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$rows      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one batched lookup per page; results go into the post cache and this class's cache.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the two IN lists are runs of %s built above, one per value.
					"SELECT * FROM {$wpdb->posts} WHERE post_name IN ({$in_names}) AND post_type IN ({$in_types}) AND post_status = 'publish'",
					array_merge( $name_list, $types )
				)
			);
			$rows = is_array( $rows ) ? $rows : array();
			update_post_cache( $rows );
		}
		$by_name = array();
		foreach ( $rows as $row ) {
			$by_name[ (string) $row->post_name ][] = (int) $row->ID;
		}
		$candidates = array();
		foreach ( $leafs as $leaf ) {
			foreach ( $by_name[ $leaf ] ?? array() as $id ) {
				$candidates[] = $id;
			}
		}
		// Which language each candidate is in: a translation's slug can be the same as its
		// original's, and only the default-language one is the page the link names.
		Relations::prime( 'post', $candidates );
		foreach ( $leafs as $path => $leaf ) {
			$found = 0;
			foreach ( $by_name[ $leaf ] ?? array() as $id ) {
				$lang = Relations::language_of( 'post', $id );
				if ( null !== $lang && Languages::default_code() !== $lang ) {
					continue;
				}
				if ( self::path_of( $id ) === self::normalise( $path ) ) {
					$found = $id;
					break;
				}
			}
			$out[ $path ] = $found;
		}

		return $out;
	}

	/**
	 * A post's own address, as a path in the default-language form (normalised like a link).
	 *
	 * @param int $id Post.
	 * @return string
	 */
	private static function path_of( int $id ): string {
		$url  = Router::neutral_url( (string) get_permalink( $id ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( Router::raw_home(), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return self::normalise( trim( rawurldecode( $path ), '/' ) );
	}

	/**
	 * A decoded path compared case-insensitively, segment by segment as slugs.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalise( string $path ): string {
		return implode( '/', array_map( array( self::class, 'slug' ), explode( '/', $path ) ) );
	}

	/**
	 * A path segment as WordPress stores a slug (as get_page_by_path() reads one).
	 *
	 * @param string $segment Decoded segment.
	 * @return string
	 */
	private static function slug( string $segment ): string {
		return sanitize_title_for_query( str_replace( '%20', ' ', rawurlencode( $segment ) ) );
	}
}
