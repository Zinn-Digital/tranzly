<?php
/**
 * Carries a legacy (1.x/2.x) "Tranzly Language Switcher" widget placement over to the switcher
 * widget of this version.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Switcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ Why (TRZ-ASSETS, 2026-09-30): Tranzly 1.0.1-2.0.0 registered its widget as
 * `tranzly_language_switcher`; this version registers `lang_switcher`. WordPress renders a
 * sidebar entry only for a registered widget type, so every legacy placement silently vanished
 * from the site on upgrade.
 *
 * The new widget is added BESIDE the legacy entry, never instead of it, and the legacy instance
 * option is left as it was: this version does not register the legacy type, so it renders one
 * switcher; and a site rolled back to the legacy plugin (the checked auto-update's restore, or a
 * person) renders its old widget and ignores the new one. Runs once per site.
 */
final class Legacy_Widgets {

	/** The legacy widget type. */
	public const LEGACY_BASE = 'tranzly_language_switcher';

	/** This version's widget type (Widget::__construct()). */
	public const BASE = 'lang_switcher';

	/** Autoloaded marker: the carry-over ran on this site. */
	public const DONE_OPTION = 'tranzly_legacy_widgets';

	/**
	 * `plugins_loaded`: once per site, costing one autoloaded read afterwards.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		if ( false !== get_option( self::DONE_OPTION, false ) ) {
			return;
		}
		$done = self::migrate(
			(array) get_option( 'sidebars_widgets', array() ),
			(array) get_option( 'widget_' . self::LEGACY_BASE, array() ),
			(array) get_option( 'widget_' . self::BASE, array( '_multiwidget' => 1 ) )
		);
		if ( null !== $done ) {
			update_option( 'widget_' . self::BASE, $done['instances'], true );
			update_option( 'sidebars_widgets', $done['sidebars'] );
		}
		update_option( self::DONE_OPTION, '1', true );
	}

	/**
	 * Pure: the new sidebars and `lang_switcher` instances, or null when there is nothing to carry.
	 *
	 * @param array<string, mixed>     $sidebars  `sidebars_widgets`.
	 * @param array<int|string, mixed> $legacy    `widget_tranzly_language_switcher`.
	 * @param array<int|string, mixed> $instances `widget_lang_switcher`.
	 * @return array{sidebars: array<string, mixed>, instances: array<int|string, mixed>}|null
	 */
	public static function migrate( array $sidebars, array $legacy, array $instances ): ?array {
		$changed = false;
		$next    = 1 + max( array_merge( array( 1 ), array_filter( array_keys( $instances ), 'is_int' ) ) );
		foreach ( $sidebars as $sidebar => $ids ) {
			if ( ! is_array( $ids ) || 'wp_inactive_widgets' === $sidebar ) {
				continue;
			}
			$out = array();
			foreach ( $ids as $id ) {
				$out[] = $id;
				if ( ! is_string( $id ) || 1 !== preg_match( '/^' . self::LEGACY_BASE . '-(\d+)$/', $id, $m ) ) {
					continue;
				}
				$old                = is_array( $legacy[ (int) $m[1] ] ?? null ) ? $legacy[ (int) $m[1] ] : array();
				$instances[ $next ] = array(
					// The legacy widget was a flag dropdown; the closest design here, same title.
					'title'   => sanitize_text_field( (string) ( $old['title'] ?? '' ) ),
					'style'   => 'dropdown',
					'display' => 'name',
					'flags'   => true,
				);
				$out[]              = self::BASE . '-' . $next;
				++$next;
				$changed = true;
			}
			$sidebars[ $sidebar ] = $out;
		}
		if ( ! $changed ) {
			return null;
		}
		$instances['_multiwidget'] = 1;

		return array(
			'sidebars'  => $sidebars,
			'instances' => $instances,
		);
	}
}
