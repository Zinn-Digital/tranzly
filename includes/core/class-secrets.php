<?php
/**
 * Encrypted storage for the site owner's translation-service keys.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keys are encrypted at rest with libsodium's secretbox (XSalsa20-Poly1305), keyed from the
 * site's own secret salts, and are only ever decrypted to call the service they belong to.
 *
 * ⛔ The legacy plugin kept the DeepL key as plain text inside `tranzly_options`, readable by any
 * code, any backup and any staging copy of the database (11-audit-tranzly.md §5 finding 5).
 *
 * ⭐ Sodium is always available: PHP ships it, and WordPress bundles sodium_compat for the hosts
 * where the extension is missing. A value encrypted on one site cannot be read on a site with
 * different salts — a copied database carries ciphertext, not keys — and decrypt() reports that
 * as "please enter the key again" rather than failing.
 */
final class Secrets {

	/** The option holding every encrypted key: name => ciphertext. */
	public const OPTION = 'tranzly_secrets';

	/** Ciphertext marker, so a stored value is never mistaken for a plain key. */
	private const PREFIX = 'tz1:';

	/**
	 * Store a key, encrypted. An empty value removes it.
	 *
	 * @param string $name  e.g. `deepl`.
	 * @param string $value The plain key.
	 * @return void
	 */
	public static function put( string $name, string $value ): void {
		$all = self::stored();
		if ( '' === trim( $value ) ) {
			unset( $all[ $name ] );
		} else {
			$all[ $name ] = self::encrypt( trim( $value ) );
		}
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Read a key back, or null when absent or unreadable here (different salts).
	 *
	 * @param string $name e.g. `deepl`.
	 * @return string|null
	 */
	public static function get( string $name ): ?string {
		$all = self::stored();

		return isset( $all[ $name ] ) ? self::decrypt( (string) $all[ $name ] ) : null;
	}

	/**
	 * Is a key stored (readable or not)?
	 *
	 * @param string $name e.g. `deepl`.
	 * @return bool
	 */
	public static function has( string $name ): bool {
		return isset( self::stored()[ $name ] );
	}

	/**
	 * A key shown to a person: the last four characters only.
	 *
	 * @param string $name e.g. `deepl`.
	 * @return string|null
	 */
	public static function masked( string $name ): ?string {
		$plain = self::get( $name );

		return null === $plain ? null : str_repeat( '•', 8 ) . substr( $plain, -4 );
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $plain The plain text.
	 * @return string `tz1:` + base64( nonce . ciphertext ).
	 */
	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text; not obfuscation.
	}

	/**
	 * Decrypt a string made by encrypt(), or null when it cannot be read with this site's key.
	 *
	 * @param string $stored The stored value.
	 * @return string|null
	 */
	public static function decrypt( string $stored ): ?string {
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encrypt().
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
		} catch ( \SodiumException $e ) {
			return null;
		}

		return false === $plain ? null : $plain;
	}

	/**
	 * Every stored ciphertext.
	 *
	 * @return array<string, string>
	 */
	private static function stored(): array {
		$all = get_option( self::OPTION, array() );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * The 32-byte key, derived from the site's secret salts (wp-config.php, or the database
	 * fallback WordPress keeps when wp-config.php defines none).
	 *
	 * @return string
	 */
	private static function key(): string {
		return sodium_crypto_generichash( 'tranzly-secrets-v1|' . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
