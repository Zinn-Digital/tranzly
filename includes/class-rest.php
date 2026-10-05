<?php
/**
 * REST routes: settings, and the REST mirror of the language API.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `tranzly/v1` routes.
 *
 * ⛔ Every route has a real `permission_callback`:
 *  - `settings`     — `manage_options`, read and write;
 *  - `languages`    — public on purpose: the language list is what every front-end switcher
 *                     prints to every visitor, so there is nothing to protect;
 *  - `translations` — the caller must be able to see the source post (published and publicly
 *                     viewable, or `read_post` for the user), so an ID probe cannot reveal that
 *                     a draft or private post exists or has translations.
 */
final class Rest {

	/** REST namespace. */
	public const NAMESPACE = 'tranzly/v1';

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
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_settings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update_settings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => array(
						'prefix'    => array(
							'type'     => 'string',
							'required' => false,
						),
						'mcp'       => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'languages' => array(
							'type'     => 'array',
							'required' => false,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'code' => array( 'type' => 'string' ),
									'name' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/languages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_languages' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/translations/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_translation' ),
				'permission_callback' => array( self::class, 'can_see_post' ),
				'args'                => array(
					'id'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'lang' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Only administrators may read or change settings.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * May the caller see the source post?
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function can_see_post( \WP_REST_Request $request ): bool {
		$post = get_post( (int) $request->get_param( 'id' ) );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( 'publish' === $post->post_status && is_post_publicly_viewable( $post ) && '' === $post->post_password ) {
			return true;
		}

		return current_user_can( 'read_post', $post->ID );
	}

	/**
	 * GET settings.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_settings(): \WP_REST_Response {
		return new \WP_REST_Response( self::settings_payload() );
	}

	/**
	 * POST/PUT settings.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_settings( \WP_REST_Request $request ) {
		$input = array();
		foreach ( array( 'prefix', 'languages', 'mcp' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}

		$saved = Settings::save( $input );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		Assets::publish();

		return new \WP_REST_Response( self::settings_payload() );
	}

	/**
	 * GET languages — the REST mirror of tranzly_languages() / tranzly_current_language().
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_languages(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'default'   => Languages::default_code(),
				'current'   => Languages::current(),
				'languages' => Languages::all(),
			)
		);
	}

	/**
	 * GET translations/{id}?lang= — the REST mirror of tranzly_get_translation().
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_translation( \WP_REST_Request $request ) {
		$lang = Languages::resolve( (string) $request->get_param( 'lang' ) );
		if ( null === $lang ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'That language is not one of this site\'s languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$post_id     = (int) $request->get_param( 'id' );
		$translation = Languages::translation( $post_id, $lang );

		// A translation the caller may not see is reported as absent, not revealed.
		if ( null !== $translation && $translation !== $post_id && ! self::visible( $translation ) ) {
			$translation = null;
		}

		return new \WP_REST_Response(
			array(
				'post'        => $post_id,
				'lang'        => $lang,
				'translation' => $translation,
			)
		);
	}

	/**
	 * Is a post visible to the caller?
	 *
	 * @param int $post_id A post ID.
	 * @return bool
	 */
	private static function visible( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return ( 'publish' === $post->post_status && is_post_publicly_viewable( $post ) ) || current_user_can( 'read_post', $post_id );
	}

	/**
	 * Settings as the screen reads them.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings_payload(): array {
		$settings = Settings::get();

		return array(
			'prefix'    => $settings['prefix'],
			'languages' => $settings['languages'],
			// WordPress's, the theme's and the plugins' own words per language (installed in the background).
			'packs'     => \ZinnDigital\Tranzly\Core\Language_Packs::status(),
			'beta'      => Licensing::beta(),
			// The switch as saved, and what is live on THIS request (the kit booted before it).
			'mcp'       => array( 'saved' => $settings['mcp'] ) + \ZinnDigital\Tranzly\McpKit\Server::describe(),
		);
	}
}
