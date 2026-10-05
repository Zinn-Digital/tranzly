<?php
/**
 * Runs an engine over a post (or term) and writes the result into its translation.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

use ZinnDigital\Tranzly\Engines\Engine;
use ZinnDigital\Tranzly\Engines\Failure;
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

	/** The AI provider/model that wrote a translation (`gemini/gemini-3.8-flash`); empty for other engines. */
	public const MODEL_META = '_tranzly_model';

	/** Post / term meta: sha1 of the source segments it was made from (staleness, T7). */
	public const SOURCE_HASH_META = '_tranzly_source_hash';

	/** Statuses a machine may not overwrite without `force`. */
	public const PROTECTED = array( 'human', 'legacy' );

	/**
	 * The translation Tranzly itself is writing right now (0 when none).
	 *
	 * ⛔ Protection's `post_updated` marker cannot tell this write from a person's edit — the queue
	 * runs as the job's owner, so there IS a current user — and the `machine` marker is written
	 * only after `wp_update_post()` returns. A worker SIGKILLed between the two left the
	 * translation marked `human`, so the resumed job skipped it as protected and it could never be
	 * re-translated again (main's post-merge suite, 2026-09-28: done 499, skipped 1). An in-memory
	 * flag dies with the process, so a kill can no longer leave a false `human` behind.
	 *
	 * @var int
	 */
	private static int $writing = 0;

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
		$code = Languages::resolve( $lang );
		if ( null === $code ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'That language is not one of this site\'s languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$chain = self::chain( $engine_id, $code );
		if ( is_wp_error( $chain ) ) {
			return $chain;
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

		$target = Relations::live_post_translation( $source_id, $code );
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
		$ran      = self::run( $chain, $segments, $source_lang, $code );
		if ( is_wp_error( $ran ) ) {
			return $ran;
		}
		$result = $ran['texts'];
		$engine = $ran['engine'];

		return self::write_post(
			$source_id,
			$code,
			$result,
			array(
				'status'   => $status,
				'mark'     => 'machine',
				'engine'   => $engine->id(),
				'model'    => (string) ( $ran['model'] ?? '' ),
				'segments' => $segments,
			)
		);
	}

	/**
	 * The status a translation is written with: the one asked for, except that a translation is
	 * never more public than its source. Asked to publish the translation of a draft, pending,
	 * scheduled or private original, it stays a draft (live 2026-10-02: `jobs create --post-type
	 * --publish` published the translations of drafts).
	 *
	 * @param string $asked  `publish`, `draft` or '' (leave the status as it is).
	 * @param string $source The original's post status.
	 * @return string
	 */
	public static function status_for( string $asked, string $source ): string {
		return 'publish' === $asked && 'publish' !== $source ? 'draft' : $asked;
	}

	/**
	 * Write translated segments into a post's translation, creating it when it does not exist. The
	 * ONE write path: the engine (above), a translator's XLIFF/CSV file and a competitor import (T7)
	 * all come through here, so integrations see every translation on `tranzly_translated_post_fields`.
	 *
	 * Callers check protection themselves (a person's file writes on purpose); this checks permission.
	 *
	 * @param int                   $source_id The original post.
	 * @param string                $code      A listed language code.
	 * @param array<string, string> $texts     Segment key => translated text.
	 * @param array<string, mixed>  $args      `status` (post status or empty), `mark` (`machine`,
	 *                                         `human`, `legacy`), `engine` (id or empty), `segments`
	 *                                         (the source segments the texts were made from).
	 * @return int|\WP_Error The translation's ID.
	 */
	public static function write_post( int $source_id, string $code, array $texts, array $args = array() ) {
		if ( ! Content::can_translate_post( $source_id ) ) {
			return new \WP_Error( 'tranzly_forbidden', __( 'You are not allowed to translate this item.', 'tranzly' ), array( 'status' => 403 ) );
		}
		$post = get_post( $source_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'tranzly_not_found', __( 'That item does not exist.', 'tranzly' ), array( 'status' => 404 ) );
		}
		$status = self::status_for( (string) ( $args['status'] ?? '' ), (string) $post->post_status );
		$target = Relations::live_post_translation( $source_id, $code );
		if ( null !== $target && ! current_user_can( 'edit_post', $target ) ) {
			return new \WP_Error( 'tranzly_forbidden', __( 'You are not allowed to change that translation.', 'tranzly' ), array( 'status' => 403 ) );
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
			if ( isset( $texts[ $key ] ) ) {
				$update[ $field ] = $texts[ $key ];
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
		$update        = (array) apply_filters( 'tranzly_translated_post_fields', $update, $texts, $post, $code );
		self::$writing = (int) $target;
		try {
			$saved = wp_update_post( wp_slash( $update ), true );
		} finally {
			self::$writing = 0;
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$engine_id = (string) ( $args['engine'] ?? '' );
		$segments  = isset( $args['segments'] ) && is_array( $args['segments'] ) ? $args['segments'] : self::post_segments( $post );
		update_post_meta( $target, self::STATUS_META, (string) ( $args['mark'] ?? 'machine' ) );
		update_post_meta( $target, self::ENGINE_META, $engine_id );
		update_post_meta( $target, self::MODEL_META, (string) ( $args['model'] ?? '' ) );
		update_post_meta( $target, self::SOURCE_HASH_META, self::hash( $segments ) );

		/**
		 * Fires after an engine translated a post (or a person's file or an import wrote one).
		 *
		 * @param int    $target    The translation.
		 * @param int    $source_id The original.
		 * @param string $code      The language.
		 * @param string $engine    The engine id; empty when a file or an import wrote it.
		 */
		do_action( 'tranzly_post_translated', (int) $target, $source_id, $code, $engine_id );

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
		$code = Languages::resolve( $lang );
		if ( null === $code ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'That language is not one of this site\'s languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$chain = self::chain( $engine_id, $code );
		if ( is_wp_error( $chain ) ) {
			return $chain;
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

		/**
		 * Filters the segments of a term sent to the engine (`name`, `description`, plus whatever
		 * integrations add: SEO titles, custom fields). Write added ones on `tranzly_term_translated`.
		 *
		 * @param array<string, array{text: string, format: string}> $segments The segments.
		 * @param \WP_Term                                          $term     The original.
		 */
		$segments = (array) apply_filters( 'tranzly_term_segments', $segments, $term );
		$ran      = self::run( $chain, $segments, $source_lang, $code );
		if ( is_wp_error( $ran ) ) {
			return $ran;
		}
		$result = $ran['texts'];
		$engine = $ran['engine'];

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
		update_term_meta( $target, self::MODEL_META, (string) ( $ran['model'] ?? '' ) );

		/**
		 * Fires after an engine translated a term, with every translated segment (integrations write
		 * the segments they added on `tranzly_term_segments`).
		 *
		 * @param int                   $target     The translation.
		 * @param int                   $term_id    The original.
		 * @param string                $code       The language.
		 * @param array<string, string> $translated Segment key => translation.
		 */
		do_action( 'tranzly_term_translated', (int) $target, $term_id, $code, $result );

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
	 * The engines to try, in order: the one named (a person chose it: no fallback), or the
	 * language's chain from the settings (Pro: per-language engine, then the fallback list).
	 *
	 * @param string $engine_id An engine id, or empty.
	 * @param string $lang      Target language.
	 * @return array<int, Engine>|\WP_Error
	 */
	public static function chain( string $engine_id, string $lang ) {
		if ( '' !== $engine_id ) {
			$one = self::engine( $engine_id );
			return is_wp_error( $one ) ? $one : array( $one );
		}
		$chain = array();
		foreach ( Engine_Settings::chain( $lang ) as $id ) {
			$engine = Registry::instance()->get( $id );
			// An AI engine is usable for a language only when THAT language's provider is set up.
			if ( null !== $engine && ( method_exists( $engine, 'is_configured_for' ) ? $engine->is_configured_for( $lang ) : $engine->is_configured() ) ) {
				$chain[] = $engine;
			}
		}

		return array() === $chain ? self::engine( '' ) : $chain;
	}

	/**
	 * Translate segments: from memory where possible (Pro), the rest through the chain — each
	 * engine within its monthly cap, the next one taking over when one fails (Pro).
	 *
	 * @param array<int, Engine>                                 $chain    Engines, in order.
	 * @param array<string, array{text: string, format: string}> $segments The segments.
	 * @param string                                             $source   Source language.
	 * @param string                                             $target   Target language.
	 * @return array{texts: array<string, string>, engine: Engine, model: string}|\WP_Error
	 */
	private static function run( array $chain, array $segments, string $source, string $target ) {
		$options = self::options_for( $target, $chain[0] );

		$out      = array();
		$missing  = array(); // format => key => text.
		$reserved = array(); // key => memory key.
		/**
		 * Filters the translation memory in use: none in the free plugin; the premium layer offers
		 * one (docs/843, WordPress.org guideline 5).
		 *
		 * @param Translation_Memory|null $memory The memory, or null.
		 */
		$memory = apply_filters( 'tranzly_translation_memory', null );
		$memory = $memory instanceof Translation_Memory ? $memory : null;
		$keys   = array();
		foreach ( $segments as $key => $segment ) {
			$format = ( $segment['format'] ?? 'text' ) === 'html' ? 'html' : 'text';
			$text   = (string) $segment['text'];
			if ( null !== $memory ) {
				$keys[ (string) $key ] = $memory->key( $text, $format, $source, $target, $options );
			}
			$missing[ $format ][ (string) $key ] = $text;
		}
		if ( null !== $memory ) {
			$hits = $memory->lookup( array_values( $keys ) );
			foreach ( $missing as $format => $texts ) {
				foreach ( $texts as $key => $text ) {
					if ( isset( $hits[ $keys[ $key ] ] ) ) {
						$out[ $key ] = $hits[ $keys[ $key ] ];
						unset( $missing[ $format ][ $key ] );
					}
				}
			}
			foreach ( $missing as $texts ) {
				foreach ( $texts as $key => $text ) {
					// ⛔ The same text twice in ONE item (a repeated button label, two identical
					// paragraphs) is already this call's reservation, not another job's: reserving it
					// again refused the whole item as "busy" (found by T3's every-core-block round trip).
					if ( in_array( $keys[ $key ], $reserved, true ) ) {
						$reserved[ $key ] = $keys[ $key ];
						continue;
					}
					if ( ! $memory->reserve( $keys[ $key ], $source, $target ) ) {
						foreach ( array_unique( $reserved ) as $mine ) {
							$memory->release( $mine );
						}
						return new \WP_Error( 'tranzly_memory_busy', __( 'The same text is being translated by another job right now; this item will use that answer.', 'tranzly' ), array( 'status' => 409 ) );
					}
					$reserved[ $key ] = $keys[ $key ];
				}
			}
		}
		$missing = array_filter( $missing );
		// Memory may hold answers stored before the style rules existed: they are styled too
		// (idempotent, so an answer already styled is unchanged).
		$sources = array();
		foreach ( $segments as $key => $segment ) {
			$sources[ (string) $key ] = (string) $segment['text'];
		}
		$out = Style_Rules::apply_all( $out, $sources, $target );
		if ( array() === $missing ) {
			return array(
				'texts'  => $out,
				'engine' => $chain[0],
				'model'  => '',
			);
		}

		$ran = self::through_chain( $chain, $missing, $source, $target, $options );
		if ( ! is_wp_error( $ran ) ) {
			foreach ( $ran['texts'] as $key => $translation ) {
				$out[ $key ] = $translation;
				if ( null !== $memory && isset( $reserved[ $key ] ) ) {
					$memory->store( $reserved[ $key ], $translation, $ran['engine']->id() );
					unset( $reserved[ $key ] );
				}
			}

			return array(
				'texts'  => $out,
				'engine' => $ran['engine'],
				'model'  => $ran['model'],
			);
		}
		foreach ( null === $memory ? array() : array_unique( $reserved ) as $mine ) {
			$memory->release( $mine );
		}

		return $ran;
	}

	/**
	 * The options an engine receives for a language (do-not-translate list, glossary, formality,
	 * instructions), before the format is added.
	 *
	 * @param string $target The target language.
	 * @param Engine $engine The first engine of the language's chain.
	 * @return array<string, mixed>
	 */
	public static function options_for( string $target, Engine $engine ): array {
		/**
		 * Filters the options passed to the engine (do-not-translate list, glossary, formality,
		 * instructions).
		 *
		 * @param array<string, mixed> $options Options.
		 * @param string               $target  The target language.
		 * @param Engine               $engine  The first engine of the chain.
		 */
		return (array) apply_filters( 'tranzly_engine_options', Glossary::options_for( $target ), $target, $engine );
	}

	/**
	 * Translate one original into several languages in BATCHED calls ahead of the per-language
	 * work (owner, 2026-10-01: translation is always batched; docs/28 §"Batching"). Whatever the
	 * batch answers waits in the engine for {@see translate_post()}, which a caller runs next for
	 * each language as before; a language the batch did not answer is translated by its own call.
	 *
	 * Only engines that batch take part (the AI engine; DeepL already takes one language per
	 * request by design). Segments translation memory already holds for a language are not sent
	 * again, and nothing is sent when the engine's monthly cap would not cover it.
	 *
	 * @param int                $source_id The original post.
	 * @param array<int, string> $langs     Target languages.
	 * @param string             $engine_id An engine id; empty for each language's chain.
	 * @param callable|null      $tick      Called after every engine call.
	 * @return int Answers prepared (segments × languages).
	 */
	public static function prefetch( int $source_id, array $langs, string $engine_id = '', ?callable $tick = null ): int {
		$post = get_post( $source_id );
		if ( ! $post instanceof \WP_Post || ! Content::can_translate_post( $source_id ) ) {
			return 0;
		}
		return self::prefetch_segments( self::post_segments( $post ), Relations::language_of( 'post', $source_id ) ?? Languages::default_code(), $langs, $engine_id, $tick );
	}

	/**
	 * {@see prefetch()} for any segments (a term, the shared strings).
	 *
	 * @param array<string, array{text: string, format: string}> $segments The segments.
	 * @param string                                             $source   Source language.
	 * @param array<int, string>                                 $langs    Target languages.
	 * @param string                                             $engine_id An engine id; empty for each language's chain.
	 * @param callable|null                                      $tick     Called after every engine call.
	 * @param bool                                               $memory_first Leave out what translation memory holds (posts and terms read it; the shared strings do not).
	 * @return int
	 */
	public static function prefetch_segments( array $segments, string $source, array $langs, string $engine_id = '', ?callable $tick = null, bool $memory_first = true ): int {
		$memory  = $memory_first ? apply_filters( 'tranzly_translation_memory', null ) : null;
		$memory  = $memory instanceof Translation_Memory ? $memory : null;
		$engines = array();
		$plan    = array(); // engine id => signature => array( texts => format => key => text, targets => target => options ).
		foreach ( array_unique( array_map( 'strval', $langs ) ) as $lang ) {
			$code = Languages::resolve( $lang );
			if ( null === $code || $code === $source ) {
				continue;
			}
			$chain = self::chain( $engine_id, $code );
			if ( is_wp_error( $chain ) || ! method_exists( $chain[0], 'prefetch' ) ) {
				continue;
			}
			$engine  = $chain[0];
			$options = self::options_for( $code, $engine );
			$texts   = array();
			$keys    = array();
			foreach ( $segments as $key => $segment ) {
				$format = ( $segment['format'] ?? 'text' ) === 'html' ? 'html' : 'text';
				$text   = (string) $segment['text'];
				if ( '' === trim( $text ) ) {
					continue;
				}
				if ( null !== $memory ) {
					$keys[ (string) $key ] = $memory->key( $text, $format, $source, $code, $options );
				}
				$texts[ $format ][ (string) $key ] = $text;
			}
			if ( null !== $memory && array() !== $keys ) {
				$hits = $memory->lookup( array_values( $keys ) );
				foreach ( $texts as $format => $list ) {
					foreach ( $list as $key => $unused ) {
						if ( isset( $hits[ $keys[ $key ] ] ) ) {
							unset( $texts[ $format ][ $key ] );
						}
					}
				}
				$texts = array_filter( $texts );
			}
			if ( array() === $texts ) {
				continue;
			}
			// Languages that need exactly the same texts are batched together.
			$sig                                    = md5( (string) wp_json_encode( $texts ) );
			$engines[ $engine->id() ]               = $engine;
			$plan[ $engine->id() ][ $sig ]['texts'] = $texts;
			$plan[ $engine->id() ][ $sig ]['targets'][ $code ] = $options;
		}

		$done = 0;
		foreach ( $plan as $id => $groups ) {
			$engine = $engines[ $id ];
			foreach ( $groups as $group ) {
				if ( count( $group['targets'] ) < 2 ) {
					continue;
				}
				// Each budget (the engine's, and with a model per language the AI provider's) is
				// charged with the cost of the languages it pays for.
				$cost = array();
				foreach ( $group['texts'] as $texts ) {
					foreach ( array_keys( $group['targets'] ) as $code ) {
						$one = (float) ( $engine->estimate( $texts, (string) $code )['cost_usd'] ?? 0 );
						foreach ( Engine_Settings::budgets( $engine, (string) $code ) as $budget ) {
							$cost[ $budget ] = ( $cost[ $budget ] ?? 0.0 ) + $one;
						}
					}
				}
				$capped = false;
				foreach ( $cost as $budget => $usd ) {
					$capped = $capped || Engine_Settings::over_cap( (string) $budget, $usd );
				}
				if ( $capped ) {
					continue; // Each language's own call meets the cap and reports it.
				}
				foreach ( $group['texts'] as $format => $texts ) {
					$targets = array();
					foreach ( $group['targets'] as $code => $options ) {
						$targets[ $code ] = array( 'format' => $format ) + $options;
					}
					$done += (int) $engine->prefetch( $texts, $source, $targets, $tick );
				}
			}
		}

		return $done;
	}

	/**
	 * Translate through a chain: each engine in turn within its monthly cap(s), the next taking over
	 * when one fails; the answers styled by the target language's team rules ({@see Style_Rules}),
	 * whichever engine wrote them, and the spend recorded. The ONE path every engine call takes
	 * (posts, terms, shared strings, comments), so caps, fallback and style rules hold everywhere.
	 *
	 * @param array<int, Engine>                   $chain   Engines, in order.
	 * @param array<string, array<string, string>> $missing format => key => text.
	 * @param string                               $source  Source language.
	 * @param string                               $target  Target language.
	 * @param array<string, mixed>                 $options Engine options (no format).
	 * @return array{texts: array<string, string>, engine: Engine, model: string}|\WP_Error
	 */
	public static function through_chain( array $chain, array $missing, string $source, string $target, array $options ) {
		$last = null;
		foreach ( $chain as $engine ) {
			$cost = 0.0;
			foreach ( $missing as $texts ) {
				$cost += (float) ( $engine->estimate( $texts, $target )['cost_usd'] ?? 0 );
			}
			$budgets = Engine_Settings::budgets( $engine, $target );
			$capped  = false;
			foreach ( $budgets as $budget ) {
				$capped = $capped || Engine_Settings::over_cap( $budget, $cost );
			}
			if ( $capped ) {
				$last = Failure::make( Failure::CAP, $engine );
				continue;
			}
			$answer = self::call( $engine, $missing, $source, $target, $options );
			if ( is_wp_error( $answer ) ) {
				$last = $answer;
				continue;
			}
			foreach ( $budgets as $budget ) {
				Engine_Settings::add_spend( $budget, $cost );
			}
			$sources = array();
			foreach ( $missing as $texts ) {
				$sources += $texts;
			}

			return array(
				'texts'  => Style_Rules::apply_all( $answer, $sources, $target ),
				'engine' => $engine,
				'model'  => Engine_Settings::model_of( $engine, $target ),
			);
		}

		return $last instanceof \WP_Error ? $last : new \WP_Error( 'tranzly_no_engine', __( 'No translation engine could take this item.', 'tranzly' ), array( 'status' => 400 ) );
	}

	/**
	 * One engine, once per format, checked to have answered every key.
	 *
	 * @param Engine                               $engine  The engine.
	 * @param array<string, array<string, string>> $missing format => key => text.
	 * @param string                               $source  Source language.
	 * @param string                               $target  Target language.
	 * @param array<string, mixed>                 $options Engine options.
	 * @return array<string, string>|\WP_Error
	 */
	private static function call( Engine $engine, array $missing, string $source, string $target, array $options ) {
		$out = array();
		foreach ( $missing as $format => $texts ) {
			$answer = $engine->translate( $texts, $source, $target, array( 'format' => $format ) + $options );
			if ( is_wp_error( $answer ) ) {
				return $answer;
			}
			if ( array() !== array_diff_key( $texts, (array) $answer ) ) {
				return Failure::make( Failure::UNKNOWN, $engine, __( 'The engine did not return a translation for every part of this item. Nothing was changed.', 'tranzly' ) );
			}
			foreach ( $texts as $key => $unused ) {
				$out[ $key ] = (string) $answer[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Is Tranzly itself writing this translation right now (not a person)?
	 *
	 * @param int $post_id A post.
	 * @return bool
	 */
	public static function is_writing( int $post_id ): bool {
		return 0 !== self::$writing && self::$writing === $post_id;
	}

	/**
	 * A hash of what the translation was made from.
	 *
	 * @param array<string, array{text: string, format: string}> $segments The segments.
	 * @return string
	 */
	public static function hash( array $segments ): string {
		ksort( $segments );

		return sha1( (string) wp_json_encode( $segments ) );
	}
}
