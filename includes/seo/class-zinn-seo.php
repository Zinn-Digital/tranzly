<?php
/**
 * Working as one with Zinn® SEO, our own SEO plugin (zs-130, zs-131, zs-134).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

use ZinnDigital\Tranzly\Core\Strings;
use ZinnDigital\Tranzly\Integrations\Placeholders;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who owns what when both plugins are active:
 *
 * - Tranzly owns LANGUAGE: the address of each language, hreflang with `x-default`, `html lang`
 *   and `dir`. Nothing here changes those.
 * - Zinn® SEO owns the rest of the head and the files search engines read: titles, descriptions,
 *   Open Graph, structured data, the sitemap and llms.txt, each per language. So Tranzly prints no
 *   `og:locale` of its own (it adds the other languages to Zinn® SEO's Open Graph tags instead),
 *   and adds nothing to WordPress's own sitemap, which Zinn® SEO replaces.
 * - A translation is indexed exactly when its original is: Zinn® SEO's robots setting follows the
 *   original onto every translation, like the other SEO plugins' ({@see Robots}).
 * - The SEO title, description, social text, focus phrases and breadcrumb title are translated
 *   WITH the post, in the same request to the translation engine ({@see Zinn_Seo_Fields}).
 * - Zinn® SEO's site-wide words (title and description patterns, the llms.txt summary, the
 *   description of the organisation) are kept per language beside the site title and tagline,
 *   and handed back to Zinn® SEO in the language of the page ({@see shared_strings()}).
 *
 * ⛔ Everything here asks first whether Zinn® SEO is active, so a site without it runs none of it.
 * Zinn® SEO is reached only through what it publishes: its version constant, `zinn_seo()` and its
 * documented hooks.
 */
final class Zinn_Seo {

	/** Zinn® SEO's robots setting of a post (a list of directives). */
	public const ROBOTS_KEY = '_zseo_robots';

	/** Zinn® SEO's meta description of a post. */
	public const DESCRIPTION_KEY = '_zseo_desc';

	/** Zinn® SEO's template variables (`%title%`, `%term(category)%`): never translated. */
	public const VARIABLES = '/%[a-z][a-z0-9_-]{0,63}(?:\([^)%]{1,80}\))?%/i';

	/** Option: the robots pass was run again for Zinn® SEO's setting. */
	public const STATE = 'tranzly_robots_sync_zseo';

	/**
	 * Hook everything (nothing but one `defined()` when Zinn® SEO is not there).
	 *
	 * @return void
	 */
	public static function register(): void {
		// Zinn® SEO may load after Tranzly: ask once every plugin has loaded.
		add_action( 'plugins_loaded', array( self::class, 'boot' ), 20 );
	}

	/**
	 * `plugins_loaded`: wire the two plugins together, when both are active.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! self::active() ) {
			return;
		}
		add_filter( 'tranzly_robots_meta_keys', array( self::class, 'robots_keys' ) );
		add_filter( 'tranzly_integrations', array( self::class, 'integrations' ) );
		add_filter( 'zinn_seo_translate_fields_step', array( self::class, 'catch_up' ), 10, 3 );
		add_filter( 'zinn_seo_head_tags', array( self::class, 'og_alternates' ), 20 );
		add_filter( 'tranzly_shared_strings', array( self::class, 'shared_strings' ), 10, 2 );
		add_filter( 'zinn_seo_template', array( self::class, 'template' ), 10, 1 );
		add_filter( 'zinn_seo_site_text', array( self::class, 'site_text' ), 10, 3 );
		add_action( 'admin_init', array( self::class, 'resync_robots' ), 5 );
	}

	/**
	 * Is Zinn® SEO active on this site?
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return defined( 'ZINN_SEO_VERSION' ) && function_exists( 'zinn_seo' );
	}

	/**
	 * Does Zinn® SEO print the page's head (it stands back while another SEO plugin does)?
	 *
	 * @return bool
	 */
	public static function prints_head(): bool {
		if ( ! self::active() ) {
			return false;
		}
		$head = '\ZinnDigital\ZinnSEO\Head\Head';

		return ! is_callable( array( $head, 'should_print' ) ) || (bool) call_user_func( array( $head, 'should_print' ) );
	}

	/**
	 * Is a module of Zinn® SEO switched on?
	 *
	 * @param string $module Module id (`sitemaps`, `social`).
	 * @return bool
	 */
	public static function module_on( string $module ): bool {
		if ( ! self::active() ) {
			return false;
		}
		$api = call_user_func( 'zinn_seo' );

		return is_object( $api ) && is_callable( array( $api, 'module_enabled' ) ) && (bool) $api->module_enabled( $module );
	}

	/**
	 * Does Zinn® SEO print the Open Graph tags of this site?
	 *
	 * @return bool
	 */
	public static function prints_open_graph(): bool {
		return self::prints_head() && self::module_on( 'social' );
	}

	/**
	 * Does Zinn® SEO draw the site's sitemap?
	 *
	 * @return bool
	 */
	public static function draws_sitemap(): bool {
		return self::prints_head() && self::module_on( 'sitemaps' );
	}

	/**
	 * Where Zinn® SEO's sitemap is (its own answer: the address differs in its no-trace mode).
	 *
	 * @return string
	 */
	public static function sitemap_url(): string {
		$sitemaps = '\ZinnDigital\ZinnSEO\Sitemaps\Sitemaps';
		if ( is_callable( array( $sitemaps, 'url' ) ) ) {
			return (string) call_user_func( array( $sitemaps, 'url' ) );
		}

		return Router::raw_home() . '/sitemap.xml';
	}

	/**
	 * `tranzly_robots_meta_keys`: Zinn® SEO's robots setting follows the original too.
	 *
	 * @param mixed $keys The keys.
	 * @return array<int, string>
	 */
	public static function robots_keys( $keys ): array {
		$keys   = array_values( array_map( 'strval', (array) $keys ) );
		$keys[] = self::ROBOTS_KEY;

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Write a translation's robots setting through Zinn® SEO, so its sitemap, its llms.txt and
	 * its change history hear of it (a bare meta write would leave a page that was just hidden
	 * from search in the sitemap until its next save).
	 *
	 * @param int   $target The translation.
	 * @param mixed $value  The original's setting, or null when it has none.
	 * @return bool Written through Zinn® SEO.
	 */
	public static function write_robots( int $target, $value ): bool {
		return self::set_field( 'post', $target, 'robots', is_array( $value ) ? $value : array() );
	}

	/**
	 * Set one of Zinn® SEO's fields through its own API.
	 *
	 * @param string $kind  `post` or `term`.
	 * @param int    $id    Object ID.
	 * @param string $field Field name (`title`, `desc`, `robots`…).
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public static function set_field( string $kind, int $id, string $field, $value ): bool {
		if ( ! self::active() ) {
			return false;
		}
		$api    = call_user_func( 'zinn_seo' );
		$method = 'term' === $kind ? 'set_term_field' : 'set_post_field';
		if ( ! is_object( $api ) || ! is_callable( array( $api, $method ) ) ) {
			return false;
		}

		return true === $api->$method( $id, $field, $value );
	}

	/**
	 * `admin_init`, once: a site that was translated BEFORE this version has translations whose
	 * Zinn® SEO robots setting was never copied, and the one-time pass of {@see Robots} may already
	 * have finished. Start it again from the first original (it resumes from its cursor, a
	 * hundred originals per request, and writes only what differs).
	 *
	 * @return void
	 */
	public static function resync_robots(): void {
		if ( 'done' === get_option( self::STATE ) ) {
			return;
		}
		update_option( self::STATE, 'done', false );
		update_option( Robots::STATE, 0, false );
	}

	/**
	 * `tranzly_shared_strings`: Zinn® SEO's site-wide words — its title and description patterns,
	 * the llms.txt summary, the description of the organisation — join the site's own texts
	 * (beside the site title and the tagline), so they are translated and corrected on the same
	 * screen and kept in the same per-language store every translated page already loads.
	 *
	 * @param mixed $sources Key => source.
	 * @param mixed $scope   The scope.
	 * @return array<string, mixed>
	 */
	public static function shared_strings( $sources, $scope = '' ): array {
		$sources = (array) $sources;
		if ( 'site' !== $scope || ! function_exists( 'zinn_seo_translatable_texts' ) ) {
			return $sources;
		}
		return self::with_site_texts( $sources, (array) call_user_func( 'zinn_seo_translatable_texts' ) );
	}

	/**
	 * A scope's sources with Zinn® SEO's texts added, each under the key of its own words. A text
	 * with variables, or of several lines, travels as markup: its variables masked so no engine
	 * translates them, its line breaks kept. Pure.
	 *
	 * @param array<string, mixed> $sources Key => source.
	 * @param array<int, mixed>    $texts   Zinn® SEO's texts.
	 * @return array<string, mixed>
	 */
	public static function with_site_texts( array $sources, array $texts ): array {
		foreach ( $texts as $text ) {
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				continue;
			}
			$sources[ self::text_key( $text ) ] = self::needs_markup( $text ) ? array(
				'text'   => Placeholders::mask( $text, self::VARIABLES )[0],
				'format' => 'html',
			) : $text;
		}

		return $sources;
	}

	/**
	 * `zinn_seo_template`: a page kind's title and description patterns in the language viewed.
	 *
	 * @param mixed $record The record (`title`, `desc`, `og_title`, `og_desc`, …).
	 * @return mixed
	 */
	public static function template( $record ) {
		if ( ! is_array( $record ) ) {
			return $record;
		}
		$lang = Strings::front_language();
		if ( null === $lang ) {
			return $record;
		}
		foreach ( array( 'title', 'desc', 'og_title', 'og_desc' ) as $part ) {
			if ( is_string( $record[ $part ] ?? null ) && '' !== trim( $record[ $part ] ) ) {
				$record[ $part ] = self::translated( trim( $record[ $part ] ), $lang );
			}
		}

		return $record;
	}

	/**
	 * `zinn_seo_site_text`: a site-wide text in a language.
	 *
	 * @param mixed $text The text as written.
	 * @param mixed $key  The setting it comes from; `blogname` and `blogdescription` are the
	 *                    site title and tagline, which have keys of their own in the store.
	 * @param mixed $lang The language asked for; '' for the one being viewed.
	 * @return mixed
	 */
	public static function site_text( $text, $key = '', $lang = '' ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $text;
		}
		$code = '' === (string) $lang ? Strings::front_language() : Languages::resolve( (string) $lang );
		if ( null === $code || Languages::default_code() === $code ) {
			return $text;
		}
		if ( in_array( $key, Strings::SITE_FIELDS, true ) ) {
			$stored = Strings::all( $code )[ 'site.' . $key ] ?? '';

			return '' !== $stored ? $stored : $text;
		}

		return self::translated( trim( $text ), $code );
	}

	/**
	 * The stored translation of a site-wide text, or the text itself when there is none — or when
	 * the translation lost one of the text's variables (a title that cannot resolve is worse than
	 * one in the main language).
	 *
	 * @param string $text The text as written.
	 * @param string $lang A language code.
	 * @return string
	 */
	public static function translated( string $text, string $lang ): string {
		return self::from_store( $text, (string) ( Strings::all( $lang )[ self::text_key( $text ) ] ?? '' ) );
	}

	/**
	 * A text's translation as the page uses it, from what the store holds for it. Pure.
	 *
	 * @param string $text   The text as written.
	 * @param string $stored What the store holds ('' when nothing).
	 * @return string
	 */
	public static function from_store( string $text, string $stored ): string {
		if ( '' === $stored ) {
			return $text;
		}
		if ( ! self::needs_markup( $text ) ) {
			return $stored;
		}
		$out = trim( Placeholders::unmask( $stored, Placeholders::mask( $text, self::VARIABLES )[1] ) );

		return '' !== $out && self::variables_of( $out ) === self::variables_of( $text ) ? $out : $text;
	}

	/**
	 * The store's key of a shared text.
	 *
	 * @param string $text The text as written.
	 * @return string
	 */
	public static function text_key( string $text ): string {
		return 'text.' . sha1( $text );
	}

	/**
	 * Does a text travel as markup (it holds variables to protect, or more than one line)?
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function needs_markup( string $text ): bool {
		return Placeholders::has( $text, self::VARIABLES ) || false !== strpos( $text, "\n" );
	}

	/**
	 * The variables of a text, sorted (to compare two texts' sets).
	 *
	 * @param string $text Text.
	 * @return array<int, string>
	 */
	private static function variables_of( string $text ): array {
		preg_match_all( self::VARIABLES, $text, $m );
		$found = array_map( 'strtolower', $m[0] );
		sort( $found );

		return $found;
	}

	/**
	 * `tranzly_integrations`: translate Zinn® SEO's fields with the post.
	 *
	 * @param mixed $all The integrations.
	 * @return array<int, mixed>
	 */
	public static function integrations( $all ): array {
		$all = (array) $all;
		if ( class_exists( '\ZinnDigital\Tranzly\Integrations\Integration' ) ) {
			require_once __DIR__ . '/class-zinn-seo-fields.php';
			$all[] = new Zinn_Seo_Fields();
		}

		return $all;
	}

	/**
	 * `zinn_seo_translate_fields_step`: fill the SEO fields that translations made earlier lack
	 * ({@see Zinn_Seo_Fields::catch_up()}; the class is loaded only when the work is asked for).
	 *
	 * @param mixed $given Another listener's answer.
	 * @param mixed $after The last original of the previous step.
	 * @param mixed $limit Originals per step.
	 * @return mixed
	 */
	public static function catch_up( $given = null, $after = 0, $limit = 10 ) {
		if ( ! class_exists( '\ZinnDigital\Tranzly\Integrations\Integration' ) ) {
			return $given;
		}
		require_once __DIR__ . '/class-zinn-seo-fields.php';

		return Zinn_Seo_Fields::catch_up( $given, $after, $limit );
	}

	/**
	 * `zinn_seo_head_tags`: the page's other languages, right after Zinn® SEO's own `og:locale`
	 * (Facebook and LinkedIn read them to offer the page in the reader's language).
	 *
	 * @param mixed $lines The head's lines.
	 * @return array<int|string, mixed>
	 */
	public static function og_alternates( $lines ) {
		return is_array( $lines ) ? self::with_alternates( $lines, self::og_alternate_lines() ) : $lines;
	}

	/**
	 * A head's lines with more lines placed right after its `og:locale` tag; nothing is added
	 * when it has none (Zinn® SEO's social tags are switched off). Pure.
	 *
	 * @param array<int|string, mixed> $lines The head's lines.
	 * @param array<int, string>       $extra Lines to add.
	 * @return array<int|string, mixed>
	 */
	public static function with_alternates( array $lines, array $extra ): array {
		$at = null;
		foreach ( $lines as $i => $line ) {
			if ( is_string( $line ) && false !== strpos( $line, 'property="og:locale"' ) ) {
				$at = $i;
				break;
			}
		}
		$out = array();
		foreach ( $lines as $i => $line ) {
			if ( is_int( $i ) ) {
				$out[] = $line;
			} else {
				$out[ $i ] = $line;
			}
			if ( $i === $at ) {
				foreach ( $extra as $tag ) {
					$out[] = $tag;
				}
			}
		}

		return $out;
	}

	/**
	 * The `og:locale:alternate` tags of the page being viewed (the pure half is in Head).
	 *
	 * @return array<int, string>
	 */
	public static function og_alternate_lines(): array {
		$current = Languages::current();
		$out     = array();
		foreach ( array_keys( Head::alternates() ) as $code ) {
			if ( $code !== $current ) {
				$out[] = '<meta property="og:locale:alternate" content="' . esc_attr( Head::og_locale( (string) $code ) ) . '">';
			}
		}

		return $out;
	}
}
