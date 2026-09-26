<?php
/**
 * Multisite: every site of a network gets its own translation tables, and on the Agency plan a
 * network-wide default language list for new sites.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

use ZinnDigital\Tranzly\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multisite support (tz-r15). Each site keeps its own tables (`{site prefix}tranzly_*`), created on the site's first
 * request after activation (Schema::maybe_install) — so nothing here loops over the network in a
 * page load. What the Agency plan adds is the NETWORK layer: a site created later is set up at
 * once and starts with the network's default languages, and `wp tranzly network` sets up or
 * reports every site from the command line (all of them, page by page).
 */
final class Network {

	/** Network option: the default language list for new sites (Agency). */
	public const OPTION = 'tranzly_network_languages';

	/**
	 * Hook site creation.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( self::class, 'on_new_site' ), 200, 1 );
		}
	}

	/**
	 * Is the network layer available (Agency plan, or the `tranzly_network_enabled` filter)?
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$agency = function_exists( 'tranzly_fs' ) && tranzly_fs()->can_use_premium_code() && tranzly_fs()->is_plan( 'agency' );

		/**
		 * Filters whether the multisite network layer is on.
		 *
		 * @param bool $agency True on the Agency plan.
		 */
		return (bool) apply_filters( 'tranzly_network_enabled', $agency );
	}

	/**
	 * `wp_initialize_site`: set a new site up straight away when Tranzly is network-active.
	 *
	 * @param \WP_Site $site The new site.
	 * @return void
	 */
	public static function on_new_site( $site ): void {
		if ( ! $site instanceof \WP_Site || ! self::enabled() || ! self::network_active() ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::setup_current_site();
		restore_current_blog();
	}

	/**
	 * Create the tables for the current site, and give it the network's languages if it has none.
	 *
	 * @return void
	 */
	public static function setup_current_site(): void {
		Schema::maybe_install();
		$languages = get_site_option( self::OPTION, array() );
		if ( is_array( $languages ) && array() !== $languages && false === get_option( Settings::OPTION, false ) ) {
			Settings::save( array( 'languages' => $languages ) );
		}
	}

	/**
	 * Is the plugin network-activated?
	 *
	 * @return bool
	 */
	public static function network_active(): bool {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_multisite() && is_plugin_active_for_network( plugin_basename( TRANZLY_FILE ) );
	}
}
