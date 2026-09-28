<?php
/**
 * Shortcode-built pages (Divi 4, WPBakery, Oxygen classic): text between tags and text attributes.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ WHY NOT THE BLOCK PARSER. A shortcode page is one "classic" chunk to the block parser, so the
 * whole page — `[et_pb_text admin_label="Text" _builder_version="4.27"]…` — would go to the engine
 * as HTML, and an engine will happily translate `admin_label`, reorder attributes or "fix" the
 * brackets, after which the builder shows a broken layout. This splits the page into TAGS (never
 * sent, written back byte for byte) and the TEXT between them, and — for tags of the builder whose
 * page this is — the attribute values that are visible text (a button's label, a heading).
 *
 * Keys: `t<n>` for the n-th text run, `a<n>.<attribute>` for an attribute of the n-th tag. The
 * same page always splits the same way, so a translation's pieces pair with the original's.
 *
 * Pure: no WordPress state.
 */
final class Shortcodes {

	/** A shortcode tag: `[name attrs]`, `[name attrs /]` or `[/name]`, quoted values may hold `]`. */
	private const TAG = '/\[(\/?)([A-Za-z][\w-]*)((?:[^\]"\']|"[^"]*"|\'[^\']*\')*)\]/';

	/** One attribute: `name="v"`, `name='v'` or `name=v`. */
	private const ATTR = '/([\w-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'\]]+))/';

	/**
	 * Is this content made with shortcodes of the given tag prefixes?
	 *
	 * @param string             $content  Post content.
	 * @param array<int, string> $prefixes Tag prefixes (`et_pb_`, `vc_`).
	 * @return bool
	 */
	public static function uses( string $content, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( str_contains( $content, '[' . $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split content into tokens: `[ 'tag', raw, name, attrs ]` or `[ 'text', raw ]`.
	 *
	 * @param string $content Content.
	 * @return array<int, array<int, string>>
	 */
	public static function tokens( string $content ): array {
		$out = array();
		$at  = 0;
		if ( preg_match_all( self::TAG, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $match ) {
				$start = (int) $match[0][1];
				if ( $start > $at ) {
					$out[] = array( 'text', substr( $content, $at, $start - $at ) );
				}
				$out[] = array( 'tag', (string) $match[0][0], (string) $match[2][0], (string) $match[3][0], (string) $match[1][0] );
				$at    = $start + strlen( (string) $match[0][0] );
			}
		}
		if ( $at < strlen( $content ) ) {
			$out[] = array( 'text', substr( $content, $at ) );
		}

		return $out;
	}

	/**
	 * The segments of a shortcode page.
	 *
	 * @param string             $content  Content.
	 * @param array<int, string> $prefixes The builder's tag prefixes (only their attributes are read).
	 * @param callable           $is_attr  `( string $tag, string $attribute, string $value ): bool`.
	 * @param array<int, string> $raw_tags Tags whose enclosed content is code, never text.
	 * @return array<string, array{text: string, format: string}>
	 */
	public static function segments( string $content, array $prefixes, callable $is_attr, array $raw_tags = array() ): array {
		$out = array();
		self::walk(
			$content,
			$prefixes,
			$is_attr,
			$raw_tags,
			static function ( string $key, string $text, string $format ) use ( &$out ): ?string {
				$out[ $key ] = array(
					'text'   => $text,
					'format' => $format,
				);
				return null;
			}
		);

		return $out;
	}

	/**
	 * The content with translations written back.
	 *
	 * @param string                $content      Content.
	 * @param array<int, string>    $prefixes     Tag prefixes.
	 * @param callable              $is_attr      Attribute predicate.
	 * @param array<int, string>    $raw_tags     Code tags.
	 * @param array<string, string> $translations Key => translation.
	 * @return string
	 */
	public static function rebuild( string $content, array $prefixes, callable $is_attr, array $raw_tags, array $translations ): string {
		return self::walk(
			$content,
			$prefixes,
			$is_attr,
			$raw_tags,
			static fn( string $key ): ?string => isset( $translations[ $key ] ) && '' !== $translations[ $key ] ? $translations[ $key ] : null
		);
	}

	/**
	 * Walk the tokens, visiting text runs and text attributes, rebuilding with replacements.
	 *
	 * @param string             $content  Content.
	 * @param array<int, string> $prefixes Tag prefixes.
	 * @param callable           $is_attr  Attribute predicate.
	 * @param array<int, string> $raw_tags Code tags.
	 * @param callable           $visit    `( key, text, format ): ?string`.
	 * @return string
	 */
	private static function walk( string $content, array $prefixes, callable $is_attr, array $raw_tags, callable $visit ): string {
		$out    = '';
		$raw    = 0; // Depth inside a code tag.
		$text_n = 0;
		$tag_n  = 0;
		foreach ( self::tokens( $content ) as $token ) {
			if ( 'text' === $token[0] ) {
				$key = 't' . ( $text_n++ );
				if ( 0 === $raw && Tree::has_words( $token[1] ) ) {
					$new  = $visit( $key, $token[1], 'html' );
					$out .= null === $new ? $token[1] : self::keep_space( $token[1], $new );
				} else {
					$out .= $token[1];
				}
				continue;
			}
			$n    = $tag_n++;
			$name = $token[2];
			if ( in_array( $name, $raw_tags, true ) ) {
				$raw += '/' === $token[4] ? -1 : ( self::closes_itself( $token[3] ) ? 0 : 1 );
				$raw  = max( 0, $raw );
				$out .= $token[1];
				continue;
			}
			if ( '/' === $token[4] || ! self::owned( $name, $prefixes ) ) {
				$out .= $token[1];
				continue;
			}
			$out .= self::rewrite_attributes( $token[1], $token[3], $name, 'a' . $n, $is_attr, $visit );
		}

		return $out;
	}

	/**
	 * Visit a tag's text attributes and rewrite only their values.
	 *
	 * @param string   $raw     The whole tag.
	 * @param string   $attrs   Its attribute string.
	 * @param string   $name    Tag name.
	 * @param string   $prefix  Key prefix for this tag.
	 * @param callable $is_attr Predicate.
	 * @param callable $visit   Visitor.
	 * @return string
	 */
	private static function rewrite_attributes( string $raw, string $attrs, string $name, string $prefix, callable $is_attr, callable $visit ): string {
		if ( ! preg_match_all( self::ATTR, $attrs, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $raw;
		}
		$base    = strpos( $raw, $attrs );
		$base    = false === $base ? 0 : $base;
		$changes = array();
		foreach ( $m as $match ) {
			$attribute = (string) $match[1][0];
			$group     = isset( $match[2] ) && -1 !== $match[2][1] ? 2 : ( isset( $match[3] ) && -1 !== $match[3][1] ? 3 : 4 );
			if ( ! isset( $match[ $group ] ) || -1 === $match[ $group ][1] ) {
				continue;
			}
			$value = (string) $match[ $group ][0];
			if ( str_starts_with( $value, '@ET-DC@' ) || ! Tree::has_words( $value ) || ! $is_attr( $name, $attribute, $value ) ) {
				continue;
			}
			$new = $visit( $prefix . '.' . $attribute, $value, 'text' );
			if ( null !== $new ) {
				// The value lives inside `"…"` inside `[…]`: those three characters must not appear raw.
				$changes[] = array( $base + (int) $match[ $group ][1], strlen( $value ), str_replace( array( '"', '[', ']' ), array( '&quot;', '&#91;', '&#93;' ), $new ) );
			}
		}
		foreach ( array_reverse( $changes ) as $change ) {
			$raw = substr_replace( $raw, $change[2], $change[0], $change[1] );
		}

		return $raw;
	}

	/**
	 * Does a tag belong to the builder?
	 *
	 * @param string             $name     Tag name.
	 * @param array<int, string> $prefixes Prefixes.
	 * @return bool
	 */
	private static function owned( string $name, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is a tag self-closing (`[tag /]`)?
	 *
	 * @param string $attrs Attribute string.
	 * @return bool
	 */
	private static function closes_itself( string $attrs ): bool {
		return str_ends_with( rtrim( $attrs ), '/' );
	}

	/**
	 * Keep a text run's leading and trailing whitespace (builders put line breaks between tags).
	 *
	 * @param string $source      Original run.
	 * @param string $translation Its translation.
	 * @return string
	 */
	private static function keep_space( string $source, string $translation ): string {
		preg_match( '/^\s*/', $source, $lead );
		preg_match( '/\s*$/', $source, $trail );

		return ( $lead[0] ?? '' ) . trim( $translation ) . ( $trail[0] ?? '' );
	}
}
