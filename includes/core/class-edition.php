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
 * Whether the premium layer is running. ⛔ WordPress.org guideline 5 (review 2026-10-01): the
 * free plugin carries no licence check and no Pro behaviour. Every Pro feature lives in a
 * `__premium_only` path, and the premium layer (absent from the free package) answers the
 * `tranzly_is_pro` filter with its licence. Free code only asks it to decide whether to SHOW an
 * upgrade prompt.
 */
final class Edition {

	/**
	 * Is a Pro licence active?
	 *
	 * @return bool
	 */
	public static function pro(): bool {
		/**
		 * Filters whether the premium layer is running. Only the premium layer answers it.
		 *
		 * @param bool $pro False in the free plugin.
		 */
		return (bool) apply_filters( 'tranzly_is_pro', false );
	}
}
