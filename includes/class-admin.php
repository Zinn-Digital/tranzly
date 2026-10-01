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
			self::menu_icon(),
			// ⛔ Below Settings (80), never higher: WordPress.org's 2026-09-25 closure notice listed a
			// high menu position (T-6, docs/plugins-overhaul/02-wporg-closure-notices.md), and the
			// re-review is one shot. wp/tests/e2e/tranzly/wporg-closure.sh asserts it.
			81
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

	/**
	 * The admin menu icon: the product's own pictogram as a monochrome SVG data URI (PLUGIN-ICONS,
	 * 2026-10-01 — the owner asked for the product icons "to be used everywhere", and a dashicon
	 * is nobody's icon). Fill-only on purpose: WordPress's svg-painter recolours `fill` to the
	 * admin colour scheme and leaves strokes alone. Source:
	 * `ui/src/brand/product-icons/menu/tranzly.svg`; `node scripts/product-icons.mjs --check`
	 * fails when this copy drifts from it.
	 *
	 * @return string
	 */
	private static function menu_icon(): string {
		$menu_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black"><path fill-rule="evenodd" d="M1 2h11v12H1zM3 4v2h2.5v6h2V6H10V4z"/><path fill-rule="evenodd" d="M13.5 6H19v12H8v-2.5h5.5zM14 9h1.4l1 3.2 1-3.2h1.4l-1.8 5h-1.2z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $menu_svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a data URI is the documented form for a menu icon.
	}
}
