<?php
/**
 * REST routes for translations of posts, terms, site text and media, the display options and the
 * legacy import.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Api;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Legacy_Import;
use ZinnDigital\Tranzly\Core\Options;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Strings;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `tranzly/v1` write routes.
 *
 * ⛔⛔ EVERY ROUTE HAS A REAL `permission_callback`, AND EVERY WRITE IS A REST WRITE. The legacy
 * plugin's seven admin-ajax handlers computed a nonce and never checked it, and checked no
 * capability at all (11-audit-tranzly.md §5 findings 1, 2, 8). A REST request authenticated by the
 * login cookie is only treated as that user when it carries the `wp_rest` nonce (WordPress core),
 * so cross-site forgery is refused before a callback runs; the capability is then checked per
 * object: `edit_post` / `edit_term` on the thing being changed, `manage_options` for site-wide
 * settings and the import.
 */
final class Rest_Content {

	/** A language code in a route. */
	private const LANG = '[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,12}){0,2}';

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

		foreach ( array( 'posts', 'terms' ) as $kind ) {
			register_rest_route(
				$ns,
				'/' . $kind . '/(?P<id>\d+)/translations',
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( self::class, 'get_group' ),
						'permission_callback' => array( self::class, 'can_edit_object' ),
					),
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => array( self::class, 'create_translation' ),
						'permission_callback' => array( self::class, 'can_create_translation' ),
						'args'                => array(
							'lang'        => self::lang_arg(),
							'name'        => array( 'type' => 'string' ),
							'slug'        => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
						),
					),
					array(
						'methods'             => \WP_REST_Server::DELETABLE,
						'callback'            => array( self::class, 'unlink' ),
						'permission_callback' => array( self::class, 'can_edit_object' ),
					),
				)
			);
			register_rest_route(
				$ns,
				'/' . $kind . '/(?P<id>\d+)/translations/(?P<lang>' . self::LANG . ')',
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'link' ),
					'permission_callback' => array( self::class, 'can_link' ),
					'args'                => array(
						'target' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
					),
				)
			);
		}

		register_rest_route(
			$ns,
			'/strings/(?P<lang>' . self::LANG . ')',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_strings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'put_string' ),
					'permission_callback' => array( self::class, 'can_put_string' ),
					'args'                => array(
						'key'         => array(
							'type'     => 'string',
							'required' => true,
						),
						'translation' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/media/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'put_media' ),
				'permission_callback' => array( self::class, 'can_edit_media' ),
				'args'                => array(
					'lang'        => self::lang_arg(),
					'field'       => array(
						'type'     => 'string',
						'enum'     => Strings::MEDIA_FIELDS,
						'required' => true,
					),
					'translation' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/display',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_display' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'put_display' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => array(
						'switcher' => array(
							'type'                 => 'object',
							'properties'           => array(
								'position' => array(
									'type' => 'string',
									'enum' => Options::POSITIONS,
								),
								'style'    => array(
									'type' => 'string',
									'enum' => Options::STYLES,
								),
								'new_tab'  => array( 'type' => 'boolean' ),
								'floating' => array(
									'type' => 'string',
									'enum' => Options::FLOATING,
								),
								'display'  => array(
									'type' => 'string',
									'enum' => Options::DISPLAYS,
								),
							),
							'additionalProperties' => false,
						),
						'credit'   => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/legacy',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'legacy_status' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/legacy/(?P<action>dry-run|run|undo)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'legacy_action' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
	}

	/**
	 * The `lang` argument.
	 *
	 * @return array<string, mixed>
	 */
	private static function lang_arg(): array {
		return array(
			'type'     => 'string',
			'required' => true,
			'pattern'  => '^' . self::LANG . '$',
		);
	}

	/**
	 * `posts` or `terms` → the relation type.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return string
	 */
	private static function type( \WP_REST_Request $request ): string {
		return str_contains( $request->get_route(), '/terms/' ) ? 'term' : 'post';
	}

	/**
	 * Site-wide settings and the import: administrators only.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * May the caller edit this post / term?
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function can_edit_object( \WP_REST_Request $request ): bool {
		$id = (int) $request->get_param( 'id' );

		return 'term' === self::type( $request )
			? ( get_term( $id ) instanceof \WP_Term && current_user_can( 'edit_term', $id ) )
			: ( get_post( $id ) instanceof \WP_Post && current_user_can( 'edit_post', $id ) );
	}

	/**
	 * May the caller create a translation of this post / term?
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function can_create_translation( \WP_REST_Request $request ): bool {
		$id = (int) $request->get_param( 'id' );

		return 'term' === self::type( $request ) ? Content::can_translate_term( $id ) : Content::can_translate_post( $id );
	}

	/**
	 * Linking changes BOTH objects, so the caller must be able to edit both.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function can_link( \WP_REST_Request $request ): bool {
		if ( ! self::can_edit_object( $request ) ) {
			return false;
		}
		$target = (int) $request->get_param( 'target' );

		return 'term' === self::type( $request )
			? ( get_term( $target ) instanceof \WP_Term && current_user_can( 'edit_term', $target ) )
			: ( get_post( $target ) instanceof \WP_Post && current_user_can( 'edit_post', $target ) );
	}

	/**
	 * Site title and tagline need `manage_options`; widgets need `edit_theme_options`.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function can_put_string( \WP_REST_Request $request ): bool {
		return str_starts_with( (string) $request->get_param( 'key' ), 'widget.' )
			? current_user_can( 'edit_theme_options' )
			: current_user_can( 'manage_options' );
	}

	/**
	 * May the caller edit this attachment?
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function can_edit_media( \WP_REST_Request $request ): bool {
		$id = (int) $request->get_param( 'id' );

		return 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * GET the group of a post / term, each member with its title and edit link.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function get_group( \WP_REST_Request $request ): \WP_REST_Response {
		$type    = self::type( $request );
		$id      = (int) $request->get_param( 'id' );
		$members = array();
		foreach ( Relations::translations( $type, $id ) as $lang => $member ) {
			$visible = 'term' === $type ? current_user_can( 'edit_term', $member ) : current_user_can( 'edit_post', $member );
			// A member the caller may not edit is listed by language only: its existence is not secret
			// to someone who can edit the group, its content is.
			$members[] = array(
				'lang'  => $lang,
				'id'    => $member,
				'title' => $visible ? ( 'term' === $type ? (string) get_term_field( 'name', $member ) : get_the_title( $member ) ) : null,
			);
		}

		return new \WP_REST_Response(
			array(
				'id'      => $id,
				'type'    => $type,
				'lang'    => Relations::language_of( $type, $id ) ?? Languages::default_code(),
				'members' => $members,
			)
		);
	}

	/**
	 * POST a new translation.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_translation( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$lang = (string) $request->get_param( 'lang' );
		$made = 'term' === self::type( $request )
			? Content::create_term_translation(
				$id,
				$lang,
				array_filter(
					array(
						'name'        => $request->get_param( 'name' ),
						'slug'        => $request->get_param( 'slug' ),
						'description' => $request->get_param( 'description' ),
					),
					static fn( $v ) => null !== $v
				)
			)
			: Content::create_post_translation( $id, $lang );
		if ( is_wp_error( $made ) ) {
			return $made;
		}

		return new \WP_REST_Response(
			array(
				'id'   => $made,
				'lang' => Languages::resolve( $lang ),
			),
			201
		);
	}

	/**
	 * PUT: make an existing post / term the translation of this one.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function link( \WP_REST_Request $request ) {
		$type = self::type( $request );
		$lang = Languages::resolve( (string) $request->get_param( 'lang' ) );
		if ( null === $lang ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'That language is not one of this site\'s languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$id     = (int) $request->get_param( 'id' );
		$target = (int) $request->get_param( 'target' );
		if ( 'post' === $type && get_post_type( $id ) !== get_post_type( $target ) ) {
			return new \WP_Error( 'tranzly_bad_link', __( 'A translation must be the same kind of content as the original.', 'tranzly' ), array( 'status' => 400 ) );
		}
		if ( 'term' === $type && get_term( $id )->taxonomy !== get_term( $target )->taxonomy ) {
			return new \WP_Error( 'tranzly_bad_link', __( 'A translation must be the same kind of content as the original.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$linked = Relations::link( $type, $id, Relations::language_of( $type, $id ) ?? Languages::default_code(), $target, $lang );
		if ( is_wp_error( $linked ) ) {
			return $linked;
		}

		return self::get_group( $request );
	}

	/**
	 * DELETE: take this post / term out of its group (it keeps its language).
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function unlink( \WP_REST_Request $request ): \WP_REST_Response {
		Relations::unlink( self::type( $request ), (int) $request->get_param( 'id' ) );

		return self::get_group( $request );
	}

	/**
	 * GET every site/widget translation in a language.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_strings( \WP_REST_Request $request ) {
		$lang = Languages::resolve( (string) $request->get_param( 'lang' ) );
		if ( null === $lang ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'That language is not one of this site\'s languages.', 'tranzly' ), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response(
			array(
				'lang'    => $lang,
				'strings' => (object) Strings::all( $lang ),
			)
		);
	}

	/**
	 * PUT one site/widget translation. Plain text for the site title and tagline and widget titles;
	 * widget body text keeps the HTML the user is allowed to post.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_string( \WP_REST_Request $request ) {
		$lang = Languages::resolve( (string) $request->get_param( 'lang' ) );
		if ( null === $lang || Languages::default_code() === $lang ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'Choose one of the site\'s other languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$key  = (string) $request->get_param( 'key' );
		$text = (string) $request->get_param( 'translation' );
		$text = preg_match( '/\.(text|content)$/', $key ) && ! current_user_can( 'unfiltered_html' ) ? wp_kses_post( $text ) : $text;
		$text = preg_match( '/\.(text|content)$/', $key ) ? $text : sanitize_text_field( $text );
		$done = Strings::set( $lang, $key, $text );

		return is_wp_error( $done ) ? $done : self::get_strings( $request );
	}

	/**
	 * PUT one media translation.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_media( \WP_REST_Request $request ) {
		$lang = Languages::resolve( (string) $request->get_param( 'lang' ) );
		if ( null === $lang || Languages::default_code() === $lang ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'Choose one of the site\'s other languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$id    = (int) $request->get_param( 'id' );
		$field = (string) $request->get_param( 'field' );
		$text  = (string) $request->get_param( 'translation' );
		$text  = 'caption' === $field ? wp_kses_post( $text ) : sanitize_text_field( $text );
		$done  = Strings::set_media( $id, $lang, $field, $text );
		if ( is_wp_error( $done ) ) {
			return $done;
		}

		return new \WP_REST_Response(
			array(
				'id'          => $id,
				'lang'        => $lang,
				'field'       => $field,
				'translation' => Strings::media( $id, $lang, $field ),
			)
		);
	}

	/**
	 * GET the display options.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_display(): \WP_REST_Response {
		return new \WP_REST_Response( Options::get() );
	}

	/**
	 * PUT the display options.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_display( \WP_REST_Request $request ) {
		$input = array();
		foreach ( array( 'switcher', 'credit' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}
		$saved = Options::save( $input );

		return is_wp_error( $saved ) ? $saved : self::get_display();
	}

	/**
	 * GET the legacy import's state and report.
	 *
	 * @return \WP_REST_Response
	 */
	public static function legacy_status(): \WP_REST_Response {
		$state = Legacy_Import::state();

		return new \WP_REST_Response(
			array(
				'status'   => $state['status'],
				'report'   => $state['report'],
				'skipped'  => $state['skipped'] ?? array(),
				'started'  => $state['started_gmt'] ?? null,
				'finished' => $state['finished_gmt'] ?? null,
			)
		);
	}

	/**
	 * POST dry-run / run / undo.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function legacy_action( \WP_REST_Request $request ): \WP_REST_Response {
		switch ( (string) $request->get_param( 'action' ) ) {
			case 'dry-run':
				return new \WP_REST_Response( array( 'report' => Legacy_Import::dry_run() ) );
			case 'undo':
				return new \WP_REST_Response( Legacy_Import::undo() );
			default:
				Legacy_Import::begin();
				Legacy_Import::step( 15.0 );
				if ( in_array( Legacy_Import::state()['status'], array( 'collecting', 'applying' ), true ) ) {
					wp_schedule_single_event( time(), Legacy_Import::HOOK );
				}
				return self::legacy_status();
		}
	}
}
