<?php
/**
 * Walking a builder's data tree (arrays and objects) for text, and writing translations back.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every page builder stores its page as a tree — Elementor and Bricks as JSON arrays, Beaver
 * Builder as serialized objects, Breakdance as JSON inside JSON — with the visible text at leaves
 * among thousands of settings that must not change (colours, sizes, IDs, links). This finds the
 * leaves a predicate calls text, keyed by their PATH (`0/elements/1/settings/title`), and writes
 * translations back to exactly those paths, leaving every other leaf, every key order and every
 * object class as it was — so the builder opens the translated page exactly like the original.
 *
 * Pure: no WordPress state; the unit suite proves the round trip.
 */
final class Tree {

	/** Separates path steps (keys may contain dots; none of these builders uses a slash in a key). */
	public const SEP = '/';

	/**
	 * Collect text leaves.
	 *
	 * @param mixed    $data      The tree.
	 * @param callable $is_text   `( string $key, string $value, array $path, mixed $holder ): ?string`
	 *                            — returns the leaf's format (`text` or `html`) or null to skip it.
	 * @param callable $descend   `( string $key, array $path, mixed $node ): bool` — false skips a subtree.
	 * @return array<string, array{text: string, format: string}> Path => segment.
	 */
	public static function collect( $data, callable $is_text, ?callable $descend = null ): array {
		$out = array();
		self::walk(
			$data,
			array(),
			static function ( array $path, string $value, $holder ) use ( &$out, $is_text ): ?string {
				$format = $is_text( (string) end( $path ), $value, $path, $holder );
				if ( null !== $format && self::has_words( $value ) ) {
					$out[ implode( self::SEP, $path ) ] = array(
						'text'   => $value,
						'format' => 'html' === $format ? 'html' : 'text',
					);
				}
				return null;
			},
			$descend
		);

		return $out;
	}

	/**
	 * Write translations back to their paths.
	 *
	 * @param mixed                 $data         The tree.
	 * @param array<string, string> $translations Path => translation.
	 * @return mixed The tree with those leaves replaced.
	 */
	public static function apply( $data, array $translations ) {
		if ( array() === $translations ) {
			return $data;
		}

		return self::walk(
			$data,
			array(),
			static function ( array $path ) use ( $translations ): ?string {
				$key = implode( self::SEP, $path );
				return isset( $translations[ $key ] ) && '' !== $translations[ $key ] ? $translations[ $key ] : null;
			},
			null
		);
	}

	/**
	 * The walk: visit every string leaf, replace it with what the visitor returns.
	 *
	 * @param mixed              $node    A node.
	 * @param array<int, string> $path    Its path.
	 * @param callable           $visit   `( array $path, string $value, mixed $holder ): ?string`.
	 * @param callable|null      $descend Subtree filter.
	 * @return mixed
	 */
	private static function walk( $node, array $path, callable $visit, ?callable $descend ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $key => $child ) {
				$here = array_merge( $path, array( (string) $key ) );
				if ( null !== $descend && ( is_array( $child ) || is_object( $child ) ) && ! $descend( (string) $key, $here, $child ) ) {
					continue;
				}
				$node[ $key ] = self::leaf( $child, $here, $visit, $descend, $node );
			}
			return $node;
		}
		if ( is_object( $node ) ) {
			$copy = clone $node;
			foreach ( get_object_vars( $copy ) as $key => $child ) {
				$here = array_merge( $path, array( (string) $key ) );
				if ( null !== $descend && ( is_array( $child ) || is_object( $child ) ) && ! $descend( (string) $key, $here, $child ) ) {
					continue;
				}
				$copy->$key = self::leaf( $child, $here, $visit, $descend, $copy );
			}
			return $copy;
		}

		return $node;
	}

	/**
	 * One child: recurse into a container, visit a string.
	 *
	 * @param mixed              $child   The child.
	 * @param array<int, string> $here    Its path.
	 * @param callable           $visit   Visitor.
	 * @param callable|null      $descend Subtree filter.
	 * @param mixed              $holder  The container.
	 * @return mixed
	 */
	private static function leaf( $child, array $here, callable $visit, ?callable $descend, $holder ) {
		if ( is_array( $child ) || is_object( $child ) ) {
			return self::walk( $child, $here, $visit, $descend );
		}
		if ( is_string( $child ) ) {
			$new = $visit( $here, $child, $holder );
			return null === $new ? $child : $new;
		}

		return $child;
	}

	/**
	 * Does a value hold words a reader reads (not only digits, a colour, a URL, a size)?
	 *
	 * @param string $value A leaf.
	 * @return bool
	 */
	public static function has_words( string $value ): bool {
		$plain = trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $plain || ! \ZinnDigital\Tranzly\Content\Block_Parser::has_words( $plain ) ) {
			return false;
		}

		return ! self::is_code( $plain );
	}

	/**
	 * Is a value a link, a colour, a size, an ID or a class list rather than words?
	 *
	 * @param string $value A value.
	 * @return bool
	 */
	public static function is_code( string $value ): bool {
		$value = trim( $value );

		return 1 === preg_match( '#^(?:(?:https?|mailto|tel|ftp|data):|//|/[^\s]*$|\#[\w-]*$|[a-z]+\([^)]*\)$|-?\d+(?:\.\d+)?(?:px|em|rem|%|vh|vw|deg|s|ms)?$|[a-f0-9]{8,}$)#i', $value )
			|| 1 === preg_match( '/^[a-z0-9]+(?:_[a-z0-9]+)+$/', $value );
	}

	/**
	 * A key that names a link, an id, a style or media — never text, whatever its value.
	 *
	 * @param string $key A key.
	 * @return bool
	 */
	public static function is_code_key( string $key ): bool {
		$words = 'url|href|src|srcset|link|links|id|ids|slug|class|classes|css|style|type|rel|target|anchor|icon|image|img|media|video|color|colour|font|size|width|height|align|animation|svg|tag|key|hash|nonce|format|unit|lang|locale|code|shortcode|html';

		// Snake, kebab or whole names (button_url, link-id, url) and camelCase ones (buttonUrl, imageId).
		return 1 === preg_match( '/(?:^|[_-])(?:' . $words . ')$/i', $key )
			|| 1 === preg_match( '/[a-z0-9](?:' . implode( '|', array_map( 'ucfirst', explode( '|', $words ) ) ) . ')$/', $key );
	}
}
