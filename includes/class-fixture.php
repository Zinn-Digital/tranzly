<?php
/**
 * Tranzly's fixture block: a language switcher rendered from its own API.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders `tranzly/fixture-switcher`.
 *
 * ⭐ It exists for the rendered-HTML footprint gate (F5) and as the reference consumer of the
 * language API. The pure half takes every input as an argument so a test can run it without
 * WordPress.
 */
final class Fixture {

	/**
	 * Block callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( array $attributes ): string {
		$languages = array();
		foreach ( Languages::all() as $language ) {
			$language['url'] = Languages::url( $language['code'] );
			$languages[]     = $language;
		}
		if ( count( $languages ) < 2 ) {
			return '';
		}
		Assets::enqueue_style( 'front.css' );

		return self::render( $attributes, Settings::prefix(), $languages, Languages::current() );
	}

	/**
	 * The switcher's HTML.
	 *
	 * @param array<string, mixed>             $attributes `label`.
	 * @param string                           $prefix     The class prefix.
	 * @param array<int, array<string, mixed>> $languages  Each with `code`, `name`, `url`.
	 * @param string                           $current    The current language code.
	 * @return string
	 */
	public static function render( array $attributes, string $prefix, array $languages, string $current ): string {
		if ( count( $languages ) < 2 ) {
			return '';
		}
		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		if ( '' === $label ) {
			$label = __( 'Choose a language', 'tranzly' );
		}

		$items = '';
		foreach ( $languages as $language ) {
			$code       = (string) $language['code'];
			$bcp47      = str_replace( '_', '-', $code );
			$is_current = 0 === strcasecmp( $code, $current );
			$class      = $is_current ? Frontend::classes( $prefix, 'lsw__item', 'lsw__item--current' ) : Frontend::classes( $prefix, 'lsw__item' );
			$items     .= '<li class="' . esc_attr( $class ) . '"><a href="' . esc_url( (string) ( $language['url'] ?? '' ) ) . '" hreflang="' . esc_attr( $bcp47 ) . '" lang="' . esc_attr( $bcp47 ) . '"'
				. ( $is_current ? ' aria-current="true"' : '' ) . '>' . esc_html( (string) ( $language['name'] ?? $code ) ) . '</a></li>';
		}

		return '<nav class="' . esc_attr( Frontend::classes( $prefix, 'lsw' ) ) . '" aria-label="' . esc_attr( $label ) . '"><ul class="' . esc_attr( Frontend::classes( $prefix, 'lsw__list' ) ) . '">' . $items . '</ul></nav>';
	}
}
