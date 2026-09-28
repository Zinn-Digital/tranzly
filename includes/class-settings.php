<?php
/**
 * Stored settings: the front-end class prefix and the site's languages.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's one option row, and the rules for what it may contain.
 */
final class Settings {

	/** The option name. */
	public const OPTION = 'tranzly_settings';

	/** The prefix every front-end class name, handle and asset folder starts with. */
	public const DEFAULT_PREFIX = 'zd';

	/**
	 * Words a prefix may not contain, because each one would put a plugin's identity back into
	 * the front-end HTML that F5 exists to keep neutral (CONTRACT §7).
	 */
	private const FOOTPRINT_WORDS = array( 'pbs', 'sandwich', 'tranzly', 'builder' );

	/**
	 * The stored settings merged over the defaults.
	 *
	 * @return array{prefix: string, languages: array<int, array{code: string, name: string}>}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$languages = self::clean_languages( (array) ( $stored['languages'] ?? array() ) );
		if ( array() === $languages ) {
			$languages = array( self::language_entry( self::site_locale(), '' ) );
		}

		return array(
			'prefix'    => self::is_valid_prefix( (string) ( $stored['prefix'] ?? '' ) )
				? (string) $stored['prefix']
				: self::DEFAULT_PREFIX,
			'languages' => $languages,
		);
	}

	/**
	 * Save the settings. Anything invalid is refused rather than coerced.
	 *
	 * @param array<string, mixed> $input The new values.
	 * @return true|\WP_Error
	 */
	public static function save( array $input ) {
		$current = self::get();

		if ( array_key_exists( 'prefix', $input ) ) {
			$prefix = strtolower( trim( (string) $input['prefix'] ) );
			if ( ! self::is_valid_prefix( $prefix ) ) {
				return new \WP_Error(
					'tranzly_invalid_prefix',
					__( 'The prefix must be 1 to 8 lowercase letters or digits, start with a letter, and must not name the plugin.', 'tranzly' ),
					array( 'status' => 400 )
				);
			}
			$current['prefix'] = $prefix;
		}

		if ( array_key_exists( 'languages', $input ) ) {
			$raw = is_array( $input['languages'] ) ? $input['languages'] : array();
			foreach ( $raw as $entry ) {
				$code = is_array( $entry ) ? (string) ( $entry['code'] ?? '' ) : '';
				if ( ! self::is_valid_code( $code ) ) {
					return new \WP_Error(
						'tranzly_invalid_language',
						/* translators: %s: the language code that was refused. */
						sprintf( __( '"%s" is not a language code. Use a WordPress locale such as fr_FR or de_DE.', 'tranzly' ), $code ),
						array( 'status' => 400 )
					);
				}
			}
			$languages = self::clean_languages( $raw );
			if ( array() === $languages ) {
				return new \WP_Error(
					'tranzly_no_languages',
					__( 'List at least one language.', 'tranzly' ),
					array( 'status' => 400 )
				);
			}
			$current['languages'] = $languages;
		}

		update_option( self::OPTION, $current, true );
		// The address settings give every language its folder from this list and remember the
		// answer for the request; a language added now must have its folder now (lane L06: an
		// import that adds a language and then builds links read "Undefined array key").
		if ( class_exists( '\ZinnDigital\Tranzly\Seo\Url_Settings', false ) ) {
			\ZinnDigital\Tranzly\Seo\Url_Settings::reset();
		}

		return true;
	}

	/**
	 * The prefix in effect for this request: stored value, then the `tranzly_frontend_prefix`
	 * filter, then validation.
	 *
	 * @return string
	 */
	public static function prefix(): string {
		/**
		 * Filters the neutral front-end class prefix.
		 *
		 * @param string $prefix The stored prefix, `zd` by default.
		 */
		$prefix = (string) apply_filters( 'tranzly_frontend_prefix', self::get()['prefix'] );

		return self::is_valid_prefix( $prefix ) ? $prefix : self::DEFAULT_PREFIX;
	}

	/**
	 * Is this a usable prefix? One lowercase letter followed by up to seven lowercase letters or
	 * digits, and none of the plugins' own names inside it.
	 *
	 * @param string $prefix Candidate prefix.
	 * @return bool
	 */
	public static function is_valid_prefix( string $prefix ): bool {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9]{0,7}$/', $prefix ) ) {
			return false;
		}
		foreach ( self::FOOTPRINT_WORDS as $word ) {
			if ( str_contains( $prefix, $word ) ) {
				return false;
			}
		}
		return true;
	}

	/*
	 * ⭐ No cap on the number of languages, in free or Pro (tz-f7, owner-approved). The 3.0.0
	 * scaffold refused more than 100; T1 removed that limit.
	 */

	/**
	 * Is this a WordPress-style locale code (`fr`, `fr_FR`, `pt_BR_formal`, `de_CH_informal`)?
	 *
	 * @param string $code Candidate code.
	 * @return bool
	 */
	public static function is_valid_code( string $code ): bool {
		return 1 === preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2}(?:_[a-z0-9]{2,12})?)?$/', $code );
	}

	/**
	 * The site's own locale.
	 *
	 * @return string
	 */
	public static function site_locale(): string {
		$locale = (string) get_locale();

		return self::is_valid_code( $locale ) ? $locale : 'en_US';
	}

	/**
	 * Valid, de-duplicated language entries, in the order given.
	 *
	 * @param array<int|string, mixed> $raw Entries with `code` and optional `name`.
	 * @return array<int, array{code: string, name: string}>
	 */
	private static function clean_languages( array $raw ): array {
		$out  = array();
		$seen = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$code = (string) ( $entry['code'] ?? '' );
			if ( ! self::is_valid_code( $code ) || isset( $seen[ strtolower( $code ) ] ) ) {
				continue;
			}
			$seen[ strtolower( $code ) ] = true;
			$out[]                       = self::language_entry( $code, (string) ( $entry['name'] ?? '' ) );
		}

		return $out;
	}

	/**
	 * One language entry. A missing name is filled with the language's own name for itself when
	 * PHP's intl extension is available, and with the code otherwise.
	 *
	 * @param string $code A valid code.
	 * @param string $name The stored name, possibly empty.
	 * @return array{code: string, name: string}
	 */
	private static function language_entry( string $code, string $name ): array {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name && class_exists( '\Locale' ) ) {
			$name = (string) \Locale::getDisplayLanguage( $code, $code );
		}

		return array(
			'code' => $code,
			'name' => '' === $name ? $code : $name,
		);
	}
}
