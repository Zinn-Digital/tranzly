<?php
/**
 * `GET|PUT tranzly/v1/menus`: which menu each theme location shows in each language (tz-c4).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The route behind Tranzly → Menus and templates. `edit_theme_options`, like Appearance → Menus.
 */
final class Menus_Rest {

	/**
	 * Hook the route.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register it.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			Rest::NAMESPACE,
			'/menus',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_menus' ),
					'permission_callback' => array( Shared_Strings::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'put_menus' ),
					'permission_callback' => array( Shared_Strings::class, 'can_manage' ),
					'args'                => array(
						'map' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * `GET menus`.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_menus(): \WP_REST_Response {
		$locations = array();
		foreach ( get_registered_nav_menus() as $slug => $name ) {
			$locations[] = array(
				'slug' => (string) $slug,
				'name' => (string) $name,
			);
		}
		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = array(
				'id'   => (int) $menu->term_id,
				'name' => (string) $menu->name,
			);
		}

		return new \WP_REST_Response(
			array(
				'locations' => $locations,
				'menus'     => $menus,
				'map'       => (object) Menus::map(),
				'block'     => wp_is_block_theme(),
			)
		);
	}

	/**
	 * `PUT menus`.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_menus( \WP_REST_Request $request ) {
		$saved = Menus::save( (array) $request->get_param( 'map' ) );

		return is_wp_error( $saved ) ? $saved : self::get_menus();
	}
}
