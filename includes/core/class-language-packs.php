<?php
/**
 * WordPress's own words in each of the site's languages: the language packs of WordPress, the
 * active theme and the plugins, installed when a language is added.
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
 * ⛔ Live on demo.tranzly.io (W5, 2026-10-04): the Arabic shop read "Add to cart", "Default
 * sorting", "Showing all 3 results" and "Home" in English. Tranzly translates the site's CONTENT;
 * the words of WordPress, WooCommerce and the theme come from translate.wordpress.org language
 * packs, and nothing installed them for a language the site owner added in Tranzly, so every
 * customer adding a language got half-English pages.
 *
 * So when a language is added, WordPress's own installer fetches that locale's packs for WordPress,
 * then for every installed plugin and theme — in the background (Action Scheduler), never in the
 * request that saved the setting. Nothing is installed where WordPress itself would refuse
 * (`DISALLOW_FILE_MODS`, no direct file access): the status says so instead. Off with the
 * `tranzly_install_language_packs` filter.
 */
final class Language_Packs {

	/** The background action. */
	public const HOOK = 'tranzly_install_language_packs';

	/** Per locale: what the last install did. */
	public const STATUS = 'tranzly_language_packs';

	/**
	 * Hook the setting and the background action.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'changed' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, array( self::class, 'added' ), 10, 2 );
		add_action( self::HOOK, array( self::class, 'install' ) );
	}

	/**
	 * `add_option_tranzly_settings`.
	 *
	 * @param string $option The option.
	 * @param mixed  $value  Its value.
	 * @return void
	 */
	public static function added( $option, $value ): void {
		unset( $option );
		self::changed( array(), $value );
	}

	/**
	 * `update_option_tranzly_settings`: schedule the packs of each language that is new.
	 *
	 * @param mixed $old_value The old settings.
	 * @param mixed $value     The new settings.
	 * @return void
	 */
	public static function changed( $old_value, $value ): void {
		$codes = static fn( $settings ): array => array_values(
			array_filter(
				array_map(
					static fn( $row ) => is_array( $row ) ? (string) ( $row['code'] ?? '' ) : '',
					is_array( $settings ) && is_array( $settings['languages'] ?? null ) ? $settings['languages'] : array()
				)
			)
		);
		// New languages, and any language whose packs were never attempted (a site that added it
		// before 3.25.0): saving the settings is the moment to put that right.
		$status = self::status();
		$todo   = array();
		foreach ( $codes( $value ) as $code ) {
			if ( 'en_US' !== $code && ( ! in_array( $code, $codes( $old_value ), true ) || ! isset( $status[ $code ] ) ) ) {
				$todo[] = $code;
			}
		}
		self::schedule( $todo );
	}

	/**
	 * Schedule the packs of every language never attempted (an update from a version without this).
	 *
	 * @return void
	 */
	public static function backfill(): void {
		self::changed( Settings::get(), Settings::get() );
	}

	/**
	 * Install the packs of these locales in the background.
	 *
	 * @param array<int, string> $locales WordPress locales.
	 * @return void
	 */
	public static function schedule( array $locales ): void {
		/**
		 * Whether Tranzly installs the WordPress, theme and plugin language packs of a language
		 * added to the site (so WordPress's and WooCommerce's own words appear translated). On by
		 * default.
		 *
		 * @param bool               $install Install them.
		 * @param array<int, string> $locales The languages just added.
		 */
		if ( array() === $locales || ! apply_filters( 'tranzly_install_language_packs', true, $locales ) ) {
			return;
		}
		$status = self::status();
		foreach ( $locales as $locale ) {
			$status[ $locale ] = array(
				'state' => 'queued',
				'at'    => gmdate( 'c' ),
			);
		}
		update_option( self::STATUS, $status, false );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( 'locales' => array_values( $locales ) ), Queue::GROUP );
		} else {
			wp_schedule_single_event( time(), self::HOOK, array( array_values( $locales ) ) );
		}
	}

	/**
	 * What the last install did, per locale: `state` (`queued`, `installed`, `partial`,
	 * `unavailable`, `not_allowed`), `core`, `packs` and `at`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function status(): array {
		$status = get_option( self::STATUS, array() );

		return is_array( $status ) ? $status : array();
	}

	/**
	 * The background action: WordPress's pack for each locale, then every plugin's and theme's.
	 *
	 * @param array<int, string> $locales WordPress locales.
	 * @return array<string, array<string, mixed>> The status of each.
	 */
	public static function install( $locales ): array {
		$locales = array_values( array_filter( array_map( 'strval', (array) $locales ) ) );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$status = self::status();
		if ( ! wp_can_install_language_pack() ) {
			foreach ( $locales as $locale ) {
				$status[ $locale ] = array(
					'state' => 'not_allowed',
					'at'    => gmdate( 'c' ),
				);
			}
			update_option( self::STATUS, $status, false );

			return $status;
		}
		$core = array();
		foreach ( $locales as $locale ) {
			// False when translate.wordpress.org has no WordPress pack for the locale.
			$core[ $locale ] = false !== wp_download_language_pack( $locale );
		}
		// The plugins' and theme's packs: WordPress lists them only for installed languages, so
		// ask again now that the core packs are in, then install them with its own upgrader.
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		wp_update_plugins();
		wp_update_themes();
		$updates = array_values(
			array_filter(
				(array) wp_get_translation_updates(),
				static fn( $update ) => is_object( $update ) && in_array( (string) ( $update->language ?? '' ), $locales, true )
			)
		);
		$done    = array();
		if ( array() !== $updates ) {
			$upgrader = new \Language_Pack_Upgrader( new \Automatic_Upgrader_Skin() );
			$results  = (array) $upgrader->bulk_upgrade( $updates, array( 'clear_update_cache' => true ) );
			foreach ( $updates as $i => $update ) {
				// Each result is the upgrader's answer: an array (or true) on success, a WP_Error or false on failure.
				if ( isset( $results[ $i ] ) && false !== $results[ $i ] && ! is_wp_error( $results[ $i ] ) ) {
					$done[ (string) $update->language ] = ( $done[ (string) $update->language ] ?? 0 ) + 1;
				}
			}
		}
		foreach ( $locales as $locale ) {
			$packs             = (int) ( $done[ $locale ] ?? 0 );
			$status[ $locale ] = array(
				'state' => $core[ $locale ] ? 'installed' : ( $packs > 0 ? 'partial' : 'unavailable' ),
				'core'  => $core[ $locale ],
				'packs' => $packs,
				'at'    => gmdate( 'c' ),
			);
		}
		update_option( self::STATUS, $status, false );

		return $status;
	}
}
