<?php
/**
 * The wp-admin screen.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The branded admin screen: languages, settings, beta updates, and About/credits.
 *
 * The screen is a React app (`src/admin/`, built by @wordpress/scripts into `build/`). Its
 * strings are translated through `wp_set_script_translations()` from the JSON files that
 * `wp i18n make-json` writes into `languages/`.
 */
final class Admin {

	/** Menu slug, shared with the licensing SDK's menu integration. */
	public const SLUG = 'tranzly';

	/** Script handle. */
	public const HANDLE = 'tranzly-admin';

	/**
	 * The page hook suffix returned by add_menu_page().
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Hook the menu and assets.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Add the top-level menu.
	 *
	 * @return void
	 */
	public static function menu(): void {
		self::$hook = (string) add_menu_page(
			__( 'Tranzly', 'tranzly' ),
			__( 'Tranzly', 'tranzly' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-translation',
			59
		);
	}

	/**
	 * Print the mount point.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'tranzly' ), 403 );
		}

		// The page heading is the admin shell's; printing a second one here would give the screen
		// two <h1>s. The fallbacks below carry their own, because the shell never renders there.
		// wp-header-end: WordPress places admin notices here, above the shell, rather than inside
		// the shell's own header (common.js moves them after the first .wrap h1 otherwise).
		echo '<div class="wrap"><hr class="wp-header-end">';
		if ( ! is_readable( TRANZLY_DIR . 'build/settings.asset.php' ) ) {
			echo '<h1>' . esc_html__( 'Tranzly', 'tranzly' ) . '</h1>';
			// A source checkout that was never built. Say so rather than show an empty page.
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The admin screen\'s scripts are missing from this copy of the plugin. Reinstall it from a released package.', 'tranzly' ) . '</p></div>';
		}
		echo '<div id="tranzly-admin-root"></div>';
		echo '<noscript><h1>' . esc_html__( 'Tranzly', 'tranzly' ) . '</h1><p>' . esc_html__( 'This screen needs JavaScript.', 'tranzly' ) . '</p></noscript></div>';
	}

	/**
	 * Enqueue the app on this screen only.
	 *
	 * @param string $hook_suffix The current admin page.
	 * @return void
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( '' === self::$hook || $hook_suffix !== self::$hook ) {
			return;
		}
		$asset_file = TRANZLY_DIR . 'build/settings.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			TRANZLY_URL . 'build/settings.js',
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? TRANZLY_VERSION ),
			true
		);
		wp_set_script_translations( self::HANDLE, 'tranzly', TRANZLY_DIR . 'languages' );
		wp_add_inline_script( self::HANDLE, 'window.tranzlyAdmin = ' . wp_json_encode( self::data() ) . ';', 'before' );
		\ZinnDigital\Tranzly\AdminKit\Kit::enqueue( self::HANDLE );

		if ( is_readable( TRANZLY_DIR . 'build/settings.css' ) ) {
			wp_enqueue_style( self::HANDLE, TRANZLY_URL . 'build/settings.css', array( 'wp-components' ), (string) ( $asset['version'] ?? TRANZLY_VERSION ) );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Data the app boots with.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		/**
		 * Filters the data the admin app boots with. The premium layer uses it to report its
		 * edition; nothing sensitive belongs here, because it is printed into the page.
		 *
		 * @param array<string, mixed> $data Boot data.
		 */
		return (array) apply_filters(
			'tranzly_admin_data',
			array(
				'version'    => TRANZLY_VERSION,
				'edition'    => 'free',
				'restPath'   => '/' . Rest::NAMESPACE . '/settings',
				'upgradeUrl' => Licensing::upgrade_url(),
				'productUrl' => 'https://tranzly.io',
				'companyUrl' => 'https://zinndigital.com',
			)
		);
	}
}
