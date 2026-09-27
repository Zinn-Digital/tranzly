<?php
/**
 * Which edition is running.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro features in the core check here. The premium CODE lives in `__premium_only` paths; this
 * gates Pro BEHAVIOUR of shared code (per-language engines, fallback, caps, memory, glossary).
 */
final class Edition {

	/**
	 * Is a Pro licence active?
	 *
	 * @return bool
	 */
	public static function pro(): bool {
		$pro = function_exists( 'tranzly_fs' ) && tranzly_fs()->can_use_premium_code();

		/**
		 * Filters whether Pro features are on (tests and staging use it; the licence decides).
		 *
		 * @param bool $pro True with an active Pro licence.
		 */
		return (bool) apply_filters( 'tranzly_is_pro', $pro );
	}
}
