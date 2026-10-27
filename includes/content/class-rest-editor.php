<?php
/**
 * What the editor's "Translations" panel shows: every language of a post, with its state.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Edition;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Strings;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET tranzly/v1/posts/{id}/languages` (edit_post): for each site language, the post's version in
 * it (or none), its publish status, its translation state (machine / human / legacy / copy),
 * whether it is protected, and the links a person needs — the editor, the side-by-side editor and
 * the page. The one-click "Translate" (tz-w1) posts to the existing `posts/{id}/translate`.
 */
final class Rest_Editor {

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
			'/posts/(?P<id>\d+)/languages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_languages' ),
				'permission_callback' => static fn( \WP_REST_Request $r ): bool => current_user_can( 'edit_post', (int) $r->get_param( 'id' ) ),
			)
		);
		// The Site Editor's templates, template parts and navigation menus (ZT-STB8XM, 2026-10-27).
		// Their ids are `theme//slug`, which the numeric route above can never match.
		register_rest_route(
			Rest::NAMESPACE,
			'/site-editor/languages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_site_editor_languages' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_theme_options' ),
				'args'                => array(
					'type' => array(
						'type'     => 'string',
						'enum'     => array( 'wp_template', 'wp_template_part', 'wp_navigation' ),
						'required' => true,
					),
					'id'   => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * `GET site-editor/languages`: how much of a template, template part or navigation menu is
	 * translated in each language, and where a person edits those translations.
	 *
	 * A template's words are not a post in each language: they are shared texts (Tranzly > Menus
	 * and templates), translated once and shown wherever the template is. A Header's Navigation
	 * labels are counted with it, since the visitor reads them in the Header.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_site_editor_languages( \WP_REST_Request $request ) {
		$type    = (string) $request->get_param( 'type' );
		$id      = (string) $request->get_param( 'id' );
		$content = self::site_editor_content( $type, $id );
		if ( null === $content ) {
			return new \WP_Error( 'tranzly_not_found', __( 'This template could not be found.', 'tranzly' ), array( 'status' => 404 ) );
		}
		$blocks = parse_blocks( $content );
		$labels = Shared_Strings::navigation_labels( array_merge( $blocks, self::referenced_navigation( $blocks ) ) );
		$keys   = array();
		foreach ( $labels as $label ) {
			$keys[ 'text.' . sha1( $label ) ] = 'menus';
		}
		// Template words translate with Pro (Content\Pro\Templates); the free edition shows them as written.
		if ( Edition::pro() ) {
			foreach ( Block_Parser::segments( $content ) as $segment ) {
				$keys += array( 'text.' . sha1( $segment['text'] ) => 'templates' );
			}
		}
		$scope = in_array( 'templates', $keys, true ) ? 'templates' : 'menus';
		$rows  = array();
		foreach ( Languages::all() as $language ) {
			$code       = (string) $language['code'];
			$is_default = Languages::default_code() === $code;
			$have       = $is_default ? array() : Strings::all( $code );
			$rows[]     = array(
				'code'       => $code,
				'name'       => $language['name'],
				'is_default' => $is_default,
				'total'      => count( $keys ),
				'translated' => $is_default ? count( $keys ) : count( array_intersect_key( $have, $keys ) ),
				'edit'       => $is_default ? null : add_query_arg(
					array(
						'page'  => 'tranzly',
						'scope' => $scope,
						'lang'  => $code,
					),
					admin_url( 'admin.php' )
				) . '#/menus',
			);
		}

		return new \WP_REST_Response(
			array(
				'type'      => $type,
				'id'        => $id,
				'languages' => $rows,
			)
		);
	}

	/**
	 * The stored blocks of a Site Editor entity.
	 *
	 * @param string $type `wp_template`, `wp_template_part` or `wp_navigation`.
	 * @param string $id   `theme//slug`, or a navigation menu's post id.
	 * @return string|null
	 */
	private static function site_editor_content( string $type, string $id ): ?string {
		if ( 'wp_navigation' === $type ) {
			$post = get_post( (int) $id );
			return $post instanceof \WP_Post && 'wp_navigation' === $post->post_type ? (string) $post->post_content : null;
		}
		$template = get_block_template( $id, $type );

		return $template instanceof \WP_Block_Template ? self::expand( (string) $template->content, 0 ) : null;
	}

	/**
	 * A template's blocks followed by the patterns and template parts it shows: a theme's
	 * Header is often ONE `core/pattern` block, and a page template shows its Header and Footer
	 * through `core/template-part` blocks, so their words are only there once written out.
	 *
	 * @param string $content Block markup.
	 * @param int    $depth   How deep we already are (a pattern may include itself).
	 * @return string
	 */
	private static function expand( string $content, int $depth ): string {
		if ( $depth > 4 ) {
			return $content;
		}
		$out  = $content;
		$walk = static function ( array $blocks ) use ( &$walk, &$out, $depth ): void {
			foreach ( $blocks as $block ) {
				$name  = (string) ( $block['blockName'] ?? '' );
				$attrs = (array) ( $block['attrs'] ?? array() );
				if ( 'core/pattern' === $name && ! empty( $attrs['slug'] ) ) {
					$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) $attrs['slug'] );
					$out    .= is_array( $pattern ) ? self::expand( (string) ( $pattern['content'] ?? '' ), $depth + 1 ) : '';
				} elseif ( 'core/template-part' === $name && ! empty( $attrs['slug'] ) ) {
					$theme = (string) ( $attrs['theme'] ?? get_stylesheet() );
					$part  = get_block_template( $theme . '//' . (string) $attrs['slug'], 'wp_template_part' );
					$out  .= $part instanceof \WP_Block_Template ? self::expand( (string) $part->content, $depth + 1 ) : '';
				}
				$walk( (array) ( $block['innerBlocks'] ?? array() ) );
			}
		};
		$walk( parse_blocks( $content ) );

		return $out;
	}

	/**
	 * The blocks of the navigation menus a template's Navigation blocks show: the one each names
	 * (`ref`), or WordPress's fallback menu for a Navigation block that names none.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private static function referenced_navigation( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( 'core/navigation' === ( $block['blockName'] ?? '' ) ) {
				$ref = (int) ( $block['attrs']['ref'] ?? 0 );
				if ( $ref <= 0 && empty( $block['innerBlocks'] ) && class_exists( '\WP_Navigation_Fallback' ) ) {
					$fallback = \WP_Navigation_Fallback::get_fallback();
					$ref      = $fallback instanceof \WP_Post ? (int) $fallback->ID : 0;
				}
				$nav = $ref > 0 ? get_post( $ref ) : null;
				if ( $nav instanceof \WP_Post && 'wp_navigation' === $nav->post_type ) {
					$out = array_merge( $out, parse_blocks( (string) $nav->post_content ) );
				}
			}
			$out = array_merge( $out, self::referenced_navigation( (array) ( $block['innerBlocks'] ?? array() ) ) );
		}

		return $out;
	}

	/**
	 * The route callback.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_languages( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! Content::is_translatable_type( $post->post_type ) ) {
			return new \WP_Error( 'tranzly_not_translatable', __( 'This kind of content is not translated.', 'tranzly' ), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( self::payload( $id ) );
	}

	/**
	 * The payload (also used by the admin bar and the side-by-side screen).
	 *
	 * @param int $id A post.
	 * @return array<string, mixed>
	 */
	public static function payload( int $id ): array {
		$group  = Relations::translations( 'post', $id );
		$own    = Languages::resolve( Relations::language_of( 'post', $id ) ) ?? Languages::default_code();
		$source = $group[ Languages::default_code() ] ?? ( array() === $group ? $id : (int) reset( $group ) );
		$rows   = array();
		foreach ( Languages::all() as $language ) {
			$code   = $language['code'];
			$member = $code === $own ? $id : ( $group[ $code ] ?? null );
			$row    = array(
				'code'      => $code,
				'name'      => $language['name'],
				'id'        => $member,
				'is_self'   => $member === $id,
				'is_source' => $member === $source,
				'status'    => null,
				'state'     => null,
				'protected' => false,
				'title'     => null,
				'edit'      => null,
				'view'      => null,
				'compare'   => null,
			);
			if ( null !== $member ) {
				$can              = current_user_can( 'edit_post', $member );
				$state            = $member === $source ? 'original' : Protection::state( (int) $member );
				$row['status']    = get_post_status( $member );
				$row['state']     = $state;
				$row['protected'] = in_array( $state, array( 'human', 'legacy' ), true );
				$row['title']     = $can ? get_the_title( $member ) : null;
				$row['edit']      = $can ? get_edit_post_link( $member, 'raw' ) : null;
				$row['view']      = (string) get_permalink( $member );
				$row['compare']   = $can && $member !== $source ? Side_By_Side::url( (int) $member ) : null;
			}
			$rows[] = $row;
		}

		return array(
			'id'        => $id,
			'lang'      => $own,
			'source'    => $source,
			'languages' => $rows,
		);
	}
}
