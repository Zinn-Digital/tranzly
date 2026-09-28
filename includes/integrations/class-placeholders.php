<?php
/**
 * Keeping template variables out of the engine's reach (`%%title%%`, `%title%`, `#post_title`,
 * `[your-name]`, `{field_id="3"}`).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An SEO title like `%%title%% %%sep%% %%sitename%%` or a form e-mail like `From: [your-name]` is
 * mostly VARIABLES: a machine that translates `%%sitename%%` to `%%nomdusite%%` breaks the title on
 * every page. Before sending, each variable becomes an inert `<span translate="no">` holding a
 * number, the text goes as HTML (every engine keeps tags), and afterwards each span becomes its
 * variable again. A span the engine lost is restored at the end, so a variable is never dropped.
 *
 * Pure: no WordPress state.
 */
final class Placeholders {

	/** SEO plugins' variables. */
	public const SEO = '/%%[\w-]+%%|%[\w-]+(?:\([^)]*\))?%|#[a-z_]+(?:-[a-z_]+)?|\{[\w:-]+\}/';

	/** Form plugins' mail tags and merge tags. */
	public const FORMS = '/\[[\w-]+(?:\s[^\]]*)?\]|\{[^{}]+\}/';

	/**
	 * Replace variables with numbered spans.
	 *
	 * @param string $text    The text.
	 * @param string $pattern A pattern from above.
	 * @return array{0: string, 1: array<int, string>} The masked HTML and the variables in order.
	 */
	public static function mask( string $text, string $pattern ): array {
		$vars   = array();
		$masked = preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( &$vars ): string {
				$vars[] = $m[0];
				return '<span translate="no" class="notranslate" data-tz="' . ( count( $vars ) - 1 ) . '"></span>';
			},
			htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8', false )
		);

		return array( is_string( $masked ) ? $masked : $text, $vars );
	}

	/**
	 * Put the variables back.
	 *
	 * @param string             $html The engine's answer.
	 * @param array<int, string> $vars The variables.
	 * @return string Plain text again.
	 */
	public static function unmask( string $html, array $vars ): string {
		$seen = array();
		$out  = preg_replace_callback(
			'#<span\b[^>]*data-tz="(\d+)"[^>]*>(?:\s*</span>)?#i',
			static function ( array $m ) use ( $vars, &$seen ): string {
				$i          = (int) $m[1];
				$seen[ $i ] = true;
				return $vars[ $i ] ?? '';
			},
			$html
		);
		$out  = html_entity_decode( (string) $out, ENT_NOQUOTES | ENT_HTML5, 'UTF-8' );
		foreach ( $vars as $i => $var ) {
			if ( ! isset( $seen[ $i ] ) ) {
				$out = rtrim( $out ) . ' ' . $var;
			}
		}

		return $out;
	}

	/**
	 * Does a text hold any variable of a pattern?
	 *
	 * @param string $text    Text.
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	public static function has( string $text, string $pattern ): bool {
		return 1 === preg_match( $pattern, $text );
	}
}
