<?php
/**
 * The side-by-side translation editor (tz-w4): original and translation next to each other,
 * piece by piece, each marked machine or human-checked.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Admin;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A hidden admin screen (Tranzly → Compare, reached from the editor panel and the admin bar) and
 * `GET|PUT tranzly/v1/posts/{id}/segments`.
 *
 * ⭐ The pieces are the block parser's segments (Block_Parser), keyed by their place in the
 * blocks, so the original's piece and the translation's piece pair up by key. A person saving a
 * piece writes it back into THAT place of the translation (the rest of the markup untouched),
 * marks the piece `human` and — through Protection — the whole translation protected.
 */
final class Side_By_Side {

	/** Admin page slug. */
	public const PAGE = 'tranzly-compare';

	/** Post meta: segment key => `human` for pieces a person saved. */
	public const STATES_META = '_tranzly_segment_states';

	/** Script handle (wp-admin only). */
	public const HANDLE = 'tranzly-compare';

	/**
	 * Hook the screen, the route and the reset on machine translation.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'tranzly_post_translated', array( self::class, 'forget_states' ), 10, 1 );
	}

	/**
	 * The screen's address for a translation.
	 *
	 * @param int $post_id A translation.
	 * @return string
	 */
	public static function url( int $post_id ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'post' => $post_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * `admin_menu`: a page with no menu entry (it is always opened for one translation).
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_submenu_page( '', __( 'Compare translation', 'tranzly' ), __( 'Compare translation', 'tranzly' ), 'edit_posts', self::PAGE, array( self::class, 'render' ) );
	}

	/**
	 * The screen's root element.
	 *
	 * @return void
	 */
	public static function render(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which post to show; every change goes through REST with its nonce.
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this translation.', 'tranzly' ), 403 );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Compare translation', 'tranzly' ) . '</h1><div id="tranzly-compare-root" data-post="' . esc_attr( (string) $post_id ) . '"></div></div>';
	}

	/**
	 * `admin_enqueue_scripts`.
	 *
	 * @param string $hook_suffix The screen.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( ! is_string( $hook_suffix ) || ! str_contains( $hook_suffix, self::PAGE ) ) {
			return;
		}
		$asset_file = TRANZLY_DIR . 'build/compare.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script( self::HANDLE, TRANZLY_URL . 'build/compare.js', (array) ( $asset['dependencies'] ?? array() ), (string) ( $asset['version'] ?? TRANZLY_VERSION ), true );
		wp_set_script_translations( self::HANDLE, 'tranzly', TRANZLY_DIR . 'languages' );
		wp_enqueue_style( 'wp-components' );
		if ( is_readable( TRANZLY_DIR . 'build/compare.css' ) ) {
			wp_enqueue_style( self::HANDLE, TRANZLY_URL . 'build/compare.css', array( 'wp-components' ), (string) ( $asset['version'] ?? TRANZLY_VERSION ) );
		}
		unset( $asset );
		// The screen is right-to-left when the administrator's language is.
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		// Keep the Tranzly admin menu highlighted.
		add_filter( 'parent_file', static fn() => Admin::SLUG );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			Rest::NAMESPACE,
			'/posts/(?P<id>\d+)/segments',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_segments' ),
					'permission_callback' => array( self::class, 'can_edit' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'put_segments' ),
					'permission_callback' => array( self::class, 'can_edit' ),
					'args'                => array(
						'segments' => array(
							'type'    => 'object',
							'default' => array(),
						),
						'texts'    => array(
							'type'    => 'object',
							'default' => array(),
						),
					),
				),
			)
		);
	}

	/**
	 * May the caller edit this translation?
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function can_edit( \WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'id' ) );
	}

	/**
	 * `GET posts/{id}/segments`.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_segments( \WP_REST_Request $request ) {
		$pair = self::pair( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $pair ) ) {
			return $pair;
		}

		return new \WP_REST_Response( self::compare( $pair['source'], $pair['translation'] ) );
	}

	/**
	 * `PUT posts/{id}/segments` — `segments`: key => the corrected translation.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_segments( \WP_REST_Request $request ) {
		$pair = self::pair( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $pair ) ) {
			return $pair;
		}
		$changes = (array) $request->get_param( 'segments' );
		// `texts`: key => plain text for a piece shown as text; it goes back inside the piece's own markup.
		$current = self::pieces( $pair['translation'] );
		foreach ( (array) $request->get_param( 'texts' ) as $key => $text ) {
			$key   = (string) $key;
			$piece = $current[ $key ] ?? null;
			if ( null === $piece || ! is_string( $text ) ) {
				continue; // save() names an unknown piece.
			}
			if ( 'html' !== $piece['format'] ) {
				$changes[ $key ] = $text;
				continue;
			}
			$shell = self::text_shell( (string) $piece['text'] );
			if ( null === $shell ) {
				return new \WP_Error( 'tranzly_segment_has_markup', __( 'This piece now contains formatting. Reload the page to edit it.', 'tranzly' ), array( 'status' => 409 ) );
			}
			$changes[ $key ] = $shell[0] . esc_html( $text ) . $shell[2];
		}
		$saved = self::save( $pair['translation'], $changes );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new \WP_REST_Response( self::compare( $pair['source'], get_post( $pair['translation']->ID ) ) );
	}

	/**
	 * A translation and its original.
	 *
	 * @param int $id The translation.
	 * @return array{source: \WP_Post, translation: \WP_Post}|\WP_Error
	 */
	public static function pair( int $id ) {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! Protection::is_translation( $id ) ) {
			return new \WP_Error( 'tranzly_not_translation', __( 'This is not a translation, so there is nothing to compare.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$group  = Relations::translations( 'post', $id );
		$source = get_post( (int) ( $group[ Languages::default_code() ] ?? 0 ) );
		if ( ! $source instanceof \WP_Post ) {
			foreach ( $group as $member ) {
				if ( (int) $member !== $id ) {
					$source = get_post( (int) $member );
					break;
				}
			}
		}
		if ( ! $source instanceof \WP_Post ) {
			return new \WP_Error( 'tranzly_no_original', __( 'The original of this translation no longer exists.', 'tranzly' ), array( 'status' => 404 ) );
		}

		return array(
			'source'      => $source,
			'translation' => $post,
		);
	}

	/**
	 * The pieces of both, paired by key (the pure-ish half).
	 *
	 * @param \WP_Post $source      The original.
	 * @param \WP_Post $translation The translation.
	 * @return array<string, mixed>
	 */
	public static function compare( \WP_Post $source, \WP_Post $translation ): array {
		$from   = self::pieces( $source );
		$to     = self::pieces( $translation );
		$states = (array) get_post_meta( $translation->ID, self::STATES_META, true );
		$whole  = Protection::state( $translation->ID );
		$rows   = array();
		foreach ( $from as $key => $piece ) {
			$target = $to[ $key ]['text'] ?? null;
			$state  = 'missing';
			if ( null !== $target ) {
				if ( 'human' === ( $states[ $key ] ?? '' ) ) {
					$state = 'human';
				} elseif ( $target === $piece['text'] ) {
					$state = 'untranslated';
				} else {
					$state = 'legacy' === $whole ? 'legacy' : ( 'human' === $whole ? 'human' : 'machine' );
				}
			}
			// ⛔ W8, demo.tranzly.io 2026-10-05: an HTML piece showed as raw block markup on both
			// sides (`<h1 class="wp-block-heading …">About …</h1>`). A piece whose markup wraps ONE run
			// of text is shown and edited as that text; the markup is kept as it is on save.
			$src_shell = 'html' === $piece['format'] ? self::text_shell( (string) $piece['text'] ) : null;
			$tgt_shell = 'html' === $piece['format'] && null !== $target ? self::text_shell( (string) $target ) : null;
			$rows[]    = array(
				'key'         => $key,
				'format'      => $piece['format'],
				'source'      => $piece['text'],
				'target'      => $target,
				'state'       => $state,
				'view'        => 'html' !== $piece['format'] || ( null !== $tgt_shell ) ? 'text' : 'html',
				'source_text' => 'html' !== $piece['format'] ? (string) $piece['text'] : ( null !== $src_shell ? $src_shell[1] : html_entity_decode( wp_strip_all_tags( (string) $piece['text'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				'target_text' => 'html' !== $piece['format'] ? $target : ( null !== $tgt_shell ? $tgt_shell[1] : null ),
			);
		}

		return array(
			'id'          => $translation->ID,
			'source_id'   => $source->ID,
			'lang'        => Languages::resolve( Relations::language_of( 'post', $translation->ID ) ) ?? '',
			'source_lang' => Languages::resolve( Relations::language_of( 'post', $source->ID ) ) ?? Languages::default_code(),
			'state'       => $whole,
			// The pieces pair up only while the translation keeps the original's block layout.
			'aligned'     => array_keys( $from ) === array_keys( array_intersect_key( $to, $from ) ) && count( $to ) === count( $from ),
			'edit'        => get_edit_post_link( $translation->ID, 'raw' ),
			'segments'    => $rows,
		);
	}

	/**
	 * An HTML piece as markup + ONE run of text + markup, or null when it holds no text or several
	 * runs (inline formatting, links). The text is decoded (`&amp;` → `&`).
	 *
	 * @param string $html The piece.
	 * @return array{0: string, 1: string, 2: string}|null
	 */
	public static function text_shell( string $html ): ?array {
		$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$at = null;
		foreach ( $parts as $i => $part ) {
			if ( '' === trim( $part ) || str_starts_with( $part, '<' ) ) {
				continue;
			}
			if ( null !== $at ) {
				return null;
			}
			$at = $i;
		}
		if ( null === $at ) {
			return null;
		}
		$run  = $parts[ $at ];
		$lead = substr( $run, 0, strlen( $run ) - strlen( ltrim( $run ) ) );
		$tail = substr( $run, strlen( rtrim( $run ) ) );

		return array(
			implode( '', array_slice( $parts, 0, $at ) ) . $lead,
			html_entity_decode( trim( $run ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			$tail . implode( '', array_slice( $parts, $at + 1 ) ),
		);
	}

	/**
	 * Save corrected pieces into a translation.
	 *
	 * @param \WP_Post             $translation The translation.
	 * @param array<string, mixed> $changes     Key => text.
	 * @return true|\WP_Error
	 */
	public static function save( \WP_Post $translation, array $changes ) {
		$pieces = self::pieces( $translation );
		$update = array( 'ID' => $translation->ID );
		$blocks = array();
		foreach ( $changes as $key => $text ) {
			$key = (string) $key;
			if ( ! isset( $pieces[ $key ] ) || ! is_string( $text ) ) {
				/* translators: %s: an internal piece identifier. */
				return new \WP_Error( 'tranzly_unknown_segment', sprintf( __( 'Piece %s is not part of this translation any more. Reload the page and try again.', 'tranzly' ), $key ), array( 'status' => 409 ) );
			}
			$text = 'html' === $pieces[ $key ]['format'] ? wp_kses_post( $text ) : sanitize_text_field( $text );
			if ( 'title' === $key ) {
				$update['post_title'] = $text;
			} elseif ( 'excerpt' === $key ) {
				$update['post_excerpt'] = $text;
			} else {
				$blocks[ $key ] = $text;
			}
		}
		if ( array() !== $blocks ) {
			$update['post_content'] = Block_Parser::rebuild( (string) $translation->post_content, $blocks );
		}
		$saved = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$states = (array) get_post_meta( $translation->ID, self::STATES_META, true );
		foreach ( array_keys( $changes ) as $key ) {
			$states[ (string) $key ] = 'human';
		}
		update_post_meta( $translation->ID, self::STATES_META, $states );
		update_post_meta( $translation->ID, Translator::STATUS_META, 'human' );

		return true;
	}

	/**
	 * A post's pieces: title, excerpt, then its content's block segments.
	 *
	 * @param \WP_Post $post A post.
	 * @return array<string, array{text: string, format: string}>
	 */
	public static function pieces( \WP_Post $post ): array {
		$out = array();
		if ( '' !== trim( $post->post_title ) ) {
			$out['title'] = array(
				'text'   => $post->post_title,
				'format' => 'text',
			);
		}
		if ( '' !== trim( $post->post_excerpt ) ) {
			$out['excerpt'] = array(
				'text'   => $post->post_excerpt,
				'format' => 'text',
			);
		}

		return $out + Block_Parser::segments( (string) $post->post_content );
	}

	/**
	 * A machine translated the post again (a person unlocked it): no piece is a person's now.
	 *
	 * @param int $post_id The translation.
	 * @return void
	 */
	public static function forget_states( $post_id ): void {
		delete_post_meta( (int) $post_id, self::STATES_META );
	}
}
