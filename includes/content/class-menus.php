<?php
/**
 * Menus (tz-c4): a separate menu per language, or the same menu translated automatically.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Strings;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Seo\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two ways, per theme menu location, and they combine:
 *
 * 1. **A separate menu per language** — option `tranzly_menus` maps location => language => menu;
 *    a visitor reading German gets the German menu in that location.
 * 2. **Automatic** (every menu without a German one): each item that points at a page, post or
 *    category points at its German translation instead, with the translation's title — unless the
 *    item has its own label, which is then translated like any other site string
 *    (`menu.<item id>` in the language's strings, Tranzly → Menus and templates). Block-theme
 *    navigation links are handled the same way at render time.
 *
 * Nothing extra is queried: the item's translations come from Relations' memo (primed with the
 * menu's posts by the main query when they are listed, one query otherwise per menu), and the
 * labels from the language's strings option, which every translated page already loads.
 */
final class Menus {

	/** Option: location => language => menu term ID. */
	public const OPTION = 'tranzly_menus';

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'theme_mod_nav_menu_locations', array( self::class, 'filter_locations' ), 20 );
		add_filter( 'wp_nav_menu_objects', array( self::class, 'translate_items' ), 5, 1 );
		add_filter( 'render_block_data', array( self::class, 'translate_navigation_link' ), 20, 1 );
	}

	/**
	 * The stored map.
	 *
	 * @return array<string, array<string, int>>
	 */
	public static function map(): array {
		$map = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $map ) ? $map : array() as $location => $langs ) {
			foreach ( (array) $langs as $lang => $menu ) {
				if ( (int) $menu > 0 && null !== Languages::resolve( (string) $lang ) ) {
					$out[ (string) $location ][ (string) $lang ] = (int) $menu;
				}
			}
		}

		return $out;
	}

	/**
	 * Save the map (menus that are not nav menus are refused).
	 *
	 * @param array<string, mixed> $map Location => language => menu.
	 * @return true|\WP_Error
	 */
	public static function save( array $map ) {
		$clean = array();
		foreach ( $map as $location => $langs ) {
			foreach ( (array) $langs as $lang => $menu ) {
				$menu = (int) $menu;
				if ( 0 === $menu ) {
					continue;
				}
				if ( null === Languages::resolve( (string) $lang ) || ! is_nav_menu( $menu ) ) {
					return new \WP_Error( 'tranzly_bad_menu', __( 'Choose one of your menus for each language.', 'tranzly' ), array( 'status' => 400 ) );
				}
				$clean[ sanitize_key( (string) $location ) ][ (string) $lang ] = $menu;
			}
		}
		update_option( self::OPTION, $clean, true );

		return true;
	}

	/**
	 * `theme_mod_nav_menu_locations`: the language's own menu where one is chosen.
	 *
	 * @param mixed $locations Location => menu ID.
	 * @return mixed
	 */
	public static function filter_locations( $locations ) {
		if ( ! is_array( $locations ) || ! Router::is_front() ) {
			return $locations;
		}
		$lang = Languages::current();
		foreach ( self::map() as $location => $langs ) {
			if ( isset( $langs[ $lang ] ) ) {
				$locations[ $location ] = $langs[ $lang ];
			}
		}

		return $locations;
	}

	/**
	 * `wp_nav_menu_objects`: each item leads to the visitor's language.
	 *
	 * @param array<int, object> $items Menu items.
	 * @return array<int, object>
	 */
	public static function translate_items( $items ) {
		if ( ! is_array( $items ) || ! Router::is_front() ) {
			return $items;
		}
		$lang = Languages::current();
		if ( Languages::default_code() === $lang ) {
			return $items;
		}
		$posts = array();
		foreach ( $items as $item ) {
			if ( 'post_type' === ( $item->type ?? '' ) ) {
				$posts[] = (int) $item->object_id;
			}
		}
		Relations::prime( 'post', $posts );
		$strings = Strings::all( $lang );
		foreach ( $items as $item ) {
			$label = $strings[ 'menu.' . (int) $item->ID ] ?? '';
			$own   = self::translate_target( (string) ( $item->type ?? '' ), (int) ( $item->object_id ?? 0 ), $lang );
			if ( null !== $own ) {
				$item->url = $own['url'];
				// An item titled like its page follows the page's translation; a custom label is
				// the site owner's own words and is translated as a string.
				if ( '' === $label && self::is_default_title( $item, $own['source_title'] ) ) {
					$item->title = esc_html( $own['title'] );
				}
			}
			if ( '' !== $label ) {
				$item->title = esc_html( $label );
			}
		}

		return $items;
	}

	/**
	 * `render_block_data`: block-theme navigation links (core/navigation-link, -submenu).
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	public static function translate_navigation_link( $block ) {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( ! in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) || ! Router::is_front() ) {
			return $block;
		}
		$lang = Languages::current();
		if ( Languages::default_code() === $lang ) {
			return $block;
		}
		$attrs = (array) ( $block['attrs'] ?? array() );
		$kind  = (string) ( $attrs['kind'] ?? '' );
		$type  = 'post-type' === $kind ? 'post_type' : ( 'taxonomy' === $kind ? 'taxonomy' : '' );
		$own   = self::translate_target( $type, (int) ( $attrs['id'] ?? 0 ), $lang );
		$label = (string) ( $attrs['label'] ?? '' );
		if ( null !== $own ) {
			$block['attrs']['url'] = $own['url'];
			if ( wp_strip_all_tags( $label ) === $own['source_title'] ) {
				$block['attrs']['label'] = $own['title'];
				return $block;
			}
		}
		$key = 'text.' . sha1( $label );
		$tr  = Strings::all( $lang )[ $key ] ?? '';
		if ( '' !== $label && '' !== $tr ) {
			$block['attrs']['label'] = $tr;
		}

		return $block;
	}

	/**
	 * The translation an item points at in a language: its address, title and the original's title.
	 *
	 * @param string $type    `post_type` or `taxonomy`.
	 * @param int    $id      The object.
	 * @param string $lang    The language.
	 * @return array{url: string, title: string, source_title: string}|null
	 */
	private static function translate_target( string $type, int $id, string $lang ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		if ( 'post_type' === $type ) {
			$target = Languages::translation( $id, $lang );
			if ( null === $target || $target === $id || ! Router::is_public_post( $target ) ) {
				return null;
			}
			return array(
				'url'          => (string) get_permalink( $target ),
				'title'        => get_the_title( $target ),
				'source_title' => get_the_title( $id ),
			);
		}
		if ( 'taxonomy' === $type ) {
			$target = Router::term_translation( $id, $lang );
			if ( null === $target || $target === $id ) {
				return null;
			}
			$link = get_term_link( $target );
			$term = get_term( $target );
			$src  = get_term( $id );
			if ( is_wp_error( $link ) || ! $term instanceof \WP_Term || ! $src instanceof \WP_Term ) {
				return null;
			}
			return array(
				'url'          => $link,
				'title'        => $term->name,
				'source_title' => $src->name,
			);
		}

		return null;
	}

	/**
	 * Did the site owner keep the item's default title (its page's title)?
	 *
	 * @param object $item         The menu item.
	 * @param string $source_title The page's title.
	 * @return bool
	 */
	private static function is_default_title( $item, string $source_title ): bool {
		$raw = (string) get_post_field( 'post_title', (int) $item->ID, 'raw' );

		return '' === $raw || wp_strip_all_tags( (string) $item->title ) === wp_strip_all_tags( $source_title );
	}
}
