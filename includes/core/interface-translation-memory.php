<?php
/**
 * The contract a translation memory fulfils. The free plugin has none; the premium layer
 * offers one through the `tranzly_translation_memory` filter.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A memory of finished translations, keyed by text, format, both languages and engine options.
 */
interface Translation_Memory {

	/**
	 * The memory key of one text.
	 *
	 * @param string               $text    Source text.
	 * @param string               $format  `text` or `html`.
	 * @param string               $source  Source language.
	 * @param string               $target  Target language.
	 * @param array<string, mixed> $options The engine options in force.
	 * @return string
	 */
	public function key( string $text, string $format, string $source, string $target, array $options ): string;

	/**
	 * Ready translations for these keys: key => translation.
	 *
	 * @param array<int, string> $keys Keys.
	 * @return array<string, string>
	 */
	public function lookup( array $keys ): array;

	/**
	 * Reserve a key for this job; false when another job holds it.
	 *
	 * @param string $key    Key.
	 * @param string $source Source language.
	 * @param string $target Target language.
	 * @return bool
	 */
	public function reserve( string $key, string $source, string $target ): bool;

	/**
	 * Store a translation under a reserved key.
	 *
	 * @param string $key         Key.
	 * @param string $translation Translation.
	 * @param string $engine      Engine id.
	 * @return void
	 */
	public function store( string $key, string $translation, string $engine ): void;

	/**
	 * Release a reservation that produced nothing.
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public function release( string $key ): void;
}
