<?php
/**
 * Every language in the sitemap, whichever sitemap the site uses (tz-s5).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translations are posts and terms, so every sitemap that lists posts lists them — once
 * Query_Filter stops hiding other languages from sitemap requests — and each URL carries its own
 * language because the links are filtered (Router). What no sitemap lists by itself is the HOME
 * PAGE of each language when the home page is the blog (it is not a post), so this adds those:
 * a core sitemap provider, and the equivalent hook of Yoast SEO, Rank Math, SEOPress and
 * All in One SEO.
 */
final class Sitemaps {

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_core_provider' ), 20 );
		add_filter( 'wpseo_sitemap_post_type_first_links', array( self::class, 'yoast_first_links' ), 20, 2 );
		add_filter( 'rank_math/sitemap/page_content', array( self::class, 'rank_math_content' ), 20, 1 );
		add_filter( 'aioseo_sitemap_posts', array( self::class, 'aioseo_posts' ), 20, 2 );
		add_filter( 'seopress_sitemaps_single_query', array( self::class, 'all_languages_args' ), 20, 1 );
		add_filter( 'seopress_sitemaps_xml_single', array( self::class, 'seopress_xml' ), 20, 1 );
	}

	/**
	 * Rank Math: `<url>` entries appended to the page sitemap.
	 *
	 * @param string $content Extra XML.
	 * @return string
	 */
	public static function rank_math_content( $content ) {
		return (string) $content . self::url_entries();
	}

	/**
	 * SEOPress: the language homes after the site's own home entry, in the sitemap that has it.
	 *
	 * @param string $xml The sitemap XML.
	 * @return string
	 */
	public static function seopress_xml( $xml ) {
		if ( ! is_string( $xml ) || ! str_contains( $xml, '<loc>' . esc_url( trailingslashit( Router::raw_home() ) ) . '</loc>' ) ) {
			return $xml;
		}
		$pos = strrpos( $xml, '</urlset>' );

		return false === $pos ? $xml : substr( $xml, 0, $pos ) . self::url_entries() . substr( $xml, $pos );
	}

	/**
	 * All in One SEO: the language homes in the page sitemap.
	 *
	 * @param array<int, array<string, mixed>> $entries   Entries.
	 * @param string                           $post_type The post type.
	 * @return array<int, array<string, mixed>>
	 */
	public static function aioseo_posts( $entries, $post_type = '' ) {
		if ( ! is_array( $entries ) || 'page' !== $post_type ) {
			return $entries;
		}

		return array_merge( self::aioseo_pages( array() ), $entries );
	}

	/**
	 * The language homes as sitemap `<url>` elements.
	 *
	 * @return string
	 */
	public static function url_entries(): string {
		$xml = '';
		foreach ( self::extra_homes() as $url ) {
			$xml .= "\t<url>\n\t\t<loc>" . esc_url( $url ) . "</loc>\n\t</url>\n";
		}

		return $xml;
	}

	/**
	 * The home page of every language except the default, when the home page is the blog.
	 *
	 * @return array<int, string>
	 */
	public static function extra_homes(): array {
		if ( count( Languages::all() ) < 2 || 'page' === get_option( 'show_on_front' ) ) {
			return array(); // A static front page is a page: its translations are listed as pages.
		}
		$out = array();
		foreach ( Languages::all() as $language ) {
			if ( Languages::default_code() !== $language['code'] ) {
				$out[] = Router::home_for( $language['code'] );
			}
		}

		return $out;
	}

	/**
	 * WordPress's own sitemap: a `languages` provider with the language home pages.
	 *
	 * @return void
	 */
	public static function register_core_provider(): void {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) || array() === self::extra_homes() ) {
			return;
		}
		require_once __DIR__ . '/class-sitemap-provider.php';
		wp_register_sitemap_provider( 'languages', new Sitemap_Provider() );
	}

	/**
	 * Yoast SEO / Rank Math: the language homes at the top of the `page` sitemap.
	 *
	 * @param array<int, array<string, mixed>> $links     Links.
	 * @param string                           $post_type The sitemap's post type.
	 * @return array<int, array<string, mixed>>
	 */
	public static function yoast_first_links( $links, $post_type = '' ) {
		if ( ! is_array( $links ) || 'page' !== $post_type ) {
			return $links;
		}
		foreach ( self::extra_homes() as $url ) {
			$links[] = array(
				'loc' => $url,
				'mod' => get_lastpostmodified( 'gmt' ),
				'chf' => 'daily',
				'pri' => 1,
			);
		}

		return $links;
	}

	/**
	 * All in One SEO: the language homes as additional pages.
	 *
	 * @param array<int, array<string, mixed>> $pages Pages.
	 * @return array<int, array<string, mixed>>
	 */
	public static function aioseo_pages( $pages ) {
		$pages = is_array( $pages ) ? $pages : array();
		foreach ( self::extra_homes() as $url ) {
			$pages[] = array(
				'loc'        => $url,
				'lastmod'    => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( (string) get_lastpostmodified( 'gmt' ) ) ),
				'changefreq' => 'daily',
				'priority'   => 1.0,
			);
		}

		return $pages;
	}

	/**
	 * SEOPress builds its post sitemaps with WP_Query: every language.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public static function all_languages_args( $args ) {
		return Query_Filter::all_languages( $args );
	}
}
