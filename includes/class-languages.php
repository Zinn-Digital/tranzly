<?php
/**
 * The language API's implementation: which languages exist, which one is current, and where a
 * post's translation lives.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

use ZinnDigital\Tranzly\Core\Relations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ The language list is the one the site owner saves on the settings screen, with no cap on its
 * length (tz-f7). The current language comes from the `lang` query variable, then the language of
 * the post being viewed, then a cookie, then the first listed language. A post's translations come
 * from the translation groups (Core\Relations, T1) — the storage changed under these methods in
 * T1 and their signatures did not, which is the promise docs/adr/0033 makes to Page Builder
 * Sandwich.
 */
final class Languages {

	/** Post meta the 3.0.0 stub read (language => post ID). Read by nothing since T1; uninstall still removes it. */
	public const META_TRANSLATIONS = '_tranzly_translations';

	/** Post meta the 3.0.0 stub read (a post's language). Read by nothing since T1; uninstall still removes it. */
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
		/**
		 * Short-circuits the current language: return a language code to use it. This is how
		 * tranzly_switch_language() works; return null to let Tranzly decide.
		 *
		 * @param string|null $lang Null.
		 */
		$forced = apply_filters( 'tranzly_pre_current_language', null );
		if ( is_string( $forced ) ) {
			$resolved = self::resolve( $forced );
			if ( null !== $resolved ) {
				return $resolved;
			}
		}

		// ⭐ With language folders, subdomains or domains (T4) the ADDRESS decides: no folder is the
		// default language, whatever a cookie or a stray ?lang= says, or one URL would show two
		// languages to a search engine.
		if ( 'query' !== Seo\Router::mode() ) {
			return Seo\Router::url_language() ?? self::default_code();
		}

		$var = self::query_var();

		$from_query = (string) get_query_var( $var, '' );
		if ( '' === $from_query && isset( $_GET[ $var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads a public language selector, changes nothing.
			$from_query = sanitize_text_field( wp_unslash( (string) $_GET[ $var ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		}
		$resolved = self::resolve( $from_query );
		if ( null !== $resolved ) {
			return $resolved;
		}

		// Post-per-language: a translation IS in its language, so viewing it sets the language.
		if ( did_action( 'wp' ) && is_singular() ) {
			$resolved = self::resolve( Relations::language_of( 'post', (int) get_queried_object_id() ) );
			if ( null !== $resolved ) {
				return $resolved;
			}
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
		if ( 'query' !== Seo\Router::mode() ) {
			return null === $url ? Seo\Router::switch_url( $code ) : Seo\Router::language_url( $url, $code );
		}
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

		$group = Relations::translations( 'post', $post_id );
		$own   = self::resolve( Relations::language_of( 'post', $post_id ) ) ?? self::default_code();
		if ( $own === $resolved ) {
			return $post_id;
		}
		foreach ( $group as $lang => $id ) {
			if ( self::resolve( $lang ) === $resolved ) {
				return $id;
			}
		}

		return null;
	}
}
