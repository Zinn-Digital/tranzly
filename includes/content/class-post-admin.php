<?php
/**
 * Translations from the CLASSIC editing screen: a "Translations" box on every translatable post
 * type that is not edited with the block editor — WooCommerce products, a site that uses the
 * Classic Editor, custom post types — with the same choices the block editor's panel offers.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ Every public post type is translated in the free edition, products included, but until 3.18
 * only the block editor had a way in: on a product (WooCommerce edits products in the classic
 * editor) a site owner had to go to Tranzly's own screens. WordPress.org closed the old plugin for
 * locking product translation behind an upsell in the product meta box; this box is the opposite —
 * no locked or disabled control, no upsell, and it does the whole job (L07, T9b closure item T-2;
 * wp/tests/e2e/tranzly/surfaces.spec.mjs).
 *
 * `__back_compat_meta_box` keeps it out of the block editor, which has the Translations panel.
 * The actions are the two calls the REST routes make: Translator::translate_post() (the language's
 * engine; a translation a person edited is never overwritten) and Content::create_post_translation()
 * (a draft copy to write by hand).
 */
final class Post_Admin {

	/** The admin-post action. */
	public const ACTION = 'tranzly_post_translation';

	/**
	 * Hook the box and its action.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_box' ), 20, 1 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * `add_meta_boxes`: the box, on translatable post types, in the classic editor only.
	 *
	 * @param string $post_type The screen's post type.
	 * @return void
	 */
	public static function add_box( $post_type ): void {
		if ( ! is_string( $post_type ) || ! Content::is_translatable_type( $post_type ) ) {
			return;
		}
		add_meta_box(
			'tranzly-translations',
			__( 'Translations', 'tranzly' ),
			array( self::class, 'render' ),
			$post_type,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * The box.
	 *
	 * @param \WP_Post $post The post being edited.
	 * @return void
	 */
	public static function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save this post first, then translate it here.', 'tranzly' ) . '</p>';
			return;
		}
		if ( ! Content::can_translate_post( $post->ID ) ) {
			return;
		}
		$own   = Relations::language_of( 'post', $post->ID ) ?? Languages::default_code();
		$group = Relations::translations( 'post', $post->ID );
		echo '<ul class="tranzly-post-translations">';
		foreach ( Languages::all() as $language ) {
			$code = (string) $language['code'];
			$name = '' !== (string) $language['name'] ? (string) $language['name'] : $code;
			echo '<li><strong lang="' . esc_attr( str_replace( '_', '-', $code ) ) . '">' . esc_html( $name ) . '</strong> ';
			if ( $code === $own ) {
				echo esc_html__( 'This one', 'tranzly' ) . '</li>';
				continue;
			}
			$target = isset( $group[ $code ] ) ? (int) $group[ $code ] : 0;
			if ( $target > 0 && get_post( $target ) instanceof \WP_Post ) {
				if ( current_user_can( 'edit_post', $target ) ) {
					echo '<a href="' . esc_url( (string) get_edit_post_link( $target, 'raw' ) ) . '">' . esc_html__( 'Edit', 'tranzly' ) . '</a> · ';
					echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . Side_By_Side::PAGE . '&post=' . $target ) ) . '">' . esc_html__( 'Side by side', 'tranzly' ) . '</a> · ';
				}
				echo '<a href="' . esc_url( self::action_url( $post->ID, $code, 'machine' ) ) . '">' . esc_html__( 'Translate again', 'tranzly' ) . '</a>';
			} else {
				echo '<a class="button button-small" href="' . esc_url( self::action_url( $post->ID, $code, 'machine' ) ) . '">' . esc_html__( 'Translate', 'tranzly' ) . '</a> ';
				echo '<a href="' . esc_url( self::action_url( $post->ID, $code, 'copy' ) ) . '">' . esc_html__( 'Create a copy to translate by hand', 'tranzly' ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ul><p class="description">' . esc_html__( 'Translate uses the engine set for that language. A version a person edited is never overwritten.', 'tranzly' ) . '</p>';
	}

	/**
	 * The signed address of one action.
	 *
	 * @param int    $post_id The original.
	 * @param string $lang    Target language.
	 * @param string $how     `machine` or `copy`.
	 * @return string
	 */
	public static function action_url( int $post_id, string $lang, string $how ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => $post_id,
					'lang'   => $lang,
					'how'    => $how,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $post_id
		);
	}

	/**
	 * `admin-post`: make or refresh the translation (always a draft: the person reads it, then
	 * publishes), then open it, with the outcome in a notice.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( self::ACTION . '_' . $post_id );
		$lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
		$how  = isset( $_GET['how'] ) && 'copy' === $_GET['how'] ? 'copy' : 'machine';
		if ( ! Content::can_translate_post( $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to translate this item.', 'tranzly' ), 403 );
		}
		$made = 'copy' === $how
			? Content::create_post_translation( $post_id, $lang )
			: Translator::translate_post( $post_id, $lang );
		if ( is_wp_error( $made ) ) {
			Term_Admin::remember( 'error', $made->get_error_message() );
			wp_safe_redirect( (string) get_edit_post_link( $post_id, 'raw' ) );
			exit;
		}
		/* translators: %s: a language's name, e.g. Deutsch. */
		Term_Admin::remember( 'success', sprintf( __( '%s: translated.', 'tranzly' ), Term_Admin::language_name( $lang ) ) );
		wp_safe_redirect( (string) get_edit_post_link( (int) $made, 'raw' ) );
		exit;
	}
}
