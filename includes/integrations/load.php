<?php
/**
 * Loads the integrations module (T6). The Pro integrations load from Integrations::all() in the
 * premium package only.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-tree.php';
require_once __DIR__ . '/class-shortcodes.php';
require_once __DIR__ . '/class-placeholders.php';
require_once __DIR__ . '/class-text.php';
require_once __DIR__ . '/class-integration.php';
require_once __DIR__ . '/class-nested-blocks.php';
require_once __DIR__ . '/class-pbs.php';
require_once __DIR__ . '/class-integrations.php';
