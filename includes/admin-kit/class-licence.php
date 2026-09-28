<?php
/**
 * What the licence on this site entitles it to.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-licence.php by wp/bin/build-admin-kit.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\Tranzly\AdminKit
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\AdminKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place a plugin asks "which plan is this site on?" and "may it use feature X?".
 *
 * ⛔⛔ **LEGACY PLANS ARE MAPPED BY LICENCE QUOTA, NEVER BY PLAN ORDER** (docs/843 D9, D34).
 * The old plans (Page Builder Sandwich `premium`, Tranzly `pro`) were created in Freemius
 * before Personal, Business and Agency, so the SDK's `is_plan()` — which compares plan
 * POSITIONS — would rank an unlimited legacy licence below Personal and take features away from
 * the customers D9 promised to keep whole. So a paid plan that is not one of the three is
 * read by what the licence actually covers:
 *
 *     legacy licence, unlimited sites  -> agency   (every feature, white label included)
 *     legacy licence, more than 1 site -> business (every Pro feature, priority support)
 *     legacy licence, 1 site           -> personal (every Pro feature)
 *
 * ⭐ A bundle licence needs no rule of its own: the SDK activates the bundle's per-product
 * licence in each plugin (`bundle_license_auto_activation`), and each of those is on the
 * product plan of the same name. So a bundle's Business licence reads as `business` in both
 * plugins through the same path as any other.
 */
final class Licence {

	/** Plan ranks. A plan includes every feature of the plans below it. */
	public const RANK = array(
		'free'     => 0,
		'personal' => 1,
		'business' => 2,
		'agency'   => 3,
	);

	/**
	 * Nothing to hook today; kept so boot() reads as the list of parts.
	 *
	 * @return void
	 */
	public static function register(): void {}

	/**
	 * The plan tier in force: `free`, `personal`, `business` or `agency`.
	 *
	 * @return string
	 */
	public static function tier(): string {
		$tier = self::tier_from( Kit::fs() );

		/**
		 * Filters the plan tier the kit reports for this plugin (tests and staging; the licence
		 * decides in production).
		 *
		 * @param string $tier `free`, `personal`, `business` or `agency`.
		 */
		$tier = (string) apply_filters( 'tranzly_kit_tier', $tier );

		return isset( self::RANK[ $tier ] ) ? $tier : 'free';
	}

	/**
	 * The tier an SDK instance reports. Separate from tier() so it can be tested against a fake.
	 *
	 * @param object|null $fs The SDK instance.
	 * @return string
	 */
	public static function tier_from( ?object $fs ): string {
		if ( null === $fs || ! self::call( $fs, 'can_use_premium_code' ) ) {
			return 'free';
		}
		$plan = self::plan_name( $fs );
		if ( in_array( $plan, array( 'personal', 'business', 'agency' ), true ) ) {
			return $plan;
		}

		// A legacy (or otherwise unknown) paid plan: read what the licence covers (D34).
		// ⛔ A licence the SDK cannot show us is NOT an unlimited one: quota() answers null for
		// both, and reading that as unlimited would hand white label to whoever it failed on.
		if ( null === self::licence( $fs ) ) {
			return 'personal';
		}
		$quota = self::quota( $fs );
		if ( null === $quota ) {
			return 'agency';
		}

		return $quota > 1 ? 'business' : 'personal';
	}

	/**
	 * The name of the plan the SDK reports, lower-case; `free` when there is none.
	 *
	 * @param object $fs The SDK instance.
	 * @return string
	 */
	public static function plan_name( object $fs ): string {
		$plan = method_exists( $fs, 'get_plan' ) ? $fs->get_plan() : null;
		$name = is_object( $plan ) && isset( $plan->name ) ? strtolower( (string) $plan->name ) : '';

		return '' === $name ? 'free' : $name;
	}

	/**
	 * The licence's site quota: an int, or null for unlimited (or no licence at all).
	 *
	 * @param object $fs The SDK instance.
	 * @return int|null
	 */
	public static function quota( object $fs ): ?int {
		$licence = self::licence( $fs );
		if ( null === $licence ) {
			return null;
		}
		$unlimited = method_exists( $licence, 'is_unlimited' ) ? (bool) $licence->is_unlimited() : ! isset( $licence->quota );

		return $unlimited || ! isset( $licence->quota ) ? null : (int) $licence->quota;
	}

	/**
	 * May this site use a feature?
	 *
	 * @param string $feature A feature id from docs/plugins-overhaul/features.json (`pbs-g3`).
	 * @return bool False for an id the matrix does not know: an unknown feature is never
	 *              "free by default".
	 */
	public static function can( string $feature ): bool {
		return self::tier_can( self::tier(), $feature );
	}

	/**
	 * Does a tier include a feature?
	 *
	 * @param string $tier    Plan tier.
	 * @param string $feature Feature id.
	 * @return bool
	 */
	public static function tier_can( string $tier, string $feature ): bool {
		$min = Matrix::min_plan( $feature );
		if ( null === $min || ! isset( self::RANK[ $tier ], self::RANK[ $min ] ) ) {
			return false;
		}

		return self::RANK[ $tier ] >= self::RANK[ $min ];
	}

	/**
	 * What the shell shows about the licence. No key, no secret: this is printed into the page.
	 *
	 * @return array<string, mixed>
	 */
	public static function snapshot(): array {
		$fs   = Kit::fs();
		$tier = self::tier();
		$out  = array(
			'tier'          => $tier,
			'plan'          => 'free',
			'legacy'        => false,
			'premium'       => false,
			'registered'    => false,
			'sites'         => null,
			'activated'     => null,
			'unlimited'     => false,
			'trial'         => array(
				'active'    => false,
				'available' => false,
				'used'      => false,
				'endsAt'    => '',
				'days'      => (int) ( Data::get( 'plans' )['trial_days'] ?? 14 ),
			),
			'localhost'     => self::is_local_site(),
			'freeLocalhost' => true,
			'priority'      => self::tier_can( $tier, 'sh-r5' ),
			'whiteLabel'    => self::tier_can( $tier, 'pbs-g3' ),
			'upgradeUrl'    => '',
			'trialUrl'      => '',
			'pricingUrl'    => '',
			'accountUrl'    => '',
			'renewal'       => null,
			'bundle'        => '' !== (string) ( Data::get( 'products' )['bundle']['id'] ?? '' ),
			'installId'     => '',
			'licenceId'     => '',
		);
		if ( null === $fs ) {
			return $out;
		}

		$out['premium']    = self::call( $fs, 'can_use_premium_code' );
		$out['registered'] = self::call( $fs, 'is_registered' );
		$plan              = self::plan_name( $fs );
		$out['plan']       = $out['premium'] ? $plan : 'free';
		$out['legacy']     = $out['premium'] && ! isset( self::RANK[ $plan ] );

		$licence = self::licence( $fs );
		if ( null !== $licence ) {
			$quota                = self::quota( $fs );
			$out['sites']         = $quota;
			$out['unlimited']     = null === $quota;
			$out['activated']     = isset( $licence->activated ) ? (int) $licence->activated : null;
			$out['licenceId']     = isset( $licence->id ) ? (string) $licence->id : '';
			$out['freeLocalhost'] = ! isset( $licence->is_free_localhost ) || (bool) $licence->is_free_localhost;
			$out['renewal']       = self::renewal( $fs, $licence );
		}
		$site = method_exists( $fs, 'get_site' ) ? $fs->get_site() : null;
		if ( is_object( $site ) && isset( $site->id ) ) {
			$out['installId'] = (string) $site->id;
		}

		$out['trial']['active']    = self::call( $fs, 'is_trial' );
		$out['trial']['used']      = self::call( $fs, 'is_trial_utilized' );
		$out['trial']['available'] = self::call( $fs, 'has_trial_plan' ) && ! $out['trial']['used'] && ! $out['trial']['active'] && ! self::call( $fs, 'is_paying' );
		if ( $out['trial']['active'] && is_object( $site ) && ! empty( $site->trial_ends ) ) {
			$out['trial']['endsAt'] = (string) $site->trial_ends;
		}

		if ( ! $out['premium'] ) {
			$out['upgradeUrl'] = self::url( $fs, 'get_upgrade_url' );
			$out['trialUrl']   = $out['trial']['available'] ? self::url( $fs, 'get_trial_url' ) : '';
		}
		$out['pricingUrl'] = self::url( $fs, 'pricing_url' );
		if ( $out['registered'] ) {
			$out['accountUrl'] = self::url( $fs, 'get_account_url' );
		}

		return $out;
	}

	/**
	 * Start the no-card trial (feature adm-7). The SDK can start it in place only for an
	 * installation that is connected to the licensing service; otherwise the caller sends the
	 * user to the trial URL, where they connect and start it in one step.
	 *
	 * @return array{started: bool, url: string, message: string}
	 */
	public static function start_trial(): array {
		$fs = Kit::fs();
		if ( null === $fs ) {
			return array(
				'started' => false,
				'url'     => '',
				'message' => __( 'The licensing service is not available on this site.', 'tranzly' ),
			);
		}
		if ( ! self::call( $fs, 'is_registered' ) || ! method_exists( $fs, 'start_trial' ) ) {
			return array(
				'started' => false,
				'url'     => self::url( $fs, 'get_trial_url' ),
				'message' => '',
			);
		}
		$result = $fs->start_trial();
		if ( true === $result ) {
			return array(
				'started' => true,
				'url'     => '',
				'message' => '',
			);
		}

		return array(
			'started' => false,
			'url'     => self::url( $fs, 'get_trial_url' ),
			'message' => is_string( $result ) && '' !== $result ? wp_strip_all_tags( $result ) : __( 'The trial could not be started here. Continue on the trial page instead.', 'tranzly' ),
		);
	}

	/**
	 * Is this site a staging, local or development copy (feature sh-r1)?
	 *
	 * ⭐ The licensing SDK's own test is authoritative, because it is what decides whether the
	 * activation counts: a `localhost`, `*.local`, `*.test`, `*.dev`, `staging.*` or similar
	 * address. WordPress's environment type is used as well, so a site the owner has marked
	 * `staging` is described as one even on a public address.
	 *
	 * @return bool
	 */
	public static function is_local_site(): bool {
		$url = (string) home_url();
		if ( class_exists( '\FS_Site' ) && method_exists( '\FS_Site', 'is_localhost_by_address' ) && \FS_Site::is_localhost_by_address( $url ) ) {
			return true;
		}

		return in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true );
	}

	/**
	 * The renewal offer, when the licence ends soon or has ended and nothing renews it.
	 *
	 * @param object $fs      The SDK instance.
	 * @param object $licence The licence.
	 * @return array{expires: string, expired: bool, url: string}|null
	 */
	private static function renewal( object $fs, object $licence ): ?array {
		$expires = isset( $licence->expiration ) ? (string) $licence->expiration : '';
		if ( '' === $expires ) {
			return null; // A licence with no end date needs no renewal.
		}
		$ends = strtotime( $expires . ' UTC' );
		if ( false === $ends || $ends > time() + 30 * DAY_IN_SECONDS ) {
			return null;
		}
		$subscription = method_exists( $fs, '_get_subscription' ) ? $fs->_get_subscription() : null;
		if ( is_object( $subscription ) && method_exists( $subscription, 'is_active' ) && $subscription->is_active() ) {
			return null; // It renews by itself.
		}

		return array(
			'expires' => gmdate( 'c', $ends ),
			'expired' => $ends <= time(),
			'url'     => self::url( $fs, 'get_account_url' ),
		);
	}

	/**
	 * The SDK's licence object, when there is one.
	 *
	 * @param object $fs The SDK instance.
	 * @return object|null
	 */
	private static function licence( object $fs ): ?object {
		$licence = method_exists( $fs, '_get_license' ) ? $fs->_get_license() : null;

		return is_object( $licence ) ? $licence : null;
	}

	/**
	 * Call a boolean SDK method that may not exist on an older SDK or a fake.
	 *
	 * @param object $fs     The SDK instance.
	 * @param string $method Method name.
	 * @return bool
	 */
	private static function call( object $fs, string $method ): bool {
		return method_exists( $fs, $method ) && (bool) $fs->$method();
	}

	/**
	 * A URL from an SDK method, or an empty string.
	 *
	 * @param object $fs     The SDK instance.
	 * @param string $method Method name.
	 * @return string
	 */
	private static function url( object $fs, string $method ): string {
		return method_exists( $fs, $method ) ? (string) $fs->$method() : '';
	}
}
