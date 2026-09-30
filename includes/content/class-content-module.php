<?php
/**
 * T3: content — block-safe translation, protection, the translation editors, menus, per-language
 * blocks and images. The module's loader.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Core\Edition;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Seo\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires T3, and puts "Edit this translation" in the admin bar (tz-r3): from any page of the site a
 * person who may edit it reaches the normal editor (or Page Builder Sandwich, which is the block
 * editor) and the side-by-side editor for the version they are reading.
 */
final class Content_Module {

	/**
	 * Hook everything. The Pro half (templates and patterns, images per language, the visual
	 * editor) loads only in the premium package with a licence.
	 *
	 * @return void
	 */
	public static function register(): void {
		Block_Parser::register();
		Protection::register();
		Rest_Editor::register();
		Side_By_Side::register();
		Menus::register();
		Menus_Rest::register();
		Shared_Strings::register();
		Term_Admin::register();
		Media_Admin::register();
		Visibility::register();
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 90 );

		$pro = __DIR__ . '/pro_' . '_premium_only'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- the free package must not carry the premium token (CONTRACT §3).
		if ( is_readable( $pro . '/class-templates.php' ) && Edition::pro() ) {
			require_once $pro . '/class-templates.php';
			require_once $pro . '/class-images.php';
			require_once $pro . '/class-visual-editor.php';
			Pro\Templates::register();
			Pro\Images::register();
			Pro\Visual_Editor::register();
		}
	}

	/**
	 * `admin_bar_menu`: the translation of the page being read.
	 *
	 * @param \WP_Admin_Bar $bar The admin bar.
	 * @return void
	 */
	public static function admin_bar( $bar ): void {
		if ( ! $bar instanceof \WP_Admin_Bar || is_admin() || ! is_singular() || count( Languages::all() ) < 2 ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'tranzly-translation',
				'title' => esc_html__( 'Translations', 'tranzly' ),
				'href'  => get_edit_post_link( $id, 'raw' ),
			)
		);
		foreach ( Rest_Editor::payload( $id )['languages'] as $row ) {
			if ( empty( $row['id'] ) ) {
				continue;
			}
			$bar->add_node(
				array(
					'parent' => 'tranzly-translation',
					'id'     => 'tranzly-edit-' . sanitize_key( (string) $row['code'] ),
					/* translators: %s: a language's name. */
					'title'  => esc_html( sprintf( __( 'Edit the %s version', 'tranzly' ), $row['name'] ) ),
					'href'   => (string) $row['edit'],
				)
			);
			if ( ! empty( $row['compare'] ) ) {
				$bar->add_node(
					array(
						'parent' => 'tranzly-translation',
						'id'     => 'tranzly-compare-' . sanitize_key( (string) $row['code'] ),
						/* translators: %s: a language's name. */
						'title'  => esc_html( sprintf( __( 'Compare the %s version side by side', 'tranzly' ), $row['name'] ) ),
						'href'   => (string) $row['compare'],
					)
				);
			}
		}
		unset( $row );
		if ( Router::is_front() ) {
			/**
			 * Fires after the Translations admin-bar menu is built (the Pro visual editor adds its
			 * "Translate on this page" item here).
			 *
			 * @param \WP_Admin_Bar $bar The admin bar.
			 * @param int           $id  The post being read.
			 */
			do_action( 'tranzly_admin_bar', $bar, $id );
		}
	}
}
