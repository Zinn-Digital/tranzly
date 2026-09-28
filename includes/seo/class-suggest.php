<?php
/**
 * The polite "this page is in your language" banner (tz-s8).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

use ZinnDigital\Tranzly\Assets;
use ZinnDigital\Tranzly\Core\Locales;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔ NEVER A REDIRECT. The page ships the SAME HTML to every visitor (so page caches stay correct)
 * with the list of versions that exist; the visitor's browser compares that with its own
 * languages and offers one. A crawler has no browser language and sees nothing at all.
 *
 * ⭐ The banner speaks the language it offers — a German reader on an English page is asked in
 * German. Those few sentences are read straight from Tranzly's own translation file for that
 * language, so no page ever loads 57 translation catalogues to print one banner.
 */
final class Suggest {

	/**
	 * Hook the footer.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Before wp_print_footer_scripts (wp_footer, 20), or the enqueued script prints nowhere.
		add_action( 'wp_footer', array( self::class, 'print' ), 5 );
	}

	/**
	 * `wp_footer`: the data and the script, when there is another version to offer.
	 *
	 * @return void
	 */
	public static function print(): void {
		if ( ! Url_Settings::get()['suggest'] || ! Router::is_front() || is_customize_preview() ) {
			return;
		}
		$offers = self::offers( Head::alternates(), Languages::current() );
		if ( array() === $offers ) {
			return;
		}
		$handle = Assets::enqueue_script( 'suggest.js' );
		Assets::enqueue_style( 'switcher.css' );
		if ( null === $handle ) {
			return;
		}
		$data = array(
			'current' => str_replace( '_', '-', Languages::current() ),
			'offers'  => $offers,
		);
		wp_add_inline_script( $handle, 'window.' . \ZinnDigital\Tranzly\Settings::prefix() . 'Lsg=' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . ';', 'before' );
	}

	/**
	 * What to offer: every other version of this page, each with its sentences in its own language
	 * (the pure half).
	 *
	 * @param array<string, string> $alternates Language => URL.
	 * @param string                $current    The page's language.
	 * @return array<int, array<string, string>>
	 */
	public static function offers( array $alternates, string $current ): array {
		$names = array();
		foreach ( Languages::all() as $language ) {
			$names[ $language['code'] ] = $language['name'];
		}
		$out = array();
		foreach ( $alternates as $code => $url ) {
			if ( $code === $current ) {
				continue;
			}
			$t     = self::texts( $code );
			$name  = $names[ $code ] ?? $code;
			$out[] = array(
				'tag'   => Head::hreflang( $code ),
				'url'   => $url,
				'dir'   => Locales::is_rtl( $code ) ? 'rtl' : 'ltr',
				'label' => $t['label'],
				/* The name is inserted here, so the sentence stays one translatable unit. */
				'text'  => sprintf( $t['text'], $name ),
				'yes'   => sprintf( $t['yes'], $name ),
				'no'    => $t['no'],
			);
		}

		return $out;
	}

	/**
	 * The banner's sentences in a language, from Tranzly's own translation file for it; English
	 * when there is none.
	 *
	 * @param string $locale A locale.
	 * @return array{label: string, text: string, yes: string, no: string}
	 */
	public static function texts( string $locale ): array {
		static $memo = array();
		if ( isset( $memo[ $locale ] ) ) {
			return $memo[ $locale ];
		}
		// These calls exist so the strings reach the translation catalogue; the values used below
		// are looked up in the TARGET language's file, not the site's.
		$unused = array(
			'label' => __( 'Language suggestion', 'tranzly' ),
			/* translators: %s: a language's name in that language, such as Deutsch. */
			'text'  => __( 'This page is also available in %s.', 'tranzly' ),
			/* translators: %s: a language's name in that language, such as Deutsch. */
			'yes'   => __( 'Read it in %s', 'tranzly' ),
			'no'    => __( 'No thanks', 'tranzly' ),
		);
		$msgids = array(
			'label' => 'Language suggestion',
			'text'  => 'This page is also available in %s.',
			'yes'   => 'Read it in %s',
			'no'    => 'No thanks',
		);
		unset( $unused );
		$messages = self::catalogue( $locale );
		$out      = array();
		foreach ( $msgids as $key => $msgid ) {
			$value       = $messages[ $msgid ] ?? null;
			$out[ $key ] = is_string( $value ) && '' !== $value && ( 'label' === $key || 'no' === $key || str_contains( $value, '%s' ) ) ? $value : $msgid;
		}
		$memo[ $locale ] = $out;

		return $out;
	}

	/**
	 * Tranzly's messages for a locale (exact file, else the first file of the same language), or
	 * null when there is none.
	 *
	 * @param string $locale A locale.
	 * @return array<string, string>|null
	 */
	private static function catalogue( string $locale ): ?array {
		$dir   = TRANZLY_DIR . 'languages/';
		$files = array( $dir . 'tranzly-' . $locale . '.l10n.php' );
		$found = glob( $dir . 'tranzly-' . Locales::primary( $locale ) . '*.l10n.php' );
		$files = array_merge( $files, is_array( $found ) ? $found : array() );
		foreach ( $files as $file ) {
			if ( is_readable( $file ) && 1 === preg_match( '#/tranzly-[A-Za-z_]+\.l10n\.php$#', $file ) ) {
				$data = include $file;
				if ( is_array( $data ) && is_array( $data['messages'] ?? null ) ) {
					return $data['messages'];
				}
			}
		}

		return null;
	}
}
