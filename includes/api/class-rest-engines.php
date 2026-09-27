<?php
/**
 * REST routes for engines: list them, estimate, translate.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Api;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Engines\Engine;
use ZinnDigital\Tranzly\Engines\Registry;
use ZinnDigital\Tranzly\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `tranzly/v1/engines`, `…/estimate`, `…/posts/{id}/translate`, `…/terms/{id}/translate`.
 *
 * ⭐ Translating ONE item runs in the request: it is what a person clicking "translate" waits for.
 * Anything bigger belongs to the background queue (T2), never to a loop in a request.
 */
final class Rest_Engines {

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = Rest::NAMESPACE;
		register_rest_route(
			$ns,
			'/engines',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_engines' ),
				'permission_callback' => array( self::class, 'can_edit_posts' ),
			)
		);
		register_rest_route(
			$ns,
			'/estimate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'estimate' ),
				'permission_callback' => array( self::class, 'can_edit_posts' ),
				'args'                => array(
					'posts'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'post_type' => array( 'type' => 'string' ),
					'langs'     => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'required' => true,
					),
					'engine'    => array( 'type' => 'string' ),
				),
			)
		);
		foreach ( array( 'posts', 'terms' ) as $kind ) {
			register_rest_route(
				$ns,
				'/' . $kind . '/(?P<id>\d+)/translate',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'translate' ),
					'permission_callback' => array( self::class, 'can_translate' ),
					'args'                => array(
						'lang'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'engine' => array( 'type' => 'string' ),
						'force'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'status' => array(
							'type' => 'string',
							'enum' => array( 'publish', 'draft' ),
						),
					),
				)
			);
		}
	}

	/**
	 * Anyone who can write posts may see the engines and ask for an estimate.
	 *
	 * @return bool
	 */
	public static function can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * May the caller translate this post / term?
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function can_translate( \WP_REST_Request $request ): bool {
		$id = (int) $request->get_param( 'id' );

		return str_contains( $request->get_route(), '/terms/' ) ? Content::can_translate_term( $id ) : Content::can_translate_post( $id );
	}

	/**
	 * GET engines.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_engines(): \WP_REST_Response {
		$default = Registry::instance()->default_engine();
		$out     = array();
		foreach ( Registry::instance()->all() as $engine ) {
			$out[] = array(
				'id'         => $engine->id(),
				'label'      => $engine->label(),
				'configured' => $engine->is_configured(),
				'default'    => $default instanceof Engine && $default->id() === $engine->id(),
			);
		}

		return new \WP_REST_Response( $out );
	}

	/**
	 * POST estimate. Only posts the caller may edit are counted.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function estimate( \WP_REST_Request $request ) {
		$ids = array_map( 'intval', (array) $request->get_param( 'posts' ) );
		if ( '' !== (string) $request->get_param( 'post_type' ) ) {
			$ids = array_merge( $ids, Rest_Jobs::sources_of_type( (string) $request->get_param( 'post_type' ) ) );
		}
		$ids    = array_values( array_filter( array_unique( $ids ), static fn( $id ) => current_user_can( 'edit_post', $id ) ) );
		$result = Translator::estimate( $ids, array_map( 'strval', (array) $request->get_param( 'langs' ) ), (string) $request->get_param( 'engine' ) );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result + array( 'posts' => count( $ids ) ) );
	}

	/**
	 * POST translate.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function translate( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$args = array(
			'force'  => (bool) $request->get_param( 'force' ),
			'status' => (string) $request->get_param( 'status' ),
		);
		$done = str_contains( $request->get_route(), '/terms/' )
			? Translator::translate_term( $id, (string) $request->get_param( 'lang' ), (string) $request->get_param( 'engine' ), $args )
			: Translator::translate_post( $id, (string) $request->get_param( 'lang' ), (string) $request->get_param( 'engine' ), $args );

		return is_wp_error( $done ) ? $done : new \WP_REST_Response( array( 'id' => $done ), 200 );
	}
}
