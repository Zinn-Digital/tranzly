<?php
/**
 * The block half of the Page Builder Sandwich ↔ Tranzly contract.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which block attributes are translatable, and translates them at render time.
 *
 * ⭐ THE RULE (docs/adr/0033): an attribute is translatable when its block.json schema says
 * `"role": "content"`, plus whatever the `tranzly_translatable_attributes` filter adds or removes.
 * A block author — Page Builder Sandwich or anyone else — opts in by declaring the role; nothing
 * about Tranzly is imported, so a block keeps working when Tranzly is absent.
 *
 * Every method that decides something takes its inputs as arguments, so the contract test runs
 * the same code the site runs, without WordPress.
 */
final class Contract {

	/**
	 * Hook render-time translation.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'render_block_data', array( self::class, 'filter_render_block_data' ), 10, 1 );
	}

	/**
	 * Attribute names whose schema declares `role: content`.
	 *
	 * @param array<string, mixed> $schema A block type's `attributes` schema.
	 * @return array<int, string>
	 */
	public static function content_attributes( array $schema ): array {
		$names = array();
		foreach ( $schema as $name => $definition ) {
			if ( is_array( $definition ) && 'content' === ( $definition['role'] ?? null ) ) {
				$names[] = (string) $name;
			}
		}

		return $names;
	}

	/**
	 * The translatable attributes of a block type.
	 *
	 * @param string                    $block_name A block name such as `pbs/fixture`.
	 * @param array<string, mixed>|null $schema     The attributes schema; read from the block
	 *                                              registry when null.
	 * @return array<int, string>
	 */
	public static function translatable_attributes( string $block_name, ?array $schema = null ): array {
		if ( null === $schema ) {
			$schema = array();
			if ( class_exists( '\WP_Block_Type_Registry' ) ) {
				$type = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
				if ( null !== $type && is_array( $type->attributes ) ) {
					$schema = $type->attributes;
				}
			}
		}

		/**
		 * Filters the attributes Tranzly translates for a block type.
		 *
		 * @param array<int, string> $attributes Names declared `role: content`.
		 * @param string             $block_name The block name.
		 */
		$names = apply_filters( 'tranzly_translatable_attributes', self::content_attributes( $schema ), $block_name );

		return array_values( array_unique( array_map( 'strval', is_array( $names ) ? $names : array() ) ) );
	}

	/**
	 * Every translatable string in a tree of parsed blocks.
	 *
	 * @param array<int, array<string, mixed>>    $blocks  Output of parse_blocks().
	 * @param array<string, array<string, mixed>> $schemas Block name => attributes schema, for
	 *                                                     callers without a registry.
	 * @return array<int, array{block: string, attribute: string, text: string}>
	 */
	public static function strings( array $blocks, array $schemas = array() ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' !== $name ) {
				foreach ( self::translatable_attributes( $name, $schemas[ $name ] ?? null ) as $attribute ) {
					$value = $block['attrs'][ $attribute ] ?? null;
					if ( is_string( $value ) && '' !== trim( $value ) ) {
						$out[] = array(
							'block'     => $name,
							'attribute' => $attribute,
							'text'      => $value,
						);
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::strings( $block['innerBlocks'], $schemas ) );
			}
		}

		return $out;
	}

	/**
	 * One parsed block with its translatable attributes translated into a language.
	 *
	 * Translation text comes from the `tranzly_translate_string` filter. With no translation
	 * source attached it returns the original, so an untranslated string renders unchanged rather
	 * than blank.
	 *
	 * @param array<string, mixed>      $block  A parsed block.
	 * @param string                    $code   The target language.
	 * @param array<string, mixed>|null $schema    The block's attributes schema, or null for the
	 *                                             registry.
	 * @param callable|null             $translate A `( string $text, string $code, array $context ): string`
	 *                                             translator to use instead of the filter.
	 * @return array<string, mixed>
	 */
	public static function translate_block( array $block, string $code, ?array $schema = null, ?callable $translate = null ): array {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( '' === $name || empty( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
			return $block;
		}

		foreach ( self::translatable_attributes( $name, $schema ) as $attribute ) {
			$value = $block['attrs'][ $attribute ] ?? null;
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}
			$context = array(
				'block'     => $name,
				'attribute' => $attribute,
			);
			if ( null !== $translate ) {
				$translated = $translate( $value, $code, $context );
			} else {
				/**
				 * Filters one translatable string into a language.
				 *
				 * @param string $value   The source text.
				 * @param string $code    The target language code.
				 * @param array  $context `block` and `attribute`.
				 */
				$translated = apply_filters( 'tranzly_translate_string', $value, $code, $context );
			}
			if ( is_string( $translated ) && '' !== $translated ) {
				$block['attrs'][ $attribute ] = $translated;
			}
		}

		return $block;
	}

	/**
	 * `render_block_data` callback: translate on the front end when the current language is not
	 * the default one. Blocks render from the translated attributes and never know it happened.
	 *
	 * @param array<string, mixed> $parsed_block The block about to render.
	 * @return array<string, mixed>
	 */
	public static function filter_render_block_data( array $parsed_block ): array {
		// The editor previews the source text: wp-admin, and REST (the block renderer) requests.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $parsed_block;
		}
		$current = Languages::current();
		if ( Languages::default_code() === $current ) {
			return $parsed_block;
		}

		return self::translate_block( $parsed_block, $current );
	}
}
