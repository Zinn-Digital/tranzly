<?php
/**
 * Loads and wires the translation core, its REST routes and its WP-CLI command.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-schema.php';
require_once __DIR__ . '/class-relations.php';
require_once __DIR__ . '/class-secrets.php';
require_once __DIR__ . '/class-locales.php';
require_once __DIR__ . '/class-options.php';
require_once __DIR__ . '/class-strings.php';
require_once __DIR__ . '/class-content.php';
require_once __DIR__ . '/class-legacy-graph.php';
require_once __DIR__ . '/class-legacy-import.php';
require_once __DIR__ . '/class-network.php';
require_once dirname( __DIR__ ) . '/engines/interface-engine.php';
require_once dirname( __DIR__ ) . '/engines/class-registry.php';
require_once __DIR__ . '/class-translator.php';
require_once dirname( __DIR__ ) . '/api/class-language-switch.php';
require_once dirname( __DIR__ ) . '/api/functions-api.php';
require_once dirname( __DIR__ ) . '/api/class-rest-engines.php';
require_once dirname( __DIR__ ) . '/api/class-rest-content.php';
require_once dirname( __DIR__ ) . '/cli/class-cli.php';

/**
 * One entry point for the plugin's bootstrap, so the files outside this directory change by one
 * line each.
 */
final class Boot {

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		Schema::register();
		Content::register();
		Strings::register();
		Legacy_Import::register();
		Network::register();
		\ZinnDigital\Tranzly\Api\Language_Switch::register();
		\ZinnDigital\Tranzly\Api\Rest_Content::register();
		\ZinnDigital\Tranzly\Api\Rest_Engines::register();
		\ZinnDigital\Tranzly\Cli\Cli::register();
		self::premium();
	}

	/**
	 * The Pro-only parts of the core: the Polylang/WPML compatibility layer (tz-dev4).
	 *
	 * ⛔ Same rule as bootstrap.php: the premium-only directory name is assembled from two halves,
	 * so this free-package file never carries the token the licensing service strips on, and the
	 * free package drops the directory itself, so the file test fails there (CONTRACT §3).
	 *
	 * @return void
	 */
	private static function premium(): void {
		$file = dirname( __DIR__ ) . '/api/compat_' . '_premium_only/class-compat.php'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- deliberate split, see above.
		if ( is_readable( $file ) && function_exists( 'tranzly_fs' ) && tranzly_fs()->can_use_premium_code() ) {
			require_once $file;
			\ZinnDigital\Tranzly\Api\Compat::register();
		}
	}

	/**
	 * Activation: create the tables straight away (an UPDATE reaches Schema::maybe_install()).
	 *
	 * @return void
	 */
	public static function activate(): void {
		Schema::install();
	}

	/**
	 * Uninstall: every table and option the core owns. The legacy plugin's post meta is left
	 * alone: it is the customer's old data, not ours.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;
		Schema::uninstall();
		foreach ( array( Options::OPTION, Secrets::OPTION, Legacy_Import::OPTION, Legacy_Import::STATUS_OPTION ) as $option ) {
			delete_option( $option );
		}
		$like = $wpdb->esc_like( 'tranzly_strings_' ) . '%';
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ) as $name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: find our per-language options.
			delete_option( (string) $name );
		}
		delete_site_option( Network::OPTION );
		delete_post_meta_by_key( Strings::MEDIA_META );
		delete_post_meta_by_key( Legacy_Import::STATUS_META );
		wp_clear_scheduled_hook( Legacy_Import::HOOK );
	}
}
