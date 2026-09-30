<?php
/**
 * Media text per language (tz-c11) — alternative text, caption and title — edited where WordPress
 * edits them: the attachment's details in the Media Library and on its edit screen.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Core\Strings;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ Until 3.16 the translations were stored and shown to visitors (Core\Strings) but could only be
 * written through the REST API: no screen reached them (T9a route inventory). WordPress's own
 * attachment-fields filters put one field per language and text next to the original, in the
 * modal and on the edit screen alike, and save through the same Strings::set_media() the REST route
 * uses. Only images get the alternative-text field, as WordPress does.
 */
final class Media_Admin {

	/**
	 * Hook the attachment fields.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'attachment_fields_to_edit', array( self::class, 'fields' ), 20, 2 );
		add_filter( 'attachment_fields_to_save', array( self::class, 'save' ), 20, 2 );
	}

	/**
	 * The fields for one attachment: for every language but the default, its alt text (images),
	 * caption and title.
	 *
	 * @param array<string, array<string, mixed>> $fields     Fields WordPress shows.
	 * @param \WP_Post                            $attachment The attachment.
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields( $fields, $attachment ) {
		if ( ! is_array( $fields ) || ! $attachment instanceof \WP_Post || ! current_user_can( 'edit_post', $attachment->ID ) ) {
			return $fields;
		}
		$default = Languages::default_code();
		foreach ( Languages::all() as $language ) {
			$code = (string) $language['code'];
			if ( $code === $default ) {
				continue;
			}
			$name = '' !== (string) $language['name'] ? (string) $language['name'] : $code;
			foreach ( self::field_labels( wp_attachment_is_image( $attachment->ID ) ) as $field => $label ) {
				$fields[ self::key( $code, $field ) ] = array(
					/* translators: 1: a field such as "Alternative text", 2: a language's name, e.g. Deutsch. */
					'label' => sprintf( __( '%1$s (%2$s)', 'tranzly' ), $label, $name ),
					'input' => 'text',
					'value' => (string) Strings::media( $attachment->ID, $code, $field ),
				);
			}
		}

		return $fields;
	}

	/**
	 * `attachment_fields_to_save`: store what was typed (an emptied field removes the translation).
	 *
	 * @param array<string, mixed> $post       The attachment's post data.
	 * @param array<string, mixed> $attachment The submitted attachment fields.
	 * @return array<string, mixed>
	 */
	public static function save( $post, $attachment ) {
		$id = is_array( $post ) ? (int) ( $post['ID'] ?? 0 ) : 0;
		if ( $id < 1 || ! is_array( $attachment ) || ! current_user_can( 'edit_post', $id ) ) {
			return $post;
		}
		$default = Languages::default_code();
		foreach ( Languages::all() as $language ) {
			$code = (string) $language['code'];
			if ( $code === $default ) {
				continue;
			}
			foreach ( Strings::MEDIA_FIELDS as $field ) {
				$key = self::key( $code, $field );
				if ( array_key_exists( $key, $attachment ) ) {
					Strings::set_media( $id, $code, $field, sanitize_text_field( (string) $attachment[ $key ] ) );
				}
			}
		}

		return $post;
	}

	/**
	 * The field name for a language and a text.
	 *
	 * @param string $code  Language code.
	 * @param string $field `alt`, `caption` or `title`.
	 * @return string
	 */
	public static function key( string $code, string $field ): string {
		return 'tranzly_' . strtolower( $code ) . '_' . $field;
	}

	/**
	 * The texts shown, in WordPress's order.
	 *
	 * @param bool $image Whether the attachment is an image.
	 * @return array<string, string>
	 */
	private static function field_labels( bool $image ): array {
		$labels = array();
		if ( $image ) {
			$labels['alt'] = __( 'Alternative text', 'tranzly' );
		}
		$labels['caption'] = __( 'Caption', 'tranzly' );
		$labels['title']   = __( 'Title', 'tranzly' );

		return $labels;
	}
}
