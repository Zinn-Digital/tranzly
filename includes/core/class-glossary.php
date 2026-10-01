<?php
/**
 * Words never translated, preferred translations, and the tone per language.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One option, `tranzly_glossary`:
 *
 * - `dnt`: words that are never translated — brand and product names (free, tz-r4);
 * - `terms`: language => source term => preferred translation (Pro, tz-e8), used by EVERY engine,
 *   AI models included, not only DeepL;
 * - `tone`: language => `formality` (`default`, `more`, `less`) and free-text `instructions`
 *   (audience, brand voice) (Pro, tz-e9).
 */
final class Glossary {

	/** The option. */
	public const OPTION = 'tranzly_glossary';

	/** Formality values. */
	public const FORMALITY = array( 'default', 'more', 'less' );

	/**
	 * The stored glossary.
	 *
	 * @return array{dnt: array<int, string>, terms: array<string, array<string, string>>, tone: array<string, array{formality: string, instructions: string}>}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'dnt'   => array_values( array_map( 'strval', is_array( $stored['dnt'] ?? null ) ? $stored['dnt'] : array() ) ),
			'terms' => is_array( $stored['terms'] ?? null ) ? $stored['terms'] : array(),
			'tone'  => is_array( $stored['tone'] ?? null ) ? $stored['tone'] : array(),
		);
	}

	/**
	 * Save. Text is plain text; Pro-only parts are refused unless the premium layer handles them.
	 *
	 * @param array<string, mixed> $input Any of `dnt`, `terms`, `tone`.
	 * @return true|\WP_Error
	 */
	public static function save( array $input ) {
		$current = self::get();
		if ( array_key_exists( 'dnt', $input ) ) {
			$words          = array_map( static fn( $w ) => trim( sanitize_text_field( (string) $w ) ), (array) $input['dnt'] );
			$current['dnt'] = array_values( array_unique( array_filter( $words, static fn( $w ) => '' !== $w ) ) );
		}
		$pro_keys = array_filter(
			array( 'terms', 'tone' ),
			static fn( string $k ): bool => array_key_exists( $k, $input )
		);
		if ( array() !== $pro_keys ) {
			/**
			 * Saves preferred translations and tone per language. Only the premium layer answers
			 * (WordPress.org guideline 5: no Pro behaviour in the free plugin).
			 *
			 * @param array<string, mixed>|\WP_Error|null $saved   The glossary with those keys applied,
			 *                                                     a refusal, or null.
			 * @param array<string, mixed>                 $input   What was submitted.
			 * @param array<string, mixed>                 $current The glossary so far.
			 */
			$saved = apply_filters( 'tranzly_glossary_save', null, $input, $current );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			if ( is_array( $saved ) ) {
				$current = $saved;
			} else {
				foreach ( $pro_keys as $pro_key ) {
					if ( array() !== (array) $input[ $pro_key ] ) {
						return new \WP_Error( 'tranzly_pro_only', __( 'Preferred translations and tone per language are Tranzly Pro features. The do-not-translate list is free.', 'tranzly' ), array( 'status' => 403 ) );
					}
				}
			}
		}
		update_option( self::OPTION, $current, true );

		return true;
	}

	/**
	 * The engine options for a target language: `do_not_translate`, plus whatever the premium
	 * layer adds (`tranzly_glossary_options`).
	 *
	 * @param string $lang Target language.
	 * @return array<string, mixed>
	 */
	public static function options_for( string $lang ): array {
		$all = self::get();

		/**
		 * Filters the engine options for a target language: the do-not-translate list, plus the
		 * glossary and tone when the premium layer adds them.
		 *
		 * @param array<string, mixed> $options Options.
		 * @param string               $lang    Target language.
		 * @param array<string, mixed> $all     The stored glossary.
		 */
		return (array) apply_filters( 'tranzly_glossary_options', array( 'do_not_translate' => $all['dnt'] ), $lang, $all );
	}
}
