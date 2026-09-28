<?php
/**
 * How languages appear in URLs, and the multilingual SEO switches.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

use ZinnDigital\Tranzly\Core\Edition;
use ZinnDigital\Tranzly\Core\Locales;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `tranzly_urls` option.
 *
 * - `mode`: `directory` (/de/…, the free default, tz-s1), `query` (?lang=de, what 3.0.x did),
 *   `subdomain` (de.example.com) or `domain` (example.de) — the last two are Pro (tz-s2).
 * - `segments`: language => the URL word that selects it (`de`, `pt-br`). Filled in when empty.
 * - `domains`: language => host, for `domain` mode.
 * - `bases`: language => [ taxonomy or post type => translated base ] (tz-s3: /de/kategorie/…).
 * - `suggest`: the polite "view this page in your language" banner (tz-s8). On by default.
 * - `noindex_untranslated`: Pro (tz-s7). Off by default.
 *
 * ⛔ Anything invalid is REFUSED, never coerced: a URL setting that silently became something
 * else would move every page of a site to addresses nobody asked for.
 */
final class Url_Settings {

	/** The option name. */
	public const OPTION = 'tranzly_urls';

	/** Every URL mode. */
	public const MODES = array( 'directory', 'query', 'subdomain', 'domain' );

	/** Modes only the Pro edition may choose. */
	public const PRO_MODES = array( 'subdomain', 'domain' );

	/** Things whose URL base can be translated: the core taxonomies, and WooCommerce's. */
	public const BASES = array( 'category', 'post_tag', 'product', 'product_cat', 'product_tag' );

	/**
	 * Per-request memo.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $memo = null;

	/**
	 * The stored settings over the defaults, with every language given a segment.
	 *
	 * @return array{mode: string, segments: array<string, string>, domains: array<string, string>, bases: array<string, array<string, string>>, suggest: bool, noindex_untranslated: bool}
	 */
	public static function get(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$mode = in_array( $stored['mode'] ?? '', self::MODES, true ) ? (string) $stored['mode'] : 'directory';
		if ( in_array( $mode, self::PRO_MODES, true ) && ! Edition::pro() ) {
			$mode = 'directory'; // A lapsed licence falls back to subfolders, never to a broken site.
		}

		$segments = array();
		$taken    = array();
		$saved    = is_array( $stored['segments'] ?? null ) ? $stored['segments'] : array();
		foreach ( Languages::all() as $language ) {
			$code = $language['code'];
			$seg  = isset( $saved[ $code ] ) && self::is_valid_segment( (string) $saved[ $code ] ) ? (string) $saved[ $code ] : '';
			if ( '' === $seg || isset( $taken[ $seg ] ) ) {
				$seg = self::default_segment( $code, $taken );
			}
			$taken[ $seg ]     = true;
			$segments[ $code ] = $seg;
		}

		$domains = array();
		foreach ( is_array( $stored['domains'] ?? null ) ? $stored['domains'] : array() as $code => $host ) {
			if ( null !== Languages::resolve( (string) $code ) && self::is_valid_host( (string) $host ) ) {
				$domains[ (string) $code ] = strtolower( (string) $host );
			}
		}

		$bases = array();
		foreach ( is_array( $stored['bases'] ?? null ) ? $stored['bases'] : array() as $code => $map ) {
			if ( null === Languages::resolve( (string) $code ) || ! is_array( $map ) ) {
				continue;
			}
			foreach ( $map as $what => $base ) {
				if ( in_array( $what, self::BASES, true ) && self::is_valid_base( (string) $base ) ) {
					$bases[ (string) $code ][ (string) $what ] = (string) $base;
				}
			}
		}

		self::$memo = array(
			'mode'                 => $mode,
			'segments'             => $segments,
			'domains'              => $domains,
			'bases'                => $bases,
			'suggest'              => false !== ( $stored['suggest'] ?? true ),
			'noindex_untranslated' => Edition::pro() && true === ( $stored['noindex_untranslated'] ?? false ),
		);

		return self::$memo;
	}

	/**
	 * Save. Returns the refusal for the first invalid field and changes nothing then.
	 *
	 * @param array<string, mixed> $input Any of the keys `get()` returns.
	 * @return true|\WP_Error
	 */
	public static function save( array $input ) {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( array_key_exists( 'mode', $input ) ) {
			$mode = (string) $input['mode'];
			if ( ! in_array( $mode, self::MODES, true ) ) {
				return self::refuse( 'mode', __( 'Choose how languages appear in your addresses: a folder, a subdomain, a separate domain or a query parameter.', 'tranzly' ) );
			}
			if ( in_array( $mode, self::PRO_MODES, true ) && ! Edition::pro() ) {
				return self::refuse( 'mode', __( 'Subdomains and separate domains per language are part of Tranzly Pro.', 'tranzly' ), 403 );
			}
			$stored['mode'] = $mode;
		}

		if ( array_key_exists( 'segments', $input ) ) {
			if ( ! is_array( $input['segments'] ) ) {
				return self::refuse( 'segments', __( 'The language folders must be a list.', 'tranzly' ) );
			}
			$seen = array();
			$out  = array();
			foreach ( $input['segments'] as $code => $segment ) {
				$code    = Languages::resolve( (string) $code );
				$segment = strtolower( trim( (string) $segment ) );
				if ( null === $code || ! self::is_valid_segment( $segment ) || isset( $seen[ $segment ] ) ) {
					/* translators: %s: the folder name that was refused. */
					return self::refuse( 'segments', sprintf( __( '"%s" cannot be a language folder. Use 2 to 12 lowercase letters, digits or hyphens, different for every language, and not a word WordPress already uses.', 'tranzly' ), $segment ) );
				}
				$seen[ $segment ] = true;
				$out[ $code ]     = $segment;
			}
			$stored['segments'] = $out;
		}

		if ( array_key_exists( 'domains', $input ) ) {
			if ( ! is_array( $input['domains'] ) ) {
				return self::refuse( 'domains', __( 'The domains must be a list.', 'tranzly' ) );
			}
			$out = array();
			foreach ( $input['domains'] as $code => $host ) {
				$code = Languages::resolve( (string) $code );
				$host = strtolower( trim( (string) $host ) );
				if ( '' === $host ) {
					continue;
				}
				if ( null === $code || ! self::is_valid_host( $host ) ) {
					/* translators: %s: the domain that was refused. */
					return self::refuse( 'domains', sprintf( __( '"%s" is not a domain name, such as example.de.', 'tranzly' ), $host ) );
				}
				$out[ $code ] = $host;
			}
			if ( count( $out ) !== count( array_unique( $out ) ) ) {
				return self::refuse( 'domains', __( 'Each language needs its own domain.', 'tranzly' ) );
			}
			$stored['domains'] = $out;
		}

		if ( array_key_exists( 'bases', $input ) ) {
			if ( ! is_array( $input['bases'] ) ) {
				return self::refuse( 'bases', __( 'The translated address words must be a list.', 'tranzly' ) );
			}
			$out = array();
			foreach ( $input['bases'] as $code => $map ) {
				$code = Languages::resolve( (string) $code );
				if ( null === $code || ! is_array( $map ) ) {
					return self::refuse( 'bases', __( 'The translated address words must be listed per language.', 'tranzly' ) );
				}
				foreach ( $map as $what => $base ) {
					$base = trim( (string) $base, " \t/" );
					if ( '' === $base ) {
						continue;
					}
					if ( ! in_array( $what, self::BASES, true ) || ! self::is_valid_base( $base ) ) {
						/* translators: %s: the address word that was refused. */
						return self::refuse( 'bases', sprintf( __( '"%s" cannot be used in an address. Use lowercase letters, digits and hyphens.', 'tranzly' ), $base ) );
					}
					$out[ $code ][ (string) $what ] = $base;
				}
			}
			$stored['bases'] = $out;
		}

		foreach ( array( 'suggest', 'noindex_untranslated' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				if ( ! is_bool( $input[ $flag ] ) ) {
					return self::refuse( $flag, __( 'This setting is on or off.', 'tranzly' ) );
				}
				if ( 'noindex_untranslated' === $flag && $input[ $flag ] && ! Edition::pro() ) {
					return self::refuse( $flag, __( 'Hiding untranslated pages from search engines is part of Tranzly Pro.', 'tranzly' ), 403 );
				}
				$stored[ $flag ] = $input[ $flag ];
			}
		}

		update_option( self::OPTION, $stored, true );
		self::reset();
		// The language folders and translated bases live in the rewrite rules' neighbourhood.
		update_option( 'rewrite_rules', '' );

		return true;
	}

	/**
	 * Create the option (empty = every default) when it is missing, autoloaded: WordPress runs a
	 * query on EVERY request for an option that does not exist, and this one is read on every
	 * request (the speed promise, tz-r14). Called on activation and in wp-admin, never on the front.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, array(), '', true );
		}
	}

	/**
	 * Forget the memo (after a save, and in tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$memo = null;
	}

	/**
	 * The URL word for a language nobody named one for: the primary language (`de`), or the whole
	 * code (`pt-br`) when another language already took the short one.
	 *
	 * @param string              $code  A language code.
	 * @param array<string, bool> $taken Segments already used.
	 * @return string
	 */
	public static function default_segment( string $code, array $taken = array() ): string {
		$short = strtolower( Locales::primary( $code ) );
		if ( self::is_valid_segment( $short ) && ! isset( $taken[ $short ] ) && ! self::shares_primary( $code ) ) {
			return $short;
		}
		$long = strtolower( str_replace( '_', '-', $code ) );
		$try  = $long;
		for ( $n = 2; isset( $taken[ $try ] ); $n++ ) {
			$try = $long . '-' . $n;
		}

		return $try;
	}

	/**
	 * Is this a usable language folder? Not a word WordPress routes itself.
	 *
	 * @param string $segment Candidate.
	 * @return bool
	 */
	public static function is_valid_segment( string $segment ): bool {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{1,11}$/', $segment ) ) {
			return false;
		}

		return ! in_array( $segment, array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'page', 'comments', 'search', 'author', 'embed', 'trackback' ), true );
	}

	/**
	 * Is this a host name (no scheme, no path)?
	 *
	 * @param string $host Candidate.
	 * @return bool
	 */
	public static function is_valid_host( string $host ): bool {
		return 1 === preg_match( '/^(?=.{3,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}(?::[0-9]{2,5})?$/', strtolower( $host ) );
	}

	/**
	 * Is this a usable translated base (one or two path words)?
	 *
	 * @param string $base Candidate.
	 * @return bool
	 */
	public static function is_valid_base( string $base ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,59}(?:\/[a-z0-9][a-z0-9-]{0,59})?$/', $base );
	}

	/**
	 * Does another listed language share this one's primary subtag (pt_BR and pt_PT)?
	 *
	 * @param string $code A language code.
	 * @return bool
	 */
	private static function shares_primary( string $code ): bool {
		$primary = Locales::primary( $code );
		foreach ( Languages::all() as $language ) {
			if ( $language['code'] !== $code && Locales::primary( $language['code'] ) === $primary ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A refusal.
	 *
	 * @param string $field   The field.
	 * @param string $message Plain-English reason.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private static function refuse( string $field, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error(
			'tranzly_invalid_url_setting',
			$message,
			array(
				'status' => $status,
				'field'  => $field,
			)
		);
	}
}
