<?php
/**
 * The language switcher: one renderer behind every place a switcher appears (T5).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Switcher;

use ZinnDigital\Tranzly\Assets;
use ZinnDigital\Tranzly\Core\Locales;
use ZinnDigital\Tranzly\Frontend;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Seo\Router;
use ZinnDigital\Tranzly\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The block, the shortcode, the widget, the menu item, the floating button, Page Builder
 * Sandwich's element and the Elementor / Bricks widgets all call `render()`, so a switcher looks
 * and behaves the same wherever it is placed and there is one place to keep accessible:
 *
 * - a `<nav>` landmark with an accessible name (the label, which is translatable);
 * - every link has `hreflang` and `lang` (a screen reader reads "Deutsch" in German), and the
 *   current language carries `aria-current="true"`;
 * - the dropdown is a native `<details>`/`<summary>` disclosure: keyboard- and screen-reader
 *   operable with no script at all. A small script adds Escape-to-close, closing on an outside
 *   click, and returns focus to the button;
 * - flags are OFF by default and always decorative (`aria-hidden`), because a flag is a country,
 *   not a language (tz-l6).
 *
 * ⭐ Styling is by CSS custom properties on the `<nav>` (`--<prefix>-lsw-*`), so a builder's own
 * style controls (PBS, Elementor) set colours and spacing without new CSS per builder.
 */
final class Switcher {

	/** The designs. */
	public const STYLES = array( 'list', 'pills', 'buttons', 'dropdown', 'codes' );

	/** How each language is named. */
	public const DISPLAYS = array( 'name', 'code', 'name_code' );

	/** Custom properties a caller may set, and the CSS property each styles. */
	public const STYLE_VARS = array(
		'color'       => 'color',
		'background'  => 'background',
		'activeColor' => 'active-color',
		'activeBg'    => 'active-bg',
		'borderColor' => 'border-color',
		'radius'      => 'radius',
		'gap'         => 'gap',
		'fontSize'    => 'font-size',
	);

	/**
	 * Default arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'style'       => 'list',
			'display'     => 'name',
			'flags'       => false,
			'showCurrent' => true,
			'hideMissing' => false,
			'vertical'    => false,
			'label'       => '',
			'floating'    => '',
			'vars'        => array(),
		);
	}

	/**
	 * Clean arguments: anything unknown falls back to its default (a builder must never be able to
	 * break the markup with a stored value).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public static function normalise( array $args ): array {
		$d   = self::defaults();
		$out = array(
			'style'       => in_array( $args['style'] ?? '', self::STYLES, true ) ? (string) $args['style'] : $d['style'],
			'display'     => in_array( $args['display'] ?? '', self::DISPLAYS, true ) ? (string) $args['display'] : $d['display'],
			'flags'       => self::truthy( $args['flags'] ?? false ),
			'showCurrent' => self::truthy( $args['showCurrent'] ?? true ),
			'hideMissing' => self::truthy( $args['hideMissing'] ?? false ),
			'vertical'    => self::truthy( $args['vertical'] ?? false ),
			'label'       => trim( sanitize_text_field( (string) ( $args['label'] ?? '' ) ) ),
			'floating'    => in_array( $args['floating'] ?? '', array( 'bottom-end', 'bottom-start', 'top-end', 'top-start' ), true ) ? (string) $args['floating'] : '',
			'vars'        => array(),
		);
		foreach ( (array) ( $args['vars'] ?? array() ) as $key => $value ) {
			$value = trim( (string) $value );
			if ( isset( self::STYLE_VARS[ $key ] ) && '' !== $value && 1 === preg_match( '/^[#(),.%\sa-zA-Z0-9-]{1,60}$/', $value ) && ! str_contains( strtolower( $value ), 'url' ) && ! str_contains( strtolower( $value ), 'expression' ) ) {
				$out['vars'][ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * The switcher for the current request. Empty when fewer than two languages exist.
	 *
	 * @param array<string, mixed> $args See defaults().
	 * @return string
	 */
	public static function render( array $args = array() ): string {
		$args  = self::normalise( $args );
		$items = self::items( $args['hideMissing'] );
		if ( count( $items ) < 2 ) {
			return '';
		}
		Assets::enqueue_style( 'switcher.css' );
		if ( 'dropdown' === $args['style'] || '' !== $args['floating'] ) {
			Assets::enqueue_script( 'switcher.js' );
		}
		$args['label'] = self::unique_label( '' !== $args['label'] ? $args['label'] : __( 'Choose a language', 'tranzly' ) );

		return self::html( $args, $items, Languages::current(), Settings::prefix() );
	}

	/**
	 * A landmark name not yet used on this page: two navigation landmarks with the same name are
	 * indistinguishable to a screen-reader user moving between landmarks (WCAG 1.3.1; axe
	 * `landmark-unique`). The second "Choose a language" becomes "Choose a language (2)".
	 *
	 * @param string $label The wanted name.
	 * @return string
	 */
	private static function unique_label( string $label ): string {
		static $used  = array();
		$key          = strtolower( $label );
		$used[ $key ] = ( $used[ $key ] ?? 0 ) + 1;
		if ( 1 === $used[ $key ] ) {
			return $label;
		}

		/* translators: 1: a switcher's accessible name, 2: its number on the page (2, 3, …). */
		return sprintf( __( '%1$s (%2$d)', 'tranzly' ), $label, $used[ $key ] );
	}

	/**
	 * The languages with the address that shows this page in each.
	 *
	 * @param bool $hide_missing Leave out languages this page has no translation in.
	 * @return array<int, array{code: string, name: string, url: string, missing: bool}>
	 */
	public static function items( bool $hide_missing = false ): array {
		$out     = array();
		$current = Languages::current();
		$page    = did_action( 'wp' ) && ( is_singular() || ( is_home() && ! is_front_page() ) ) ? (int) get_queried_object_id() : 0;
		foreach ( Languages::all() as $language ) {
			$missing = false;
			if ( $page > 0 && $language['code'] !== $current ) {
				$target  = Languages::translation( $page, $language['code'] );
				$missing = null === $target || ! Router::is_public_post( $target );
			}
			if ( $missing && $hide_missing ) {
				continue;
			}
			$out[] = array(
				'code'    => $language['code'],
				'name'    => $language['name'],
				'url'     => Languages::url( $language['code'] ),
				'missing' => $missing,
			);
		}

		return $out;
	}

	/**
	 * The markup (the pure half: every input is an argument, so a test can run it).
	 *
	 * @param array<string, mixed>                                                       $args    Normalised arguments.
	 * @param array<int, array{code: string, name: string, url: string, missing?: bool}> $items   Languages.
	 * @param string                                                                     $current The current language.
	 * @param string                                                                     $prefix  The class prefix.
	 * @return string
	 */
	public static function html( array $args, array $items, string $current, string $prefix ): string {
		if ( count( $items ) < 2 ) {
			return '';
		}
		$label = '' !== $args['label'] ? $args['label'] : __( 'Choose a language', 'tranzly' );
		$mods  = array( 'lsw', 'lsw--' . $args['style'] );
		if ( $args['vertical'] ) {
			$mods[] = 'lsw--vertical';
		}
		if ( '' !== $args['floating'] ) {
			$mods[] = 'lsw--floating';
			$mods[] = 'lsw--' . $args['floating'];
		}
		$style = '';
		foreach ( $args['vars'] as $key => $value ) {
			$style .= '--' . $prefix . '-lsw-' . self::STYLE_VARS[ $key ] . ':' . $value . ';';
		}

		$links        = '';
		$current_item = null;
		foreach ( $items as $item ) {
			$is_current = 0 === strcasecmp( $item['code'], $current );
			if ( $is_current ) {
				$current_item = $item;
			}
			if ( $is_current && ! $args['showCurrent'] && 'dropdown' !== $args['style'] && '' === $args['floating'] ) {
				continue;
			}
			$cls = array( 'lsw__item' );
			if ( $is_current ) {
				$cls[] = 'lsw__item--current';
			}
			if ( ! empty( $item['missing'] ) ) {
				$cls[] = 'lsw__item--missing';
			}
			$links .= '<li class="' . esc_attr( Frontend::classes( $prefix, ...$cls ) ) . '">'
				. self::link( $item, $args, $is_current, $prefix ) . '</li>';
		}
		$list = '<ul class="' . esc_attr( Frontend::classes( $prefix, 'lsw__list' ) ) . '">' . $links . '</ul>';

		$open = '<nav class="' . esc_attr( Frontend::classes( $prefix, ...$mods ) ) . '" aria-label="' . esc_attr( $label ) . '"'
			. ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';

		if ( 'dropdown' === $args['style'] || '' !== $args['floating'] ) {
			$shown   = $current_item ?? $items[0];
			$summary = '<summary class="' . esc_attr( Frontend::classes( $prefix, 'lsw__toggle' ) ) . '">'
				. '<span class="' . esc_attr( Frontend::classes( $prefix, 'lsw__sr' ) ) . '">' . esc_html( $label ) . ': </span>'
				. self::face( $shown, $args, $prefix ) . '</summary>';

			return $open . '<details class="' . esc_attr( Frontend::classes( $prefix, 'lsw__menu' ) ) . '">' . $summary . $list . '</details></nav>';
		}

		return $open . $list . '</nav>';
	}

	/**
	 * One language link.
	 *
	 * @param array<string, mixed> $item       The language.
	 * @param array<string, mixed> $args       Arguments.
	 * @param bool                 $is_current Whether it is the page's language.
	 * @param string               $prefix     Class prefix.
	 * @return string
	 */
	private static function link( array $item, array $args, bool $is_current, string $prefix ): string {
		$tag = str_replace( '_', '-', (string) $item['code'] );

		return '<a class="' . esc_attr( Frontend::classes( $prefix, 'lsw__link' ) ) . '" href="' . esc_url( (string) $item['url'] ) . '" hreflang="' . esc_attr( $tag ) . '" lang="' . esc_attr( $tag ) . '"'
			. ( Locales::is_rtl( (string) $item['code'] ) ? ' dir="rtl"' : '' )
			. ( $is_current ? ' aria-current="true"' : '' ) . '>'
			. self::face( $item, $args, $prefix ) . '</a>';
	}

	/**
	 * What a language looks like inside its link: optional flag, then its name and/or code.
	 *
	 * @param array<string, mixed> $item   The language.
	 * @param array<string, mixed> $args   Arguments.
	 * @param string               $prefix Class prefix.
	 * @return string
	 */
	private static function face( array $item, array $args, string $prefix ): string {
		$code  = (string) $item['code'];
		$short = strtoupper( Locales::primary( $code ) );
		$name  = (string) $item['name'];
		$out   = '';
		if ( $args['flags'] ) {
			$flag = self::flag( $code );
			if ( '' !== $flag ) {
				$out .= '<span class="' . esc_attr( Frontend::classes( $prefix, 'lsw__flag' ) ) . '" aria-hidden="true">' . $flag . '</span>';
			}
		}
		$display = 'codes' === $args['style'] ? 'code' : $args['display'];
		if ( 'code' === $display ) {
			// The visible code is short; the accessible name stays the language's own name.
			$out .= '<abbr class="' . esc_attr( Frontend::classes( $prefix, 'lsw__code' ) ) . '" title="' . esc_attr( $name ) . '" aria-hidden="true">' . esc_html( $short ) . '</abbr>'
				. '<span class="' . esc_attr( Frontend::classes( $prefix, 'lsw__sr' ) ) . '">' . esc_html( $name ) . '</span>';
		} elseif ( 'name_code' === $display ) {
			$out .= '<span class="' . esc_attr( Frontend::classes( $prefix, 'lsw__name' ) ) . '">' . esc_html( $name ) . '</span> <span class="' . esc_attr( Frontend::classes( $prefix, 'lsw__code' ) ) . '" aria-hidden="true">' . esc_html( $short ) . '</span>';
		} else {
			$out .= '<span class="' . esc_attr( Frontend::classes( $prefix, 'lsw__name' ) ) . '">' . esc_html( $name ) . '</span>';
		}

		return $out;
	}

	/**
	 * A flag emoji for a locale's region (`de_DE` → 🇩🇪). A language with no region gets the
	 * region it is most often shown with; one with none known gets no flag at all.
	 *
	 * @param string $code A locale.
	 * @return string
	 */
	public static function flag( string $code ): string {
		$parts  = explode( '_', $code );
		$region = isset( $parts[1] ) && 1 === preg_match( '/^[A-Z]{2}$/', $parts[1] ) ? $parts[1] : null;
		if ( null === $region ) {
			$usual  = array(
				'en' => 'GB',
				'fr' => 'FR',
				'de' => 'DE',
				'es' => 'ES',
				'it' => 'IT',
				'ja' => 'JP',
				'ar' => 'SA',
				'he' => 'IL',
				'el' => 'GR',
				'cs' => 'CZ',
				'da' => 'DK',
				'sv' => 'SE',
				'uk' => 'UA',
				'vi' => 'VN',
				'fa' => 'IR',
				'hi' => 'IN',
				'ko' => 'KR',
				'zh' => 'CN',
				'nl' => 'NL',
				'pl' => 'PL',
				'pt' => 'PT',
				'ru' => 'RU',
				'tr' => 'TR',
				'fi' => 'FI',
				'nb' => 'NO',
				'hu' => 'HU',
				'ro' => 'RO',
				'id' => 'ID',
				'th' => 'TH',
			);
			$region = $usual[ strtolower( $parts[0] ) ] ?? null;
		}
		if ( null === $region ) {
			return '';
		}
		$flag = '';
		foreach ( str_split( $region ) as $letter ) {
			$flag .= mb_chr( 0x1F1E6 + ord( $letter ) - ord( 'A' ), 'UTF-8' );
		}

		return $flag;
	}

	/**
	 * A stored boolean from a block, shortcode or widget (`true`, `1`, `"1"`, `"yes"`, `"true"`).
	 *
	 * @param mixed $value The value.
	 * @return bool
	 */
	private static function truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on' ), true );
	}
}
