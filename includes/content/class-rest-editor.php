<?php
/**
 * What the editor's "Translations" panel shows: every language of a post, with its state.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Relations;
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
