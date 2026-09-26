<?php
/**
 * Runs an engine over a post (or term) and writes the result into its translation.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

use ZinnDigital\Tranzly\Engines\Engine;
use ZinnDigital\Tranzly\Engines\Registry;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One translation of one object by one engine. The background queue (T2), the editor (T3), REST
 * and WP-CLI all call this, so the rules below hold on every path:
 *
 * - ⛔ A PROTECTED translation is never overwritten by a machine: one a person edited (`human`)
 *   and one imported from Tranzly 2.x (`legacy`, where nobody knows whether a person edited it).
 *   `force` unlocks it on purpose (tz-r1).
 * - The permission check lives HERE, so no caller can forget it.
 * - What a post is made of is a filter (`tranzly_post_segments`), so the block-aware parser (T3)
 *   and integrations (T6) replace the plain title/excerpt/content split without touching this.
 */
final class Translator {

	/** Post / term meta: how the current translation was made. */
	public const STATUS_META = '_tranzly_translation_status';

	/** Post / term meta: the engine that made it. */
	public const ENGINE_META = '_tranzly_engine';

	/** Post / term meta: sha1 of the source segments it was made from (staleness, T7). */
	public const SOURCE_HASH_META = '_tranzly_source_hash';

	/** Statuses a machine may not overwrite without `force`. */
	public const PROTECTED = array( 'human', 'legacy' );

	/**
	 * Translate a post into a language, creating the translation when it does not exist.
	 *
	 * @param int                  $source_id The original post.
	 * @param string               $lang      A listed language.
	 * @param string               $engine_id An engine id; empty for the default engine.
	 * @param array<string, mixed> $args      `force` (bool): overwrite a protected translation;
	 *                                        `status` (`publish` or `draft`): the translation's
	 *                                        status afterwards (a NEW translation is a draft unless
	 *                                        `publish` is asked for, by someone allowed to publish).
	 * @return int|\WP_Error The translation's ID.
	 */
	public static function translate_post( int $source_id, string $lang, string $engine_id = '', array $args = array() ) {
		$engine = self::engine( $engine_id );
		if ( is_wp_error( $engine ) ) {
			return $engine;
		}
		$code = Languages::resolve( $lang );
		if ( null === $code ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'That language is not one of this site\'s languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		if ( ! Content::can_translate_post( $source_id ) ) {
			return new \WP_Error( 'tranzly_forbidden', __( 'You are not allowed to translate this item.', 'tranzly' ), array( 'status' => 403 ) );
		}
		$status = (string) ( $args['status'] ?? '' );
		if ( '' !== $status && ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
			return new \WP_Error( 'tranzly_bad_status', __( 'A translation can be published or kept as a draft.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$type = get_post_type_object( (string) get_post_type( $source_id ) );
		if ( 'publish' === $status && ( null === $type || ! current_user_can( $type->cap->publish_posts ) ) ) {
			return new \WP_Error( 'tranzly_forbidden', __( 'You are not allowed to publish this kind of content.', 'tranzly' ), array( 'status' => 403 ) );
		}
		$source_lang = Relations::language_of( 'post', $source_id ) ?? Languages::default_code();
		if ( $source_lang === $code ) {
			return new \WP_Error( 'tranzly_same_language', __( 'This item is already written in that language.', 'tranzly' ), array( 'status' => 400 ) );
		}

		$target = Relations::translations( 'post', $source_id )[ $code ] ?? null;
		if ( null !== $target ) {
			if ( ! current_user_can( 'edit_post', $target ) ) {
				return new \WP_Error( 'tranzly_forbidden', __( 'You are not allowed to change that translation.', 'tranzly' ), array( 'status' => 403 ) );
			}
			// ⛔ Not `$status`: that holds the post status asked for (publish/draft), and reusing it
			// wrote this meta value ('human', 'machine') into post_status, unpublishing the page.
			$state = (string) get_post_meta( $target, self::STATUS_META, true );
			if ( in_array( $state, self::PROTECTED, true ) && empty( $args['force'] ) ) {
				return new \WP_Error(
					'tranzly_protected',
					__( 'This translation was edited by a person (or imported), so it is protected. Unlock it to translate it again.', 'tranzly' ),
					array(
						'status' => 409,
						'id'     => $target,
					)
				);
			}
		}

		$post     = get_post( $source_id );
		$segments = self::post_segments( $post );
		$result   = self::run( $engine, $segments, $source_lang, $code );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( null === $target ) {
			$made = Content::create_post_translation( $source_id, $code );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$target = $made;
		}

		$update = array( 'ID' => $target );
		if ( '' !== $status ) {
			$update['post_status'] = $status;
		}
		foreach ( array(
			'title'   => 'post_title',
			'excerpt' => 'post_excerpt',
			'content' => 'post_content',
		) as $key => $field ) {
			if ( isset( $result[ $key ] ) ) {
				$update[ $field ] = $result[ $key ];
			}
		}

		/**
		 * Filters the post fields written into a translation, after the engine ran. Integrations
		 * that added their own segments (custom fields, builder data) write them here.
		 *
		 * @param array<string, mixed>  $update     `ID` plus the post fields to update.
		 * @param array<string, string> $translated Segment key => translated text.
		 * @param \WP_Post              $post       The original.
		 * @param string                $code       The target language.
		 */
		$update = (array) apply_filters( 'tranzly_translated_post_fields', $update, $result, $post, $code );
		$saved  = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		update_post_meta( $target, self::STATUS_META, 'machine' );
		update_post_meta( $target, self::ENGINE_META, $engine->id() );
		update_post_meta( $target, self::SOURCE_HASH_META, self::hash( $segments ) );

		/**
		 * Fires after an engine translated a post.
		 *
		 * @param int    $target    The translation.
		 * @param int    $source_id The original.
		 * @param string $code      The language.
		 * @param string $engine    The engine id.
		 */
		do_action( 'tranzly_post_translated', (int) $target, $source_id, $code, $engine->id() );

		return (int) $target;
	}

	/**
	 * Translate a term's name and description into its translation, creating it when needed.
	 *
	 * @param int                  $term_id   The original term.
	 * @param string               $lang      A listed language.
	 * @param string               $engine_id An engine id; empty for the default engine.
	 * @param array<string, mixed> $args      `force` (bool).
	 * @return int|\WP_Error The translation's term ID.
	 */
	public static function translate_term( int $term_id, string $lang, string $engine_id = '', array $args = array() ) {
		$engine = self::engine( $engine_id );
		if ( is_wp_error( $engine ) ) {
			return $engine;
		}
		$code = Languages::resolve( $lang );
		if ( null === $code ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'That language is not one of this site\'s languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		if ( ! Content::can_translate_term( $term_id ) ) {
			return new \WP_Error( 'tranzly_forbidden', __( 'You are not allowed to translate this item.', 'tranzly' ), array( 'status' => 403 ) );
		}
		$term        = get_term( $term_id );
		$source_lang = Relations::language_of( 'term', $term_id ) ?? Languages::default_code();
		$target      = Relations::translations( 'term', $term_id )[ $code ] ?? null;
		if ( null !== $target && in_array( (string) get_term_meta( $target, self::STATUS_META, true ), self::PROTECTED, true ) && empty( $args['force'] ) ) {
			return new \WP_Error( 'tranzly_protected', __( 'This translation was edited by a person (or imported), so it is protected. Unlock it to translate it again.', 'tranzly' ), array( 'status' => 409 ) );
		}

		$segments = array( 'name' => array( 'text' => $term->name, 'format' => 'text' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		if ( '' !== trim( $term->description ) ) {
			$segments['description'] = array(
				'text'   => $term->description,
				'format' => 'html',
			);
		}
		$result = self::run( $engine, $segments, $source_lang, $code );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$slug = sanitize_title( $result['name'] ?? '' );
		if ( null === $target ) {
			$made = Content::create_term_translation(
				$term_id,
				$code,
				array(
					'name'        => $result['name'] ?? '',
					'slug'        => '' === $slug || term_exists( $slug, $term->taxonomy ) ? '' : $slug,
					'description' => $result['description'] ?? $term->description,
				)
			);
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$target = $made;
		} else {
			$updated = wp_update_term(
				$target,
				$term->taxonomy,
				array(
					'name'        => $result['name'] ?? $term->name,
					'description' => $result['description'] ?? $term->description,
				)
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}
		update_term_meta( $target, self::STATUS_META, 'machine' );
		update_term_meta( $target, self::ENGINE_META, $engine->id() );

		return (int) $target;
	}

	/**
	 * What a post is made of, for translation: key => text + format.
	 *
	 * @param \WP_Post $post The original.
	 * @return array<string, array{text: string, format: string}>
	 */
	public static function post_segments( \WP_Post $post ): array {
		$segments = array();
		foreach ( array(
			'title'   => array( $post->post_title, 'text' ),
			'excerpt' => array( $post->post_excerpt, 'text' ),
			'content' => array( $post->post_content, 'html' ),
		) as $key => $pair ) {
			if ( '' !== trim( (string) $pair[0] ) ) {
				$segments[ $key ] = array(
					'text'   => (string) $pair[0],
					'format' => $pair[1],
				);
			}
		}

		/**
		 * Filters the segments of a post sent to the engine. Key => `text` + `format` (`text` or
		 * `html`). The block-aware parser and integrations add, split or replace segments here, and
		 * write them back on `tranzly_translated_post_fields`.
		 *
		 * @param array<string, array{text: string, format: string}> $segments The segments.
		 * @param \WP_Post                                           $post     The original.
		 */
		return (array) apply_filters( 'tranzly_post_segments', $segments, $post );
	}

	/**
	 * Estimate the cost of translating posts into languages, before anything is sent (tz-e6).
	 *
	 * @param array<int, int>    $post_ids The originals.
	 * @param array<int, string> $langs    Target languages.
	 * @param string             $engine_id An engine id; empty for the default.
	 * @return array{characters: int, cost_usd: float|null, engine: string}|\WP_Error
	 */
	public static function estimate( array $post_ids, array $langs, string $engine_id = '' ) {
		$engine = self::engine( $engine_id );
		if ( is_wp_error( $engine ) ) {
			return $engine;
		}
		$characters = 0;
		$cost       = 0.0;
		$known      = true;
		foreach ( $post_ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$texts = array_map( static fn( $s ) => $s['text'], self::post_segments( $post ) );
			foreach ( $langs as $lang ) {
				$one         = $engine->estimate( $texts, (string) $lang );
				$characters += (int) $one['characters'];
				if ( null === $one['cost_usd'] ) {
					$known = false;
				} else {
					$cost += (float) $one['cost_usd'];
				}
			}
		}

		return array(
			'characters' => $characters,
			'cost_usd'   => $known ? round( $cost, 4 ) : null,
			'engine'     => $engine->id(),
		);
	}

	/**
	 * The named engine, or the default one.
	 *
	 * @param string $engine_id Engine id or empty.
	 * @return Engine|\WP_Error
	 */
	public static function engine( string $engine_id ) {
		$registry = Registry::instance();
		$engine   = '' === $engine_id ? $registry->default_engine() : $registry->get( $engine_id );
		if ( null === $engine ) {
			return new \WP_Error( 'tranzly_no_engine', '' === $engine_id ? __( 'No translation engine is set up yet. Add a key for one in Tranzly → Engines.', 'tranzly' ) : __( 'That translation engine is not available on this site.', 'tranzly' ), array( 'status' => 400 ) );
		}
		if ( ! $engine->is_configured() ) {
			return new \WP_Error( 'tranzly_engine_not_configured', __( 'That translation engine is not set up yet: add its key first.', 'tranzly' ), array( 'status' => 400 ) );
		}

		return $engine;
	}

	/**
	 * Call the engine once per format and check it answered every key.
	 *
	 * @param Engine                                             $engine   The engine.
	 * @param array<string, array{text: string, format: string}> $segments The segments.
	 * @param string                                             $source   Source language.
	 * @param string                                             $target   Target language.
	 * @return array<string, string>|\WP_Error
	 */
	private static function run( Engine $engine, array $segments, string $source, string $target ) {
		$by_format = array();
		foreach ( $segments as $key => $segment ) {
			$format                                = ( $segment['format'] ?? 'text' ) === 'html' ? 'html' : 'text';
			$by_format[ $format ][ (string) $key ] = (string) $segment['text'];
		}

		/**
		 * Filters the options passed to the engine (do-not-translate list, formality,
		 * instructions — T2 fills these per language).
		 *
		 * @param array<string, mixed> $options Options.
		 * @param string               $target  The target language.
		 * @param Engine               $engine  The engine.
		 */
		$options = (array) apply_filters( 'tranzly_engine_options', array(), $target, $engine );

		$out = array();
		foreach ( $by_format as $format => $texts ) {
			$answer = $engine->translate( $texts, $source, $target, array( 'format' => $format ) + $options );
			if ( is_wp_error( $answer ) ) {
				return $answer;
			}
			$missing = array_diff_key( $texts, (array) $answer );
			if ( array() !== $missing ) {
				return new \WP_Error(
					'tranzly_engine_incomplete',
					/* translators: %s: a translation engine's name. */
					sprintf( __( '%s did not return a translation for every part of this item. Nothing was changed.', 'tranzly' ), $engine->label() ),
					array( 'status' => 502 )
				);
			}
			foreach ( $texts as $key => $unused ) {
				$out[ $key ] = (string) $answer[ $key ];
			}
		}

		return $out;
	}

	/**
	 * A hash of what the translation was made from.
	 *
	 * @param array<string, array{text: string, format: string}> $segments The segments.
	 * @return string
	 */
	private static function hash( array $segments ): string {
		ksort( $segments );

		return sha1( (string) wp_json_encode( $segments ) );
	}
}
