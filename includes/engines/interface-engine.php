<?php
/**
 * The translation-engine interface: implement it to add any translation provider.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Engines;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A translation engine (tz-dev2).
 *
 * Every engine Tranzly ships (DeepL, the AI models, Google, Microsoft) implements this, and so can
 * yours: register an instance on `tranzly_register_engines` and it appears everywhere an engine
 * can be chosen — the editor, bulk jobs, REST and `wp tranzly translate --engine=<id>`.
 *
 *     add_action( 'tranzly_register_engines', function ( $registry ) {
 *         $registry->add( new My_Engine() );
 *     } );
 *
 * Rules an engine must keep:
 *  - `translate()` returns an array with EXACTLY the keys it was given, in any order, each value
 *    the translation of the text under the same key; or a \WP_Error when the whole call failed.
 *  - Text in `html` format keeps its markup: translate the text between tags, never the tags,
 *    attribute names or URLs.
 *  - Words in `$options['do_not_translate']` come back unchanged.
 *  - It never sends anything to any service but the one the site owner configured for it.
 */
interface Engine {

	/**
	 * A stable identifier: lowercase letters, digits and hyphens (`deepl`, `my-engine`).
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * The name people see.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Can it translate right now (a key is saved, the service is set up)?
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Translate texts.
	 *
	 * @param array<string, string> $texts   key => source text.
	 * @param string                $source  Source language, a WordPress locale (`en_US`).
	 * @param string                $target  Target language, a WordPress locale (`de_DE`).
	 * @param array<string, mixed>  $options `format` (`text` or `html`), `do_not_translate`
	 *                                       (list of words), `formality` (`default`, `more`,
	 *                                       `less`), `instructions` (free text for AI engines).
	 * @return array<string, string>|\WP_Error
	 */
	public function translate( array $texts, string $source, string $target, array $options = array() );

	/**
	 * What translating these texts would cost on the site owner's own account, before anything
	 * is sent: the characters billed, and the approximate price in US dollars when the engine
	 * knows it (null when it does not — never a guess presented as a price).
	 *
	 * @param array<string, string> $texts  key => source text.
	 * @param string                $target Target language.
	 * @return array{characters: int, cost_usd: float|null}
	 */
	public function estimate( array $texts, string $target ): array;
}
