<?php
/**
 * Text shared by every language — menu labels, navigation, templates, patterns — translated as
 * strings and kept in each language's string store.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Core\Strings;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ ONE store, keyed so a lookup costs nothing: `menu.<item id>` for a menu item's own label and
 * `text.<sha1 of the source>` for everything else, in the per-language option every translated page
 * already loads (Core\Strings). Human edits are recorded in `tranzly_string_states` (admin-only)
 * and never overwritten by a machine (tz-r1), exactly like a post.
 *
 * `GET|POST tranzly/v1/shared-strings?lang=&scope=menus|site|templates` lists the texts with their
 * translations and states, translates the missing ones with the site's engine (`POST`, in pages of
 * 50 with a cursor — no cap on how many pages), and `PUT` saves a person's corrections.
 */
final class Shared_Strings {

	/** Option: language => key => `human`. */
	public const STATES = 'tranzly_string_states';

	/** Texts translated per request. */
	public const PAGE = 50;

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
		$args = array(
			'lang'  => array(
				'type'     => 'string',
				'required' => true,
			),
			'scope' => array(
				'type'    => 'string',
				/**
				 * Filters the shared-text scopes (lane L06, T6: integrations add `woocommerce`,
				 * `forms` and `theme`; their texts come in on `tranzly_shared_strings`).
				 *
				 * @param array<int, string> $scopes `menus`, `site`, `templates`.
				 */
				'enum'    => array_values( array_unique( (array) apply_filters( 'tranzly_shared_string_scopes', array( 'menus', 'site', 'templates' ) ) ) ),
				'default' => 'menus',
			),
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/shared-strings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_strings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => $args,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'translate_missing' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => $args + array(
						'after' => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'save_strings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => $args + array(
						'strings' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Menus and templates are the site's, so `edit_theme_options`.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * The texts of a scope: key => source text.
	 *
	 * @param string $scope `menus` or `templates`.
	 * @return array<string, string>
	 */
	public static function sources( string $scope ): array {
		$own = array();
		if ( 'menus' === $scope ) {
			$own = self::menu_sources();
		} elseif ( 'site' === $scope ) {
			$own = self::site_sources();
		}

		/**
		 * Filters the shared texts Tranzly translates as strings, per scope. The Pro layer adds
		 * block templates, template parts and patterns to `templates`.
		 *
		 * @param array<string, string> $sources Key => source text.
		 * @param string                $scope   `menus`, `site` or `templates`.
		 */
		return (array) apply_filters( 'tranzly_shared_strings', $own, $scope );
	}

	/**
	 * The site title, the tagline and the text of every widget placed in a sidebar (tz-c13), keyed
	 * as Core\Strings applies them on the front end (`site.<option>`, `widget.<id>.<field>`).
	 * Widget text and content are HTML; a title is plain text. Inactive widgets are left out: a
	 * visitor never reads them.
	 *
	 * @return array<string, mixed>
	 */
	public static function site_sources(): array {
		$out = array();
		foreach ( Strings::SITE_FIELDS as $field ) {
			$value = trim( (string) get_option( $field ) );
			if ( '' !== $value && Block_Parser::has_words( $value ) ) {
				$out[ 'site.' . $field ] = $value;
			}
		}
		// The stored placement, read directly: wp_get_sidebars_widgets() is a private core function
		// (Plugin Check forbids it), and the option is what it returns outside the Customizer.
		foreach ( (array) get_option( 'sidebars_widgets', array() ) as $sidebar => $ids ) {
			if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $ids ) ) {
				continue;
			}
			foreach ( $ids as $id ) {
				if ( ! is_string( $id ) || 1 !== preg_match( '/^([a-z0-9_-]{1,100})-([0-9]{1,9})$/', $id, $m ) ) {
					continue;
				}
				$instances = get_option( 'widget_' . $m[1] );
				$instance  = is_array( $instances ) ? ( $instances[ (int) $m[2] ] ?? null ) : null;
				if ( ! is_array( $instance ) ) {
					continue;
				}
				foreach ( array( 'title', 'text', 'content' ) as $field ) {
					$value = $instance[ $field ] ?? null;
					if ( ! is_string( $value ) || ! Block_Parser::has_words( wp_strip_all_tags( $value ) ) ) {
						continue;
					}
					$out[ 'widget.' . $id . '.' . $field ] = 'title' === $field ? $value : array(
						'text'   => $value,
						'format' => 'html',
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Menu item labels a person typed, and block-theme navigation labels.
	 *
	 * @return array<string, string>
	 */
	public static function menu_sources(): array {
		$out = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				$label = trim( (string) get_post_field( 'post_title', (int) $item->ID, 'raw' ) );
				if ( '' !== $label && Block_Parser::has_words( $label ) ) {
					$out[ 'menu.' . (int) $item->ID ] = $label;
				}
			}
		}
		$navs = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		foreach ( $navs as $nav ) {
			foreach ( self::navigation_labels( parse_blocks( (string) $nav->post_content ) ) as $label ) {
				$out[ 'text.' . sha1( $label ) ] = $label;
			}
		}

		return $out;
	}

	/**
	 * Labels of navigation links in a block tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, string>
	 */
	private static function navigation_labels( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
				$label = (string) ( $block['attrs']['label'] ?? '' );
				if ( Block_Parser::has_words( $label ) ) {
					$out[] = $label;
				}
			}
			$out = array_merge( $out, self::navigation_labels( (array) ( $block['innerBlocks'] ?? array() ) ) );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * `GET shared-strings`.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_strings( \WP_REST_Request $request ) {
		$lang = self::lang( $request );
		if ( is_wp_error( $lang ) ) {
			return $lang;
		}
		$have   = Strings::all( $lang );
		$states = self::states( $lang );
		$rows   = array();
		foreach ( self::sources( (string) $request->get_param( 'scope' ) ) as $key => $source ) {
			$tr     = $have[ $key ] ?? '';
			$rows[] = array(
				'key'         => $key,
				'source'      => self::text( $source ),
				'format'      => self::format( $source ),
				'translation' => $tr,
				'state'       => '' === $tr ? 'missing' : ( $states[ $key ] ?? 'machine' ),
			);
		}

		return new \WP_REST_Response(
			array(
				'lang'    => $lang,
				'strings' => $rows,
			)
		);
	}

	/**
	 * `POST shared-strings`: translate the next page of missing texts with the site's engine.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function translate_missing( \WP_REST_Request $request ) {
		$lang = self::lang( $request );
		if ( is_wp_error( $lang ) ) {
			return $lang;
		}
		$have    = Strings::all( $lang );
		$missing = array();
		foreach ( self::sources( (string) $request->get_param( 'scope' ) ) as $key => $text ) {
			if ( '' === ( $have[ $key ] ?? '' ) ) {
				$missing[ $key ] = $text;
			}
		}
		$after = (int) $request->get_param( 'after' );
		$page  = array_slice( $missing, $after, self::PAGE, true );
		if ( array() === $page ) {
			return new \WP_REST_Response(
				array(
					'translated' => 0,
					'next'       => null,
				)
			);
		}
		$done = self::translate( $page, $lang );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$saved = Strings::set_many( $lang, $done );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$rest = count( $missing ) - $after - count( $page );

		return new \WP_REST_Response(
			array(
				'translated' => count( $done ),
				// Translated texts leave the missing list, so the next page starts where this one did.
				'next'       => $rest > 0 ? $after + ( count( $page ) - count( $done ) ) : null,
			)
		);
	}

	/**
	 * `PUT shared-strings`: a person's corrections; they become protected.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_strings( \WP_REST_Request $request ) {
		$lang = self::lang( $request );
		if ( is_wp_error( $lang ) ) {
			return $lang;
		}
		$sources = self::sources( (string) $request->get_param( 'scope' ) );
		$map     = array();
		foreach ( (array) $request->get_param( 'strings' ) as $key => $text ) {
			$key = (string) $key;
			if ( ! isset( $sources[ $key ] ) ) {
				/* translators: %s: an internal text identifier. */
				return new \WP_Error( 'tranzly_unknown_string', sprintf( __( 'Text %s is not on this site any more. Reload the page.', 'tranzly' ), $key ), array( 'status' => 409 ) );
			}
			$map[ $key ] = 'html' === self::format( $sources[ $key ] ) ? wp_kses_post( (string) $text ) : sanitize_text_field( (string) $text );
		}
		$saved = Strings::set_many( $lang, $map );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$states = (array) get_option( self::STATES, array() );
		foreach ( $map as $key => $text ) {
			if ( '' === $text ) {
				unset( $states[ $lang ][ $key ] );
			} else {
				$states[ $lang ][ $key ] = 'human';
			}
		}
		update_option( self::STATES, $states, false );

		return self::get_strings( $request );
	}

	/**
	 * Translate texts with the language's engine chain (never a person's corrections: those are not
	 * in the missing list).
	 *
	 * @param array<string, mixed> $texts Key => source (see text() / format()).
	 * @param string               $lang  Target language.
	 * @return array<string, string>|\WP_Error Key => translation.
	 */
	public static function translate( array $texts, string $lang ) {
		$chain = Translator::chain( '', $lang );
		if ( is_wp_error( $chain ) ) {
			return $chain;
		}
		$by_format = array();
		foreach ( $texts as $key => $source ) {
			$by_format[ self::format( $source ) ][ (string) $key ] = self::text( $source );
		}
		$options = Translator::options_for( $lang, $chain[0] );
		$last    = null;
		foreach ( $chain as $engine ) {
			$out = array();
			foreach ( $by_format as $format => $batch ) {
				$answer = $engine->translate( $batch, Languages::default_code(), $lang, array( 'format' => $format ) + $options );
				if ( is_wp_error( $answer ) ) {
					$last = $answer;
					continue 2;
				}
				foreach ( $batch as $key => $unused ) {
					if ( isset( $answer[ $key ] ) && is_string( $answer[ $key ] ) && '' !== $answer[ $key ] ) {
						$out[ $key ] = 'html' === $format ? wp_kses_post( $answer[ $key ] ) : sanitize_text_field( $answer[ $key ] );
					}
				}
			}
			return $out;
		}

		return $last instanceof \WP_Error ? $last : new \WP_Error( 'tranzly_no_engine', __( 'No translation engine could take this text.', 'tranzly' ), array( 'status' => 400 ) );
	}

	/**
	 * Translate the missing texts of a scope for SEVERAL languages in batched calls (owner,
	 * 2026-10-01: translation is always batched). The answers wait in the engine for each
	 * language's own {@see translate()} (the REST route's pages, or a script's), which then makes
	 * no call for them. The texts sent are those missing in ANY of the languages, so languages
	 * that miss almost the same texts share their calls.
	 *
	 * @param string             $scope `menus`, `site` or `templates`.
	 * @param array<int, string> $langs Target languages.
	 * @return int Answers prepared (texts × languages).
	 */
	public static function prefetch_missing( string $scope, array $langs ): int {
		$sources = self::sources( $scope );
		$need    = array();
		$codes   = array();
		foreach ( $langs as $lang ) {
			$code = Languages::resolve( (string) $lang );
			if ( null === $code || Languages::default_code() === $code ) {
				continue;
			}
			$have = Strings::all( $code );
			foreach ( $sources as $key => $source ) {
				if ( '' === ( $have[ $key ] ?? '' ) ) {
					$need[ (string) $key ] = array(
						'text'   => self::text( $source ),
						'format' => self::format( $source ),
					);
					$codes[ $code ]        = true;
				}
			}
		}
		if ( count( $codes ) < 2 || array() === $need ) {
			return 0;
		}

		return Translator::prefetch_segments( $need, Languages::default_code(), array_keys( $codes ), '', null, false );
	}

	/**
	 * A source's text (a source is a string, or `text` + `format` for a piece of block HTML).
	 *
	 * @param mixed $source A source.
	 * @return string
	 */
	public static function text( $source ): string {
		return is_array( $source ) ? (string) ( $source['text'] ?? '' ) : (string) $source;
	}

	/**
	 * A source's format: `html` or `text`.
	 *
	 * @param mixed $source A source.
	 * @return string
	 */
	public static function format( $source ): string {
		return is_array( $source ) && 'html' === ( $source['format'] ?? '' ) ? 'html' : 'text';
	}

	/**
	 * A person's edits, per key, for a language.
	 *
	 * @param string $lang Language.
	 * @return array<string, string>
	 */
	public static function states( string $lang ): array {
		$all = get_option( self::STATES, array() );

		return is_array( $all ) && is_array( $all[ $lang ] ?? null ) ? $all[ $lang ] : array();
	}

	/**
	 * The requested language, which must not be the default one.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return string|\WP_Error
	 */
	private static function lang( \WP_REST_Request $request ) {
		$lang = Languages::resolve( (string) $request->get_param( 'lang' ) );
		if ( null === $lang || Languages::default_code() === $lang ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'Choose one of your other languages.', 'tranzly' ), array( 'status' => 400 ) );
		}

		return $lang;
	}
}
