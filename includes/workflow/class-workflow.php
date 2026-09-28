<?php
/**
 * T7: the translation workflow. The module's loader.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Workflow;

use ZinnDigital\Tranzly\Core\Edition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires T7. Free: the translation status dashboard with its one-click fixes (tz-w9) and the
 * out-of-date tracking it needs. Pro (premium package + licence): whole-site bulk translation
 * (tz-w2), auto-translate or mark out of date on update (tz-w3), translator and reviewer roles
 * (tz-w6), XLIFF/CSV exchange (tz-w7), switching from WPML, Polylang or TranslatePress (tz-w8)
 * and the AI quality check (tz-r6).
 */
final class Workflow {

	/** Option holding the workflow settings. */
	public const OPTION = 'tranzly_workflow';

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		Staleness::register();
		Status::register();

		$pro = __DIR__ . '/pro_' . '_premium_only'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- the free package must not carry the premium token (CONTRACT §3).
		if ( is_readable( $pro . '/class-settings.php' ) && Edition::pro() ) {
			foreach ( array( 'settings', 'bulk', 'auto-translate', 'review', 'review-roles', 'xliff', 'exchange', 'import-source', 'import-polylang', 'import-wpml', 'import-translatepress', 'importers', 'quality', 'pro-rest' ) as $file ) {
				require_once $pro . '/class-' . $file . '.php';
			}
			Pro\Auto_Translate::register();
			Pro\Review::register();
			Pro\Exchange::register();
			Pro\Importers::register();
			Pro\Quality::register();
			Pro\Pro_Rest::register();
		}
	}

	/**
	 * The workflow settings, merged over the defaults. Stored for every edition so a site that
	 * lets its licence lapse keeps its choices for when it renews.
	 *
	 * @return array{on_update: string, on_publish: bool, review: bool, quality_threshold: int, quality_provider: string, quality_model: string}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'on_update'         => in_array( $stored['on_update'] ?? '', array( 'off', 'stale', 'translate' ), true ) ? (string) $stored['on_update'] : 'stale',
			'on_publish'        => ! empty( $stored['on_publish'] ),
			'review'            => ! empty( $stored['review'] ),
			'quality_threshold' => max( 0, min( 100, (int) ( $stored['quality_threshold'] ?? 80 ) ) ),
			'quality_provider'  => sanitize_key( (string) ( $stored['quality_provider'] ?? '' ) ),
			'quality_model'     => sanitize_text_field( (string) ( $stored['quality_model'] ?? '' ) ),
		);
	}
}
