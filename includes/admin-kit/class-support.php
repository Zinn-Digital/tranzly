<?php
/**
 * Get help, report a bug, send feedback, request a feature.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-support.php by wp/bin/build-admin-kit.php.
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
 * Files a ticket in the one Zinn Digital support inbox (features sh-r6, sh-r3; docs/843 D17;
 * x1-x7-contract.md §2.4).
 *
 * Works from an e-mail address alone: a site that never connected sends the address with the
 * ticket, and the platform files it under a support contact, never a customer organisation (D33).
 * A connected site sends its connection token instead.
 *
 * ⛔ Nothing is sent before the site owner has agreed on the consent screen, which lists exactly
 * what goes where. The consent is recorded per site with who gave it and when.
 */
final class Support {

	/** Option recording the consent. Per plugin. */
	public const CONSENT = 'tranzly_kit_support_consent';

	/** Ticket kinds the API accepts. */
	public const KINDS = array( 'help', 'bug', 'feedback', 'feature_request' );

	/** Credential kinds the API accepts (temporary_access is added by this class only). */
	public const CREDENTIAL_KINDS = array( 'wp_admin', 'file_manager', 'cpanel', 'ftp', 'other' );

	/**
	 * Has someone on this site agreed to send support data to Zinn Digital?
	 *
	 * @return bool
	 */
	public static function has_consent(): bool {
		$row = get_option( self::CONSENT, array() );

		return is_array( $row ) && ! empty( $row['at'] );
	}

	/**
	 * Record the current user's agreement.
	 *
	 * @return void
	 */
	public static function give_consent(): void {
		update_option(
			self::CONSENT,
			array(
				'user' => get_current_user_id(),
				'at'   => time(),
			),
			false
		);
	}

	/**
	 * File a ticket.
	 *
	 * @param array<string, mixed> $input Fields from the screen: kind, email, name, subject,
	 *                                    message, diagnostics (bool), credentials (list),
	 *                                    access_days (0 for none).
	 * @return array{ok: bool, status: int, message: string, reference: string, priority: string, access: array<string, mixed>|null}
	 */
	public static function send( array $input ): array {
		if ( ! self::has_consent() ) {
			return self::fail( 403, __( 'Agree to sending support information first.', 'tranzly' ) );
		}
		$kind    = in_array( $input['kind'] ?? '', self::KINDS, true ) ? (string) $input['kind'] : 'help';
		$subject = trim( sanitize_text_field( (string) ( $input['subject'] ?? '' ) ) );
		$message = trim( sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ) );
		$email   = self::reply_address( (string) ( $input['email'] ?? '' ) );
		$token   = Connection::token();
		if ( '' === $subject || '' === $message ) {
			return self::fail( 400, __( 'Write a subject and a message.', 'tranzly' ) );
		}
		if ( '' === $token && ! is_email( $email ) ) {
			return self::fail( 400, __( 'Enter an e-mail address support can reply to.', 'tranzly' ) );
		}
		// 0 = no temporary access; anything else must be one of the lifetimes the screen offers.
		$days_raw = $input['access_days'] ?? 0;
		if ( is_string( $days_raw ) && ctype_digit( $days_raw ) ) {
			$days_raw = (int) $days_raw; // A client that sends "3" means 3.
		}
		if ( ! is_int( $days_raw ) || ( 0 !== $days_raw && ! in_array( $days_raw, Temp_Access::DAYS, true ) ) ) {
			return self::fail( 400, __( 'Choose 1, 3 or 7 days.', 'tranzly' ) );
		}

		$credentials = self::credentials( (array) ( $input['credentials'] ?? array() ) );
		$access      = null;
		$days        = $days_raw;
		$may_attach  = in_array( $kind, array( 'help', 'bug' ), true );
		if ( ! $may_attach ) {
			$credentials = array(); // Feedback and feature requests never carry access.
			$days        = 0;
		}
		if ( $days > 0 ) {
			$created = Temp_Access::create( $days );
			if ( is_wp_error( $created ) ) {
				$data = $created->get_error_data();

				return self::fail( is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400, $created->get_error_message() );
			}
			$access        = $created;
			$credentials[] = array(
				'kind'       => 'temporary_access',
				'url'        => $created['url'],
				'username'   => $created['username'],
				'secret'     => $created['password'],
				'notes'      => __( 'Temporary support access created by the site owner. It is deleted automatically when it expires.', 'tranzly' ),
				'expires_at' => $created['expires_at'],
			);
		}

		$licence = Licence::snapshot();
		$body    = array(
			'name'                => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
			'product'             => Kit::host( 'slug' ),
			'kind'                => $kind,
			'subject'             => mb_substr( $subject, 0, 200 ),
			'message'             => mb_substr( $message, 0, 20000 ),
			'locale'              => get_user_locale(),
			'consent_diagnostics' => ! empty( $input['diagnostics'] ),
			'licence'             => array(
				'plan'                => (string) $licence['plan'],
				'freemius_install_id' => (string) $licence['installId'],
				'freemius_license_id' => (string) $licence['licenceId'],
			),
			'website'             => '',
		);
		if ( '' === $token ) {
			$body['email'] = $email;
		}
		if ( $body['consent_diagnostics'] ) {
			$body['diagnostics'] = Diagnostics::collect();
		}
		if ( array() !== $credentials ) {
			$body['credentials'] = $credentials;
		}

		$result = Engine::call( 'POST', '/v1/plugin-support/tickets', $body, $token );
		if ( ! $result['ok'] && 401 === $result['status'] && '' !== $token ) {
			Connection::mark_revoked( 'connection_revoked' === $result['code'] ? 'revoked_by_zinn' : 'unknown_to_zinn' );
		}
		if ( ! $result['ok'] ) {
			if ( null !== $access ) {
				Temp_Access::revoke( (int) $access['user_id'] ); // Access that reached nobody must not linger.
			}

			// A 4xx is the site's own request and keeps its meaning (422, 429 …); anything from the
			// far side failing, or not answering, is a 502 from this site, never its own 500.
			$status = $result['status'] >= 400 && $result['status'] < 500 ? $result['status'] : 502;

			return self::fail( $status, $result['message'], $result['retry_after'] );
		}

		return array(
			'ok'        => true,
			'status'    => 201,
			'message'   => '',
			'reference' => sanitize_text_field( (string) ( $result['body']['reference'] ?? '' ) ),
			'priority'  => 'high' === ( $result['body']['priority'] ?? '' ) ? 'high' : 'normal',
			'access'    => null === $access ? null : array(
				'username'   => $access['username'],
				'expires_at' => $access['expires_at'],
			),
		);
	}

	/**
	 * The reply address exactly as typed, or '' when it is not one plain address.
	 *
	 * ⛔ sanitize_email() CLEANS an address rather than refusing it: "a@b.test\r\nBcc: c@d.test"
	 * came back as the single address "a@b.testBccc@d.test", which passed is_email() and was sent
	 * to support as the customer's reply address, so the answer went nowhere (D28904). An address
	 * that sanitize_email() had to change is not the address the person meant: refuse it.
	 *
	 * @param string $raw What the screen sent.
	 * @return string
	 */
	public static function reply_address( string $raw ): string {
		$typed = trim( $raw );
		$clean = sanitize_email( $typed );

		return ( '' !== $typed && $clean === $typed ) ? $clean : '';
	}

	/**
	 * The optional access details the owner typed, cleaned. Secrets are passed through as
	 * typed (they are the point) and are never logged or stored on this site.
	 *
	 * @param array<int, mixed> $rows Rows from the screen.
	 * @return array<int, array{kind: string, url: string, username: string, secret: string, notes: string}>
	 */
	public static function credentials( array $rows ): array {
		$out = array();
		foreach ( array_slice( $rows, 0, 5 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$kind   = in_array( $row['kind'] ?? '', self::CREDENTIAL_KINDS, true ) ? (string) $row['kind'] : 'other';
			$secret = (string) ( $row['secret'] ?? '' );
			$user   = sanitize_text_field( (string) ( $row['username'] ?? '' ) );
			// The engine refuses a login with no secret, and a truncated password is a wrong
			// one, so both are dropped here rather than sent to fail (engine limits: secret 2000,
			// notes 1000 — `docs/plugins-overhaul/x1-x7-contract.md` §4).
			if ( '' === $secret || mb_strlen( $secret ) > 2000 ) {
				continue;
			}
			$out[] = array(
				'kind'     => $kind,
				'url'      => esc_url_raw( (string) ( $row['url'] ?? '' ) ),
				'username' => mb_substr( $user, 0, 200 ),
				'secret'   => $secret,
				'notes'    => mb_substr( sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ), 0, 1000 ),
			);
		}

		return $out;
	}

	/**
	 * A failure result.
	 *
	 * @param int    $status      HTTP status.
	 * @param string $message     Our message.
	 * @param int    $retry_after Seconds, for a rate limit.
	 * @return array{ok: bool, status: int, message: string, reference: string, priority: string, access: null, retry_after: int}
	 */
	private static function fail( int $status, string $message, int $retry_after = 0 ): array {
		return array(
			'ok'          => false,
			'status'      => $status,
			'message'     => $message,
			'reference'   => '',
			'priority'    => 'normal',
			'access'      => null,
			'retry_after' => $retry_after,
		);
	}
}
