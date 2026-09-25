<?php
/**
 * The public language API (docs/adr/0033).
 *
 * These function names and signatures are the contract other plugins — Page Builder Sandwich
 * first — code against. Callers test `function_exists()` before calling, so Tranzly stays
 * optional for them.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

use ZinnDigital\Tranzly\Contract;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's languages, default first.
 *
 * @return array<int, array{code: string, name: string}> Each with a WordPress locale `code`
 *                                                        and a display `name`.
 */
function tranzly_languages(): array {
	return Languages::all();
}

/**
 * The language of the current request (a WordPress locale code such as `fr_FR`).
 *
 * @return string
 */
function tranzly_current_language(): string {
	return Languages::current();
}

/**
 * The default language: the first listed.
 *
 * @return string
 */
function tranzly_default_language(): string {
	return Languages::default_code();
}

/**
 * A URL showing the given language.
 *
 * @param string      $lang A language code.
 * @param string|null $url  The URL to adapt; the current URL when null.
 * @return string
 */
function tranzly_language_url( string $lang, ?string $url = null ): string {
	return Languages::url( $lang, $url );
}

/**
 * The ID of a post's translation into a language, or null when there is none. Returns the post's
 * own ID when it is already written in that language.
 *
 * @param int    $post_id A post ID.
 * @param string $lang    A language code.
 * @return int|null
 */
function tranzly_get_translation( int $post_id, string $lang ): ?int {
	return Languages::translation( $post_id, $lang );
}

/**
 * The attribute names Tranzly translates for a block type: `role: content` in its block.json,
 * adjusted by the `tranzly_translatable_attributes` filter.
 *
 * @param string $block_name A registered block name.
 * @return array<int, string>
 */
function tranzly_translatable_attributes( string $block_name ): array {
	return Contract::translatable_attributes( $block_name );
}
