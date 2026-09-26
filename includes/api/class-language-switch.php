<?php
/**
 * Switching the current language in code, and back.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Api;

use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A stack, like switch_to_blog(): every switch is undone by one restore, and nested switches unwind
 * in order. WordPress's own translations follow (switch_to_locale), so `__()` in the switched
 * block returns that language's strings.
 */
final class Language_Switch {

	/**
	 * Switched languages, innermost last.
	 *
	 * @var array<int, string>
	 */
	private static array $stack = array();

	/**
	 * Hook the override.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'tranzly_pre_current_language', array( self::class, 'filter_current' ) );
	}

	/**
	 * `tranzly_pre_current_language`.
	 *
	 * @param string|null $lang Incoming value.
	 * @return string|null
	 */
	public static function filter_current( $lang ) {
		return array() === self::$stack ? $lang : end( self::$stack );
	}

	/**
	 * Switch to a language.
	 *
	 * @param string $lang A listed language.
	 * @return bool False when it is not a listed language (nothing changes).
	 */
	public static function switch_to( string $lang ): bool {
		$code = Languages::resolve( $lang );
		if ( null === $code ) {
			return false;
		}
		self::$stack[] = $code;
		switch_to_locale( $code );

		return true;
	}

	/**
	 * Undo the last switch.
	 *
	 * @return bool False when nothing was switched.
	 */
	public static function restore(): bool {
		if ( array() === self::$stack ) {
			return false;
		}
		array_pop( self::$stack );
		restore_previous_locale();

		return true;
	}
}
