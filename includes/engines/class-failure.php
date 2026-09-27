<?php
/**
 * Why an engine failed, in plain English, with what the site owner can do about it.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Engines;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The failed-translation report (tz-r2) and the fallback chain (tz-e5) both read the CLASS.
 *
 * ⭐ CLAUDE.md §2.57: the site owner chose the engine and holds its key, so a key or billing problem
 * names the vendor and links the place to fix it, and an outage links the vendor's status page. A
 * failure that cannot be classified says so — it is never reported as the vendor's outage.
 */
final class Failure {

	/** The service is down or answered with a server error. */
	public const OUTAGE = 'outage';

	/** The service could not be reached at all. */
	public const UNREACHABLE = 'unreachable';

	/** The key is missing, wrong or revoked. */
	public const CREDENTIAL = 'credential';

	/** The account's quota or balance is used up. */
	public const QUOTA = 'quota';

	/** Too many requests; try again shortly. */
	public const RATE_LIMITED = 'rate_limited';

	/** The text is too long for one request. */
	public const TOO_LONG = 'too_long';

	/** The engine refused this content. */
	public const REFUSED = 'refused';

	/** The engine cannot translate into (or from) this language. */
	public const UNSUPPORTED = 'unsupported_language';

	/** Tranzly stopped it: this month's spending cap is reached. */
	public const CAP = 'monthly_cap';

	/** Anything else. */
	public const UNKNOWN = 'unknown';

	/** Classes worth trying the next engine for (every one; a later engine may succeed). */
	public const RETRYABLE = array( self::OUTAGE, self::UNREACHABLE, self::RATE_LIMITED );

	/**
	 * A failure as a WP_Error.
	 *
	 * @param string $kind   One of the constants.
	 * @param Engine $engine The engine.
	 * @param string $detail What the service said, for the report (shown as detail, never as the
	 *                       headline).
	 * @param string $link   Where to fix it (billing / keys page, or status page for an outage).
	 * @return \WP_Error
	 */
	public static function make( string $kind, Engine $engine, string $detail = '', string $link = '' ): \WP_Error {
		return new \WP_Error(
			'tranzly_engine_failed',
			self::message( $kind, $engine->label() ),
			array(
				'status'    => self::CAP === $kind ? 402 : 502,
				'class'     => $kind,
				'engine'    => $engine->id(),
				'link'      => $link,
				'detail'    => mb_substr( wp_strip_all_tags( $detail ), 0, 300 ),
				'retryable' => in_array( $kind, self::RETRYABLE, true ),
			)
		);
	}

	/**
	 * The headline for a class.
	 *
	 * @param string $kind A class.
	 * @param string $name The engine's name.
	 * @return string
	 */
	public static function message( string $kind, string $name ): string {
		switch ( $kind ) {
			case self::OUTAGE:
				/* translators: %s: a translation service's name. */
				return sprintf( __( '%s is having problems right now. Try again shortly, or use another engine.', 'tranzly' ), $name );
			case self::UNREACHABLE:
				/* translators: %s: a translation service's name. */
				return sprintf( __( 'Your site could not reach %s. Check that it can make outgoing connections, then try again.', 'tranzly' ), $name );
			case self::CREDENTIAL:
				/* translators: %s: a translation service's name. */
				return sprintf( __( '%s did not accept your API key. Check the key in Tranzly → Engines.', 'tranzly' ), $name );
			case self::QUOTA:
				/* translators: %s: a translation service's name. */
				return sprintf( __( 'Your %s account has used up its quota or balance. Top it up with them, or use another engine.', 'tranzly' ), $name );
			case self::RATE_LIMITED:
				/* translators: %s: a translation service's name. */
				return sprintf( __( '%s asked us to slow down. It will be tried again automatically.', 'tranzly' ), $name );
			case self::TOO_LONG:
				/* translators: %s: a translation service's name. */
				return sprintf( __( 'This text is too long for %s in one go. Split the content, or use another engine.', 'tranzly' ), $name );
			case self::REFUSED:
				/* translators: %s: a translation service's name. */
				return sprintf( __( '%s refused to translate this content. Try another engine, or translate it by hand.', 'tranzly' ), $name );
			case self::UNSUPPORTED:
				/* translators: %s: a translation service's name. */
				return sprintf( __( '%s does not translate this language. Choose another engine for it.', 'tranzly' ), $name );
			case self::CAP:
				/* translators: %s: a translation service's name. */
				return sprintf( __( 'This month\'s spending cap for %s is reached, so nothing more was sent to it. Raise the cap in Tranzly → Engines, or wait until next month.', 'tranzly' ), $name );
			default:
				/* translators: %s: a translation service's name. */
				return sprintf( __( 'The translation with %s failed for a reason Tranzly could not identify. The details are below.', 'tranzly' ), $name );
		}
	}

	/**
	 * The class of a WP_Error an engine returned (unknown when it is not one of ours).
	 *
	 * @param \WP_Error $error The error.
	 * @return string
	 */
	public static function class_of( \WP_Error $error ): string {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['class'] ) ? (string) $data['class'] : self::UNKNOWN;
	}
}
