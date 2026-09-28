<?php
/**
 * Show content only in some languages (tz-r7): any block, shown or hidden per language.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Seo\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every block gets one attribute, `tranzlyLanguages`: `{ "mode": "only"|"except", "langs": [codes] }`.
 * The editor sets it from a "Languages" panel on every block (src/editor/visibility.js); the
 * front end drops a block whose rule excludes the visitor's language. It lives in the block's
 * delimiter comment, which never reaches a visitor (F5).
 *
 * ⭐ Where it matters most is content SHARED by every language: block-theme headers and footers,
 * synced patterns, widgets — a German-only offer in the footer. In a post-per-language site a
 * post's blocks are copied into each translation, so the rule travels with them too.
 */
final class Visibility {

	/** The attribute name. */
	public const ATTRIBUTE = 'tranzlyLanguages';

	/**
	 * Hook the attribute and the render filter.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'register_block_type_args', array( self::class, 'add_attribute' ), 20, 1 );
		add_filter( 'render_block', array( self::class, 'filter_render' ), 5, 2 );
	}

	/**
	 * `register_block_type_args`: declare the attribute on every block so the editor and REST
	 * validation accept it.
	 *
	 * @param array<string, mixed> $args Block type args.
	 * @return array<string, mixed>
	 */
	public static function add_attribute( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$args['attributes']                    = is_array( $args['attributes'] ?? null ) ? $args['attributes'] : array();
		$args['attributes'][ self::ATTRIBUTE ] = array(
			'type'    => 'object',
			'default' => array(),
		);

		return $args;
	}

	/**
	 * `render_block`: drop a block the visitor's language may not see.
	 *
	 * @param string               $html  Rendered block.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	public static function filter_render( $html, $block ) {
		if ( ! is_string( $html ) || ! is_array( $block ) || empty( $block['attrs'][ self::ATTRIBUTE ] ) || ! Router::is_front() ) {
			return $html;
		}

		return self::visible( (array) $block['attrs'][ self::ATTRIBUTE ], Languages::current() ) ? $html : '';
	}

	/**
	 * Does a rule let a language see the block (the pure half)?
	 *
	 * @param array<string, mixed> $rule `mode` and `langs`.
	 * @param string               $lang A language code.
	 * @return bool
	 */
	public static function visible( array $rule, string $lang ): bool {
		$langs = array_map( 'strval', (array) ( $rule['langs'] ?? array() ) );
		if ( array() === $langs ) {
			return true;
		}
		$in = in_array( $lang, $langs, true );

		return 'except' === ( $rule['mode'] ?? 'only' ) ? ! $in : $in;
	}
}
