<?php
/**
 * The language API's implementation: which languages exist, which one is current, and where a
 * post's translation lives.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ A WORKING STUB OVER STORED DATA (docs/adr/0033). The language list is the one the site owner
 * saves on the settings screen; the current language comes from the `lang` query variable, then a
 * cookie, then the first listed language; a post's translations are read from post meta. The
 * T-milestones replace the storage behind these methods, never their signatures — that is what
 * lets Page Builder Sandwich code against them today.
 */
final class Languages {

	/** Post meta: map of language code => translated post ID. */
	public const META_TRANSLATIONS = '_tranzly_translations';

	/** Post meta: the language a post is written in (absent = the default language). */
	public const META_LANGUAGE = '_tranzly_language';

	/**
	 * Hook the public query variable.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
	}

	/**
	 * Add the language query variable.
	 *
	 * @param array<int, string> $vars Public query variables.
	 * @return array<int, string>
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::query_var();

		return $vars;
	}

	/**
	 * The query variable carrying the language. Filterable for sites where `lang` is taken.
	 *
	 * @return string
	 */
	public static function query_var(): string {
		/**
		 * Filters the query variable that selects the language.
		 *
		 * @param string $var Default `lang`.
		 */
		$var = (string) apply_filters( 'tranzly_query_var', 'lang' );

		return 1 === preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $var ) ? $var : 'lang';
	}

	/**
	 * Every listed language, default first.
	 *
	 * @return array<int, array{code: string, name: string}>
	 */
	public static function all(): array {
		return Settings::get()['languages'];
	}

	/**
	 * The default language: the first one listed.
	 *
	 * @return string
	 */
	public static function default_code(): string {
		return self::all()[0]['code'];
	}

	/**
	 * The canonical code for a candidate (`fr-fr`, `FR_fr`, `fr_FR` all give `fr_FR`), or null
	 * when it is not a listed language.
	 *
	 * @param string|null $candidate A code from a URL, cookie or caller.
	 * @return string|null
	 */
	public static function resolve( ?string $candidate ): ?string {
		if ( null === $candidate || '' === $candidate ) {
			return null;
		}
		$wanted = strtolower( str_replace( '-', '_', $candidate ) );
		foreach ( self::all() as $language ) {
			if ( strtolower( $language['code'] ) === $wanted ) {
				return $language['code'];
			}
		}

		return null;
	}

	/**
	 * The language of the current request.
	 *
	 * @return string
	 */
	public static function current(): string {
		$var = self::query_var();

		$from_query = (string) get_query_var( $var, '' );
		if ( '' === $from_query && isset( $_GET[ $var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads a public language selector, changes nothing.
			$from_query = sanitize_text_field( wp_unslash( (string) $_GET[ $var ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		}
		$resolved = self::resolve( $from_query );
		if ( null !== $resolved ) {
			return $resolved;
		}

		$cookie = Settings::prefix() . '_lang';
		if ( isset( $_COOKIE[ $cookie ] ) ) {
			$resolved = self::resolve( sanitize_text_field( wp_unslash( (string) $_COOKIE[ $cookie ] ) ) );
			if ( null !== $resolved ) {
				return $resolved;
			}
		}

		return self::default_code();
	}

	/**
	 * A URL that shows the given language: the default language drops the query variable.
	 *
	 * @param string      $code A language code.
	 * @param string|null $url  The URL to adapt; the current request's URL when null.
	 * @return string
	 */
	public static function url( string $code, ?string $url = null ): string {
		$resolved = self::resolve( $code );
		$var      = self::query_var();
		$url      = null === $url ? add_query_arg( array() ) : $url;

		if ( null === $resolved || self::default_code() === $resolved ) {
			return remove_query_arg( $var, $url );
		}

		return add_query_arg( $var, strtolower( str_replace( '_', '-', $resolved ) ), $url );
	}

	/**
	 * The ID of a post's translation into a language, or null when none exists.
	 *
	 * @param int    $post_id A post ID.
	 * @param string $code    A language code.
	 * @return int|null
	 */
	public static function translation( int $post_id, string $code ): ?int {
		$resolved = self::resolve( $code );
		if ( null === $resolved || $post_id <= 0 ) {
			return null;
		}

		$own = self::resolve( (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) ) ?? self::default_code();
		if ( $own === $resolved ) {
			return $post_id;
		}

		$map = get_post_meta( $post_id, self::META_TRANSLATIONS, true );
		if ( ! is_array( $map ) ) {
			return null;
		}
		foreach ( $map as $lang => $id ) {
			if ( self::resolve( (string) $lang ) === $resolved && (int) $id > 0 ) {
				return (int) $id;
			}
		}

		return null;
	}
}
