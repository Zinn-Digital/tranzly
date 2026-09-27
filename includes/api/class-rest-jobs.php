<?php
/**
 * REST routes for background jobs, the failed-translation report, engine settings, keys and the
 * glossary.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Api;

use ZinnDigital\Tranzly\Core\Engine_Settings;
use ZinnDigital\Tranzly\Core\Glossary;
use ZinnDigital\Tranzly\Core\Queue;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Schema;
use ZinnDigital\Tranzly\Core\Secrets;
use ZinnDigital\Tranzly\Engines\DeepL;
use ZinnDigital\Tranzly\Engines\Registry;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `tranzly/v1/jobs…`, `…/engines/settings`, `…/engines/{id}/key`, `…/engines/deepl/usage`,
 * `…/glossary`.
 *
 * ⛔ A job belongs to the person who started it: only they (or an administrator) may see, cancel
 * or retry it, and a retry or a hand translation also needs `edit_post` on that post. Keys are
 * write-only: they are never returned, only whether one is saved and its last four characters.
 */
final class Rest_Jobs {

	/** Engines whose key Tranzly itself stores (AI keys belong to the AI core's own screen). */
	private const KEYED = array(
		'deepl'     => 'deepl',
		'google'    => 'google',
		'microsoft' => 'microsoft',
	);

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
			'/jobs',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_jobs' ),
					'permission_callback' => array( self::class, 'can_edit_posts' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create_job' ),
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
						'status'    => array(
							'type' => 'string',
							'enum' => array( 'publish', 'draft' ),
						),
						'force'     => array( 'type' => 'boolean' ),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_job' ),
				'permission_callback' => array( self::class, 'can_see_job' ),
			)
		);
		register_rest_route(
			$ns,
			'/jobs/(?P<id>\d+)/failures',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_failures' ),
				'permission_callback' => array( self::class, 'can_see_job' ),
			)
		);
		register_rest_route(
			$ns,
			'/jobs/(?P<id>\d+)/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'cancel_job' ),
				'permission_callback' => array( self::class, 'can_see_job' ),
			)
		);
		register_rest_route(
			$ns,
			'/jobs/items/(?P<item>\d+)/(?P<action>retry|by-hand)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'item_action' ),
				'permission_callback' => array( self::class, 'can_touch_item' ),
				'args'                => array( 'engine' => array( 'type' => 'string' ) ),
			)
		);
		register_rest_route(
			$ns,
			'/engines/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_engine_settings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'put_engine_settings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/engines/(?P<engine>deepl|google|microsoft)/key',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'put_key' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'key'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'region' => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/engines/deepl/usage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'deepl_usage' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/glossary',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_glossary' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'put_glossary' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * Writers may start and follow their own jobs.
	 *
	 * @return bool
	 */
	public static function can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Site-wide settings: administrators.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The job's owner, or an administrator.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function can_see_job( \WP_REST_Request $request ): bool {
		$job = Queue::job( (int) $request->get_param( 'id' ) );

		return null !== $job && ( get_current_user_id() === (int) $job['created_by'] || current_user_can( 'manage_options' ) );
	}

	/**
	 * The job's owner or an administrator, who can also edit the post.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function can_touch_item( \WP_REST_Request $request ): bool {
		global $wpdb;
		$item = $wpdb->get_row( $wpdb->prepare( 'SELECT job_id, object_id FROM %i WHERE id = %d', Schema::tables()['items'], (int) $request->get_param( 'item' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, one row by primary key.
		if ( ! is_array( $item ) ) {
			return false;
		}
		$job = Queue::job( (int) $item['job_id'] );

		return null !== $job
			&& ( get_current_user_id() === (int) $job['created_by'] || current_user_can( 'manage_options' ) )
			&& current_user_can( 'edit_post', (int) $item['object_id'] );
	}

	/**
	 * GET jobs: the caller's (an administrator sees all), newest first.
	 *
	 * @return \WP_REST_Response
	 */
	public static function list_jobs(): \WP_REST_Response {
		global $wpdb;
		$t   = Schema::tables();
		$ids = current_user_can( 'manage_options' )
			? $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 50', $t['jobs'] ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table; a page of the newest jobs for a screen.
			: $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE created_by = %d ORDER BY id DESC LIMIT 50', $t['jobs'], get_current_user_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.

		return new \WP_REST_Response( array_values( array_filter( array_map( static fn( $id ) => Queue::progress( (int) $id ), (array) $ids ) ) ) );
	}

	/**
	 * POST a job: listed posts, or every post of a type written in the default language.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_job( \WP_REST_Request $request ) {
		$ids  = array_map( 'intval', (array) $request->get_param( 'posts' ) );
		$type = (string) $request->get_param( 'post_type' );
		if ( '' !== $type ) {
			$ids = array_merge( $ids, self::sources_of_type( $type ) );
		}
		$made = Queue::create(
			$ids,
			array_map( 'strval', (array) $request->get_param( 'langs' ) ),
			(string) $request->get_param( 'engine' ),
			array(
				'status' => (string) $request->get_param( 'status' ),
				'force'  => (bool) $request->get_param( 'force' ),
			)
		);

		return is_wp_error( $made ) ? $made : new \WP_REST_Response( $made + array( 'progress' => Queue::progress( $made['id'] ) ), 201 );
	}

	/**
	 * GET one job's progress.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function get_job( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( Queue::progress( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * GET a job's failed items (tz-r2).
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function get_failures( \WP_REST_Request $request ): \WP_REST_Response {
		$engines = array();
		foreach ( Registry::instance()->all() as $engine ) {
			if ( $engine->is_configured() ) {
				$engines[] = array(
					'id'    => $engine->id(),
					'label' => $engine->label(),
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'items'   => Queue::failures( (int) $request->get_param( 'id' ) ),
				'engines' => $engines,
			)
		);
	}

	/**
	 * POST cancel.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function cancel_job( \WP_REST_Request $request ): \WP_REST_Response {
		Queue::cancel( (int) $request->get_param( 'id' ) );

		return self::get_job( $request );
	}

	/**
	 * POST retry (optionally with another engine) or by-hand.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function item_action( \WP_REST_Request $request ) {
		$item = (int) $request->get_param( 'item' );
		if ( 'by-hand' === $request->get_param( 'action' ) ) {
			$done = Queue::by_hand( $item );
			return is_wp_error( $done ) ? $done : new \WP_REST_Response( $done );
		}
		$done = Queue::retry( $item, (string) $request->get_param( 'engine' ) );

		return is_wp_error( $done ) ? $done : new \WP_REST_Response( array( 'queued' => true ) );
	}

	/**
	 * GET engine settings, with each engine's state and this month's spend.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_engine_settings(): \WP_REST_Response {
		$engines = array();
		foreach ( Registry::instance()->all() as $engine ) {
			$id        = $engine->id();
			$engines[] = array(
				'id'         => $id,
				'label'      => $engine->label(),
				'configured' => $engine->is_configured(),
				'key'        => isset( self::KEYED[ $id ] ) ? Secrets::masked( self::KEYED[ $id ] ) : null,
				'own_key'    => isset( self::KEYED[ $id ] ),
				'spent'      => Engine_Settings::spent( $id ),
				'cap'        => Engine_Settings::cap( $id ),
			);
		}

		return new \WP_REST_Response(
			array(
				'settings'  => Engine_Settings::get(),
				'engines'   => $engines,
				'pro'       => \ZinnDigital\Tranzly\Core\Edition::pro(),
				'languages' => Languages::all(),
			)
		);
	}

	/**
	 * PUT engine settings.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_engine_settings( \WP_REST_Request $request ) {
		$input = array_intersect_key( (array) $request->get_json_params(), array_flip( array( 'default', 'per_lang', 'fallback', 'caps' ) ) );
		$saved = Engine_Settings::save( $input );

		return is_wp_error( $saved ) ? $saved : self::get_engine_settings();
	}

	/**
	 * PUT a key (empty removes it). Never echoed back.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function put_key( \WP_REST_Request $request ): \WP_REST_Response {
		$engine = (string) $request->get_param( 'engine' );
		$key    = trim( sanitize_text_field( (string) $request->get_param( 'key' ) ) );
		$region = trim( sanitize_key( (string) $request->get_param( 'region' ) ) );
		if ( 'microsoft' === $engine && '' !== $key && '' !== $region ) {
			$key .= '|' . $region;
		}
		Secrets::put( self::KEYED[ $engine ], $key );
		if ( 'deepl' === $engine ) {
			delete_transient( 'tranzly_deepl_targets' );
		}

		return new \WP_REST_Response(
			array(
				'engine' => $engine,
				'saved'  => '' !== $key,
				'key'    => Secrets::masked( self::KEYED[ $engine ] ),
			)
		);
	}

	/**
	 * GET DeepL's usage for the saved key.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function deepl_usage() {
		$usage = ( new DeepL() )->usage();

		return is_wp_error( $usage ) ? $usage : new \WP_REST_Response( $usage );
	}

	/**
	 * GET the glossary.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_glossary(): \WP_REST_Response {
		return new \WP_REST_Response( Glossary::get() + array( 'pro' => \ZinnDigital\Tranzly\Core\Edition::pro() ) );
	}

	/**
	 * PUT the glossary.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_glossary( \WP_REST_Request $request ) {
		$input = array_intersect_key( (array) $request->get_json_params(), array_flip( array( 'dnt', 'terms', 'tone' ) ) );
		$saved = Glossary::save( $input );

		return is_wp_error( $saved ) ? $saved : self::get_glossary();
	}

	/**
	 * Every post of a type in the default language (all of them, paged through; no cap).
	 *
	 * @param string $type A post type.
	 * @return array<int, int>
	 */
	public static function sources_of_type( string $type ): array {
		$default = Languages::default_code();
		$out     = array();
		for ( $page = 1; ; ++$page ) {
			$found = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'fields'         => 'ids',
					'posts_per_page' => 100,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			if ( array() === $found ) {
				break;
			}
			Relations::prime( 'post', $found );
			foreach ( $found as $id ) {
				if ( ( Relations::language_of( 'post', (int) $id ) ?? $default ) === $default ) {
					$out[] = (int) $id;
				}
			}
		}

		return $out;
	}
}
