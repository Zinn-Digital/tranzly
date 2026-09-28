<?php
/**
 * Manual edits are protected (tz-r1): a translation a person changed is never overwritten by a
 * machine, until someone unlocks it on purpose.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ The lock itself already exists: Translator refuses a `human` or `legacy` translation on every
 * path (one post, bulk jobs, retries) unless the caller passes `force`. What was missing is the
 * thing that SETS `human`. This does, whenever a person changes a translation's title, content or
 * excerpt — in the block editor, Page Builder Sandwich, the classic editor, quick edit, REST, the
 * side-by-side editor or the visual editor — and never when a machine writes it (Translator marks
 * its own writes `machine` after saving, and a request with no user is never a person).
 */
final class Protection {

	/**
	 * Hook the marker and the route.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'post_updated', array( self::class, 'on_post_updated' ), 20, 3 );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * `post_updated`: a person changed a translation — protect it.
	 *
	 * @param int      $post_id The post.
	 * @param \WP_Post $after   After the update.
	 * @param \WP_Post $before  Before it.
	 * @return void
	 */
	public static function on_post_updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || wp_is_post_revision( $after ) || wp_is_post_autosave( $after ) ) {
			return;
		}
		if ( get_current_user_id() <= 0 || Translator::is_writing( (int) $post_id ) || ! self::is_translation( (int) $post_id ) ) {
			return; // Nobody signed in, or Tranzly's own machine write: not a person's edit.
		}
		if ( $after->post_title === $before->post_title && $after->post_content === $before->post_content && $after->post_excerpt === $before->post_excerpt ) {
			return; // A status or date change is not an edit of the words.
		}
		if ( 'legacy' === get_post_meta( (int) $post_id, Translator::STATUS_META, true ) ) {
			return; // Already protected; keep saying where it came from.
		}
		update_post_meta( (int) $post_id, Translator::STATUS_META, 'human' );
	}

	/**
	 * Is this post a translation of another (not the group's original)?
	 *
	 * @param int $post_id A post.
	 * @return bool
	 */
	public static function is_translation( int $post_id ): bool {
		$group = Relations::translations( 'post', $post_id );
		if ( count( $group ) < 2 ) {
			return false;
		}
		$own = Relations::language_of( 'post', $post_id );

		return ( null !== $own && Languages::resolve( $own ) !== Languages::default_code() )
			|| '' !== (string) get_post_meta( $post_id, Translator::STATUS_META, true );
	}

	/**
	 * The protection state a person sees: `human`, `legacy`, `machine`, or `copy` (made, never
	 * translated).
	 *
	 * @param int $post_id A translation.
	 * @return string
	 */
	public static function state( int $post_id ): string {
		$state = (string) get_post_meta( $post_id, Translator::STATUS_META, true );

		return '' === $state ? 'copy' : $state;
	}

	/**
	 * `POST tranzly/v1/posts/{id}/protection` — `protected`: true locks it (marks it human),
	 * false unlocks it (a machine may translate it again).
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			Rest::NAMESPACE,
			'/posts/(?P<id>\d+)/protection',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'set_protection' ),
				'permission_callback' => static fn( \WP_REST_Request $r ): bool => current_user_can( 'edit_post', (int) $r->get_param( 'id' ) ),
				'args'                => array(
					'protected' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The route callback.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function set_protection( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! self::is_translation( $id ) ) {
			return new \WP_Error( 'tranzly_not_translation', __( 'This is not a translation, so there is nothing to protect.', 'tranzly' ), array( 'status' => 400 ) );
		}
		update_post_meta( $id, Translator::STATUS_META, $request->get_param( 'protected' ) ? 'human' : 'machine' );

		return new \WP_REST_Response(
			array(
				'id'    => $id,
				'state' => self::state( $id ),
			)
		);
	}
}
