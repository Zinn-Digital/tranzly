<?php
/**
 * Display options the core owns: the language switcher's placement and the credit link.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One option row, `tranzly_display`, read by the switchers (T5).
 *
 * ⛔ `credit` is the legacy "AI translated by Tranzly" link that the old plugin printed on every
 * translated page. It is OFF by default and the legacy import leaves it off whatever the old
 * setting was: it put the plugin's name in every visitor's HTML, which the footprint-free rule
 * (F5, owner decision D11) forbids by default.
 */
final class Options {

	/** The option name. */
	public const OPTION = 'tranzly_display';

	/** Where the automatic switcher goes: nowhere, or before / after the post content. */
	public const POSITIONS = array( 'none', 'before', 'after' );

	/** How the automatic switcher shows each language. */
	public const STYLES = array( 'names', 'flags' );

	/** Where the floating switcher sits ('' = off), in logical corners (T5, tz-l5). */
	public const FLOATING = array( '', 'bottom-end', 'bottom-start', 'top-end', 'top-start' );

	/** How the floating switcher names each language. */
	public const DISPLAYS = array( 'name', 'code', 'name_code' );

	/**
	 * The stored options over the defaults.
	 *
	 * @return array{switcher: array{position: string, style: string, new_tab: bool, floating: string, display: string}, credit: bool}
	 */
	public static function get(): array {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$switcher = is_array( $stored['switcher'] ?? null ) ? $stored['switcher'] : array();

		return array(
			'switcher' => array(
				'position' => in_array( $switcher['position'] ?? '', self::POSITIONS, true ) ? (string) $switcher['position'] : 'none',
				'style'    => in_array( $switcher['style'] ?? '', self::STYLES, true ) ? (string) $switcher['style'] : 'names',
				'new_tab'  => true === ( $switcher['new_tab'] ?? false ),
				'floating' => in_array( $switcher['floating'] ?? null, self::FLOATING, true ) ? (string) $switcher['floating'] : '',
				'display'  => in_array( $switcher['display'] ?? '', self::DISPLAYS, true ) ? (string) $switcher['display'] : 'name',
			),
			'credit'   => true === ( $stored['credit'] ?? false ),
		);
	}

	/**
	 * Save, refusing anything that is not one of the allowed values (never coerced to text: the
	 * legacy settings screen ran every field, checkboxes included, through sanitize_text_field()).
	 *
	 * @param array<string, mixed> $input `switcher` (position/style/new_tab) and/or `credit`.
	 * @return true|\WP_Error
	 */
	public static function save( array $input ) {
		$current = self::get();
		if ( array_key_exists( 'switcher', $input ) ) {
			if ( ! is_array( $input['switcher'] ) ) {
				return self::refuse( 'switcher' );
			}
			foreach ( $input['switcher'] as $key => $value ) {
				if ( 'position' === $key && in_array( $value, self::POSITIONS, true ) ) {
					$current['switcher']['position'] = $value;
				} elseif ( 'style' === $key && in_array( $value, self::STYLES, true ) ) {
					$current['switcher']['style'] = $value;
				} elseif ( 'new_tab' === $key && is_bool( $value ) ) {
					$current['switcher']['new_tab'] = $value;
				} elseif ( 'floating' === $key && in_array( $value, self::FLOATING, true ) ) {
					$current['switcher']['floating'] = $value;
				} elseif ( 'display' === $key && in_array( $value, self::DISPLAYS, true ) ) {
					$current['switcher']['display'] = $value;
				} else {
					return self::refuse( 'switcher.' . $key );
				}
			}
		}
		if ( array_key_exists( 'credit', $input ) ) {
			if ( ! is_bool( $input['credit'] ) ) {
				return self::refuse( 'credit' );
			}
			$current['credit'] = $input['credit'];
		}
		update_option( self::OPTION, $current, true );

		return true;
	}

	/**
	 * The refusal for one field.
	 *
	 * @param string $field The field name.
	 * @return \WP_Error
	 */
	private static function refuse( string $field ): \WP_Error {
		return new \WP_Error(
			'tranzly_invalid_option',
			/* translators: %s: a settings field name. */
			sprintf( __( 'The value for %s is not one of the allowed choices.', 'tranzly' ), $field ),
			array( 'status' => 400 )
		);
	}
}
