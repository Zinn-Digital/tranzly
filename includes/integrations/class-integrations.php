<?php
/**
 * T6: the integrations registry and the translator seams it plugs into.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Integrations;

use ZinnDigital\Tranzly\Core\Edition;
use ZinnDigital\Tranzly\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds every integration and connects the present ones to the translator's seams:
 * `tranzly_post_segments` / `tranzly_translated_post_fields` (posts), `tranzly_term_segments` /
 * `tranzly_term_translated` (terms), `tranzly_copy_meta_keys` (a new translation's settings) and
 * `tranzly_source_meta_keys` (what makes a translation out of date). Integrations for plugins that
 * are not on the site cost nothing: `available()` is asked once per request.
 *
 * Free: Page Builder Sandwich (tz-c5) and nested block text. Pro (premium package + licence): the
 * other page builders, WooCommerce and its currencies, the SEO plugins, custom fields, theme and
 * plugin text, forms, comments and reviews.
 */
final class Integrations {

	/** Segment key prefix. */
	public const PREFIX = 'x.';

	/**
	 * Every registered integration, by id.
	 *
	 * @var array<string, Integration>|null
	 */
	private static ?array $all = null;

	/**
	 * The available ones (memo).
	 *
	 * @var array<string, Integration>|null
	 */
	private static ?array $live = null;

	/**
	 * Hook the seams. Integrations boot at `after_setup_theme` — after every plugin AND the theme,
	 * because two of the builders (Bricks, Divi) are themes that define themselves only then.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'after_setup_theme', array( self::class, 'boot' ), 100 );
		add_filter( 'tranzly_post_segments', array( self::class, 'filter_segments' ), 20, 2 );
		add_filter( 'tranzly_translated_post_fields', array( self::class, 'filter_fields' ), 20, 4 );
		add_filter( 'tranzly_term_segments', array( self::class, 'filter_term_segments' ), 20, 2 );
		add_action( 'tranzly_term_translated', array( self::class, 'on_term_translated' ), 20, 4 );
		add_filter( 'tranzly_copy_meta_keys', array( self::class, 'filter_copy_meta' ), 20, 2 );
		add_filter( 'tranzly_source_meta_keys', array( self::class, 'filter_source_meta' ), 20, 2 );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'admin_init', array( self::class, 'ensure_options' ) );
	}

	/** Options the integrations read on EVERY request (see ensure_options()). */
	public const EVERY_REQUEST_OPTIONS = array(
		'tranzly_gettext_domains' => array(),
		'tranzly_comments'        => 'off',
		'tranzly_currencies'      => array(),
	);

	/**
	 * ⭐ THE SPEED PROMISE (tz-r14): an option that does not exist costs a database query on every
	 * request, because only existing options are autoloaded. The options the integrations read while
	 * a page is built are created empty and autoloaded (on activation and in wp-admin, never on the
	 * front end); every save keeps them autoloaded.
	 *
	 * @return void
	 */
	public static function ensure_options(): void {
		foreach ( self::EVERY_REQUEST_OPTIONS as $name => $empty ) {
			if ( false === get_option( $name, false ) ) {
				add_option( $name, $empty, '', true );
			}
		}
	}

	/**
	 * `after_setup_theme`: boot the available integrations.
	 *
	 * @return void
	 */
	public static function boot(): void {
		foreach ( self::live() as $integration ) {
			$integration->boot();
		}
	}

	/**
	 * Every integration this edition carries.
	 *
	 * @return array<string, Integration>
	 */
	public static function all(): array {
		if ( null === self::$all ) {
			$list = array( new Pbs(), new Nested_Blocks(), new Woo_Products() );
			$pro  = __DIR__ . '/pro_' . '_premium_only'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- the free package must not carry the premium token (CONTRACT §3).
			if ( is_readable( $pro . '/class-load.php' ) && Edition::pro() ) {
				require_once $pro . '/class-load.php';
				$list = array_merge( $list, Pro\Load::integrations() );
			}

			/**
			 * Filters the integrations Tranzly runs. Add an `Integration` subclass to support
			 * another plugin.
			 *
			 * @param array<int, Integration> $list The integrations.
			 */
			$list      = (array) apply_filters( 'tranzly_integrations', $list );
			self::$all = array();
			foreach ( $list as $integration ) {
				if ( $integration instanceof Integration ) {
					self::$all[ $integration->id() ] = $integration;
				}
			}
		}

		return self::$all;
	}

	/**
	 * The integrations whose plugin is present.
	 *
	 * @return array<string, Integration>
	 */
	public static function live(): array {
		if ( null !== self::$live ) {
			return self::$live;
		}
		$live = array_filter(
			self::all(),
			/**
			 * Filters whether an integration runs when its plugin is not detected — for content a
			 * builder left behind after it was switched off, or a plugin loaded in an unusual way.
			 *
			 * @param bool   $on Detected?
			 * @param string $id Integration id (`divi`, `wpbakery`, `oxygen`, `bricks`, `forms`…).
			 */
			static fn( Integration $i ): bool => (bool) apply_filters( 'tranzly_integration_available', $i->available(), $i->id() )
		);
		// Not remembered before the theme is loaded: a theme-builder is not "there" yet.
		if ( did_action( 'after_setup_theme' ) ) {
			self::$live = $live;
		}

		return $live;
	}

	/**
	 * Forget the memo (tests; a plugin activated mid-request).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$all  = null;
		self::$live = null;
	}

	/**
	 * `tranzly_post_segments`: add each integration's segments under its prefix.
	 *
	 * @param array<string, array{text: string, format: string}> $segments Segments.
	 * @param \WP_Post                                           $post     The original.
	 * @return array<string, array{text: string, format: string}>
	 */
	public static function filter_segments( $segments, $post ) {
		if ( ! is_array( $segments ) || ! $post instanceof \WP_Post ) {
			return $segments;
		}
		foreach ( self::live() as $id => $integration ) {
			foreach ( $integration->segments( $post ) as $key => $segment ) {
				$segments[ self::PREFIX . $id . '.' . $key ] = $segment;
			}
		}

		return $segments;
	}

	/**
	 * `tranzly_translated_post_fields`: hand each integration its own translated segments.
	 *
	 * @param array<string, mixed>  $update     `ID` plus fields.
	 * @param array<string, string> $translated Segment key => translation.
	 * @param \WP_Post              $post       The original.
	 * @param string                $lang       Target language.
	 * @return array<string, mixed>
	 */
	public static function filter_fields( $update, $translated, $post, $lang = '' ) {
		if ( ! is_array( $update ) || ! is_array( $translated ) || ! $post instanceof \WP_Post ) {
			return $update;
		}
		foreach ( self::live() as $id => $integration ) {
			$update = $integration->write( $update, self::own( $translated, $id ), $post, (string) $lang );
		}

		return $update;
	}

	/**
	 * `tranzly_term_segments`.
	 *
	 * @param array<string, array{text: string, format: string}> $segments Segments.
	 * @param \WP_Term                                           $term     The original.
	 * @return array<string, array{text: string, format: string}>
	 */
	public static function filter_term_segments( $segments, $term ) {
		if ( ! is_array( $segments ) || ! $term instanceof \WP_Term ) {
			return $segments;
		}
		foreach ( self::live() as $id => $integration ) {
			foreach ( $integration->term_segments( $term ) as $key => $segment ) {
				$segments[ self::PREFIX . $id . '.' . $key ] = $segment;
			}
		}

		return $segments;
	}

	/**
	 * `tranzly_term_translated`.
	 *
	 * @param int                   $target     The translation.
	 * @param int                   $term_id    The original.
	 * @param string                $lang       Language.
	 * @param array<string, string> $translated Segment key => translation.
	 * @return void
	 */
	public static function on_term_translated( $target, $term_id, $lang, $translated ): void {
		$term = get_term( (int) $term_id );
		if ( ! $term instanceof \WP_Term || ! is_array( $translated ) ) {
			return;
		}
		foreach ( self::live() as $id => $integration ) {
			$own = self::own( $translated, $id );
			if ( array() !== $own ) {
				$integration->term_write( (int) $target, $own, $term, (string) $lang );
			}
		}
	}

	/**
	 * `tranzly_copy_meta_keys`.
	 *
	 * @param array<int, string> $keys      Keys.
	 * @param int                $source_id The original.
	 * @return array<int, string>
	 */
	public static function filter_copy_meta( $keys, $source_id = 0 ) {
		$keys = is_array( $keys ) ? $keys : array();
		foreach ( self::live() as $integration ) {
			$keys = array_merge( $keys, $integration->copy_meta( (int) $source_id ) );
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * `tranzly_source_meta_keys`.
	 *
	 * @param array<int, string> $keys    Keys.
	 * @param int                $post_id The post.
	 * @return array<int, string>
	 */
	public static function filter_source_meta( $keys, $post_id = 0 ) {
		unset( $post_id );
		$keys = is_array( $keys ) ? $keys : array();
		foreach ( self::live() as $integration ) {
			$keys = array_merge( $keys, $integration->source_meta() );
		}

		return $keys;
	}

	/**
	 * This integration's translated segments, prefix removed.
	 *
	 * @param array<string, string> $translated All.
	 * @param string                $id         Integration id.
	 * @return array<string, string>
	 */
	private static function own( array $translated, string $id ): array {
		$prefix = self::PREFIX . $id . '.';
		$out    = array();
		foreach ( $translated as $key => $text ) {
			if ( str_starts_with( (string) $key, $prefix ) ) {
				$out[ substr( (string) $key, strlen( $prefix ) ) ] = (string) $text;
			}
		}

		return $out;
	}

	/**
	 * Register `GET integrations`.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			Rest::NAMESPACE,
			'/integrations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_list' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * `GET integrations`: what is supported, and what of it is on this site.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_list(): \WP_REST_Response {
		$rows = array();
		foreach ( self::all() as $integration ) {
			$rows[] = array(
				'id'        => $integration->id(),
				'label'     => $integration->label(),
				'feature'   => $integration->feature(),
				'available' => $integration->available(),
			);
		}

		return new \WP_REST_Response(
			array(
				'pro'          => Edition::pro(),
				'integrations' => $rows,
			)
		);
	}
}
