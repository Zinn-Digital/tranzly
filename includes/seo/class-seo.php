<?php
/**
 * T4: URLs and multilingual SEO — the module's loader and its REST routes.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

use ZinnDigital\Tranzly\Core\Edition;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every part of T4, and serves `tranzly/v1/urls` (settings) — `manage_options` only.
 */
final class Seo {

	/**
	 * Hook everything. The Pro half (subdomains/domains are handled here too; noindex of
	 * untranslated pages and the SEO audit) loads only in the premium package with a licence.
	 *
	 * @return void
	 */
	public static function register(): void {
		Router::register();
		Query_Filter::register();
		Head::register();
		Sitemaps::register();
		Robots::register();
		Suggest::register();
		Primer::register();
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'admin_init', array( Url_Settings::class, 'ensure' ) );
		// Cheap (two queries, a write only on change) and catches links made through the REST API.
		add_action( 'admin_init', array( Router::class, 'rebuild_front_pages' ) );

		$pro = __DIR__ . '/pro_' . '_premium_only'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- the free package must not carry the premium token (CONTRACT §3).
		if ( is_readable( $pro . '/class-noindex.php' ) && Edition::pro() ) {
			require_once $pro . '/class-hosts.php';
			require_once $pro . '/class-noindex.php';
			require_once $pro . '/class-audit.php';
			Pro\Noindex::register();
			Pro\Audit::register();
		}
	}

	/**
	 * `rest_api_init`.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			Rest::NAMESPACE,
			'/urls',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_settings' ),
					'permission_callback' => array( Rest::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'put_settings' ),
					'permission_callback' => array( Rest::class, 'can_manage' ),
					'args'                => array(
						'mode'                 => array( 'type' => 'string' ),
						'segments'             => array( 'type' => 'object' ),
						'domains'              => array( 'type' => 'object' ),
						'bases'                => array( 'type' => 'object' ),
						'suggest'              => array( 'type' => 'boolean' ),
						'noindex_untranslated' => array( 'type' => 'boolean' ),
					),
				),
			)
		);
	}

	/**
	 * `GET tranzly/v1/urls`.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_settings(): \WP_REST_Response {
		return new \WP_REST_Response( self::payload() );
	}

	/**
	 * `PUT tranzly/v1/urls`.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_settings( \WP_REST_Request $request ) {
		$input = array();
		foreach ( array( 'mode', 'segments', 'domains', 'bases', 'suggest', 'noindex_untranslated' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}
		$saved = Url_Settings::save( $input );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		Router::reset();

		return new \WP_REST_Response( self::payload() );
	}

	/**
	 * Settings plus what the screen needs to explain them.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		$settings = Url_Settings::get();
		$examples = array();
		foreach ( Languages::all() as $language ) {
			$examples[ $language['code'] ] = Router::home_for( $language['code'] );
		}

		return $settings + array(
			'pro'          => Edition::pro(),
			'default'      => Languages::default_code(),
			'examples'     => $examples,
			'bases_known'  => Bases::originals(),
			'seo_plugins'  => array_values(
				array_filter(
					array(
						defined( 'WPSEO_VERSION' ) ? 'Yoast SEO' : null,
						defined( 'RANK_MATH_VERSION' ) ? 'Rank Math' : null,
						defined( 'SEOPRESS_VERSION' ) ? 'SEOPress' : null,
						defined( 'AIOSEO_VERSION' ) ? 'All in One SEO' : null,
					)
				)
			),
			'sitemap_urls' => self::sitemap_urls(),
		);
	}

	/**
	 * Where the site's sitemap lives, whichever plugin draws it.
	 *
	 * @return array<int, string>
	 */
	private static function sitemap_urls(): array {
		$home = Router::raw_home();
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return array( $home . '/sitemap_index.xml' );
		}
		if ( defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			return array( $home . '/sitemaps.xml', $home . '/sitemap.xml' );
		}

		return array( $home . '/wp-sitemap.xml' );
	}
}
