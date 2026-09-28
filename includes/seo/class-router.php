<?php
/**
 * Language in the URL: reading it from a request, and writing it into every link.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ HOW A /de/ URL WORKS (tz-s1). The language folder is read from the request, then REMOVED from
 * `REQUEST_URI` for the length of `WP::parse_request()` only, so WordPress's own rewrite rules parse
 * `/de/ueber-uns/` as `/ueber-uns/` — no extra rewrite rules, nothing to flush, and every
 * permalink structure works. It is put back straight after parsing, so `redirect_canonical()`,
 * pagination and every plugin that reads the request see the real address.
 *
 * Links go the other way: a post, page or term link carries the language OF THAT OBJECT (a German
 * page is always /de/…, whatever language the visitor is reading), and on the front end
 * `home_url()` carries the visitor's current language, so theme links, the search form and date
 * archives stay in it.
 *
 * ⛔ Nothing here ever redirects a visitor because of their browser language (tz-s8 is a banner,
 * never a redirect): search engines crawl without one and would be sent in circles.
 */
final class Router {

	/**
	 * The language the URL names, memoised: null = not read yet, '' = none.
	 *
	 * @var string|null
	 */
	private static ?string $url_lang = null;

	/**
	 * `REQUEST_URI` as it arrived, while parsing runs on the stripped copy.
	 *
	 * @var string|null
	 */
	private static ?string $original_uri = null;

	/**
	 * The raw `page_on_front`, read before this class filters it.
	 *
	 * @var int|null
	 */
	private static ?int $raw_front = null;

	/** Option: page => language => public translation, for the front page and the posts page. */
	public const FRONT_OPTION = 'tranzly_front_pages';

	/**
	 * Option: post ID => language ('' = the default language) for every member of the groups of
	 * the site's structure pages — the ones named by STRUCTURE_OPTIONS.
	 */
	public const PAGES_OPTION = 'tranzly_page_languages';

	/**
	 * Options naming pages a site links to on every request, often before the page's own query
	 * runs (WooCommerce builds its cart, checkout and account links at `wp_loaded`). Their
	 * languages are kept in PAGES_OPTION so those links cost no query (the speed promise, tz-r14).
	 */
	public const STRUCTURE_OPTIONS = array(
		'page_on_front',
		'page_for_posts',
		'wp_page_for_privacy_policy',
		'woocommerce_shop_page_id',
		'woocommerce_cart_page_id',
		'woocommerce_checkout_page_id',
		'woocommerce_myaccount_page_id',
		'woocommerce_terms_page_id',
	);

	/**
	 * Languages read along with their objects by a query: type => id => language (null = default).
	 *
	 * @var array<string, array<int, string|null>>
	 */
	private static array $known = array(
		'post' => array(),
		'term' => array(),
	);

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'do_parse_request', array( self::class, 'strip_request' ), 1, 1 );
		add_action( 'parse_request', array( self::class, 'restore_request' ), PHP_INT_MAX );

		add_filter( 'post_link', array( self::class, 'filter_post_link' ), 20, 2 );
		add_filter( 'page_link', array( self::class, 'filter_page_link' ), 20, 2 );
		add_filter( 'post_type_link', array( self::class, 'filter_post_link' ), 20, 2 );
		add_filter( 'term_link', array( self::class, 'filter_term_link' ), 20, 2 );
		add_filter( 'home_url', array( self::class, 'filter_home_url' ), 20, 2 );

		add_filter( 'option_page_on_front', array( self::class, 'filter_front_option' ), 20, 1 );
		add_filter( 'option_page_for_posts', array( self::class, 'filter_front_option' ), 20, 1 );
		add_filter( 'locale', array( self::class, 'filter_locale' ), 20, 1 );
		add_action( 'init', array( self::class, 'set_text_direction' ), 1 );

		add_action( 'template_redirect', array( self::class, 'redirects' ), 1 );

		// The two maps follow the options and every change to a post (or a link) in those groups.
		$hooks = array( 'update_option_show_on_front', 'update_option_' . Url_Settings::OPTION );
		foreach ( self::STRUCTURE_OPTIONS as $option ) {
			$hooks[] = 'update_option_' . $option;
			$hooks[] = 'add_option_' . $option;
		}
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( self::class, 'rebuild_front_pages' ), 20, 0 );
		}
		add_action( 'save_post', array( self::class, 'maybe_rebuild_front_pages' ), 20, 1 );
		add_action( 'deleted_post', array( self::class, 'maybe_rebuild_front_pages' ), 20, 1 );
		add_action( 'tranzly_relations_changed', array( self::class, 'relations_changed' ), 20, 2 );
	}

	/**
	 * Is the request one of the site's front-end pages (not wp-admin, REST, cron or WP-CLI)?
	 *
	 * @return bool
	 */
	public static function is_front(): bool {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		$path = self::request_path();

		return ! str_starts_with( ltrim( $path, '/' ), rest_get_url_prefix() . '/' ) && 'wp-login.php' !== basename( $path );
	}

	/**
	 * The URL mode in effect.
	 *
	 * @return string
	 */
	public static function mode(): string {
		return Url_Settings::get()['mode'];
	}

	/**
	 * The language the request's URL names (folder, subdomain or domain), or null: the default
	 * language, `query` mode, or a request that is not for a page.
	 *
	 * @return string|null
	 */
	public static function url_language(): ?string {
		if ( null === self::$url_lang ) {
			self::$url_lang = self::detect( self::request_host(), self::request_path() ) ?? '';
		}

		return '' === self::$url_lang ? null : self::$url_lang;
	}

	/**
	 * Forget what was read (tests, and a settings change inside one request).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$url_lang  = null;
		self::$raw_front = null;
	}

	/**
	 * Which language a host + path names (the pure half of url_language()).
	 *
	 * @param string $host The request host (with port).
	 * @param string $path The request path, query string removed.
	 * @return string|null
	 */
	public static function detect( string $host, string $path ): ?string {
		$mode = Url_Settings::get()['mode'];
		if ( 'directory' === $mode ) {
			return self::folder_language( $path );
		}
		// Subdomains and domains are the Pro layer's (tz-s2); without it the mode cannot be set.
		if ( self::hosts() ) {
			return Pro\Hosts::detect( strtolower( $host ), $path, self::home_parts()['host'] );
		}

		return null;
	}

	/**
	 * Is the Pro host layer (subdomains / separate domains) loaded?
	 *
	 * @return bool
	 */
	public static function hosts(): bool {
		return class_exists( '\ZinnDigital\Tranzly\Seo\Pro\Hosts', false );
	}

	/**
	 * The non-default language whose folder a path starts with, or null.
	 *
	 * @param string $path A request path.
	 * @return string|null
	 */
	public static function folder_language( string $path ): ?string {
		$rest = self::after_home_path( $path, self::home_parts()['path'] );
		if ( null === $rest ) {
			return null;
		}
		$first = strtolower( explode( '/', ltrim( $rest, '/' ) )[0] );
		foreach ( Url_Settings::get()['segments'] as $code => $segment ) {
			if ( $segment === $first && Languages::default_code() !== $code ) {
				return $code;
			}
		}

		return null;
	}

	/**
	 * Does this language live in a folder (/de/)? Always in `directory` mode; in `domain` mode when
	 * it has no domain of its own (Pro).
	 *
	 * @param string $lang A language code.
	 * @return bool
	 */
	public static function uses_folder( string $lang ): bool {
		$mode = Url_Settings::get()['mode'];

		return 'directory' === $mode || ( 'domain' === $mode && self::hosts() && null === Pro\Hosts::host_for( $lang, self::home_parts()['host'] ) );
	}

	/**
	 * `do_parse_request`: hide the language folder and the translated bases from WordPress's
	 * rewrite rules for the length of the parse.
	 *
	 * @param bool $parse Whether WordPress parses the request.
	 * @return bool
	 */
	public static function strip_request( $parse ) {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! self::is_front() ) {
			return $parse;
		}
		$lang = self::url_language();
		if ( null === $lang ) {
			return $parse;
		}
		$uri                = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- restored verbatim after parsing; WordPress sanitises what it reads.
		self::$original_uri = $uri;
		$stripped           = self::strip_uri( $uri, $lang );
		if ( $stripped !== $uri ) {
			$_SERVER['REQUEST_URI'] = $stripped;
		}

		return $parse;
	}

	/**
	 * `parse_request`: put the real address back.
	 *
	 * @return void
	 */
	public static function restore_request(): void {
		if ( null !== self::$original_uri ) {
			$_SERVER['REQUEST_URI'] = self::$original_uri;
			self::$original_uri     = null;
		}
	}

	/**
	 * A request URI without its language folder, with translated bases mapped back to WordPress's
	 * own (the pure half of strip_request()).
	 *
	 * @param string $uri  The request URI.
	 * @param string $lang The language it names.
	 * @return string
	 */
	public static function strip_uri( string $uri, string $lang ): string {
		$home  = self::home_parts();
		$parts = explode( '?', $uri, 2 );
		$path  = $parts[0];
		$query = isset( $parts[1] ) ? '?' . $parts[1] : '';
		$rest  = self::after_home_path( $path, $home['path'] );
		if ( null === $rest ) {
			return $uri;
		}
		if ( self::uses_folder( $lang ) ) {
			$segment = Url_Settings::get()['segments'][ $lang ] ?? '';
			$rest    = (string) preg_replace( '#^/' . preg_quote( $segment, '#' ) . '(?=/|$)#i', '', $rest );
		}
		$rest = Bases::to_original( '' === $rest ? '/' : $rest, $lang );

		return rtrim( $home['path'], '/' ) . ( '' === $rest ? '/' : $rest ) . $query;
	}

	/**
	 * A URL of this site, shown in a language. The language already in it (if any) is replaced.
	 *
	 * @param string $url  A URL of this site.
	 * @param string $lang A language code.
	 * @return string
	 */
	public static function language_url( string $url, string $lang ): string {
		$code = Languages::resolve( $lang ) ?? Languages::default_code();
		$url  = self::neutral_url( $url );
		$mode = self::mode();

		if ( 'query' === $mode ) {
			return Languages::default_code() === $code ? $url : add_query_arg( Languages::query_var(), strtolower( str_replace( '_', '-', $code ) ), $url );
		}
		if ( Languages::default_code() === $code ) {
			return $url;
		}

		$settings = Url_Settings::get();
		$home     = self::home_parts();
		$parsed   = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || ! isset( $parsed['host'] ) || strtolower( $parsed['host'] . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' ) ) !== $home['host'] ) {
			return $url; // Not a URL of this site.
		}
		$path = (string) ( $parsed['path'] ?? '/' );
		$rest = self::after_home_path( $path, $home['path'] ) ?? $path;
		$rest = Bases::to_translated( '' === $rest ? '/' : $rest, $code );
		$host = $home['host'];
		if ( self::uses_folder( $code ) ) {
			$rest = '/' . $settings['segments'][ $code ] . ( '/' === $rest ? '/' : $rest );
		} elseif ( self::hosts() ) {
			$host = Pro\Hosts::host_for( $code, $home['host'] ) ?? $host;
		}

		return ( $parsed['scheme'] ?? 'https' ) . '://' . $host . rtrim( $home['path'], '/' ) . $rest
			. ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' )
			. ( isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '' );
	}

	/**
	 * A URL of this site with any language taken out: the default-language form.
	 *
	 * @param string $url A URL.
	 * @return string
	 */
	public static function neutral_url( string $url ): string {
		$url      = remove_query_arg( Languages::query_var(), $url );
		$settings = Url_Settings::get();
		$home     = self::home_parts();
		$parsed   = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || ! isset( $parsed['host'] ) ) {
			return $url;
		}
		$host = strtolower( $parsed['host'] . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' ) );
		$lang = null;
		if ( $host !== $home['host'] ) {
			$lang = self::hosts() ? Pro\Hosts::detect( $host, '', $home['host'] ) : null;
			if ( null === $lang ) {
				return $url; // Another site.
			}
		}
		$path = (string) ( $parsed['path'] ?? '/' );
		$rest = self::after_home_path( $path, $home['path'] );
		if ( null === $rest ) {
			return $url;
		}
		if ( null === $lang && ( 'directory' === $settings['mode'] || 'domain' === $settings['mode'] ) ) {
			$first = strtolower( explode( '/', ltrim( $rest, '/' ) )[0] );
			foreach ( $settings['segments'] as $code => $segment ) {
				if ( $segment === $first && Languages::default_code() !== $code ) {
					$lang = $code;
					$rest = (string) substr( $rest, strlen( $segment ) + 1 );
					break;
				}
			}
		}
		if ( null !== $lang ) {
			$rest = Bases::to_original( '' === $rest ? '/' : $rest, $lang );
		}

		return ( $parsed['scheme'] ?? 'https' ) . '://' . $home['host'] . rtrim( $home['path'], '/' ) . ( '' === $rest ? '/' : $rest )
			. ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' )
			. ( isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '' );
	}

	/**
	 * The home page of a language.
	 *
	 * @param string $lang A language code.
	 * @return string
	 */
	public static function home_for( string $lang ): string {
		return self::language_url( self::raw_home() . '/', $lang );
	}

	/**
	 * Where a visitor lands when they choose a language on the page they are reading: the
	 * translation of this post or term, or that language's home page when there is none.
	 *
	 * @param string $lang A language code.
	 * @return string
	 */
	public static function switch_url( string $lang ): string {
		$code = Languages::resolve( $lang ) ?? Languages::default_code();
		if ( ! did_action( 'wp' ) ) {
			return self::home_for( $code );
		}
		if ( is_front_page() || ( is_home() && 'page' !== get_option( 'show_on_front' ) ) ) {
			return self::home_for( $code );
		}
		if ( is_singular() || is_home() ) {
			$target = Languages::translation( (int) get_queried_object_id(), $code );
			return null !== $target && self::is_public_post( $target ) ? (string) get_permalink( $target ) : self::home_for( $code );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term   = get_queried_object();
			$target = $term instanceof \WP_Term ? self::term_translation( $term->term_id, $code ) : null;
			if ( null === $target ) {
				return self::home_for( $code );
			}
			$link = get_term_link( $target );
			return is_wp_error( $link ) ? self::home_for( $code ) : $link;
		}
		if ( is_404() ) {
			return self::home_for( $code );
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- becomes a URL and is escaped where printed.

		return self::language_url( self::request_origin() . $uri, $code );
	}

	/**
	 * Is this post one a visitor may open (published, viewable, no password)?
	 *
	 * @param int $post_id A post ID.
	 * @return bool
	 */
	public static function is_public_post( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post && 'publish' === $post->post_status && '' === $post->post_password && is_post_type_viewable( $post->post_type );
	}

	/**
	 * A term's translation, or null.
	 *
	 * @param int    $term_id A term.
	 * @param string $lang    A language code.
	 * @return int|null
	 */
	public static function term_translation( int $term_id, string $lang ): ?int {
		$group = Relations::translations( 'term', $term_id );
		$own   = Languages::resolve( Relations::language_of( 'term', $term_id ) ) ?? Languages::default_code();
		if ( $own === $lang ) {
			return $term_id;
		}

		return $group[ $lang ] ?? null;
	}

	/**
	 * `post_link` / `post_type_link`: a post's address is in the post's own language.
	 *
	 * @param string       $url  The permalink.
	 * @param \WP_Post|int $post The post.
	 * @return string
	 */
	public static function filter_post_link( $url, $post ) {
		// SEO plugins pass partial post objects (a stdClass with ID) straight from their own queries.
		$id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : ( is_numeric( $post ) ? (int) $post : 0 );
		if ( $id <= 0 || ! is_string( $url ) ) {
			return $url;
		}
		// Front end only: a visitor's page never changes a translation group mid-request.
		if ( is_object( $post ) && property_exists( $post, Query_Filter::PROP ) && ! array_key_exists( $id, self::$known['post'] ) && self::is_front() ) {
			self::know( 'post', $id, $post->{Query_Filter::PROP} );
		}

		return self::language_url( $url, self::post_language( $id ) );
	}

	/**
	 * `page_link`: as post_link, and every translation of the front page is its language's home.
	 *
	 * @param string $url     The permalink.
	 * @param int    $post_id The page.
	 * @return string
	 */
	public static function filter_page_link( $url, $post_id ) {
		$post_id = (int) $post_id;
		$front   = self::raw_front_page();
		$map     = $front > 0 ? get_option( self::FRONT_OPTION, array() ) : array();
		$members = is_array( $map ) ? array_map( 'intval', (array) ( $map[ $front ] ?? array() ) ) : array();
		if ( $front > 0 && 'page' === get_option( 'show_on_front' ) && ( $post_id === $front || in_array( $post_id, $members, true ) ) ) {
			return self::home_for( self::post_language( $post_id ) );
		}

		return self::filter_post_link( $url, $post_id );
	}

	/**
	 * `term_link`: a term's address is in the term's own language.
	 *
	 * @param string   $url      The link.
	 * @param \WP_Term $term     The term.
	 * @return string
	 */
	public static function filter_term_link( $url, $term ) {
		if ( ! $term instanceof \WP_Term || ! is_string( $url ) ) {
			return $url;
		}
		return self::language_url( $url, self::term_language( $term ) );
	}

	/**
	 * `home_url` on the front end: keep the visitor in their language. Addresses that are never
	 * language pages (REST, login, admin, cron, sitemaps) are left alone.
	 *
	 * @param string $url  The URL.
	 * @param string $path The path asked for.
	 * @return string
	 */
	public static function filter_home_url( $url, $path = '' ) {
		// ⛔ Never while WordPress parses the request: WP::parse_request() strips home_url()'s PATH
		// from the address, so a /de home made /de/de-vorteile/ parse as "-vorteile" (measured: a
		// German slug starting "de" was a 404).
		if ( ! is_string( $url ) || null !== self::$original_uri || ! self::is_front() || 'query' === self::mode() ) {
			return $url;
		}
		$current = Languages::current();
		if ( Languages::default_code() === $current ) {
			return $url;
		}
		$path = ltrim( (string) $path, '/' );
		if ( 1 === preg_match( '#^(?:' . preg_quote( rest_get_url_prefix(), '#' ) . '|wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-content|wp-includes)(?:/|$)|\.xml$|\.xsl$#', $path ) ) {
			return $url;
		}

		return self::language_url( $url, $current );
	}

	/**
	 * `option_page_on_front` / `option_page_for_posts` on the front end: the translation of the
	 * front page (or posts page) in the visitor's language, when it is published.
	 *
	 * @param mixed $value The page ID.
	 * @return mixed
	 */
	public static function filter_front_option( $value ) {
		$id = (int) $value;
		if ( $id <= 0 || ! self::is_front() ) {
			return $value;
		}
		$current = Languages::current();
		if ( Languages::default_code() === $current ) {
			return $value;
		}
		// ⭐ Read from an autoloaded map, not the relations table: WordPress asks for these two
		// options while it is still PARSING the request, before any query could be batched, on every
		// page in every non-default language (the speed promise, tz-r14).
		$map    = get_option( self::FRONT_OPTION, array() );
		$target = is_array( $map ) ? ( $map[ $id ][ $current ] ?? null ) : null;

		return null !== $target ? (string) $target : $value;
	}

	/**
	 * Rebuild the two autoloaded maps, when anything that could change them changes: the public
	 * translations of the front page and the posts page (`tranzly_front_pages`), and the language
	 * of every member of the structure pages' groups (`tranzly_page_languages`).
	 *
	 * @return void
	 */
	public static function rebuild_front_pages(): void {
		$pages = self::structure_pages();
		$map   = array();
		$langs = array();
		foreach ( $pages as $option => $page ) {
			Relations::reset_memo();
			$members        = Relations::translations( 'post', $page );
			$own            = array_search( $page, $members, true );
			$langs[ $page ] = false === $own ? '' : (string) $own;
			foreach ( $members as $lang => $member ) {
				$langs[ (int) $member ] = (string) $lang;
				if ( in_array( $option, array( 'page_on_front', 'page_for_posts' ), true ) && $member !== $page && self::is_public_post( (int) $member ) ) {
					$map[ $page ][ $lang ] = (int) $member;
				}
			}
		}
		self::$raw_front = null;
		if ( get_option( self::FRONT_OPTION, null ) !== $map ) {
			update_option( self::FRONT_OPTION, $map, true );
		}
		if ( get_option( self::PAGES_OPTION, null ) !== $langs ) {
			update_option( self::PAGES_OPTION, $langs, true );
		}
	}

	/**
	 * Rebuild the maps when a post in one of those groups changes.
	 *
	 * @param int $post_id A post.
	 * @return void
	 */
	public static function maybe_rebuild_front_pages( $post_id ): void {
		self::rebuild_if_structure( array( (int) $post_id ) );
	}

	/**
	 * `tranzly_relations_changed`: rebuild the maps when a link in one of those groups changed —
	 * a translation linked or unlinked by hand, the command line or the legacy import.
	 *
	 * @param string     $type `post` or `term`.
	 * @param array<int> $ids  The objects whose links changed.
	 * @return void
	 */
	public static function relations_changed( $type, $ids ): void {
		if ( 'post' === $type ) {
			self::rebuild_if_structure( array_map( 'intval', (array) $ids ) );
		}
	}

	/**
	 * Rebuild the maps when any of the posts is a structure page or in one of their groups.
	 *
	 * @param array<int> $ids Posts.
	 * @return void
	 */
	private static function rebuild_if_structure( array $ids ): void {
		$langs    = get_option( self::PAGES_OPTION, array() );
		$involved = array_merge( is_array( $langs ) ? array_map( 'intval', array_keys( $langs ) ) : array(), array_values( self::structure_pages() ) );
		if ( array() !== array_intersect( $ids, $involved ) ) {
			self::rebuild_front_pages();
		}
	}

	/**
	 * The structure pages that are set: option name => page ID, read unfiltered.
	 *
	 * @return array<string, int>
	 */
	private static function structure_pages(): array {
		remove_filter( 'option_page_on_front', array( self::class, 'filter_front_option' ), 20 );
		remove_filter( 'option_page_for_posts', array( self::class, 'filter_front_option' ), 20 );
		$pages = array();
		foreach ( self::STRUCTURE_OPTIONS as $option ) {
			$page = (int) get_option( $option );
			if ( $page > 0 ) {
				$pages[ $option ] = $page;
			}
		}
		add_filter( 'option_page_on_front', array( self::class, 'filter_front_option' ), 20, 1 );
		add_filter( 'option_page_for_posts', array( self::class, 'filter_front_option' ), 20, 1 );

		return $pages;
	}

	/**
	 * `locale` on the front end: WordPress, the theme and every plugin speak the visitor's
	 * language (html lang, dates, "Read more"), when a language pack for it is installed.
	 *
	 * @param string $locale The site locale.
	 * @return string
	 */
	public static function filter_locale( $locale ) {
		static $resolving = false;
		// ⛔ The language list falls back to get_locale() when none is saved, which lands back here.
		if ( $resolving || ! self::is_front() ) {
			return $locale;
		}
		$resolving = true;
		$current   = Languages::current();
		$resolving = false;

		return $current;
	}

	/**
	 * `init`: right-to-left languages are RTL even when WordPress has no language pack for them,
	 * so themes load their RTL stylesheet and `is_rtl()` is true.
	 *
	 * @return void
	 */
	public static function set_text_direction(): void {
		global $wp_locale;
		if ( ! self::is_front() || ! is_object( $wp_locale ) ) {
			return;
		}
		$wp_locale->text_direction = \ZinnDigital\Tranzly\Core\Locales::is_rtl( Languages::current() ) ? 'rtl' : 'ltr';
	}

	/**
	 * `template_redirect` (before WordPress's own canonical redirect):
	 *
	 * - an address without its language (an old link, a link from 3.0's `?lang=`) goes to the page's
	 *   real address with a 301, so moving to /de/ URLs loses no search ranking;
	 * - a 404 whose last path word is a page's slug in another language goes to that page (or its
	 *   translation in the language the address asked for).
	 *
	 * @return void
	 */
	public static function redirects(): void {
		if ( ! self::is_front() || is_preview() ) {
			return;
		}
		$target = null;
		$var    = Languages::query_var();
		if ( 'query' !== self::mode() && isset( $_GET[ $var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public language selector from an old link.
			$asked = Languages::resolve( sanitize_text_field( wp_unslash( (string) $_GET[ $var ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
			if ( null !== $asked ) {
				$target = self::switch_url( $asked );
			}
		} elseif ( is_404() ) {
			$target = self::find_moved();
		} elseif ( is_home() && ! is_front_page() && get_queried_object_id() > 0 && self::post_language( (int) get_queried_object_id() ) !== Languages::current() ) {
			// /fr/blog/ when the blog page has no French version: the blog's real address.
			$target = (string) get_permalink( (int) get_queried_object_id() );
		} elseif ( null !== self::url_language() && array() !== Bases::pairs( self::url_language() ) ) {
			// /de/category/… when German has its own word: one address per page.
			$here   = self::request_origin() . self::request_uri();
			$target = self::language_url( self::neutral_url( $here ), self::url_language() );
		}
		if ( null !== $target && '' !== $target && self::strip_scheme( $target ) !== self::strip_scheme( self::request_origin() . self::request_uri() ) ) {
			wp_safe_redirect( $target, 301, 'WordPress' );
			exit;
		}
	}

	/**
	 * The language a post is written in.
	 *
	 * @param int $post_id A post ID.
	 * @return string
	 */
	public static function post_language( int $post_id ): string {
		if ( array_key_exists( $post_id, self::$known['post'] ) ) {
			return self::$known['post'][ $post_id ] ?? Languages::default_code();
		}
		// ⭐ A structure page (front, blog, shop, cart…) from the autoloaded map: plugins link to
		// those before the page's query runs, so a lookup here would be one query per link.
		$pages = get_option( self::PAGES_OPTION, array() );
		if ( is_array( $pages ) && array_key_exists( $post_id, $pages ) ) {
			return Languages::resolve( (string) $pages[ $post_id ] ) ?? Languages::default_code();
		}

		return Languages::resolve( Relations::language_of( 'post', $post_id ) ) ?? Languages::default_code();
	}

	/**
	 * The language a term is written in.
	 *
	 * @param \WP_Term $term A term.
	 * @return string
	 */
	public static function term_language( \WP_Term $term ): string {
		if ( array_key_exists( $term->term_id, self::$known['term'] ) ) {
			return self::$known['term'][ $term->term_id ] ?? Languages::default_code();
		}
		if ( property_exists( $term, Query_Filter::PROP ) ) {
			return Languages::resolve( (string) $term->{Query_Filter::PROP} ) ?? Languages::default_code();
		}

		return Languages::resolve( Relations::language_of( 'term', $term->term_id ) ) ?? Languages::default_code();
	}

	/**
	 * Remember an object's language, read along with it by a query (null = the default language).
	 *
	 * @param string      $type `post` or `term`.
	 * @param int         $id   The object.
	 * @param string|null $lang Its language, or null.
	 * @return void
	 */
	public static function know( string $type, int $id, ?string $lang ): void {
		self::$known[ $type ][ $id ] = null === $lang ? null : Languages::resolve( $lang );
	}

	/**
	 * The site's home URL straight from the option: never filtered by this class.
	 *
	 * @return string
	 */
	public static function raw_home(): string {
		return untrailingslashit( (string) get_option( 'home' ) );
	}

	/**
	 * The raw front page ID.
	 *
	 * @return int
	 */
	public static function raw_front_page(): int {
		if ( null === self::$raw_front ) {
			remove_filter( 'option_page_on_front', array( self::class, 'filter_front_option' ), 20 );
			self::$raw_front = (int) get_option( 'page_on_front' );
			add_filter( 'option_page_on_front', array( self::class, 'filter_front_option' ), 20, 1 );
		}

		return self::$raw_front;
	}

	/**
	 * A 404 whose slug exists in another language: that post's address, in the language the
	 * request asked for when a translation exists there.
	 *
	 * @return string|null
	 */
	private static function find_moved(): ?string {
		global $wp;
		$request = is_object( $wp ) ? (string) $wp->request : '';
		$slug    = sanitize_title( (string) wp_basename( $request ) );
		if ( '' === $slug ) {
			return null;
		}
		$posts = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => \ZinnDigital\Tranzly\Core\Content::post_types(),
				'post_status'      => 'publish',
				'posts_per_page'   => 5,
				'suppress_filters' => false,
				'tranzly_lang'     => 'all',
				'no_found_rows'    => true,
			)
		);
		if ( array() === $posts ) {
			return null;
		}
		// An address with no language folder names the post itself (an old link to /ueber-uns/ is
		// the German page); an address WITH one asks for that language's version of it.
		if ( null === self::url_language() ) {
			return (string) get_permalink( $posts[0] );
		}
		$wanted = Languages::current();
		foreach ( $posts as $post ) {
			$translation = Languages::translation( $post->ID, $wanted );
			if ( null !== $translation && self::is_public_post( $translation ) ) {
				return (string) get_permalink( $translation );
			}
		}

		return (string) get_permalink( $posts[0] );
	}

	/**
	 * The home URL's host (with port) and path.
	 *
	 * @return array{host: string, path: string}
	 */
	private static function home_parts(): array {
		$parsed = wp_parse_url( self::raw_home() );
		$parsed = is_array( $parsed ) ? $parsed : array();

		return array(
			'host' => strtolower( (string) ( $parsed['host'] ?? '' ) . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' ) ),
			'path' => (string) ( $parsed['path'] ?? '' ),
		);
	}

	/**
	 * The part of a path after the home path (always starting `/`), or null when the path is not
	 * under it.
	 *
	 * @param string $path      A request path.
	 * @param string $home_path The home URL's path.
	 * @return string|null
	 */
	private static function after_home_path( string $path, string $home_path ): ?string {
		$home_path = rtrim( $home_path, '/' );
		if ( '' === $home_path ) {
			return '' === $path ? '/' : $path;
		}
		if ( $path === $home_path ) {
			return '/';
		}
		if ( str_starts_with( $path, $home_path . '/' ) ) {
			return substr( $path, strlen( $home_path ) );
		}

		return null;
	}

	/**
	 * The request path without its query string.
	 *
	 * @return string
	 */
	private static function request_path(): string {
		$uri = self::$original_uri ?? self::request_uri();

		return (string) ( explode( '?', $uri, 2 )[0] );
	}

	/**
	 * The request URI.
	 *
	 * @return string
	 */
	private static function request_uri(): string {
		return isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared and parsed, never printed.
	}

	/**
	 * The request host.
	 *
	 * @return string
	 */
	private static function request_host(): string {
		return isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) ) : '';
	}

	/**
	 * Scheme + host of the request.
	 *
	 * @return string
	 */
	private static function request_origin(): string {
		$host = self::request_host();

		return ( is_ssl() ? 'https' : 'http' ) . '://' . ( '' === $host ? self::home_parts()['host'] : $host );
	}

	/**
	 * A URL without its scheme, for comparing.
	 *
	 * @param string $url A URL.
	 * @return string
	 */
	private static function strip_scheme( string $url ): string {
		return (string) preg_replace( '#^https?://#i', '', $url );
	}
}
