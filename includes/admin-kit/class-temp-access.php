<?php
/**
 * Temporary support access: a real, expiring WordPress user the site owner creates.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-temp-access.php by wp/bin/build-admin-kit.php.
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
 * "Create temporary support access" (docs/plugins-overhaul/x1-x7-contract.md §3).
 *
 * ⛔⛔ **THIS IS NOT REMOTE ADMINISTRATION, AND THE DIFFERENCE IS THE WHOLE DESIGN.** The site
 * owner, signed in to their own wp-admin, presses a button. The plugin creates an ordinary
 * WordPress user with a random password and an expiry, and the credentials travel only inside
 * the support ticket the owner is sending. Support logs in through the normal login form like
 * any other user. There is **no URL, token or endpoint that logs anyone in**, nothing here
 * answers a request from outside the site, and the user cannot outlive its expiry:
 *
 *   - deleted by WP-Cron (hourly) AND on every admin page load, so a site whose cron is broken
 *     still expires it;
 *   - refused at the login form the moment it has expired, even before it is deleted;
 *   - unable to create application passwords, so it cannot leave a way back in behind it;
 *   - unable to create, edit, promote or delete users, install or edit code, or export
 *     (the capability list below), so it cannot make itself permanent.
 *
 * Creating it requires an interactive admin session: a request authenticated with an
 * application password is refused, so this cannot be driven from outside the browser.
 */
final class Temp_Access {

	/** The dedicated role (contract §3). Shared by every plugin that carries the kit. */
	public const ROLE = 'zinn_support_temp';

	/** Capabilities the role never has, on top of whatever the administrator role lacks. */
	public const WITHHELD = array(
		'create_users',
		'edit_users',
		'delete_users',
		'promote_users',
		'list_users',
		'remove_users',
		'add_users',
		'install_plugins',
		'delete_plugins',
		'edit_plugins',
		'edit_themes',
		'edit_files',
		'install_themes',
		'delete_themes',
		'update_core',
		'export',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
	);

	/** The lifetimes a site owner may choose, in days, and the default (contract §3). */
	public const DAYS         = array( 1, 3, 7 );
	public const DEFAULT_DAYS = 3;

	/** User meta, shared by every copy of the kit so either plugin can expire either's users. */
	public const META_EXPIRES = 'zinn_support_expires_at';
	public const META_PRODUCT = 'zinn_support_product';
	public const META_BY      = 'zinn_support_created_by';
	public const META_CREATED = 'zinn_support_created_at';

	/** The cron hook. Per plugin: the renderer rewrites the prefix. */
	public const CRON = 'tranzly_kit_expire_support_access';

	/**
	 * Hook the expiry paths.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'sweep' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
		add_action( self::CRON, array( self::class, 'sweep' ) );
		add_action( 'init', array( self::class, 'expire_current_user' ), 1 );
		add_filter( 'authenticate', array( self::class, 'refuse_expired_login' ), 99 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( self::class, 'no_application_passwords' ), 10, 2 );

		$file = Kit::host( 'file' );
		if ( '' !== $file ) {
			register_deactivation_hook( $file, array( self::class, 'unschedule' ) );
		}
	}

	/**
	 * Keep an hourly sweep scheduled.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	/**
	 * Remove the sweep's schedule (plugin deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Create a temporary support user.
	 *
	 * @param int $days Lifetime: 1, 3 or 7.
	 * @return array{user_id: int, username: string, password: string, url: string, expires_at: string}|\WP_Error
	 */
	public static function create( int $days ) {
		if ( ! in_array( $days, self::DAYS, true ) ) {
			return new \WP_Error( 'zinn_kit_bad_days', __( 'Choose 1, 3 or 7 days.', 'tranzly' ), array( 'status' => 400 ) );
		}
		if ( ! self::may_grant() ) {
			return new \WP_Error( 'zinn_kit_forbidden', __( 'Only a site administrator, signed in to this screen, can create temporary support access.', 'tranzly' ), array( 'status' => 403 ) );
		}
		self::ensure_role();

		$username = '';
		for ( $attempt = 0; $attempt < 5 && '' === $username; $attempt++ ) {
			$candidate = 'zinn-support-' . bin2hex( random_bytes( 3 ) );
			if ( ! username_exists( $candidate ) ) {
				$username = $candidate;
			}
		}
		if ( '' === $username ) {
			return new \WP_Error( 'zinn_kit_no_name', __( 'A unique user name could not be chosen. Try again.', 'tranzly' ), array( 'status' => 500 ) );
		}

		$password = wp_generate_password( 24, true, false );
		$expires  = time() + $days * DAY_IN_SECONDS;
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_pass'    => $password,
				'role'         => self::ROLE,
				'display_name' => __( 'Zinn Digital® support (temporary)', 'tranzly' ),
				'user_email'   => '',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		update_user_meta( $user_id, self::META_EXPIRES, $expires );
		update_user_meta( $user_id, self::META_PRODUCT, Kit::host( 'slug' ) );
		update_user_meta( $user_id, self::META_BY, get_current_user_id() );
		update_user_meta( $user_id, self::META_CREATED, time() );

		return array(
			'user_id'    => (int) $user_id,
			'username'   => $username,
			'password'   => $password,
			'url'        => wp_login_url(),
			'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $expires ),
		);
	}

	/**
	 * May the current request create temporary access?
	 *
	 * @return bool
	 */
	public static function may_grant(): bool {
		return current_user_can( 'create_users' )
			&& ! self::is_temp_user( get_current_user_id() )
			&& ! did_action( 'application_password_did_authenticate' );
	}

	/**
	 * Every temporary support user on this site.
	 *
	 * @return array<int, array{id: int, username: string, product: string, createdAt: int, expiresAt: int, expired: bool, createdBy: string}>
	 */
	public static function all(): array {
		$users = get_users(
			array(
				'meta_key' => self::META_EXPIRES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- usermeta is indexed on meta_key, and this lists a handful of users.
				'fields'   => 'all',
				'blog_id'  => 0,
				'orderby'  => 'ID',
				'order'    => 'DESC',
				'number'   => 200,
			)
		);
		$out   = array();
		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$expires = (int) get_user_meta( $user->ID, self::META_EXPIRES, true );
			$by      = get_userdata( (int) get_user_meta( $user->ID, self::META_BY, true ) );
			$out[]   = array(
				'id'        => (int) $user->ID,
				'username'  => (string) $user->user_login,
				'product'   => (string) get_user_meta( $user->ID, self::META_PRODUCT, true ),
				'createdAt' => (int) get_user_meta( $user->ID, self::META_CREATED, true ),
				'expiresAt' => $expires,
				'expired'   => $expires <= time(),
				'createdBy' => $by instanceof \WP_User ? (string) $by->display_name : '',
			);
		}

		return $out;
	}

	/**
	 * Delete one temporary support user now ("Revoke now").
	 *
	 * @param int $user_id User id.
	 * @return bool True when a temporary user was deleted; false for any other user.
	 */
	public static function revoke( int $user_id ): bool {
		if ( ! self::is_temp_user( $user_id ) ) {
			return false; // Never delete a user this feature did not create.
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';

		// Anything the support user wrote stays, owned by whoever granted the access.
		$owner    = (int) get_user_meta( $user_id, self::META_BY, true );
		$reassign = $owner > 0 && $owner !== $user_id && get_userdata( $owner ) ? $owner : null;

		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			if ( null !== $reassign ) {
				wp_delete_user( $user_id, $reassign ); // Moves this site's content first.
			}

			return wpmu_delete_user( $user_id );
		}

		return wp_delete_user( $user_id, $reassign );
	}

	/**
	 * Delete every temporary support user whose time is up.
	 *
	 * @return int How many were deleted.
	 */
	public static function sweep(): int {
		$expired = get_users(
			array(
				'meta_key'     => self::META_EXPIRES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed on meta_key; returns only expired support users.
				'meta_value'   => time(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
				'meta_compare' => '<=',
				'meta_type'    => 'NUMERIC',
				'fields'       => 'ID',
				'blog_id'      => 0,
				'number'       => 100,
			)
		);
		$deleted = 0;
		foreach ( $expired as $user_id ) {
			if ( self::revoke( (int) $user_id ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * A signed-in temporary user whose time is up is deleted on their next request, admin or not.
	 *
	 * @return void
	 */
	public static function expire_current_user(): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 && self::is_temp_user( $user_id ) && self::is_expired( $user_id ) ) {
			self::revoke( $user_id );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * `authenticate`: an expired temporary user cannot sign in, even before the sweep has run.
	 *
	 * @param mixed $user The result so far (a WP_User, a WP_Error or null).
	 * @return mixed
	 */
	public static function refuse_expired_login( $user ) {
		if ( $user instanceof \WP_User && self::is_temp_user( $user->ID ) && self::is_expired( $user->ID ) ) {
			return new \WP_Error( 'zinn_kit_access_expired', __( 'This temporary support access has expired.', 'tranzly' ) );
		}

		return $user;
	}

	/**
	 * A temporary support user may not create application passwords.
	 *
	 * @param bool     $available Whether WordPress would allow them.
	 * @param \WP_User $user      The user.
	 * @return bool
	 */
	public static function no_application_passwords( $available, $user ): bool {
		return (bool) $available && ! ( $user instanceof \WP_User && self::is_temp_user( $user->ID ) );
	}

	/**
	 * Was this user created by this feature?
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_temp_user( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) get_user_meta( $user_id, self::META_EXPIRES, true );
	}

	/**
	 * Has this user's time run out?
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_expired( int $user_id ): bool {
		return (int) get_user_meta( $user_id, self::META_EXPIRES, true ) <= time();
	}

	/**
	 * The role's capabilities: the administrator's, minus the withheld list.
	 *
	 * @param array<string, bool> $admin_caps The administrator role's capabilities.
	 * @return array<string, bool>
	 */
	public static function capabilities( array $admin_caps ): array {
		return array_diff_key( array_filter( $admin_caps ), array_flip( self::WITHHELD ) );
	}

	/**
	 * Create or refresh the role from the administrator role as it is on this site now.
	 *
	 * @return void
	 */
	public static function ensure_role(): void {
		$admin = get_role( 'administrator' );
		$caps  = self::capabilities( null === $admin ? array( 'read' => true ) : (array) $admin->capabilities );
		$role  = get_role( self::ROLE );
		if ( null !== $role && $role->capabilities === $caps ) {
			return;
		}
		remove_role( self::ROLE );
		add_role( self::ROLE, __( 'Zinn Digital® support (temporary)', 'tranzly' ), $caps );
	}
}
