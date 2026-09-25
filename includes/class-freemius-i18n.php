<?php
/**
 * The licensing SDK's own screens, in the site's language.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes the SDK strings a customer actually sees through this plugin's own catalogues.
 *
 * The SDK translates its screens with its own text domain, which ships no catalogue for most of
 * the 57 languages this plugin is sold in (Arabic among them), so the opt-in screen and the
 * "Upgrade" menu label rendered in English on an otherwise translated admin. Two supported
 * mechanisms, and nothing under vendor/ is edited:
 *
 * 1. `fs_override_i18n( $strings, '<slug>' )` for every string the SDK looks up by key for THIS
 *    plugin's module. The SDK then translates the value with our text domain, and because the
 *    values are written with `__()` here, `wp i18n make-pot` puts them in our POT and the
 *    catalogue pipeline translates them into every locale.
 * 2. The opt-in strings the SDK looks up WITHOUT a module slug (so no per-plugin override can
 *    reach them) are answered from the same catalogue through `gettext_freemius`, and only on
 *    this plugin's own admin page, so another vendor's copy of the SDK is never touched.
 */
final class Freemius_I18n {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		// `init`, not earlier: `__()` before `init` loads the catalogue too early (WP 6.7+ notice).
		add_action( 'init', array( self::class, 'override' ), 1 );
		add_filter( 'gettext_freemius', array( self::class, 'unkeyed' ), 10, 2 );
	}

	/**
	 * Hand the SDK our translations of every keyed string on the screens a customer meets.
	 *
	 * @return void
	 */
	public static function override(): void {
		if ( ! function_exists( 'fs_override_i18n' ) ) {
			return;
		}
		fs_override_i18n( self::keyed(), 'tranzly' );
	}

	/**
	 * Key => our translation, for the strings the SDK looks up for this module.
	 *
	 * @return array<string,string>
	 */
	public static function keyed(): array {
		return array(
			// Admin menu.
			'upgrade'                        => __( 'Upgrade', 'tranzly' ),
			'pricing'                        => __( 'Pricing', 'tranzly' ),
			'start-trial'                    => __( 'Start Trial', 'tranzly' ),
			'account'                        => __( 'Account', 'tranzly' ),
			'contact-us'                     => __( 'Contact Us', 'tranzly' ),
			'affiliation'                    => __( 'Affiliation', 'tranzly' ),
			// The opt-in screen.
			// The module type the SDK puts into its sentences (it lower-cases it itself).
			'plugin'                         => _x( 'Plugin', 'the kind of software', 'tranzly' ),
			/* translators: %s: the user's first name. */
			'hey-x'                          => _x( 'Hey %s,', 'greeting', 'tranzly' ),
			/* translators: %s: the module type, e.g. "plugin". */
			'connect-message'                => __( 'Opt in to get email notifications for security & feature updates, educational content, and occasional offers, and to share some basic WordPress environment info. This will help us make the %s more compatible with your site and better at doing what you need it to.', 'tranzly' ),
			'connect-message_on-update'      => __( 'Opt in to get email notifications for security & feature updates, educational content, and occasional offers, and to share some basic WordPress environment info.', 'tranzly' ),
			/* translators: %1$s: the plugin name. */
			'connect-message_on-update_skip' => __( 'If you skip this, that\'s okay! %1$s will still work just fine.', 'tranzly' ),
			'opt-in-connect'                 => __( 'Allow & Continue', 'tranzly' ),
			'skip'                           => _x( 'Skip', 'verb', 'tranzly' ),
			'skipping-wait'                  => __( 'Skipping, please wait', 'tranzly' ),
			'continue'                       => __( 'Continue', 'tranzly' ),
			'please-wait'                    => __( 'Please wait', 'tranzly' ),
			'activating'                     => _x( 'Activating', 'as activating plugin', 'tranzly' ),
			/* translators: %s: the plugin name. */
			'this-will-allow-x'              => __( 'This will allow %s to', 'tranzly' ),
			'privacy-policy'                 => __( 'Privacy Policy', 'tranzly' ),
			'tos'                            => __( 'Terms of Service', 'tranzly' ),
			'license-agreement'              => __( 'License Agreement', 'tranzly' ),
			'have-license-key'               => __( 'Have a license key?', 'tranzly' ),
			'activate-license'               => __( 'Activate License', 'tranzly' ),
			// The permission list behind "This will allow … to".
			'permissions-profile'            => __( 'View Basic Profile Info', 'tranzly' ),
			'permissions-profile_desc'       => __( 'Your WordPress user\'s: first & last name, and email address', 'tranzly' ),
			'permissions-site'               => __( 'View Basic Website Info', 'tranzly' ),
			'permissions-site_desc'          => __( 'Homepage URL & title, WP & PHP versions, and site language', 'tranzly' ),
			/* translators: %s: "Plugin". */
			'permissions-events'             => __( 'View Basic %s Info', 'tranzly' ),
			/* translators: %s: "plugin". */
			'permissions-events_desc'        => __( 'Current %s & SDK versions, and if active or uninstalled', 'tranzly' ),
			'permissions-newsletter'         => __( 'Newsletter', 'tranzly' ),
			'permissions-newsletter_desc'    => __( 'Updates, announcements, marketing, no spam', 'tranzly' ),
			'permissions-diagnostic'         => __( 'View Diagnostic Info', 'tranzly' ),
			'permissions-diagnostic_desc'    => __( 'WordPress & PHP versions, site language & title', 'tranzly' ),
			'permissions-extensions'         => __( 'View Plugins & Themes List', 'tranzly' ),
			'permissions-extensions_desc'    => __( 'Names, slugs, versions, and if active or not', 'tranzly' ),
			'optional'                       => __( 'optional', 'tranzly' ),
		);
	}

	/**
	 * Our translation of an opt-in string the SDK looks up with no module slug.
	 *
	 * @param string $translation What the SDK's own catalogue returned (often the English).
	 * @param string $text        The English source.
	 * @return string
	 */
	public static function unkeyed( $translation, $text ) {
		if ( ! is_admin() || ! isset( $_GET['page'] ) || 'tranzly' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, no state change.
			return $translation;
		}
		$ours = array(
			'Never miss an important update'        => __( 'Never miss an important update', 'tranzly' ),
			/* translators: %s: the module type, e.g. "plugin". */
			'We have introduced this opt-in so you never miss an important update and help us make the %s more compatible with your site and better at doing what you need it to.' => __( 'We have introduced this opt-in so you never miss an important update and help us make the %s more compatible with your site and better at doing what you need it to.', 'tranzly' ),
			/* translators: %1$s: the plugin name, %2$s: its version. */
			'Thank you for updating to %1$s v%2$s!' => __( 'Thank you for updating to %1$s v%2$s!', 'tranzly' ),
		);

		return $ours[ (string) $text ] ?? $translation;
	}
}
