<?php
/**
 * One integration with another plugin or a page builder (T6).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What an integration may do. Every method has a harmless default, so each integration overrides
 * only the seams it needs:
 *
 * - `segments()` / `write()`: more pieces of a post to translate (builder data, SEO fields, custom
 *   fields) and where each translated piece goes. Keys are local; the registry prefixes them with
 *   `x.<id>.` so two integrations never collide, and hands `write()` only its own.
 * - `copy_meta()`: settings a NEW translation needs to look like its original (a builder's layout
 *   flag, a product's price) — copied once, never translated.
 * - `source_meta()`: meta keys whose change makes the translations out of date (T7).
 * - `boot()`: anything at run time (swap a form for its language, send an e-mail in the order's
 *   language, …). Called only when `available()`.
 */
abstract class Integration {

	/**
	 * A stable id (`elementor`, `woocommerce`); also the segment key prefix.
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * The name people know it by.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Is the plugin, theme or builder this integrates with present on this site?
	 *
	 * @return bool
	 */
	abstract public function available(): bool;

	/**
	 * Which feature (features.json) this delivers.
	 *
	 * @return string
	 */
	public function feature(): string {
		return '';
	}

	/**
	 * Run-time hooks.
	 *
	 * @return void
	 */
	public function boot(): void {}

	/**
	 * Extra segments of a post.
	 *
	 * @param \WP_Post $post The original.
	 * @return array<string, array{text: string, format: string}>
	 */
	public function segments( \WP_Post $post ): array {
		unset( $post );
		return array();
	}

	/**
	 * Write this integration's translated segments into the update.
	 *
	 * @param array<string, mixed>  $update `ID` (the translation) plus fields; add `meta_input`.
	 * @param array<string, string> $texts  Local key => translation (only this integration's keys).
	 * @param \WP_Post              $post   The original.
	 * @param string                $lang   Target language.
	 * @return array<string, mixed>
	 */
	public function write( array $update, array $texts, \WP_Post $post, string $lang ): array {
		unset( $texts, $post, $lang );
		return $update;
	}

	/**
	 * Extra segments of a term (category, tag, product attribute…).
	 *
	 * @param \WP_Term $term The original.
	 * @return array<string, array{text: string, format: string}>
	 */
	public function term_segments( \WP_Term $term ): array {
		unset( $term );
		return array();
	}

	/**
	 * Write this integration's translated term segments.
	 *
	 * @param int                   $target The translated term.
	 * @param array<string, string> $texts  Local key => translation.
	 * @param \WP_Term              $term   The original.
	 * @param string                $lang   Target language.
	 * @return void
	 */
	public function term_write( int $target, array $texts, \WP_Term $term, string $lang ): void {
		unset( $target, $texts, $term, $lang );
	}

	/**
	 * Meta keys copied onto a new translation.
	 *
	 * @param int $source_id The original.
	 * @return array<int, string>
	 */
	public function copy_meta( int $source_id ): array {
		unset( $source_id );
		return array();
	}

	/**
	 * Meta keys that make translations out of date when they change.
	 *
	 * @return array<int, string>
	 */
	public function source_meta(): array {
		return array();
	}

	/**
	 * Helper: segments from plain meta fields (key => format), skipping empty ones.
	 *
	 * @param int                   $post_id The post.
	 * @param array<string, string> $fields  Meta key => `text` or `html`.
	 * @return array<string, array{text: string, format: string}>
	 */
	protected static function meta_segments( int $post_id, array $fields ): array {
		$out = array();
		foreach ( $fields as $key => $format ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( is_string( $value ) && Tree::has_words( $value ) ) {
				$out[ $key ] = array(
					'text'   => $value,
					'format' => $format,
				);
			}
		}

		return $out;
	}

	/**
	 * Helper: put translated plain meta fields into the update.
	 *
	 * @param array<string, mixed>  $update The update.
	 * @param array<string, string> $texts  Meta key => translation.
	 * @param array<int, string>    $keys   Keys this integration owns.
	 * @return array<string, mixed>
	 */
	protected static function meta_write( array $update, array $texts, array $keys ): array {
		foreach ( $keys as $key ) {
			if ( isset( $texts[ $key ] ) && '' !== $texts[ $key ] ) {
				$update['meta_input'][ $key ] = $texts[ $key ];
			}
		}

		return $update;
	}
}
