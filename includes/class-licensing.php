<?php
/**
 * What the licensing SDK says about this installation.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads edition, upgrade and beta state from the SDK in one place.
 */
final class Licensing {

	/**
	 * May premium code run on this site?
	 *
	 * @return bool
	 */
	public static function can_use_premium(): bool {
		return function_exists( 'tranzly_fs' ) && tranzly_fs()->can_use_premium_code();
	}

	/**
	 * The upgrade URL, or null when there is nothing to sell (premium already usable).
	 *
	 * ⭐ Upsells are runtime-gated rather than build-gated (CONTRACT §3): there are no free-only
	 * files, so the same code decides at run time whether an upgrade prompt makes sense.
	 *
	 * @return string|null
	 */
	public static function upgrade_url(): ?string {
		if ( ! function_exists( 'tranzly_fs' ) || self::can_use_premium() ) {
			return null;
		}
		$url = (string) tranzly_fs()->get_upgrade_url();

		return '' === $url ? null : $url;
	}

	/**
	 * Beta-update state (sh-r4).
	 *
	 * The SDK runs the beta programme: a licensed, connected installation opts in from its
	 * Account page, and from then on is offered beta versions as updates. Only the premium
	 * package can join (the SDK registers its opt-in handler for premium code only), so the
	 * free edition reports `available: false`.
	 *
	 * @return array{available: bool, enabled: bool, accountUrl: string}
	 */
	public static function beta(): array {
		$state = array(
			'available'  => false,
			'enabled'    => false,
			'accountUrl' => '',
		);
		if ( ! function_exists( 'tranzly_fs' ) ) {
			return $state;
		}

		$fs = tranzly_fs();
		if ( $fs->is_registered() ) {
			$state['accountUrl'] = (string) $fs->get_account_url();
		}
		$state['available'] = $fs->is_premium() && $fs->is_registered();
		if ( $state['available'] ) {
			$site             = $fs->get_site();
			$state['enabled'] = is_object( $site ) && method_exists( $site, 'is_beta' ) && $site->is_beta();
		}

		return $state;
	}
}
