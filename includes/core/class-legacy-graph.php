<?php
/**
 * Turns the legacy plugin's per-post language meta into translation groups.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure: every input is an argument, so the unit suite runs the exact code the import runs.
 *
 * ⭐ Tranzly 1.x (1.0.1-1.1.1, measured on the wordpress.org 1.1.1 zip, TRZ-ASSETS 2026-09-30) wrote
 * the SAME shapes under a `tranzly_` prefix instead of `cn_`: `tranzly_mylang`,
 * `tranzly_post_translated_to` (edges keyed `tranzly_child_post_id`) and
 * `tranzly_post_translated_to_from` (edges keyed `tranzly_parent_post_id`); the scalars
 * `translated_from` / `translated_to` and `deepl_translated` are shared. Before 3.18.2 only the
 * `cn_` names were read, so a 1.x site upgraded with every translation group dropped.
 *
 * ⭐ WHAT THE LEGACY DATA ACTUALLY LOOKS LIKE (11-audit-tranzly.md §1, §7, and the fixture made by
 * the real legacy plugin, /home/zinn/plugins-overhaul/fixtures/tranzly-free-2.0.0):
 *
 * - a parent lists its children in `cn_post_translated_to` (`translated_to` = the CHILD's code);
 * - a child names its parent in `cn_post_translated_to_from` (`translated_from` = the PARENT's);
 * - `cn_mylang` is the post's own code; the scalars `translated_from` (on a child) and
 *   `translated_to` (on a parent) ALSO hold the post's own code — the names are inverted;
 * - ⛔ "Generate new" copied EVERY meta row of the parent onto the child, so a child usually
 *   carries a copy of its parent's `cn_post_translated_to` too, claiming its siblings as its own
 *   children. That is why the groups are built as CONNECTED COMPONENTS (every edge unites two
 *   posts) rather than by trusting any one post's list: the copied lists are wrong about who is
 *   the parent and right about who belongs together.
 * - an edge can point at a post that was deleted: the legacy plugin had no delete hook. Such an
 *   edge is REPORTED and skipped, never fatal.
 */
final class Legacy_Graph {

	/**
	 * Build the groups.
	 *
	 * @param array<int, array<string, mixed>> $facts       post ID => facts collected from meta:
	 *                                                      `lang`, `child_scalar`, `parent_scalar`
	 *                                                      (strings), `children` and `parents`
	 *                                                      (peer ID => legacy code).
	 * @param array<int, bool>                 $existing    post ID => true for every post that
	 *                                                      still exists (any status but revision).
	 * @param array<int, string>               $listed      The site's listed language codes.
	 * @param string                           $site_locale The site locale.
	 * @param string                           $default_lang The default language code.
	 * @return array{groups: array<int, array{source: int, members: array<int, string>}>, dangling: array<int, array{post: int, missing: int}>, conflicts: array<int, array{post: int, lang: string, kept: int}>, unresolved: array<int, int>, languages: array<int, string>}
	 */
	public static function build( array $facts, array $existing, array $listed, string $site_locale, string $default_lang ): array {
		$parent   = array();
		$dangling = array();
		$declared = array(); // post => codes other posts' edges declare for it.
		$outgoing = array(); // post => number of children it lists.

		$find = static function ( int $x ) use ( &$parent ): int {
			while ( $parent[ $x ] !== $x ) {
				$parent[ $x ] = $parent[ $parent[ $x ] ];
				$x            = $parent[ $x ];
			}
			return $x;
		};
		$add  = static function ( int $x ) use ( &$parent ): void {
			if ( ! isset( $parent[ $x ] ) ) {
				$parent[ $x ] = $x;
			}
		};

		ksort( $facts );
		foreach ( $facts as $post => $fact ) {
			$post = (int) $post;
			if ( empty( $existing[ $post ] ) ) {
				continue;
			}
			$add( $post );
			foreach ( array( 'children', 'parents' ) as $side ) {
				foreach ( (array) ( $fact[ $side ] ?? array() ) as $peer => $code ) {
					$peer = (int) $peer;
					if ( $peer <= 0 || $peer === $post ) {
						continue;
					}
					if ( empty( $existing[ $peer ] ) ) {
						$dangling[ $post . ':' . $peer ] = array(
							'post'    => $post,
							'missing' => $peer,
						);
						continue;
					}
					$add( $peer );
					$declared[ $peer ][] = (string) $code;
					if ( 'children' === $side ) {
						$outgoing[ $post ] = ( $outgoing[ $post ] ?? 0 ) + 1;
					}
					$a = $find( $post );
					$b = $find( $peer );
					if ( $a !== $b ) {
						$parent[ max( $a, $b ) ] = min( $a, $b );
					}
				}
			}
		}

		$components = array();
		foreach ( array_keys( $parent ) as $post ) {
			$components[ $find( (int) $post ) ][] = (int) $post;
		}
		ksort( $components );

		$groups     = array();
		$conflicts  = array();
		$unresolved = array();
		$languages  = array();
		foreach ( $components as $members ) {
			sort( $members );
			$resolved = array();
			foreach ( $members as $post ) {
				$code = self::own_code( $facts[ $post ] ?? array(), $declared[ $post ] ?? array() );
				$lang = '' === $code ? null : Locales::from_legacy( $code, $listed, $site_locale );
				if ( null === $lang ) {
					$unresolved[] = $post;
					continue;
				}
				$resolved[ $post ] = $lang;
			}
			if ( array() === $resolved ) {
				continue;
			}

			// The source: the member listing the most children; ties go to the oldest post.
			$source = array_key_first( $resolved );
			foreach ( $resolved as $post => $lang ) {
				if ( ( $outgoing[ $post ] ?? 0 ) > ( $outgoing[ $source ] ?? 0 ) ) {
					$source = $post;
				}
			}

			// One member per language: the source keeps its own; the first (oldest) other wins.
			$by_lang                         = array();
			$by_lang[ $resolved[ $source ] ] = $source;
			foreach ( $resolved as $post => $lang ) {
				if ( $post === $source ) {
					continue;
				}
				if ( isset( $by_lang[ $lang ] ) ) {
					$conflicts[] = array(
						'post' => $post,
						'lang' => $lang,
						'kept' => $by_lang[ $lang ],
					);
					// Kept out of the group but not forgotten: it stays a post in its language.
					if ( $lang !== $default_lang ) {
						$groups[] = array(
							'source'  => $post,
							'members' => array( $post => $lang ),
						);
					}
					continue;
				}
				$by_lang[ $lang ] = $post;
			}

			$group = array();
			foreach ( $by_lang as $lang => $post ) {
				$group[ $post ] = $lang;
				$languages[]    = $lang;
			}
			// A lone post in the default language needs no row: that is what "no row" means.
			if ( 1 === count( $group ) && in_array( $default_lang, $group, true ) ) {
				continue;
			}
			ksort( $group );
			$groups[] = array(
				'source'  => $source,
				'members' => $group,
			);
		}

		return array(
			'groups'     => $groups,
			'dangling'   => array_values( $dangling ),
			'conflicts'  => $conflicts,
			'unresolved' => $unresolved,
			'languages'  => array_values( array_unique( $languages ) ),
		);
	}

	/**
	 * A post's own legacy code: `cn_mylang`, then the inverted-name scalar, then what the edges
	 * pointing at it say (the most common value).
	 *
	 * @param array<string, mixed> $fact     The post's facts.
	 * @param array<int, string>   $declared Codes other posts declare for it.
	 * @return string
	 */
	private static function own_code( array $fact, array $declared ): string {
		foreach ( array( 'lang', 'child_scalar', 'parent_scalar' ) as $key ) {
			$value = trim( (string) ( $fact[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		$declared = array_filter( array_map( 'trim', $declared ) );
		if ( array() === $declared ) {
			return '';
		}
		$counts = array_count_values( $declared );
		arsort( $counts );

		return (string) array_key_first( $counts );
	}

	/**
	 * Parse one legacy meta row into a post's facts (the collect step calls this per row).
	 *
	 * @param array<string, mixed> $fact  The facts so far.
	 * @param string               $key   The meta key.
	 * @param mixed                $value The unserialised meta value.
	 * @return array<string, mixed>
	 */
	public static function absorb( array $fact, string $key, $value ): array {
		$fact += array(
			'lang'          => '',
			'child_scalar'  => '',
			'parent_scalar' => '',
			'children'      => array(),
			'parents'       => array(),
			'flags'         => array(),
		);
		switch ( $key ) {
			case 'cn_mylang':
			case 'tranzly_mylang':
				if ( '' === $fact['lang'] && is_scalar( $value ) ) {
					$fact['lang'] = (string) $value;
				}
				break;
			case 'translated_from':
				if ( is_scalar( $value ) ) {
					$fact['child_scalar'] = (string) $value;
				}
				break;
			case 'translated_to':
				if ( is_scalar( $value ) ) {
					$fact['parent_scalar'] = (string) $value;
				}
				break;
			case 'cn_post_translated_to':
			case 'tranzly_post_translated_to':
				$id = 'cn_post_translated_to' === $key ? 'cn_child_post_id' : 'tranzly_child_post_id';
				foreach ( is_array( $value ) ? $value : array() as $edge ) {
					if ( is_array( $edge ) && isset( $edge[ $id ] ) ) {
						$fact['children'][ (int) $edge[ $id ] ] = (string) ( $edge['translated_to'] ?? '' );
					}
				}
				break;
			case 'cn_post_translated_to_from':
			case 'tranzly_post_translated_to_from':
				$id = 'cn_post_translated_to_from' === $key ? 'cn_parent_post_id' : 'tranzly_parent_post_id';
				foreach ( is_array( $value ) ? $value : array() as $edge ) {
					if ( is_array( $edge ) && isset( $edge[ $id ] ) ) {
						$fact['parents'][ (int) $edge[ $id ] ] = (string) ( $edge['translated_from'] ?? '' );
					}
				}
				break;
			default:
				// `deepl_translated` and `_tranzly_post_translated_to_<CODE>` are status flags.
				$fact['flags'][ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $fact;
	}
}
