<?php
/**
 * Which engine translates which language, what happens when it fails, and what it may spend.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

use ZinnDigital\Tranzly\Engines\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One option, `tranzly_engines`:
 *
 * - `default`: the engine used for every language (free and Pro);
 * - `per_lang`: language => engine (Pro, tz-e4: DeepL for German, an AI model for Japanese);
 * - `fallback`: engines tried in order when the first one fails or hits a limit (Pro, tz-e5);
 * - `caps`: engine => monthly budget in US dollars (Pro, tz-r12). Spend is counted from each
 *   engine's own estimate at the moment of the call, per calendar month (UTC).
 */
final class Engine_Settings {

	/** The option. */
	public const OPTION = 'tranzly_engines';

	/** Spend per month: `YYYY-MM` => engine => dollars. */
	public const SPEND = 'tranzly_engine_spend';

	/**
	 * The settings over their defaults.
	 *
	 * @return array{default: string, per_lang: array<string, string>, fallback: array<int, string>, caps: array<string, float>}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'default'  => is_string( $stored['default'] ?? null ) ? $stored['default'] : '',
			'per_lang' => array_map( 'strval', is_array( $stored['per_lang'] ?? null ) ? $stored['per_lang'] : array() ),
			'fallback' => array_values( array_map( 'strval', is_array( $stored['fallback'] ?? null ) ? $stored['fallback'] : array() ) ),
			'caps'     => array_map( 'floatval', is_array( $stored['caps'] ?? null ) ? $stored['caps'] : array() ),
		);
	}

	/**
	 * Save. Engines must exist; Pro-only fields are refused without Pro rather than stored unused.
	 *
	 * @param array<string, mixed> $input Any of the four keys.
	 * @return true|\WP_Error
	 */
	public static function save( array $input ) {
		$current = self::get();
		$known   = array_keys( Registry::instance()->all() );
		$engine  = static fn( $id ) => is_string( $id ) && in_array( $id, $known, true );

		if ( array_key_exists( 'default', $input ) ) {
			if ( '' !== $input['default'] && ! $engine( $input['default'] ) ) {
				return self::refuse( __( 'That translation engine is not available on this site.', 'tranzly' ) );
			}
			$current['default'] = (string) $input['default'];
		}
		foreach ( array( 'per_lang', 'fallback', 'caps' ) as $pro_key ) {
			if ( array_key_exists( $pro_key, $input ) && ! Edition::pro() && array() !== (array) $input[ $pro_key ] ) {
				return self::refuse( __( 'Different engines per language, automatic fallback and spending caps are Tranzly Pro features.', 'tranzly' ), 403 );
			}
		}
		if ( array_key_exists( 'per_lang', $input ) ) {
			$map = array();
			foreach ( (array) $input['per_lang'] as $lang => $id ) {
				if ( null === \ZinnDigital\Tranzly\Languages::resolve( (string) $lang ) || ! $engine( $id ) ) {
					return self::refuse( __( 'Each language must be one of the site\'s languages, with an available engine.', 'tranzly' ) );
				}
				$map[ (string) \ZinnDigital\Tranzly\Languages::resolve( (string) $lang ) ] = (string) $id;
			}
			$current['per_lang'] = $map;
		}
		if ( array_key_exists( 'fallback', $input ) ) {
			$list = array_values( (array) $input['fallback'] );
			foreach ( $list as $id ) {
				if ( ! $engine( $id ) ) {
					return self::refuse( __( 'The fallback list may only name available engines.', 'tranzly' ) );
				}
			}
			$current['fallback'] = array_values( array_unique( array_map( 'strval', $list ) ) );
		}
		if ( array_key_exists( 'caps', $input ) ) {
			$caps = array();
			foreach ( (array) $input['caps'] as $id => $usd ) {
				if ( ! $engine( $id ) || ! is_numeric( $usd ) || (float) $usd < 0 ) {
					return self::refuse( __( 'A monthly cap is an amount in US dollars, zero or more, for an available engine.', 'tranzly' ) );
				}
				$caps[ (string) $id ] = round( (float) $usd, 2 );
			}
			$current['caps'] = $caps;
		}
		update_option( self::OPTION, $current, true );

		return true;
	}

	/**
	 * The engines to try for a language, in order. Free: the default engine. Pro: the language's
	 * own engine (else the default), then the fallback list.
	 *
	 * @param string $lang Target language.
	 * @return array<int, string>
	 */
	public static function chain( string $lang ): array {
		$settings = self::get();
		$first    = $settings['default'];
		if ( '' === $first ) {
			$default = Registry::instance()->default_engine();
			$first   = null === $default ? '' : $default->id();
		}
		if ( ! Edition::pro() ) {
			return '' === $first ? array() : array( $first );
		}
		$first = $settings['per_lang'][ $lang ] ?? $first;

		return array_values( array_unique( array_filter( array_merge( array( $first ), $settings['fallback'] ) ) ) );
	}

	/**
	 * The monthly cap for an engine, or null (none; always null without Pro).
	 *
	 * @param string $engine Engine id.
	 * @return float|null
	 */
	public static function cap( string $engine ): ?float {
		if ( ! Edition::pro() ) {
			return null;
		}

		return self::get()['caps'][ $engine ] ?? null;
	}

	/**
	 * Dollars an engine has spent this month.
	 *
	 * @param string $engine Engine id.
	 * @return float
	 */
	public static function spent( string $engine ): float {
		$all = get_option( self::SPEND, array() );

		return (float) ( is_array( $all ) ? ( $all[ gmdate( 'Y-m' ) ][ $engine ] ?? 0 ) : 0 );
	}

	/**
	 * Would spending `$usd` more take the engine past its cap?
	 *
	 * @param string $engine Engine id.
	 * @param float  $usd    The next call's estimate.
	 * @return bool
	 */
	public static function over_cap( string $engine, float $usd ): bool {
		$cap = self::cap( $engine );

		return null !== $cap && self::spent( $engine ) + $usd > $cap;
	}

	/**
	 * Record spend. Keeps thirteen months.
	 *
	 * @param string $engine Engine id.
	 * @param float  $usd    Dollars.
	 * @return void
	 */
	public static function add_spend( string $engine, float $usd ): void {
		if ( $usd <= 0 ) {
			return;
		}
		$all   = get_option( self::SPEND, array() );
		$all   = is_array( $all ) ? $all : array();
		$month = gmdate( 'Y-m' );

		$all[ $month ][ $engine ] = round( (float) ( $all[ $month ][ $engine ] ?? 0 ) + $usd, 6 );
		krsort( $all );
		update_option( self::SPEND, array_slice( $all, 0, 13, true ), false );
	}

	/**
	 * A refusal.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private static function refuse( string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'tranzly_invalid_engines', $message, array( 'status' => $status ) );
	}
}
