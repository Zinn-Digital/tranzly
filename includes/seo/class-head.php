<?php
/**
 * The multilingual <head>: hreflang (with x-default), html lang + dir, og:locale.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

use ZinnDigital\Tranzly\Core\Locales;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ tz-s4: "The old version advertised this but never output them." Every page that exists in
 * more than one language lists every version, itself included, and `x-default` names the
 * default-language version — and because each version computes the SAME group, the links are
 * reciprocal by construction, which is the property search engines actually check.
 *
 * Only versions a visitor can open are listed (published, no password): an hreflang pointing at a
 * draft is a 404 to a crawler and invalidates the whole cluster.
 */
final class Head {

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'print_alternates' ), 2 );
		add_action( 'wp_head', array( self::class, 'print_og_locale' ), 3 );
		add_filter( 'language_attributes', array( self::class, 'filter_language_attributes' ), 20, 2 );
	}

	/**
	 * The versions of the page being viewed: language => URL. Empty when the site has one
	 * language, or on a page that has no language versions (search, 404).
	 *
	 * @return array<string, string>
	 */
	public static function alternates(): array {
		if ( count( Languages::all() ) < 2 || ! did_action( 'wp' ) || is_search() || is_404() || is_preview() ) {
			return array();
		}
		$out = array();

		if ( is_front_page() || ( is_home() && 'page' !== get_option( 'show_on_front' ) ) ) {
			foreach ( Languages::all() as $language ) {
				$out[ $language['code'] ] = Router::home_for( $language['code'] );
			}
			return $out;
		}

		if ( is_singular() || is_home() ) {
			// The posts page (a page chosen as the blog) is a page with translations, not an archive.
			$id    = (int) get_queried_object_id();
			$group = Relations::translations( 'post', $id );
			if ( array() === $group ) {
				$group = array( Router::post_language( $id ) => $id );
			}
			// Every version is opened below (status, address): one query for all of them, not one each.
			_prime_post_caches( array_map( 'intval', array_values( $group ) ), false, false );
			foreach ( $group as $lang => $member ) {
				$code = Languages::resolve( $lang );
				if ( null !== $code && Router::is_public_post( (int) $member ) ) {
					$out[ $code ] = (string) get_permalink( (int) $member );
				}
			}
			return self::ordered( $out );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( ! $term instanceof \WP_Term ) {
				return array();
			}
			$group = Relations::translations( 'term', $term->term_id );
			if ( array() === $group ) {
				$group = array( ( Languages::resolve( Relations::language_of( 'term', $term->term_id ) ) ?? Languages::default_code() ) => $term->term_id );
			}
			foreach ( $group as $lang => $member ) {
				$code = Languages::resolve( $lang );
				$link = get_term_link( (int) $member );
				if ( null !== $code && ! is_wp_error( $link ) ) {
					$out[ $code ] = $link;
				}
			}
			return self::ordered( $out );
		}

		// A post type archive (a shop, a portfolio) exists in every language at the same address.
		// Author and date archives are lists of whatever that language happens to hold, so they
		// get no alternates: an hreflang cluster must point at equivalent pages, and these are not.
		if ( ! is_post_type_archive() ) {
			return array();
		}
		foreach ( Languages::all() as $language ) {
			$out[ $language['code'] ] = Router::switch_url( $language['code'] );
		}

		return $out;
	}

	/**
	 * `wp_head`: the hreflang links.
	 *
	 * @return void
	 */
	public static function print_alternates(): void {
		echo self::alternates_html( self::alternates(), Languages::default_code() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in alternates_html().
	}

	/**
	 * The hreflang link tags for a set of versions (the pure half).
	 *
	 * @param array<string, string> $alternates Language => URL.
	 * @param string                $default_lang The default language.
	 * @return string
	 */
	public static function alternates_html( array $alternates, string $default_lang ): string {
		if ( array() === $alternates ) {
			return '';
		}
		$html = '';
		foreach ( $alternates as $code => $url ) {
			$html .= '<link rel="alternate" hreflang="' . esc_attr( self::hreflang( $code ) ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
		if ( isset( $alternates[ $default_lang ] ) ) {
			$html .= '<link rel="alternate" hreflang="x-default" href="' . esc_url( $alternates[ $default_lang ] ) . '" />' . "\n";
		}

		return $html;
	}

	/**
	 * A WordPress locale as an hreflang value: `de_DE` → `de-DE`, `de_DE_formal` → `de-DE`.
	 *
	 * @param string $code A locale.
	 * @return string
	 */
	public static function hreflang( string $code ): string {
		$parts = explode( '_', $code );
		$tag   = strtolower( $parts[0] );
		if ( isset( $parts[1] ) && 1 === preg_match( '/^[A-Z]{2}$/', $parts[1] ) ) {
			$tag .= '-' . $parts[1];
		}

		return $tag;
	}

	/**
	 * `wp_head`: og:locale and its alternates, unless an SEO plugin prints Open Graph (they read
	 * the locale WordPress reports, which Router already set).
	 *
	 * @return void
	 */
	public static function print_og_locale(): void {
		if ( count( Languages::all() ) < 2 || self::seo_plugin_active() ) {
			return;
		}
		$current = Languages::current();
		echo '<meta property="og:locale" content="' . esc_attr( self::og_locale( $current ) ) . '" />' . "\n";
		foreach ( array_keys( self::alternates() ) as $code ) {
			if ( $code !== $current ) {
				echo '<meta property="og:locale:alternate" content="' . esc_attr( self::og_locale( $code ) ) . '" />' . "\n";
			}
		}
	}

	/**
	 * An og:locale value (`de_DE`; a bare language gets its usual region).
	 *
	 * @param string $code A locale.
	 * @return string
	 */
	public static function og_locale( string $code ): string {
		$parts = explode( '_', $code );
		if ( isset( $parts[1] ) && 1 === preg_match( '/^[A-Z]{2}$/', $parts[1] ) ) {
			return $parts[0] . '_' . $parts[1];
		}
		$region = array(
			'en' => 'US',
			'ja' => 'JP',
			'ko' => 'KR',
			'zh' => 'CN',
			'ar' => 'AR',
			'he' => 'IL',
			'el' => 'GR',
			'cs' => 'CZ',
			'da' => 'DK',
			'sv' => 'SE',
			'uk' => 'UA',
			'vi' => 'VN',
			'fa' => 'IR',
			'hi' => 'IN',
		);
		$lang   = strtolower( $parts[0] );

		return $lang . '_' . ( $region[ $lang ] ?? strtoupper( $lang ) );
	}

	/**
	 * `language_attributes`: `lang` from the current language and `dir` from its script, even
	 * when WordPress has no language pack for it (tz-s6).
	 *
	 * @param string $output  The attributes.
	 * @param string $doctype `html` or `xhtml`.
	 * @return string
	 */
	public static function filter_language_attributes( $output, $doctype = 'html' ) {
		if ( ! Router::is_front() || count( Languages::all() ) < 2 ) {
			return $output;
		}
		$current = Languages::current();
		$attrs   = array();
		$attrs[] = 'dir="' . ( Locales::is_rtl( $current ) ? 'rtl' : 'ltr' ) . '"';
		$lang    = esc_attr( str_replace( '_', '-', $current ) );
		$attrs[] = 'xhtml' === $doctype ? 'xml:lang="' . $lang . '"' : 'lang="' . $lang . '"';

		return implode( ' ', $attrs );
	}

	/**
	 * Is a plugin that prints its own Open Graph tags active?
	 *
	 * @return bool
	 */
	public static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' );
	}

	/**
	 * Versions in the site's language order, default first.
	 *
	 * @param array<string, string> $map Language => URL.
	 * @return array<string, string>
	 */
	private static function ordered( array $map ): array {
		$out = array();
		foreach ( Languages::all() as $language ) {
			if ( isset( $map[ $language['code'] ] ) ) {
				$out[ $language['code'] ] = $map[ $language['code'] ];
			}
		}

		return $out;
	}
}
