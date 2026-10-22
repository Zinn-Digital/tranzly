<?php
/**
 * An engine that can say what its provider billed for the last translation.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Engines;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional, beside {@see Engine}: an engine whose provider reports the characters it BILLED (DeepL
 * does, with `show_billed_characters`). Tranzly shows that number after a machine translation, so
 * the site owner sees what the translation cost on their own account, as the provider counted it —
 * not Tranzly's estimate (which counts the text before markup and glossary handling).
 */
interface Billed_Characters {

	/**
	 * The characters the provider billed for the last `translate()` call on this instance, or null
	 * when it did not say (an older API, a failed call).
	 *
	 * @return int|null
	 */
	public function billed_characters(): ?int;
}
