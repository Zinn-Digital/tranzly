<?php
/**
 * Text inside a block's array and object attributes, and synced patterns per language.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Integrations;

use ZinnDigital\Tranzly\Contract;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ THE F6 CONTRACT, EXTENDED (agreed with the PBS lanes, docs/843 T6). A block attribute is
 * translatable when its schema says `"role": "content"`. The block parser translates such an
 * attribute when it is a STRING; a block with repeated items — tabs, an accordion, price rows,
 * testimonials — keeps its text in an ARRAY or OBJECT attribute, which nothing translated. Here,
 * every string leaf of a `role: content` array/object attribute that holds words is translated,
 * except under keys that name a link, id, class, type or media (`Tree::is_code_key`).
 *
 * Also: synced patterns (`wp_block`, used by Page Builder Sandwich for saved sections) are
 * translated like pages, and a page shows the pattern's translation in the visitor's language.
 */
final class Nested_Blocks extends Integration {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'blocks';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Blocks with repeated items, and synced patterns', 'tranzly' );
	}

	/**
	 * Always there: it is WordPress's own editor.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return function_exists( 'parse_blocks' );
	}

	/**
	 * Feature.
	 *
	 * @return string
	 */
	public function feature(): string {
		return 'tz-c5';
	}

	/**
	 * Hooks: synced patterns are translatable, and swapped at render.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'tranzly_post_types', array( self::class, 'add_patterns' ) );
		add_filter( 'render_block_data', array( self::class, 'swap_pattern' ), 5, 1 );
	}

	/**
	 * `tranzly_post_types`: synced patterns.
	 *
	 * @param array<int, string> $types Types.
	 * @return array<int, string>
	 */
	public static function add_patterns( $types ) {
		$types   = is_array( $types ) ? $types : array();
		$types[] = 'wp_block';

		return array_values( array_unique( $types ) );
	}

	/**
	 * `render_block_data`: a `core/block` reference points at the pattern's translation in the
	 * visitor's language (the page's own blocks stay as they are).
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	public static function swap_pattern( $block ) {
		if ( ! is_array( $block ) || 'core/block' !== ( $block['blockName'] ?? '' ) || empty( $block['attrs']['ref'] ) || is_admin() ) {
			return $block;
		}
		$lang = Languages::current();
		if ( Languages::default_code() === $lang ) {
			return $block;
		}
		$translation = Languages::translation( (int) $block['attrs']['ref'], $lang );
		if ( null !== $translation && 'publish' === get_post_status( $translation ) ) {
			$block['attrs']['ref'] = $translation;
		}

		return $block;
	}

	/**
	 * Segments: nested text of every block.
	 *
	 * @param \WP_Post $post The original.
	 * @return array<string, array{text: string, format: string}>
	 */
	public function segments( \WP_Post $post ): array {
		if ( ! str_contains( (string) $post->post_content, '<!-- wp:' ) ) {
			return array();
		}
		$out = array();
		self::walk(
			parse_blocks( (string) $post->post_content ),
			'',
			static function ( string $key, array $found ) use ( &$out ): ?array {
				foreach ( $found as $path => $segment ) {
					$out[ $key . Tree::SEP . $path ] = $segment;
				}
				return null;
			}
		);

		return $out;
	}

	/**
	 * Write nested text back into the content the block parser rebuilt.
	 *
	 * @param array<string, mixed>  $update The update.
	 * @param array<string, string> $texts  Key => translation.
	 * @param \WP_Post              $post   The original.
	 * @param string                $lang   Language.
	 * @return array<string, mixed>
	 */
	public function write( array $update, array $texts, \WP_Post $post, string $lang ): array {
		unset( $lang );
		if ( array() === $texts ) {
			return $update;
		}
		$content = (string) ( $update['post_content'] ?? $post->post_content );
		$blocks  = self::walk(
			parse_blocks( $content ),
			'',
			static function ( string $key, array $found, $value ) use ( $texts ) {
				$mine = array();
				foreach ( array_keys( $found ) as $path ) {
					if ( isset( $texts[ $key . Tree::SEP . $path ] ) ) {
						$mine[ $path ] = $texts[ $key . Tree::SEP . $path ];
					}
				}
				return array() === $mine ? null : array( Tree::apply( $value, $mine ) );
			}
		);

		$update['post_content'] = serialize_blocks( $blocks );

		return $update;
	}

	/**
	 * Walk blocks; for each nested `role: content` attribute call `$visit( key, found, value )`,
	 * where `found` is its text leaves; a returned `[ new value ]` replaces the attribute.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $path   Path of this level.
	 * @param callable                         $visit  Visitor.
	 * @return array<int, array<string, mixed>>
	 */
	private static function walk( array $blocks, string $path, callable $visit ): array {
		foreach ( $blocks as $i => $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			$here = '' === $path ? (string) $i : $path . '.' . $i;
			foreach ( self::nested_attributes( $name ) as $attribute ) {
				$value = $block['attrs'][ $attribute ] ?? null;
				if ( ! is_array( $value ) ) {
					continue;
				}
				$found = self::text_of( $value );
				if ( array() === $found ) {
					continue;
				}
				$new = $visit( $here . '.' . $attribute, $found, $value );
				if ( is_array( $new ) ) {
					$blocks[ $i ]['attrs'][ $attribute ] = $new[0];
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = self::walk( (array) $block['innerBlocks'], $here, $visit );
			}
		}

		return $blocks;
	}

	/**
	 * The text leaves of a nested attribute value.
	 *
	 * @param array<mixed> $value The value.
	 * @return array<string, array{text: string, format: string}>
	 */
	public static function text_of( array $value ): array {
		return Tree::collect(
			$value,
			static function ( string $key, string $text ): ?string {
				if ( Tree::is_code_key( $key ) ) {
					return null;
				}
				return 1 === preg_match( '/<[a-z][^>]*>/i', $text ) ? 'html' : 'text';
			},
			static fn( string $key ): bool => ! Tree::is_code_key( $key )
		);
	}

	/**
	 * A block type's `role: content` attributes whose type is array or object (read from the block
	 * registry, plus the `tranzly_translatable_attributes` filter via the contract).
	 *
	 * @param string $name Block name.
	 * @return array<int, string>
	 */
	public static function nested_attributes( string $name ): array {
		static $memo = array();
		if ( '' === $name ) {
			return array();
		}
		if ( ! isset( $memo[ $name ] ) ) {
			$type   = class_exists( '\WP_Block_Type_Registry' ) ? \WP_Block_Type_Registry::get_instance()->get_registered( $name ) : null;
			$schema = null !== $type && is_array( $type->attributes ) ? $type->attributes : array();
			$out    = array();
			foreach ( Contract::translatable_attributes( $name, $schema ) as $attribute ) {
				$kind = $schema[ $attribute ]['type'] ?? null;
				if ( in_array( $kind, array( 'array', 'object' ), true ) || ( is_array( $kind ) && array_intersect( $kind, array( 'array', 'object' ) ) ) ) {
					$out[] = (string) $attribute;
				}
			}
			$memo[ $name ] = $out;
		}

		return $memo[ $name ];
	}
}
