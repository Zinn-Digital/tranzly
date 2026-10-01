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
require_once __DIR__ . '/class-edition.php';
require_once __DIR__ . '/class-engine-settings.php';
require_once __DIR__ . '/class-glossary.php';
require_once __DIR__ . '/interface-translation-memory.php';
require_once __DIR__ . '/class-queue.php';
require_once dirname( __DIR__ ) . '/engines/interface-engine.php';
require_once dirname( __DIR__ ) . '/engines/class-registry.php';
require_once dirname( __DIR__ ) . '/engines/class-failure.php';
require_once dirname( __DIR__ ) . '/engines/class-deepl.php';
require_once dirname( __DIR__ ) . '/engines/class-ai.php';
require_once __DIR__ . '/class-translator.php';
require_once dirname( __DIR__ ) . '/api/class-language-switch.php';
require_once dirname( __DIR__ ) . '/api/functions-api.php';
require_once dirname( __DIR__ ) . '/api/class-rest-engines.php';
require_once dirname( __DIR__ ) . '/api/class-rest-jobs.php';
require_once dirname( __DIR__ ) . '/api/class-rest-content.php';
require_once dirname( __DIR__ ) . '/cli/class-cli.php';

// The background queue's runner. It must load before `plugins_loaded`, where it elects the newest
// copy on the site (WooCommerce and other plugins bundle it too); ours may or may not be the one used.
require_once dirname( __DIR__, 2 ) . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

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
		Queue::register();
		add_action( 'tranzly_register_engines', array( self::class, 'register_engines' ), 5 );
		\ZinnDigital\Tranzly\Api\Language_Switch::register();
		\ZinnDigital\Tranzly\Api\Rest_Content::register();
		\ZinnDigital\Tranzly\Api\Rest_Engines::register();
		\ZinnDigital\Tranzly\Api\Rest_Jobs::register();
		\ZinnDigital\Tranzly\Cli\Cli::register();
		self::premium();
	}

	/**
	 * The engines Tranzly ships: DeepL always; the AI models when the AI core is in this build;
	 * Google and Microsoft with Pro.
	 *
	 * @param \ZinnDigital\Tranzly\Engines\Registry $registry The registry.
	 * @return void
	 */
	public static function register_engines( $registry ): void {
		$registry->add( new \ZinnDigital\Tranzly\Engines\DeepL() );
		if ( \ZinnDigital\Tranzly\Engines\Ai::available() ) {
			$registry->add( new \ZinnDigital\Tranzly\Engines\Ai() );
		}
		foreach ( self::$pro_engines as $class ) {
			if ( class_exists( $class ) ) {
				$registry->add( new $class() );
			}
		}
	}

	/**
	 * Pro engine classes loaded by premium().
	 *
	 * @var array<int, string>
	 */
	private static array $pro_engines = array();

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
		if ( is_readable( $file ) && Edition::pro() ) {
			require_once $file;
			\ZinnDigital\Tranzly\Api\Compat::register();
		}
		$engines = dirname( __DIR__ ) . '/engines/pro_' . '_premium_only'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- deliberate split, see above.
		if ( is_readable( $engines . '/class-google.php' ) && Edition::pro() ) {
			require_once $engines . '/class-google.php';
			require_once $engines . '/class-microsoft.php';
			self::$pro_engines = array( '\\ZinnDigital\\Tranzly\\Engines\\Pro\\Google', '\\ZinnDigital\\Tranzly\\Engines\\Pro\\Microsoft' );
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
		foreach ( array( Options::OPTION, Secrets::OPTION, Legacy_Import::OPTION, Legacy_Import::STATUS_OPTION, Legacy_Import::FORMAT_OPTION, 'tranzly_legacy_widgets', Engine_Settings::OPTION, Engine_Settings::SPEND, Glossary::OPTION, 'tranzly_deepl_glossaries' ) as $option ) {
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
		delete_transient( 'tranzly_deepl_targets' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Queue::WORK );
			as_unschedule_all_actions( Queue::WATCHDOG );
		}
	}
}
