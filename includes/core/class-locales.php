<?php
/**
 * Facts about language codes: text direction, and how the legacy plugin's codes map to WordPress
 * locales.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure functions over language codes; nothing here reads the database, so the tests run it as is.
 *
 * ⭐ The language list itself has NO cap (tz-f7): any valid WordPress locale may be listed, in any
 * number. This class only knows facts ABOUT codes.
 */
final class Locales {

	/** Primary languages written right to left (WordPress core's own list, plus Kurdish Sorani). */
	private const RTL = array( 'ar', 'arc', 'ckb', 'dv', 'fa', 'ha_ng', 'he', 'khw', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi' );

	/**
	 * The locale each legacy code meant when no listed language matches it. The legacy plugin
	 * stored DeepL's own upper-case codes (`EN`, `DE`, …) from a fixed list of nine.
	 */
	private const LEGACY_DEFAULTS = array(
		'EN' => 'en_US',
		'DE' => 'de_DE',
		'FR' => 'fr_FR',
		'ES' => 'es_ES',
		'PT' => 'pt_PT',
		'IT' => 'it_IT',
		'NL' => 'nl_NL',
		'PL' => 'pl_PL',
		'RU' => 'ru_RU',
		'JA' => 'ja',
		'ZH' => 'zh_CN',
	);

	/**
	 * Is the language written right to left?
	 *
	 * @param string $code A WordPress locale.
	 * @return bool
	 */
	public static function is_rtl( string $code ): bool {
		$lower = strtolower( $code );
		foreach ( self::RTL as $rtl ) {
			if ( $lower === $rtl || str_starts_with( $lower, $rtl . '_' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The primary language subtag (`fr` for `fr_FR`).
	 *
	 * @param string $code A locale.
	 * @return string
	 */
	public static function primary( string $code ): string {
		return strtolower( (string) preg_replace( '/[_-].*$/', '', $code ) );
	}

	/**
	 * The WordPress locale a legacy code becomes on THIS site: the site's own listed language with
	 * the same primary subtag when there is one (a Brazilian site's `PT` is `pt_BR`), the site
	 * locale when it matches, otherwise the conventional locale. Null for a code that is not a
	 * language code at all (the legacy plugin stored whatever the request carried).
	 *
	 * @param string             $legacy      e.g. `DE`, `pt-br`.
	 * @param array<int, string> $listed      The site's listed codes, default first.
	 * @param string             $site_locale The site locale.
	 * @return string|null
	 */
	public static function from_legacy( string $legacy, array $listed, string $site_locale ): ?string {
		$legacy = trim( $legacy );
		if ( 1 !== preg_match( '/^[A-Za-z]{2,3}(?:[-_][A-Za-z]{2,4})?$/', $legacy ) ) {
			return null;
		}
		$primary = self::primary( $legacy );
		$region  = str_contains( $legacy, '-' ) || str_contains( $legacy, '_' ) ? strtoupper( (string) substr( (string) strpbrk( $legacy, '-_' ), 1 ) ) : '';

		// A code that names its region means that region: en-GB is not en_US.
		if ( '' !== $region ) {
			foreach ( array_merge( $listed, array( $site_locale ) ) as $code ) {
				if ( strtolower( $code ) === $primary . '_' . strtolower( $region ) ) {
					return $code;
				}
			}
			return 2 === strlen( $region ) ? $primary . '_' . $region : null;
		}
		foreach ( array_merge( $listed, array( $site_locale ) ) as $code ) {
			if ( self::primary( $code ) === $primary ) {
				return $code;
			}
		}

		return self::LEGACY_DEFAULTS[ strtoupper( $primary ) ] ?? $primary;
	}
}
