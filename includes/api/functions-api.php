<?php
/**
 * Template functions and the PHP API for theme and plugin developers (tz-dev1).
 *
 * The language functions of docs/adr/0033 (tranzly_languages(), tranzly_current_language(), …)
 * live in includes/functions.php; these add translations, switching and engines. Every function
 * is safe to call on any request; test `function_exists()` first so your code keeps working when
 * Tranzly is inactive.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

use ZinnDigital\Tranzly\Api\Language_Switch;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Engines\Engine;
use ZinnDigital\Tranzly\Engines\Registry;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every version of a post, the post itself included: language code => post ID. A post with no
 * translations returns just itself in its language.
 *
 * @param int $post_id A post ID.
 * @return array<string, int>
 */
function tranzly_get_translations( int $post_id ): array {
	$group = Relations::translations( 'post', $post_id );

	return array() === $group && $post_id > 0 ? array( Languages::default_code() => $post_id ) : $group;
}

/**
 * The language a post is written in.
 *
 * @param int $post_id A post ID.
 * @return string
 */
function tranzly_get_post_language( int $post_id ): string {
	return Relations::language_of( 'post', $post_id ) ?? Languages::default_code();
}

/**
 * Every version of a term: language code => term ID.
 *
 * @param int $term_id A term ID.
 * @return array<string, int>
 */
function tranzly_get_term_translations( int $term_id ): array {
	$group = Relations::translations( 'term', $term_id );

	return array() === $group && $term_id > 0 ? array( Languages::default_code() => $term_id ) : $group;
}

/**
 * A term's translation into a language, or null.
 *
 * @param int    $term_id A term ID.
 * @param string $lang    A language code.
 * @return int|null
 */
function tranzly_get_term_translation( int $term_id, string $lang ): ?int {
	$code = Languages::resolve( $lang );

	return null === $code ? null : ( tranzly_get_term_translations( $term_id )[ $code ] ?? null );
}

/**
 * The language a term is written in.
 *
 * @param int $term_id A term ID.
 * @return string
 */
function tranzly_get_term_language( int $term_id ): string {
	return Relations::language_of( 'term', $term_id ) ?? Languages::default_code();
}

/**
 * Make code run in another language until tranzly_restore_language(): the current language and
 * WordPress's own translations both switch. Nests like switch_to_blog():
 *
 * Example:
 *
 *     if ( tranzly_switch_language( 'de_DE' ) ) {
 *         echo esc_html__( 'Read more', 'my-theme' ); // German
 *         tranzly_restore_language();
 *     }
 *
 * @param string $lang A listed language.
 * @return bool
 */
function tranzly_switch_language( string $lang ): bool {
	return Language_Switch::switch_to( $lang );
}

/**
 * Undo the last tranzly_switch_language().
 *
 * @return bool
 */
function tranzly_restore_language(): bool {
	return Language_Switch::restore();
}

/**
 * Translate a post into a language with an engine, as the current user (their permissions apply).
 *
 * @param int                  $post_id The original post.
 * @param string               $lang    A listed language.
 * @param string               $engine  An engine id; empty for the default engine.
 * @param array<string, mixed> $args    `force` (bool): overwrite a protected translation.
 * @return int|\WP_Error The translation's ID.
 */
function tranzly_translate_post( int $post_id, string $lang, string $engine = '', array $args = array() ) {
	return Translator::translate_post( $post_id, $lang, $engine, $args );
}

/**
 * Add a translation engine (tz-dev2). Call it on `tranzly_register_engines`, or any time before the
 * first translation of the request.
 *
 * @param Engine $engine Your engine.
 * @return true|\WP_Error
 */
function tranzly_register_engine( Engine $engine ) {
	return Registry::instance()->add( $engine );
}

/**
 * The engines available: id => name.
 *
 * @return array<string, string>
 */
function tranzly_get_engines(): array {
	return array_map( static fn( Engine $e ) => $e->label(), Registry::instance()->all() );
}
