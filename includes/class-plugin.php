<?php
/**
 * Bootstrap.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every part of the plugin to WordPress.
 */
final class Plugin {

	/**
	 * Register hooks. Called once from the main file.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Languages::register();
		Contract::register();
		Assets::register();
		Blocks::register();
		Rest::register();
		Admin::register();
		Freemius_I18n::register();
		Core\Boot::register();
		Seo\Seo::register();
		Switcher\Places::register();

		register_activation_hook( TRANZLY_FILE, array( self::class, 'activate' ) );
	}

	/**
	 * Publish the front-end assets to their neutral folder straight away.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Core\Boot::activate();
		Seo\Url_Settings::ensure();
		Seo\Router::rebuild_front_pages();
		Switcher\Places::ensure_options();
		Assets::publish();
	}
}
