<?php
/**
 * Block-safe translation: only a block's words go to the engine, never its markup (tz-c2).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Contract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ WHY THE CONTENT IS SPLIT. Sent whole, a post's content includes the block delimiters
 * (`<!-- wp:image {"id":12,"sizeSlug":"large"} -->`) — JSON an engine may "translate", reorder or
 * break, after which the editor reports "This block contains unexpected or invalid content" on
 * every block. So the parser walks the parsed blocks and sends only:
 *
 * - each HTML chunk of a block's own markup (`innerContent`) that holds visible text, as HTML (the
 *   engine keeps the tags, translates the text between them);
 * - each translatable attribute stored in the delimiter's JSON (`"role": "content"`, plus the core
 *   blocks whose text lives there: a search box's label and placeholder, a navigation link's label);
 *
 * and writes each translation back into the SAME place before `serialize_blocks()` puts the
 * delimiters back exactly as they were. Code, raw HTML and shortcodes are never sent.
 *
 * Segment keys are paths (`c.0.2.i1` = top-level block 0, its inner block 2, chunk 1), so a
 * translation keeps the source's structure and the side-by-side editor can pair the two.
 */
final class Block_Parser {

	/** Blocks whose content is code or markup, never prose. */
	public const SKIP = array( 'core/code', 'core/html', 'core/shortcode', 'core/preformatted', 'core/freeform-code', 'core/missing' );

	/** Core blocks whose visible text is an attribute in the delimiter (not in their HTML). */
	public const COMMENT_ATTRIBUTES = array(
		'core/search'                    => array( 'label', 'placeholder', 'buttonText' ),
		'core/navigation-link'           => array( 'label', 'title' ),
		'core/navigation-submenu'        => array( 'label', 'title' ),
		'core/social-link'               => array( 'label' ),
		'core/read-more'                 => array( 'content' ),
		'core/post-excerpt'              => array( 'moreText' ),
		'core/query-pagination-next'     => array( 'label' ),
		'core/query-pagination-previous' => array( 'label' ),
		'core/post-navigation-link'      => array( 'label' ),
	);

	/** Segment key of the content, before it is split (Translator's own key). */
	private const CONTENT = 'content';

	/**
	 * Hook the two seams the translator offers (docs/plugins-overhaul/tranzly-hooks.md).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'tranzly_post_segments', array( self::class, 'filter_segments' ), 10, 2 );
		add_filter( 'tranzly_translated_post_fields', array( self::class, 'filter_fields' ), 10, 4 );
	}

	/**
	 * `tranzly_post_segments`: replace the one `content` segment with the block segments.
	 *
	 * @param array<string, array{text: string, format: string}> $segments Segments.
	 * @param \WP_Post                                           $post     The original.
	 * @return array<string, array{text: string, format: string}>
	 */
	public static function filter_segments( $segments, $post ) {
		if ( ! is_array( $segments ) || ! isset( $segments[ self::CONTENT ] ) || ! $post instanceof \WP_Post ) {
			return $segments;
		}
		unset( $segments[ self::CONTENT ] );

		return $segments + self::segments( (string) $post->post_content );
	}

	/**
	 * `tranzly_translated_post_fields`: put the translated block segments back into the content.
	 *
	 * @param array<string, mixed>  $update     `ID` plus post fields.
	 * @param array<string, string> $translated Segment key => translation.
	 * @param \WP_Post              $post       The original.
	 * @param string                $lang       The target language.
	 * @return array<string, mixed>
	 */
	public static function filter_fields( $update, $translated, $post, $lang = '' ) {
		if ( ! is_array( $update ) || ! is_array( $translated ) || ! $post instanceof \WP_Post ) {
			return $update;
		}
		$ours = array_filter( $translated, static fn( $key ): bool => str_starts_with( (string) $key, 'c.' ), ARRAY_FILTER_USE_KEY );
		if ( array() === $ours && array() === self::segments( (string) $post->post_content ) ) {
			return $update; // Nothing translatable in the content: leave the copy as it is.
		}
		/**
		 * Filters the source content just before translated segments are written into it, per
		 * language (the Pro per-language image swap uses it).
		 *
		 * @param string $content The original's content.
		 * @param string $lang    The target language.
		 */
		$source                 = (string) apply_filters( 'tranzly_content_before_rebuild', (string) $post->post_content, (string) $lang );
		$update['post_content'] = self::rebuild( $source, array_map( 'strval', $ours ) );

		return $update;
	}

	/**
	 * The segments of a post's content.
	 *
	 * @param string $content Post content (blocks or classic HTML).
	 * @return array<string, array{text: string, format: string}>
	 */
	public static function segments( string $content ): array {
		if ( '' === trim( $content ) ) {
			return array();
		}
		$out = array();
		self::walk(
			parse_blocks( $content ),
			'c',
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
	 * The content with translations written back into their places.
	 *
	 * @param string                $content      The original's content.
	 * @param array<string, string> $translations Segment key => translation.
	 * @return string
	 */
	public static function rebuild( string $content, array $translations ): string {
		$blocks = parse_blocks( $content );
		$blocks = self::walk(
			$blocks,
			'c',
			static fn( string $key ): ?string => $translations[ $key ] ?? null
		);

		return serialize_blocks( $blocks );
	}

	/**
	 * Walk blocks, handing every translatable piece to `$visit` and writing back what it returns.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $path   Path of this level.
	 * @param callable                         $visit  `( key, text, format ): ?string`; a string replaces the piece.
	 * @return array<int, array<string, mixed>>
	 */
	private static function walk( array $blocks, string $path, callable $visit ): array {
		foreach ( $blocks as $index => $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			$here = $path . '.' . $index;
			if ( in_array( $name, self::SKIP, true ) ) {
				continue;
			}
			// Attributes stored in the delimiter.
			foreach ( self::attributes_of( $name ) as $attribute ) {
				$value = $block['attrs'][ $attribute ] ?? null;
				if ( is_string( $value ) && self::has_words( $value ) && self::is_text( $attribute, $value ) ) {
					$new = $visit( $here . '.a.' . $attribute, $value, 'text' );
					if ( is_string( $new ) && '' !== $new ) {
						$blocks[ $index ]['attrs'][ $attribute ] = $new;
					}
				}
			}
			// The block's own HTML, chunk by chunk (null chunks are where inner blocks go).
			$inner_i = 0;
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $c => $chunk ) {
				if ( null === $chunk ) {
					++$inner_i;
					continue;
				}
				// Text in an HTML ATTRIBUTE (an image's alt text): an engine translating HTML leaves
				// attribute values alone, so these go as plain text and are written back by the
				// HTML API, which changes nothing else in the markup.
				$chunk                                  = self::visit_html_attributes( $name, (string) $chunk, $here . '.i' . $c, $visit );
				$blocks[ $index ]['innerContent'][ $c ] = $chunk;
				if ( ! self::has_words( wp_strip_all_tags( $chunk ) ) ) {
					continue;
				}
				$new = $visit( $here . '.i' . $c, $chunk, 'html' );
				if ( is_string( $new ) && '' !== $new ) {
					$blocks[ $index ]['innerContent'][ $c ] = self::keep_symbols( $chunk, $new );
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::walk( (array) $block['innerBlocks'], $here, $visit );
			}
			// Keep innerHTML consistent with innerContent (serialize_blocks() reads innerContent).
			if ( isset( $blocks[ $index ]['innerContent'] ) ) {
				$blocks[ $index ]['innerHTML'] = implode( '', array_filter( (array) $blocks[ $index ]['innerContent'], 'is_string' ) );
			}
		}

		return $blocks;
	}

	/**
	 * Visit the text held in HTML attributes of a chunk (the block's `role: content` attributes
	 * sourced from an attribute, e.g. core/image `alt`), writing replacements back.
	 *
	 * @param string   $name  Block name.
	 * @param string   $chunk An innerContent chunk.
	 * @param string   $key   The chunk's segment key.
	 * @param callable $visit The visitor.
	 * @return string The chunk, with any replacements.
	 */
	private static function visit_html_attributes( string $name, string $chunk, string $key, callable $visit ): string {
		$wanted = self::sourced_attributes( $name );
		if ( array() === $wanted || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $chunk;
		}
		$html = new \WP_HTML_Tag_Processor( $chunk );
		$n    = 0;
		while ( $html->next_tag() ) {
			foreach ( $wanted as $pair ) {
				if ( strtoupper( $pair['tag'] ) !== $html->get_tag() ) {
					continue;
				}
				$value = $html->get_attribute( $pair['attribute'] );
				if ( is_string( $value ) && self::has_words( $value ) && self::is_text( $pair['attribute'], $value ) ) {
					$new = $visit( $key . '.@' . $pair['attribute'] . $n, $value, 'text' );
					if ( is_string( $new ) && '' !== $new ) {
						$html->set_attribute( $pair['attribute'], $new );
					}
				}
			}
			++$n;
		}

		return $html->get_updated_html();
	}

	/**
	 * A block's `role: content` attributes sourced from an HTML attribute with a plain tag
	 * selector: `[ [ 'tag' => 'img', 'attribute' => 'alt' ] ]`.
	 *
	 * @param string $name Block name.
	 * @return array<int, array{tag: string, attribute: string}>
	 */
	private static function sourced_attributes( string $name ): array {
		static $memo = array();
		if ( isset( $memo[ $name ] ) ) {
			return $memo[ $name ];
		}
		$out = array();
		if ( '' !== $name && class_exists( '\WP_Block_Type_Registry' ) ) {
			$type   = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
			$schema = null !== $type && is_array( $type->attributes ) ? $type->attributes : array();
			foreach ( $schema as $definition ) {
				if ( is_array( $definition ) && 'attribute' === ( $definition['source'] ?? null ) && ( 'content' === ( $definition['role'] ?? null ) || in_array( $definition['attribute'] ?? '', array( 'alt', 'title' ), true ) ) && 1 === preg_match( '/^[a-z][a-z0-9]*$/', (string) ( $definition['selector'] ?? '' ) ) ) {
					$out[] = array(
						'tag'       => (string) $definition['selector'],
						'attribute' => (string) $definition['attribute'],
					);
				}
			}
		}
		$memo[ $name ] = $out;

		return $out;
	}

	/**
	 * The delimiter attributes of a block that hold text: its `role: content` attributes that are
	 * NOT sourced from its HTML (those are translated inside the HTML chunks), plus the core ones.
	 *
	 * @param string $name Block name.
	 * @return array<int, string>
	 */
	public static function attributes_of( string $name ): array {
		if ( '' === $name ) {
			return array();
		}
		$names  = self::COMMENT_ATTRIBUTES[ $name ] ?? array();
		$schema = array();
		if ( class_exists( '\WP_Block_Type_Registry' ) ) {
			$type   = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
			$schema = null !== $type && is_array( $type->attributes ) ? $type->attributes : array();
		}
		foreach ( Contract::translatable_attributes( $name, $schema ) as $attribute ) {
			$source = $schema[ $attribute ]['source'] ?? null;
			if ( null === $source ) {
				$names[] = $attribute;
			}
		}

		return array_values( array_unique( array_filter( $names ) ) );
	}

	/**
	 * Is this attribute value text a reader reads? Core marks some non-text attributes
	 * `role: content` (core/cover `url`, core/file `href`, core/media-text `mediaType`) so pattern
	 * overrides can change them; translating one breaks the image, the link or the block's own
	 * validation (found by the every-core-block round trip).
	 *
	 * @param string $attribute Attribute name.
	 * @param string $value     Its value.
	 * @return bool
	 */
	public static function is_text( string $attribute, string $value ): bool {
		if ( 1 === preg_match( '/(?:url|href|src|srcset|link|type|id|ids|slug|rel|target|anchor|class)$/i', $attribute ) ) {
			return false;
		}
		// A URL, a site path or a fragment, whatever the attribute is called.
		return 1 !== preg_match( '#^(?:(?:https?|mailto|tel|ftp|data):|//|/\\S*$|\\#\\S*$)#i', trim( $value ) );
	}

	/**
	 * Put back the runs of a chunk that hold no words (an icon's "+", a separator's "·") when an
	 * engine changed them: the block's save() writes those itself, so a changed one makes the
	 * editor call the block invalid. Only when the tags came back exactly as they went out;
	 * otherwise the translation is returned as it is.
	 *
	 * @param string $source      The original chunk.
	 * @param string $translation The engine's chunk.
	 * @return string
	 */
	public static function keep_symbols( string $source, string $translation ): string {
		$split = static fn( string $html ): array => (array) preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$from  = $split( $source );
		$to    = $split( $translation );
		if ( count( $from ) !== count( $to ) ) {
			return $translation;
		}
		foreach ( $from as $i => $piece ) {
			$tag = str_starts_with( (string) $piece, '<' );
			if ( str_starts_with( (string) $to[ $i ], '<' ) !== $tag || ( $tag && $piece !== $to[ $i ] ) ) {
				return $translation;
			}
		}
		foreach ( $from as $i => $piece ) {
			if ( ! str_starts_with( (string) $piece, '<' ) && ! self::has_words( (string) $piece ) ) {
				$to[ $i ] = $piece;
			}
		}

		return implode( '', $to );
	}

	/**
	 * Does a text hold anything a reader reads (a letter or a digit in any script)?
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_words( string $text ): bool {
		return 1 === preg_match( '/[\p{L}\p{N}]/u', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
