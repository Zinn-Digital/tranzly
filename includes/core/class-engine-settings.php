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
		$pro_keys = array_filter(
			array( 'per_lang', 'fallback', 'caps' ),
			static fn( string $k ): bool => array_key_exists( $k, $input )
		);
		if ( array() !== $pro_keys ) {
			/**
			 * Saves the per-language engines, the fallback list and the monthly caps. Only the
			 * premium layer answers (WordPress.org guideline 5: no Pro behaviour in the free plugin).
			 *
			 * @param array<string, mixed>|\WP_Error|null $saved   The settings with those keys applied,
			 *                                                     a refusal, or null (nobody handled them).
			 * @param array<string, mixed>                 $input   What was submitted.
			 * @param array<string, mixed>                 $current The settings so far.
			 */
			$saved = apply_filters( 'tranzly_engine_settings_save', null, $input, $current );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			if ( is_array( $saved ) ) {
				$current = $saved;
			} else {
				foreach ( $pro_keys as $pro_key ) {
					if ( array() !== (array) $input[ $pro_key ] ) {
						return self::refuse( __( 'Different engines per language, automatic fallback and spending caps are Tranzly Pro features.', 'tranzly' ), 403 );
					}
				}
			}
		}
		update_option( self::OPTION, $current, true );

		return true;
	}

	/**
	 * The engines to try for a language, in order: the default engine, unless the premium layer
	 * extends the chain (`tranzly_engine_chain`).
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
		$chain = '' === $first ? array() : array( $first );

		/**
		 * Filters the engines to try for a language, in order. Free: the default engine. The
		 * premium layer adds the language's own engine and the fallback list.
		 *
		 * @param array<int, string>   $chain    Engine ids.
		 * @param string               $lang     Target language.
		 * @param string               $first    The default engine.
		 * @param array<string, mixed> $settings The stored settings.
		 */
		return array_values( (array) apply_filters( 'tranzly_engine_chain', $chain, $lang, $first, $settings ) );
	}

	/**
	 * The monthly cap for an engine, or null (none unless the premium layer sets one).
	 *
	 * @param string $engine Engine id.
	 * @return float|null
	 */
	public static function cap( string $engine ): ?float {
		/**
		 * Filters an engine's monthly cap in US dollars: none in the free plugin.
		 *
		 * @param float|null $cap    The cap, or null.
		 * @param string     $engine Engine id.
		 */
		$cap = apply_filters( 'tranzly_engine_cap', null, $engine );

		return is_numeric( $cap ) ? (float) $cap : null;
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
