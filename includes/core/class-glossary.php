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
	 * Save. Text is plain text; Pro-only parts are refused without Pro.
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
		foreach ( array( 'terms', 'tone' ) as $pro_key ) {
			if ( array_key_exists( $pro_key, $input ) && ! Edition::pro() && array() !== (array) $input[ $pro_key ] ) {
				return new \WP_Error( 'tranzly_pro_only', __( 'Preferred translations and tone per language are Tranzly Pro features. The do-not-translate list is free.', 'tranzly' ), array( 'status' => 403 ) );
			}
		}
		if ( array_key_exists( 'terms', $input ) ) {
			$terms = array();
			foreach ( (array) $input['terms'] as $lang => $pairs ) {
				$code = Languages::resolve( (string) $lang );
				if ( null === $code || ! is_array( $pairs ) ) {
					return new \WP_Error( 'tranzly_bad_glossary', __( 'Glossary entries must be grouped by one of the site\'s languages.', 'tranzly' ), array( 'status' => 400 ) );
				}
				foreach ( $pairs as $from => $to ) {
					$from = trim( sanitize_text_field( (string) $from ) );
					$to   = trim( sanitize_text_field( (string) $to ) );
					if ( '' !== $from && '' !== $to ) {
						$terms[ $code ][ $from ] = $to;
					}
				}
			}
			$current['terms'] = $terms;
		}
		if ( array_key_exists( 'tone', $input ) ) {
			$tone = array();
			foreach ( (array) $input['tone'] as $lang => $spec ) {
				$code = Languages::resolve( (string) $lang );
				if ( null === $code || ! is_array( $spec ) || ! in_array( $spec['formality'] ?? 'default', self::FORMALITY, true ) ) {
					return new \WP_Error( 'tranzly_bad_tone', __( 'Tone is set per site language: formality default, more or less, plus optional instructions.', 'tranzly' ), array( 'status' => 400 ) );
				}
				$tone[ $code ] = array(
					'formality'    => (string) ( $spec['formality'] ?? 'default' ),
					'instructions' => sanitize_textarea_field( (string) ( $spec['instructions'] ?? '' ) ),
				);
			}
			$current['tone'] = $tone;
		}
		update_option( self::OPTION, $current, true );

		return true;
	}

	/**
	 * The engine options for a target language: `do_not_translate`, and with Pro `glossary`,
	 * `formality` and `instructions`.
	 *
	 * @param string $lang Target language.
	 * @return array<string, mixed>
	 */
	public static function options_for( string $lang ): array {
		$all     = self::get();
		$options = array( 'do_not_translate' => $all['dnt'] );
		if ( Edition::pro() ) {
			$options['glossary']     = (array) ( $all['terms'][ $lang ] ?? array() );
			$options['formality']    = (string) ( $all['tone'][ $lang ]['formality'] ?? 'default' );
			$options['instructions'] = (string) ( $all['tone'][ $lang ]['instructions'] ?? '' );
		}

		return $options;
	}
}
