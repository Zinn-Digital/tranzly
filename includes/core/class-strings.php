<?php
/**
 * Translations of things that are not whole posts: the site title and tagline, widgets, and
 * media text (alt, caption, title).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field-level translations (tz-c11, tz-c13), stored where WordPress already loads them:
 *
 * - site title, tagline and widget text: ONE option per language, `tranzly_strings_<lang>`, not
 *   autoloaded (so a site with thirty languages does not load thirty sets on every request);
 *   reading it costs one query on a translated page and none with a persistent object cache;
 * - media text: the attachment's own meta (`_tranzly_media`, language => field => text), which
 *   WordPress has already loaded by the time it renders the image — zero queries.
 *
 * Translations are applied on the FRONT END only, when the current language is not the default:
 * wp-admin, REST and WP-CLI always see and save the original text.
 */
final class Strings {

	/** Attachment meta holding media translations. */
	public const MEDIA_META = '_tranzly_media';

	/** Media fields that can be translated. */
	public const MEDIA_FIELDS = array( 'alt', 'caption', 'title' );

	/** Site fields that can be translated. */
	public const SITE_FIELDS = array( 'blogname', 'blogdescription' );

	/** Widget instance fields that are text a visitor reads. */
	private const WIDGET_FIELDS = array( 'title', 'text', 'content' );

	/**
	 * Per-request memo of the current language's site/widget strings.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $loaded = array();

	/**
	 * Hook the front-end filters.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::SITE_FIELDS as $field ) {
			add_filter( 'option_' . $field, array( self::class, 'filter_site_option' ), 20, 2 );
		}
		add_filter( 'widget_display_callback', array( self::class, 'filter_widget' ), 20, 3 );
		add_filter( 'wp_get_attachment_image_attributes', array( self::class, 'filter_image_attributes' ), 20, 2 );
		add_filter( 'wp_get_attachment_caption', array( self::class, 'filter_caption' ), 20, 2 );
		add_filter( 'the_title', array( self::class, 'filter_attachment_title' ), 20, 2 );
	}

	/**
	 * The option name for one language's strings.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function option_name( string $lang ): string {
		return 'tranzly_strings_' . strtolower( $lang );
	}

	/**
	 * Every site/widget string in a language: key => translation.
	 *
	 * @param string $lang Language code.
	 * @return array<string, string>
	 */
	public static function all( string $lang ): array {
		if ( ! isset( self::$loaded[ $lang ] ) ) {
			$stored                = get_option( self::option_name( $lang ), array() );
			self::$loaded[ $lang ] = is_array( $stored ) ? array_map( 'strval', $stored ) : array();
		}

		return self::$loaded[ $lang ];
	}

	/**
	 * Save (or, with an empty string, remove) one site/widget translation.
	 *
	 * @param string $lang        Language code.
	 * @param string $key         `site.blogname`, `site.blogdescription` or `widget.<id>.<field>`.
	 * @param string $translation The translated text (already sanitised for its field).
	 * @return true|\WP_Error
	 */
	public static function set( string $lang, string $key, string $translation ) {
		if ( ! self::is_valid_key( $key ) ) {
			return new \WP_Error( 'tranzly_bad_string_key', __( 'That text cannot be translated here.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$all = self::all( $lang );
		if ( '' === $translation ) {
			unset( $all[ $key ] );
		} else {
			$all[ $key ] = $translation;
		}
		update_option( self::option_name( $lang ), $all, false );
		self::$loaded[ $lang ] = $all;

		return true;
	}

	/**
	 * Is this a key the store accepts?
	 *
	 * @param string $key The key.
	 * @return bool
	 */
	public static function is_valid_key( string $key ): bool {
		if ( in_array( $key, array( 'site.blogname', 'site.blogdescription' ), true ) ) {
			return true;
		}

		// Lane L05 (T3): a menu item's own label (`menu.<item id>`), and any other shared text by
		// the hash of its source (`text.<sha1>`: block-theme navigation labels, templates, patterns).
		return 1 === preg_match( '/^(?:widget\.[a-z0-9_-]{1,100}-[0-9]{1,9}\.(title|text|content)|menu\.[0-9]{1,19}|text\.[0-9a-f]{40})$/', $key );
	}

	/**
	 * Save (or, with an empty string, remove) many strings of one language in ONE write.
	 *
	 * @param string                $lang Language code.
	 * @param array<string, string> $map  Key => translation.
	 * @return true|\WP_Error
	 */
	public static function set_many( string $lang, array $map ) {
		$all = self::all( $lang );
		foreach ( $map as $key => $translation ) {
			$key = (string) $key;
			if ( ! self::is_valid_key( $key ) ) {
				return new \WP_Error( 'tranzly_bad_string_key', __( 'That text cannot be translated here.', 'tranzly' ), array( 'status' => 400 ) );
			}
			if ( '' === (string) $translation ) {
				unset( $all[ $key ] );
			} else {
				$all[ $key ] = (string) $translation;
			}
		}
		update_option( self::option_name( $lang ), $all, false );
		self::$loaded[ $lang ] = $all;

		return true;
	}

	/**
	 * Save one media translation.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $lang          Language code.
	 * @param string $field         `alt`, `caption` or `title`.
	 * @param string $translation   The text; empty removes it.
	 * @return true|\WP_Error
	 */
	public static function set_media( int $attachment_id, string $lang, string $field, string $translation ) {
		if ( ! in_array( $field, self::MEDIA_FIELDS, true ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error( 'tranzly_bad_media_field', __( 'That media text cannot be translated here.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$map = self::media_map( $attachment_id );
		if ( '' === $translation ) {
			unset( $map[ $lang ][ $field ] );
		} else {
			$map[ $lang ][ $field ] = $translation;
		}
		update_post_meta( $attachment_id, self::MEDIA_META, array_filter( $map ) );

		return true;
	}

	/**
	 * One media translation, or null.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $lang          Language code.
	 * @param string $field         Field.
	 * @return string|null
	 */
	public static function media( int $attachment_id, string $lang, string $field ): ?string {
		$value = self::media_map( $attachment_id )[ $lang ][ $field ] ?? null;

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * `option_blogname` / `option_blogdescription`.
	 *
	 * @param mixed  $value  The stored value.
	 * @param string $option The option name.
	 * @return mixed
	 */
	public static function filter_site_option( $value, string $option = '' ) {
		$lang = self::front_language();
		if ( null === $lang ) {
			return $value;
		}
		$translated = self::all( $lang )[ 'site.' . $option ] ?? '';

		return '' === $translated ? $value : $translated;
	}

	/**
	 * `widget_display_callback`: translate a widget's visible fields before it renders (classic
	 * text widgets and block widgets alike: a block widget keeps its markup in `content`).
	 *
	 * @param mixed      $instance The widget settings.
	 * @param \WP_Widget $widget   The widget.
	 * @param array      $args     Display arguments.
	 * @return mixed
	 */
	public static function filter_widget( $instance, $widget = null, $args = array() ) {
		unset( $args );
		$lang = self::front_language();
		if ( null === $lang || ! is_array( $instance ) || ! is_object( $widget ) || empty( $widget->id ) ) {
			return $instance;
		}
		$all = self::all( $lang );
		foreach ( self::WIDGET_FIELDS as $field ) {
			$key = 'widget.' . $widget->id . '.' . $field;
			if ( isset( $instance[ $field ], $all[ $key ] ) && is_string( $instance[ $field ] ) && '' !== $all[ $key ] ) {
				$instance[ $field ] = $all[ $key ];
			}
		}

		return $instance;
	}

	/**
	 * `wp_get_attachment_image_attributes`: the translated alt text.
	 *
	 * @param array<string, string> $attributes The image attributes.
	 * @param \WP_Post              $attachment The attachment.
	 * @return array<string, string>
	 */
	public static function filter_image_attributes( $attributes, $attachment = null ) {
		$lang = self::front_language();
		if ( null !== $lang && is_array( $attributes ) && $attachment instanceof \WP_Post ) {
			$alt = self::media( $attachment->ID, $lang, 'alt' );
			if ( null !== $alt ) {
				$attributes['alt'] = $alt;
			}
		}

		return $attributes;
	}

	/**
	 * `wp_get_attachment_caption`.
	 *
	 * @param string $caption       The caption.
	 * @param int    $attachment_id The attachment ID.
	 * @return string
	 */
	public static function filter_caption( $caption, $attachment_id = 0 ) {
		$lang = self::front_language();
		if ( null === $lang ) {
			return $caption;
		}

		return self::media( (int) $attachment_id, $lang, 'caption' ) ?? $caption;
	}

	/**
	 * `the_title`, for attachments only.
	 *
	 * @param string $title The title.
	 * @param int    $id    The post ID.
	 * @return string
	 */
	public static function filter_attachment_title( $title, $id = 0 ) {
		$lang = self::front_language();
		if ( null === $lang || 'attachment' !== get_post_type( (int) $id ) ) {
			return $title;
		}

		return self::media( (int) $id, $lang, 'title' ) ?? $title;
	}

	/**
	 * The language to translate into on this request, or null when nothing should change: admin
	 * screens, REST, WP-CLI, or the default language.
	 *
	 * @return string|null
	 */
	public static function front_language(): ?string {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return null;
		}
		if ( ! did_action( 'parse_request' ) ) {
			return null; // The language is not known before the request is parsed.
		}
		$lang = Languages::current();

		return Languages::default_code() === $lang ? null : $lang;
	}

	/**
	 * Forget the per-request memo (tests).
	 *
	 * @return void
	 */
	public static function reset_memo(): void {
		self::$loaded = array();
	}

	/**
	 * An attachment's media map.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, array<string, string>>
	 */
	private static function media_map( int $attachment_id ): array {
		$map = get_post_meta( $attachment_id, self::MEDIA_META, true );

		return is_array( $map ) ? $map : array();
	}
}
