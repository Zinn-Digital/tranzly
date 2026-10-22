<?php
/**
 * Loads the plugin: the start-up decision (Cli_Start), the background queue's runner and uninstall
 * cleanup; then starts it. The start itself — every class, the boot calls, the premium layer — is
 * in start.php, which Cli_Start runs at once, or in a WP-CLI command right after WordPress has
 * loaded (stage PERF3: WP-CLI loads WordPress under the 128 MB limit and lifts it only afterwards).
 *
 * Kept out of the main file on purpose: the licensing service re-prints the main file, so it
 * holds only the headers and the SDK init, and everything with logic lives here in WPCS style.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-autoload.php';
\ZinnDigital\Tranzly\Autoload::register();
require_once __DIR__ . '/class-cli-start.php';
// The public functions (tranzly_current_language(), tranzly_register_engine() …) exist from the
// moment the plugin is read, however late it starts: another plugin may call them while
// WordPress loads.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/api/functions-api.php';
// The background queue's runner must load before `plugins_loaded`, where it elects the newest copy
// on the site (WooCommerce and other plugins bundle it too), whenever Tranzly starts.
require_once dirname( __DIR__ ) . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

// ⭐ Anything that must run WHILE WordPress loads even when the start is deferred goes above
// this line; the rest is in start.php.
\ZinnDigital\Tranzly\Cli_Start::start(
	static function (): void {
		require_once __DIR__ . '/start.php';
	}
);

/**
 * Uninstall cleanup, run by the licensing SDK's `after_uninstall` action.
 *
 * ⛔ There is no uninstall.php: Freemius refuses an upload that contains one (HTTP 400
 * `uninstall_script`), because WordPress runs that file INSTEAD of the SDK's own uninstall hook.
 *
 * @return void
 */
function tranzly_uninstall(): void {
	// Leaves the shared AI keys alone while another plugin that uses them is still installed.
	\ZinnDigital\Tranzly\AiCore\Core::uninstall( 'tranzly' );
	\ZinnDigital\Tranzly\Assets::remove_all();
	delete_option( \ZinnDigital\Tranzly\Settings::OPTION );
	delete_post_meta_by_key( \ZinnDigital\Tranzly\Languages::META_TRANSLATIONS );
	delete_post_meta_by_key( \ZinnDigital\Tranzly\Languages::META_LANGUAGE );
	delete_post_meta_by_key( \ZinnDigital\Tranzly\Content\Links::META );
	delete_option( \ZinnDigital\Tranzly\Content\Links::VERSION_OPTION );
	\ZinnDigital\Tranzly\Core\Boot::uninstall();
}
