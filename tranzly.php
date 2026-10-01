<?php

/**
 * Plugin Name:       Tranzly
 * Plugin URI:        https://zinndigital.com/wordpress-plugins/tranzly
 * Description:       Multilingual WordPress, one post per language: linked translations of posts, pages, categories, media text, widgets and the site title, with your old Tranzly translations imported.
 * Version:           3.21.1
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Neil Lock — CEO, Zinn Digital® Ltd
 * Author URI:        https://zinndigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tranzly
 * Domain Path:       /languages
 *
 * @package ZinnDigital\Tranzly
 *
 * ⛔⛔ THIS FILE IS NAMED `tranzly.php` ON PURPOSE AND MUST NEVER BE RENAMED. Every site running
 * the legacy plugin has `tranzly/tranzly.php` recorded as the active basename; WordPress
 * deactivates a plugin whose basename disappears during an update (plugins.json
 * `legacy_main_file`, CONTRACT §1 / G10).
 *
 * ⛔⛔ AND IT IS WRITTEN IN THE LICENSING SERVICE'S OWN PRINT, NOT IN WPCS STYLE. Freemius
 * re-prints the file that calls fs_dynamic_init() (four-space indent, no blank lines between
 * statements, `!$x`); every other file reaches its free package byte-for-byte. Writing this file
 * in that canonical form is what makes the house free zip and the Freemius free zip identical
 * (docs/adr/0031, docs/adr/0032). Keep it to headers, the SDK init and one require; everything
 * else lives in includes/ and follows WPCS.
 *
 * ⛔ No `Update URI` header and no secret key in this SOURCE, ever. Freemius adds the header to the premium download only (measured, docs/adr/0031). The SDK needs only the PUBLIC key below.
 */
defined( 'ABSPATH' ) || exit;
if ( function_exists( 'tranzly_fs' ) ) {
    tranzly_fs()->set_basename( false, __FILE__ );
    return;
}
define( 'TRANZLY_VERSION', '3.21.1' );
define( 'TRANZLY_FILE', __FILE__ );
define( 'TRANZLY_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRANZLY_URL', plugin_dir_url( __FILE__ ) );
if ( !function_exists( 'tranzly_fs' ) ) {
    /**
     * The licensing SDK instance for this plugin. The name is the legacy plugin's; the SDK keys a site's connection on plugin 6843 and its slug.
     *
     * @return Freemius
     */
    function tranzly_fs() {
        global $tranzly_fs;
        if ( !isset( $tranzly_fs ) ) {
            require_once __DIR__ . '/vendor/freemius/start.php';
            $tranzly_fs = fs_dynamic_init( array(
                'id'                             => '6843',
                'slug'                           => 'tranzly',
                'premium_slug'                   => 'tranzly-premium',
                'type'                           => 'plugin',
                'public_key'                     => 'pk_41c863827b360a912566ffb91d7fd',
                'bundle_id'                      => '40216',
                'bundle_public_key'              => 'pk_1bcbfe8657c755d37d4b8a4c29f46',
                'bundle_license_auto_activation' => true,
                'is_premium'                     => false,
                'premium_suffix'                 => 'Pro',
                'has_addons'                     => false,
                'has_paid_plans'                 => true,
                'trial'                          => array(
                    'days'               => 14,
                    'is_require_payment' => false,
                ),
                'is_org_compliant'               => true,
                'menu'                           => array(
                    'slug'       => 'tranzly',
                    'first-path' => 'admin.php?page=tranzly',
                    'contact'    => false,
                    'support'    => false,
                ),
                'is_live'                        => true,
            ) );
        }
        return $tranzly_fs;
    }

    tranzly_fs();
    tranzly_fs()->add_action( 'after_uninstall', 'tranzly_uninstall' );
    // The SDK's screens show THIS icon (the WordPress.org one, wp/dotorg-assets/tranzly). Without a
    // local icon the SDK downloads one from the licensing service on a local install, before any
    // consent (wp/tests/e2e/tranzly/no-http-before-consent.sh).
    tranzly_fs()->add_filter( 'plugin_icon', static fn() => __DIR__ . '/assets/icon-256x256.png' );
    do_action( 'tranzly_fs_loaded' );
}
require_once __DIR__ . '/includes/bootstrap.php';