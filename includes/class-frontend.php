<?php
/**
 * The footprint-free front-end output layer (F5).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds every class name the plugin prints on the front end.
 *
 * ⛔ The rules (CONTRACT §7, docs/adr/0033): no plugin name, slug, `pbs` or `tranzly` in a
 * front-end class, handle, asset path, HTML comment or generator tag. Every class comes from
 * this one function so the rule has exactly one place to be kept — and one place for the
 * rendered-HTML gate to catch it being broken. wp-admin stays branded.
 */
final class Frontend {

	/**
	 * Prefixed class names: `cls( 'fx', 'fx--accent' )` gives `zd-fx zd-fx--accent`.
	 *
	 * @param string ...$parts Class suffixes.
	 * @return string
	 */
	public static function cls( string ...$parts ): string {
		return self::classes( Settings::prefix(), ...$parts );
	}

	/**
	 * Prefixed class names for an explicit prefix (the pure half of self::cls()).
	 *
	 * @param string $prefix   The class prefix.
	 * @param string ...$parts Class suffixes.
	 * @return string
	 */
	public static function classes( string $prefix, string ...$parts ): string {
		$out = array();
		foreach ( $parts as $part ) {
			$part = sanitize_html_class( $part );
			if ( '' !== $part ) {
				$out[] = $prefix . '-' . $part;
			}
		}

		return implode( ' ', $out );
	}

	/**
	 * A ` lang="…"` attribute for a WordPress locale code, or an empty string.
	 *
	 * @param string|null $locale A locale such as `fr_FR`.
	 * @return string
	 */
	public static function lang_attr( ?string $locale ): string {
		if ( null === $locale || '' === $locale ) {
			return '';
		}

		return ' lang="' . esc_attr( str_replace( '_', '-', $locale ) ) . '"';
	}
}
