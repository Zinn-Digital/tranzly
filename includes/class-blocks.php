<?php
/**
 * Block registration.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the editor script and the switcher block.
 *
 * ⭐ The block metadata carries NO `style` or `viewScript`: WordPress would serve those from the
 * plugin directory on the front end. Front-end CSS goes through Assets, from a neutral path.
 */
final class Blocks {

	/** Editor script handle — wp-admin only, so it may carry the plugin's name. */
	public const EDITOR_HANDLE = 'tranzly-editor';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_blocks' ) );
	}

	/**
	 * Register the editor script, then the block.
	 *
	 * @return void
	 */
	public static function register_blocks(): void {
		$asset_file = TRANZLY_DIR . 'build/editor.asset.php';
		if ( is_readable( $asset_file ) ) {
			$asset = require $asset_file;
			wp_register_script(
				self::EDITOR_HANDLE,
				TRANZLY_URL . 'build/editor.js',
				(array) ( $asset['dependencies'] ?? array() ),
				(string) ( $asset['version'] ?? TRANZLY_VERSION ),
				true
			);
			wp_set_script_translations( self::EDITOR_HANDLE, 'tranzly', TRANZLY_DIR . 'languages' );
		}

		register_block_type(
			TRANZLY_DIR . 'blocks/fixture-switcher',
			array( 'render_callback' => array( Fixture::class, 'render_block' ) )
		);
	}
}
