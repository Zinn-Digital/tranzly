<?php
/**
 * Front-end assets served from a neutral path.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes front-end CSS/JS out of the plugin directory into `uploads/<prefix>-assets/`.
 *
 * ⭐ WHY A COPY, AND WHY IT IS DONE IN wp-admin RATHER THAN ON A PAGE VIEW (docs/adr/0033).
 * A stylesheet enqueued from the plugin directory prints `/wp-content/plugins/tranzly/`
 * into every page, which is the single loudest footprint a plugin leaves (CONTRACT §7). The
 * alternatives were a rewrite rule (needs a flush, breaks on nginx without extra config, and
 * serves every asset through PHP) and a REST/query endpoint (PHP on every asset request, no
 * static caching). A content-hashed static copy is served by the web server directly, is
 * cacheable for ever because its name changes when its content does, and needs no server
 * configuration.
 *
 * The copy is written on activation, on a version change and on a prefix change — all of them
 * admin-side events — so a front-end request never writes to disk. When a copy is missing or
 * the filesystem is not directly writable, the asset is printed inline instead: the page is
 * never unstyled and never falls back to the plugin path.
 */
final class Assets {

	/** Option holding what was published, for which version and prefix. */
	public const MANIFEST_OPTION = 'tranzly_published_assets';

	/** Front-end sources, keyed by the name callers use. */
	private const SOURCES = array(
		'front.css' => 'assets/front.css',
	);

	/** The token a source uses where the class prefix belongs. */
	private const PREFIX_TOKEN = '__PREFIX__';

	/**
	 * Hook the republish checks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_publish' ) );
	}

	/**
	 * Republish when the manifest was written for another version or prefix.
	 *
	 * @return void
	 */
	public static function maybe_publish(): void {
		if ( ! self::is_current( self::manifest() ) ) {
			self::publish();
		}
	}

	/**
	 * Copy every source into the neutral folder and record what was written.
	 *
	 * @return array<string, mixed> The new manifest.
	 */
	public static function publish(): array {
		$prefix   = Settings::prefix();
		$manifest = array(
			'version' => TRANZLY_VERSION,
			'prefix'  => $prefix,
			'files'   => array(),
		);

		$filesystem = self::filesystem();
		$uploads    = wp_upload_dir( null, false );

		if ( null !== $filesystem && empty( $uploads['error'] ) ) {
			$folder = $prefix . '-assets';
			$dir    = trailingslashit( (string) $uploads['basedir'] ) . $folder;
			$url    = trailingslashit( (string) $uploads['baseurl'] ) . $folder;

			if ( wp_mkdir_p( $dir ) ) {
				foreach ( array_keys( self::SOURCES ) as $key ) {
					$body = self::source( $key, $prefix );
					if ( null === $body ) {
						continue;
					}
					$name = substr( sha1( $body ), 0, 12 ) . '.' . pathinfo( $key, PATHINFO_EXTENSION );
					$path = $dir . '/' . $name;
					if ( $filesystem->exists( $path ) || $filesystem->put_contents( $path, $body, FS_CHMOD_FILE ) ) {
						$manifest['files'][ $key ] = array(
							'url'  => $url . '/' . $name,
							'path' => $path,
						);
					}
				}
			}
		}

		self::remove_stale( self::manifest(), $manifest );
		update_option( self::MANIFEST_OPTION, $manifest, true );

		return $manifest;
	}

	/**
	 * Enqueue a front-end stylesheet under a neutral handle, from the published copy or inline.
	 *
	 * @param string $key A key of self::SOURCES.
	 * @return void
	 */
	public static function enqueue_style( string $key ): void {
		$prefix = Settings::prefix();
		$body   = self::source( $key, $prefix );
		if ( null === $body ) {
			return;
		}

		// The handle becomes the element id WordPress prints (`<handle>-css`), so it carries the
		// neutral prefix and a content hash, never the plugin's name.
		$hash   = substr( sha1( $body ), 0, 8 );
		$handle = $prefix . '-' . $hash;
		if ( wp_style_is( $handle, 'enqueued' ) ) {
			return;
		}

		$url = self::published_url( $key );
		if ( null !== $url ) {
			wp_enqueue_style( $handle, $url, array(), $hash );
			return;
		}

		wp_register_style( $handle, false, array(), $hash );
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, $body );
	}

	/**
	 * The published URL for a source, if a current copy exists.
	 *
	 * @param string $key A key of self::SOURCES.
	 * @return string|null
	 */
	public static function published_url( string $key ): ?string {
		$manifest = self::manifest();
		if ( ! self::is_current( $manifest ) ) {
			return null;
		}
		$file = $manifest['files'][ $key ] ?? null;

		return is_array( $file ) && is_string( $file['url'] ?? null ) ? $file['url'] : null;
	}

	/**
	 * Delete every file this plugin published. Used on uninstall.
	 *
	 * @return void
	 */
	public static function remove_all(): void {
		self::remove_stale( self::manifest(), array( 'files' => array() ) );
		delete_option( self::MANIFEST_OPTION );
	}

	/**
	 * A source's contents with the prefix token replaced.
	 *
	 * @param string $key    A key of self::SOURCES.
	 * @param string $prefix The class prefix.
	 * @return string|null Null when the key or file is unknown.
	 */
	public static function source( string $key, string $prefix ): ?string {
		if ( ! isset( self::SOURCES[ $key ] ) ) {
			return null;
		}
		$path = TRANZLY_DIR . self::SOURCES[ $key ];
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$body = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside this plugin, never a URL.

		return false === $body ? null : str_replace( self::PREFIX_TOKEN, $prefix, $body );
	}

	/**
	 * The stored manifest.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(): array {
		$manifest = get_option( self::MANIFEST_OPTION, array() );

		return is_array( $manifest ) ? $manifest : array();
	}

	/**
	 * Was this manifest written for the running version and prefix?
	 *
	 * @param array<string, mixed> $manifest A manifest.
	 * @return bool
	 */
	private static function is_current( array $manifest ): bool {
		return TRANZLY_VERSION === ( $manifest['version'] ?? null )
			&& Settings::prefix() === ( $manifest['prefix'] ?? null );
	}

	/**
	 * Delete files the old manifest names and the new one does not.
	 *
	 * @param array<string, mixed> $old The previous manifest.
	 * @param array<string, mixed> $next The new manifest.
	 * @return void
	 */
	private static function remove_stale( array $old, array $next ): void {
		$keep = array();
		foreach ( (array) ( $next['files'] ?? array() ) as $file ) {
			$keep[] = (string) ( $file['path'] ?? '' );
		}
		foreach ( (array) ( $old['files'] ?? array() ) as $file ) {
			$path = (string) ( $file['path'] ?? '' );
			if ( '' !== $path && ! in_array( $path, $keep, true ) && self::is_ours( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Is this a file this class could have written? A path read back from an option is data, so
	 * nothing is deleted unless it is a hashed asset inside an `-assets` folder under uploads.
	 *
	 * @param string $path A path from a manifest.
	 * @return bool
	 */
	private static function is_ours( string $path ): bool {
		$uploads = wp_upload_dir( null, false );
		$base    = realpath( (string) ( $uploads['basedir'] ?? '' ) );
		$real    = realpath( $path );
		if ( false === $base || false === $real || ! is_file( $real ) ) {
			return false;
		}

		return str_starts_with( $real, trailingslashit( $base ) )
			&& 1 === preg_match( '#/[a-z][a-z0-9]{0,7}-assets/[0-9a-f]{12}\.(?:css|js)$#', $real );
	}

	/**
	 * A direct-access filesystem, or null when writing would need credentials.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function filesystem(): ?\WP_Filesystem_Base {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			return null;
		}
		global $wp_filesystem;

		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}
}
