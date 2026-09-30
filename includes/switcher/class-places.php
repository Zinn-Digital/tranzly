<?php
/**
 * Every place a switcher can go: block, shortcode, widget, menu item, floating button, and
 * automatically before or after the content (T5).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Switcher;

use ZinnDigital\Tranzly\Core\Edition;
use ZinnDigital\Tranzly\Core\Options;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Seo\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires Switcher::render() into WordPress. Each place stores only its options; the markup always
 * comes from the one renderer.
 */
final class Places {

	/** The shortcode tag. */
	public const SHORTCODE = 'tranzly_switcher';

	/** The address a menu item uses to say "put the languages here". */
	public const MENU_URL = '#tranzly-languages';

	/** Same, laid out flat (each language its own top-level item) rather than as a submenu. */
	public const MENU_URL_FLAT = '#tranzly-languages-flat';

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'widgets_init', array( self::class, 'register_widget' ) );
		require_once __DIR__ . '/class-legacy-widgets.php';
		add_action( 'plugins_loaded', array( Legacy_Widgets::class, 'maybe_migrate' ), 30 );
		add_filter( 'wp_nav_menu_objects', array( self::class, 'expand_menu_items' ), 20, 1 );
		add_filter( 'nav_menu_link_attributes', array( self::class, 'menu_link_attributes' ), 20, 2 );
		add_action( 'admin_head-nav-menus.php', array( self::class, 'add_menu_meta_box' ) );
		add_action( 'wp_footer', array( self::class, 'floating' ), 4 );
		add_filter( 'the_content', array( self::class, 'around_content' ), 20 );
		add_action( 'admin_init', array( self::class, 'ensure_options' ) );

		$pro = __DIR__ . '/pro_' . '_premium_only'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- the free package must not carry the premium token (CONTRACT §3).
		if ( is_readable( $pro . '/class-builders.php' ) && Edition::pro() ) {
			require_once $pro . '/class-builders.php';
			Pro\Builders::register();
		}
	}

	/**
	 * Create, autoloaded, the options every front-end request reads, so none of them costs a
	 * query by not existing (the speed promise, tz-r14). Activation and wp-admin only.
	 *
	 * @return void
	 */
	public static function ensure_options(): void {
		foreach ( array( Options::OPTION, 'widget_lang_switcher' ) as $option ) {
			if ( false === get_option( $option, false ) ) {
				add_option( $option, 'widget_lang_switcher' === $option ? array( '_multiwidget' => 1 ) : array(), '', true );
			}
		}
	}

	/**
	 * The block `tranzly/language-switcher`.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		register_block_type(
			TRANZLY_DIR . 'blocks/language-switcher',
			array( 'render_callback' => array( self::class, 'render_block' ) )
		);
	}

	/**
	 * Block callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( array $attributes ): string {
		return Switcher::render( $attributes );
	}

	/**
	 * `[tranzly_switcher style="dropdown" display="code" flags="1" current="0" hide_missing="1" vertical="1" label="…"]`.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'style'        => 'list',
				'display'      => 'name',
				'flags'        => '0',
				'current'      => '1',
				'hide_missing' => '0',
				'vertical'     => '0',
				'label'        => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		return Switcher::render(
			array(
				'style'       => $atts['style'],
				'display'     => $atts['display'],
				'flags'       => $atts['flags'],
				'showCurrent' => $atts['current'],
				'hideMissing' => $atts['hide_missing'],
				'vertical'    => $atts['vertical'],
				'label'       => $atts['label'],
			)
		);
	}

	/**
	 * `widgets_init`.
	 *
	 * @return void
	 */
	public static function register_widget(): void {
		require_once __DIR__ . '/class-widget.php';
		register_widget( Widget::class );
	}

	/**
	 * `wp_nav_menu_objects`: a menu item pointing at `#tranzly-languages` becomes the current
	 * language with the others beneath it (a submenu, the way themes draw dropdowns); one pointing at
	 * `#tranzly-languages-flat` becomes one item per language. Each carries `hreflang`/`lang`.
	 *
	 * @param array<int, \WP_Post|object> $items Menu items.
	 * @return array<int, \WP_Post|object>
	 */
	public static function expand_menu_items( $items ) {
		if ( ! is_array( $items ) || is_admin() ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $item ) {
			$url = (string) ( $item->url ?? '' );
			if ( self::MENU_URL !== $url && self::MENU_URL_FLAT !== $url ) {
				$out[] = $item;
				continue;
			}
			$langs = Switcher::items();
			if ( count( $langs ) < 2 ) {
				continue; // One language: nothing to switch to, and no dead "#" link either.
			}
			$flat    = self::MENU_URL_FLAT === $url;
			$current = Languages::current();
			if ( ! $flat ) {
				$top            = self::menu_item( $item, self::find( $langs, $current ), (int) $item->ID, (int) $item->menu_item_parent, true );
				$top->classes[] = 'menu-item-has-children';
				$out[]          = $top;
			}
			foreach ( $langs as $n => $lang ) {
				$is_current = $lang['code'] === $current;
				if ( ! $flat && $is_current ) {
					continue;
				}
				// Negative IDs cannot collide with a real menu item.
				$id    = -1 * ( (int) $item->ID * 1000 + $n + 1 );
				$out[] = self::menu_item( $item, $lang, $id, $flat ? (int) $item->menu_item_parent : (int) $item->ID, $is_current );
			}
		}

		return $out;
	}

	/**
	 * The language entry with a code.
	 *
	 * @param array<int, array<string, mixed>> $langs Items.
	 * @param string                           $code  Code.
	 * @return array<string, mixed>
	 */
	private static function find( array $langs, string $code ): array {
		foreach ( $langs as $lang ) {
			if ( $lang['code'] === $code ) {
				return $lang;
			}
		}

		return $langs[0];
	}

	/**
	 * A menu item object for one language, cloned from the placeholder so the theme's walker
	 * treats it like any other item.
	 *
	 * @param object               $source    The placeholder item.
	 * @param array<string, mixed> $lang      The language.
	 * @param int                  $id        The new item's ID.
	 * @param int                  $parent_id Its parent item ID.
	 * @param bool                 $is_current Whether it is the page's language.
	 * @return object
	 */
	private static function menu_item( $source, array $lang, int $id, int $parent_id, bool $is_current ) {
		$item                   = clone $source;
		$item->ID               = $id;
		$item->db_id            = $id;
		$item->menu_item_parent = (string) $parent_id;
		$item->title            = esc_html( (string) $lang['name'] );
		$item->url              = (string) $lang['url'];
		$item->attr_title       = '';
		$item->xfn              = '';
		$tag                    = str_replace( '_', '-', (string) $lang['code'] );
		$item->classes          = array_values( array_filter( (array) $source->classes ) );
		$item->classes[]        = \ZinnDigital\Tranzly\Frontend::cls( 'lsw__menu-item' );
		$item->current          = $is_current;
		$item->tranzly_lang     = $tag;
		$item->tranzly_current  = $is_current;

		return $item;
	}

	/**
	 * `nav_menu_link_attributes`: a language item's link says which language it leads to.
	 *
	 * @param array<string, string> $atts Link attributes.
	 * @param object                $item The menu item.
	 * @return array<string, string>
	 */
	public static function menu_link_attributes( $atts, $item ) {
		if ( is_array( $atts ) && is_object( $item ) && isset( $item->tranzly_lang ) ) {
			$atts['hreflang'] = (string) $item->tranzly_lang;
			$atts['lang']     = (string) $item->tranzly_lang;
			if ( ! empty( $item->tranzly_current ) ) {
				$atts['aria-current'] = 'true';
			}
		}

		return $atts;
	}

	/**
	 * `admin_head-nav-menus.php`: the "Languages" box on Appearance → Menus.
	 *
	 * @return void
	 */
	public static function add_menu_meta_box(): void {
		add_meta_box( 'tranzly-languages', __( 'Language switcher', 'tranzly' ), array( self::class, 'menu_meta_box' ), 'nav-menus', 'side', 'default' );
	}

	/**
	 * The box: two ready-made custom links.
	 *
	 * @return void
	 */
	public static function menu_meta_box(): void {
		$rows = array(
			array( self::MENU_URL, __( 'Languages (as a submenu)', 'tranzly' ) ),
			array( self::MENU_URL_FLAT, __( 'Languages (side by side)', 'tranzly' ) ),
		);
		echo '<div id="tranzly-languages-div" class="posttypediv"><div class="tabs-panel tabs-panel-active"><ul class="categorychecklist form-no-clear">';
		foreach ( $rows as $i => $row ) {
			$n = -9100 - $i;
			echo '<li><label class="menu-item-title"><input type="checkbox" class="menu-item-checkbox" name="menu-item[' . esc_attr( (string) $n ) . '][menu-item-object-id]" value="-1"> ' . esc_html( $row[1] ) . '</label>'
				. '<input type="hidden" class="menu-item-type" name="menu-item[' . esc_attr( (string) $n ) . '][menu-item-type]" value="custom">'
				. '<input type="hidden" class="menu-item-title" name="menu-item[' . esc_attr( (string) $n ) . '][menu-item-title]" value="' . esc_attr( $row[1] ) . '">'
				. '<input type="hidden" class="menu-item-url" name="menu-item[' . esc_attr( (string) $n ) . '][menu-item-url]" value="' . esc_attr( $row[0] ) . '"></li>';
		}
		echo '</ul></div><p class="button-controls"><span class="add-to-menu"><input type="submit" class="button submit-add-to-menu right" value="' . esc_attr__( 'Add to Menu', 'tranzly' ) . '" name="add-tranzly-languages-menu-item" id="submit-tranzly-languages-div"><span class="spinner"></span></span></p></div>';
		echo '<p class="description">' . esc_html__( 'On your site, this item shows the site\'s languages, each linking to the current page in that language.', 'tranzly' ) . '</p>';
	}

	/**
	 * `wp_footer`: the floating switcher, when it is switched on (zero set-up, any theme).
	 *
	 * @return void
	 */
	public static function floating(): void {
		if ( ! Router::is_front() ) {
			return;
		}
		$opts = Options::get()['switcher'];
		if ( '' === ( $opts['floating'] ?? '' ) ) {
			return;
		}
		$html = Switcher::render(
			array(
				'style'    => 'dropdown',
				'floating' => $opts['floating'],
				'flags'    => 'flags' === $opts['style'],
				'display'  => $opts['display'] ?? 'name',
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes every value.
	}

	/**
	 * `the_content`: the automatic switcher before or after a post's content (the option 2.x
	 * sites already had, migrated by T1).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function around_content( $content ) {
		if ( ! is_string( $content ) || ! Router::is_front() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$opts = Options::get()['switcher'];
		if ( 'none' === $opts['position'] ) {
			return $content;
		}
		$html = Switcher::render(
			array(
				'style'       => 'list',
				'flags'       => 'flags' === $opts['style'],
				'hideMissing' => true,
			)
		);

		return 'before' === $opts['position'] ? $html . $content : $content . $html;
	}
}
