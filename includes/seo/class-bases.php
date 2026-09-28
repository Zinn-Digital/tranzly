<?php
/**
 * Translated URL bases: /de/kategorie/… instead of /de/category/… (tz-s3).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps the first word of a path between WordPress's own base (`category`, `tag`, WooCommerce's
 * `product`, `product-category`, `product-tag`) and the word the site owner chose for a language.
 * Only the FIRST path word is ever mapped, so a page whose slug happens to be `kategorie` further
 * down a path is left alone.
 */
final class Bases {

	/**
	 * WordPress's base for each translatable thing, as the site is configured today.
	 *
	 * @return array<string, string> what => base (no slashes); an entry is missing when that base
	 *                               cannot be mapped (a WooCommerce product base with a %tag%).
	 */
	public static function originals(): array {
		$out             = array(
			'category' => trim( (string) get_option( 'category_base' ), '/' ),
			'post_tag' => trim( (string) get_option( 'tag_base' ), '/' ),
		);
		$out['category'] = '' === $out['category'] ? 'category' : $out['category'];
		$out['post_tag'] = '' === $out['post_tag'] ? 'tag' : $out['post_tag'];

		if ( class_exists( 'WooCommerce' ) || function_exists( 'wc_get_permalink_structure' ) ) {
			$wc                 = (array) get_option( 'woocommerce_permalinks', array() );
			$out['product']     = trim( (string) ( $wc['product_base'] ?? '' ), '/' );
			$out['product']     = '' === $out['product'] ? 'product' : $out['product'];
			$out['product_cat'] = trim( (string) ( $wc['category_base'] ?? '' ), '/' );
			$out['product_cat'] = '' === $out['product_cat'] ? 'product-category' : $out['product_cat'];
			$out['product_tag'] = trim( (string) ( $wc['tag_base'] ?? '' ), '/' );
			$out['product_tag'] = '' === $out['product_tag'] ? 'product-tag' : $out['product_tag'];
		}

		return array_filter( $out, static fn( string $base ): bool => '' !== $base && ! str_contains( $base, '%' ) );
	}

	/**
	 * A path with a translated base mapped back to WordPress's own.
	 *
	 * @param string $path A path after the home path and the language folder, starting `/`.
	 * @param string $lang Its language.
	 * @return string
	 */
	public static function to_original( string $path, string $lang ): string {
		return self::map( $path, self::pairs( $lang ), true );
	}

	/**
	 * A path with WordPress's base replaced by the language's translated one.
	 *
	 * @param string $path A default-language path starting `/`.
	 * @param string $lang The language to show it in.
	 * @return string
	 */
	public static function to_translated( string $path, string $lang ): string {
		return self::map( $path, self::pairs( $lang ), false );
	}

	/**
	 * Original base => translated base, for one language.
	 *
	 * @param string $lang A language code.
	 * @return array<string, string>
	 */
	public static function pairs( string $lang ): array {
		$translated = Url_Settings::get()['bases'][ $lang ] ?? array();
		if ( array() === $translated ) {
			return array();
		}
		$pairs = array();
		foreach ( self::originals() as $what => $base ) {
			if ( isset( $translated[ $what ] ) && $translated[ $what ] !== $base ) {
				$pairs[ $base ] = $translated[ $what ];
			}
		}

		return $pairs;
	}

	/**
	 * Replace the leading base of a path (the pure half).
	 *
	 * @param string                $path    A path starting `/`.
	 * @param array<string, string> $pairs   Original => translated.
	 * @param bool                  $reverse True to map translated back to original.
	 * @return string
	 */
	public static function map( string $path, array $pairs, bool $reverse ): string {
		if ( array() === $pairs ) {
			return $path;
		}
		if ( $reverse ) {
			$pairs = array_flip( $pairs );
		}
		// Longest first, so `product-category` wins over `product`.
		uksort( $pairs, static fn( $a, $b ): int => strlen( (string) $b ) <=> strlen( (string) $a ) );
		foreach ( $pairs as $from => $to ) {
			$from = (string) $from;
			if ( '/' . $from === $path || str_starts_with( $path, '/' . $from . '/' ) ) {
				return '/' . $to . substr( $path, strlen( $from ) + 1 );
			}
		}

		return $path;
	}
}
