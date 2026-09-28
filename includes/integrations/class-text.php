<?php
/**
 * Shared text an integration translates at run time (form labels, shop e-mails, theme strings).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Integrations;

use ZinnDigital\Tranzly\Core\Strings;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Text that belongs to a plugin's settings rather than to a post — a form's labels, a checkout
 * notice, an e-mail subject — is translated ONCE as shared text, keyed by the hash of its source
 * (`text.<sha1>`, the same store the menu labels use), and looked up while the page or the e-mail
 * is being built. So a label used on fifty pages is one translation, a person's correction to it is
 * one edit (protected from the machine), and the lookup costs nothing extra: the language's strings
 * are the one option the speed promise already counts.
 *
 * Unlike the site-title filter, this follows `tranzly_switch_language()`, so an order e-mail sent
 * from wp-admin or WP-Cron uses the ORDER's language.
 */
final class Text {

	/**
	 * The key a source is stored under.
	 *
	 * @param string $source The original text.
	 * @return string
	 */
	public static function key( string $source ): string {
		return 'text.' . sha1( $source );
	}

	/**
	 * The text in the current language (or the source when it is the default language, or when no
	 * translation exists yet).
	 *
	 * @param string $source The original.
	 * @return string
	 */
	public static function get( string $source ): string {
		if ( '' === trim( $source ) ) {
			return $source;
		}
		$lang = self::language();
		if ( null === $lang ) {
			return $source;
		}
		$have = Strings::all( $lang )[ self::key( $source ) ] ?? '';

		return '' === $have ? $source : $have;
	}

	/**
	 * The language to translate into now, or null (default language, or wp-admin screens when no
	 * switch is active — an editor sees the originals they are editing).
	 *
	 * @return string|null
	 */
	public static function language(): ?string {
		// An editor in wp-admin sees the originals they edit; only a switched language (an e-mail
		// being sent in an order's language) translates there.
		if ( is_admin() && ! wp_doing_ajax() && ! is_locale_switched() ) {
			return null;
		}
		$lang = Languages::current();
		// A form or a checkout submitted over admin-ajax or REST carries no language in its own
		// address: the page it was sent from does.
		$rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( ! is_locale_switched() && ( wp_doing_ajax() || $rest ) ) {
			$from = (string) wp_get_referer();
			if ( '' !== $from && ! str_contains( $from, '/wp-admin/' ) ) {
				$lang = \ZinnDigital\Tranzly\Seo\Router::detect( (string) wp_parse_url( $from, PHP_URL_HOST ), (string) wp_parse_url( $from, PHP_URL_PATH ) ) ?? Languages::default_code();
			} elseif ( '' !== $from ) {
				return null;
			}
		}
		if ( Languages::default_code() === $lang ) {
			return null;
		}

		return $lang;
	}

	/**
	 * Sources for a shared-strings scope, as the strings screen expects them (key => source).
	 *
	 * @param array<int, string> $texts Sources.
	 * @param string             $format `text` or `html`.
	 * @return array<string, mixed>
	 */
	public static function sources( array $texts, string $format = 'text' ): array {
		$out = array();
		foreach ( $texts as $text ) {
			$text = (string) $text;
			if ( Tree::has_words( $text ) ) {
				$out[ self::key( $text ) ] = 'html' === $format || 1 === preg_match( '/<[a-z][^>]*>/i', $text ) ? array(
					'text'   => $text,
					'format' => 'html',
				) : $text;
			}
		}

		return $out;
	}
}
