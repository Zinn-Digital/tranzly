<?php
/**
 * The upsell and cross-sell registry.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-promotions.php by wp/bin/build-admin-kit.php.
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
 * Turns `data/promotions.json` (plus the `zinn_admin_kit_promotions` filter) into the cards the
 * shell draws on our own screens (features adm-4, adm-5, sh-r7).
 *
 * ⛔ No remote call, no tracking parameter, no image fetched from us: every card is static copy
 * shipped in the plugin and linked to a bare URL (`scripts/wp-promo-check.py` asserts all three
 * on the rendered copy). ⛔ Nothing here is ever drawn outside the host's own admin page.
 *
 * ⭐ The copy is chosen by `kind`, so a new product is a JSON row: `kind: plugin` with its
 * main files renders "<Name>, from Zinn Digital®" plus its install state, with no new string.
 */
final class Promotions {

	/** The kinds of card the copy table knows. */
	public const KINDS = array( 'hosting', 'marketplace', 'platform', 'plugin', 'bundle' );

	/**
	 * Every card, resolved for the current site and host, including dismissed ones (the shell
	 * hides what the user dismissed; the Add-ons screen can bring a card back).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$rows = (array) ( Data::get( 'promotions' )['promotions'] ?? array() );

		/**
		 * Filters the promotion registry before it is resolved. Add a row to promote another
		 * product: `id`, `kind` (hosting|marketplace|platform|plugin|bundle), `slot`
		 * (promo|cross-sell), and `url` or (for a plugin) `name`, `site`, `main_files`.
		 *
		 * @param array<int, array<string, mixed>> $rows Registry rows.
		 */
		$rows = (array) apply_filters( 'tranzly_kit_promotions', $rows );
		$out  = array();
		foreach ( $rows as $row ) {
			$card = is_array( $row ) ? self::resolve( $row ) : null;
			if ( null !== $card ) {
				$out[] = $card;
			}
		}

		return $out;
	}

	/**
	 * One registry row, resolved to a card, or null when it does not apply here.
	 *
	 * @param array<string, mixed> $row Registry row.
	 * @return array<string, mixed>|null
	 */
	public static function resolve( array $row ): ?array {
		$id   = sanitize_key( (string) ( $row['id'] ?? '' ) );
		$kind = (string) ( $row['kind'] ?? '' );
		if ( '' === $id || ! in_array( $kind, self::KINDS, true ) ) {
			return null;
		}
		$card = array(
			'id'    => $id,
			'kind'  => $kind,
			'slot'  => 'promo' === ( $row['slot'] ?? '' ) ? 'promo' : 'cross-sell',
			'state' => '',
			'url'   => self::safe_url( (string) ( $row['url'] ?? '' ) ),
		);

		if ( 'plugin' === $kind ) {
			return self::plugin_card( $card, $row );
		}
		if ( 'bundle' === $kind ) {
			$offer = self::bundle_offer();
			if ( null === $offer || 'free' !== Licence::tier() ) {
				return null; // No bundle configured, or this site already pays: nothing to offer.
			}
			// The bundle's section of this plugin's own pricing page: the three plans, what the two
			// plugins cost separately and the saving, then Freemius's checkout (owner, 2026-10-07).
			$card['url'] = $offer['url'];
		}

		$copy = self::copy( $kind, (string) ( $row['brand'] ?? '' ), '' );
		if ( '' === $card['url'] || null === $copy ) {
			return null;
		}

		return array_merge( $card, $copy );
	}

	/**
	 * A card for another plugin: its install state, and "integration active" when it runs.
	 *
	 * @param array<string, mixed> $card Card so far.
	 * @param array<string, mixed> $row  Registry row.
	 * @return array<string, mixed>|null Null for the host itself.
	 */
	private static function plugin_card( array $card, array $row ): ?array {
		$slug    = sanitize_key( (string) ( $row['product'] ?? $card['id'] ) );
		$product = array_merge( Data::product( $slug ), array_intersect_key( $row, array_flip( array( 'name', 'site', 'main_files' ) ) ) );
		if ( Kit::host( 'slug' ) === $slug || empty( $product['name'] ) ) {
			return null;
		}
		$files = array_map( 'strval', (array) ( $product['main_files'] ?? array() ) );

		$card['state'] = 'available';
		foreach ( $files as $file ) {
			if ( self::is_active( $file ) ) {
				$card['state'] = 'active';
				break;
			}
			if ( self::is_installed( $file ) ) {
				$card['state'] = 'installed';
			}
		}
		$card['url']     = self::safe_url( (string) ( $product['site'] ?? '' ) );
		$card['product'] = $slug;
		if ( 'installed' === $card['state'] ) {
			$card['actionUrl'] = admin_url( 'plugins.php' );
		} elseif ( 'available' === $card['state'] ) {
			$card['actionUrl'] = admin_url( 'plugin-install.php?s=' . rawurlencode( (string) ( $product['install_search'] ?? $product['name'] ) ) . '&tab=search&type=term' );
		}

		$copy = self::copy( 'plugin', (string) $product['name'], $slug );

		return null === $copy || '' === $card['url'] ? null : array_merge( $card, $copy );
	}

	/**
	 * The Page Builder Sandwich Pro + Tranzly Pro bundle as this plugin offers it: the largest saving
	 * (products.json `bundle.saving_percent`, held equal to wp/freemius-plans.json by
	 * wp/tests/unit/AdminKitBundleOfferTest.php) and the bundle section of the host's own pricing page.
	 *
	 * @return array{percent: int, url: string}|null Null when no bundle is configured.
	 */
	public static function bundle_offer(): ?array {
		$bundle  = (array) ( Data::get( 'products' )['bundle'] ?? array() );
		$percent = (int) ( $bundle['saving_percent'] ?? 0 );
		$site    = (string) ( Data::product( (string) Kit::host( 'slug' ) )['site'] ?? '' );
		$url     = self::safe_url( '' === $site ? '' : rtrim( $site, '/' ) . '/' . ltrim( (string) ( $bundle['pricing_path'] ?? '' ), '/' ) );
		if ( '' === (string) preg_replace( '/\D/', '', (string) ( $bundle['id'] ?? '' ) ) || $percent <= 0 || '' === $url ) {
			return null;
		}
		return array(
			'percent' => $percent,
			'url'     => $url,
		);
	}

	/**
	 * The translated copy for a card.
	 *
	 * @param string $kind  Card kind.
	 * @param string $brand The brand or product name (not translated: it is a name).
	 * @param string $slug  For a plugin card, the product slug (picks a specific tagline).
	 * @return array{brand: string, title: string, body: string, cta: string, activeTitle: string, activeBody: string}|null
	 */
	public static function copy( string $kind, string $brand, string $slug ): ?array {
		$host = Kit::host( 'name' );
		switch ( $kind ) {
			case 'hosting':
				return array(
					'brand'       => $brand,
					'title'       => __( 'Hosting built for fast, multilingual WordPress', 'tranzly' ),
					'body'        => __( 'Managed WordPress hosting from the team that makes this plugin, tuned for speed and for sites in many languages.', 'tranzly' ),
					'cta'         => __( 'See Zinn Digital® hosting', 'tranzly' ),
					'activeTitle' => '',
					'activeBody'  => '',
				);
			case 'marketplace':
				return array(
					'brand'       => $brand,
					'title'       => __( 'Hire a WordPress expert on ZinnHub', 'tranzly' ),
					'body'        => __( 'A freelance marketplace for WordPress design, development, translation and SEO work.', 'tranzly' ),
					'cta'         => __( 'Visit ZinnHub', 'tranzly' ),
					'activeTitle' => '',
					'activeBody'  => '',
				);
			case 'platform':
				return array(
					'brand'       => $brand,
					'title'       => __( 'Bulk site and PBN hosting', 'tranzly' ),
					'body'        => __( 'PBN.ltd is our platform for hosting and managing many sites at once.', 'tranzly' ),
					'cta'         => __( 'Visit PBN.ltd', 'tranzly' ),
					'activeTitle' => '',
					'activeBody'  => '',
				);
			case 'bundle':
				$offer = self::bundle_offer();
				return array(
					'brand'       => $brand,
					'title'       => __( 'Get Page Builder Sandwich Pro and Tranzly Pro together', 'tranzly' ),
					'body'        => null === $offer
						? __( 'The bundle costs less than buying both plugins separately, on the same plans and site limits.', 'tranzly' )
						/* translators: %d: the largest saving, in whole percent. */
						: sprintf( __( 'Both Pro plugins on the same plan and site limit, for up to %d%% less than buying them separately.', 'tranzly' ), $offer['percent'] ),
					'cta'         => __( 'See the bundle', 'tranzly' ),
					'activeTitle' => '',
					'activeBody'  => '',
				);
			case 'plugin':
				return array(
					'brand'       => $brand,
					'title'       => self::tagline( $slug, $brand ),
					'body'        => self::pitch( $slug, $brand ),
					/* translators: %s: a plugin's name. */
					'cta'         => sprintf( __( 'Get %s', 'tranzly' ), $brand ),
					/* translators: 1: this plugin's name, 2: the other plugin's name. */
					'activeTitle' => sprintf( __( 'Integration active: %1$s and %2$s work together', 'tranzly' ), $host, $brand ),
					/* translators: %s: the other plugin's name. */
					'activeBody'  => sprintf( __( '%s is active on this site, so both plugins share one set of AI provider keys and one settings screen for them.', 'tranzly' ), $brand ),
				);
		}

		return null;
	}

	/**
	 * A plugin card's headline.
	 *
	 * @param string $slug  Product slug.
	 * @param string $brand Product name.
	 * @return string
	 */
	private static function tagline( string $slug, string $brand ): string {
		if ( 'tranzly' === $slug ) {
			return __( 'Translate this site with Tranzly', 'tranzly' );
		}
		if ( 'page-builder-sandwich' === $slug ) {
			return __( 'Build pages visually with Page Builder Sandwich', 'tranzly' );
		}

		/* translators: %s: a plugin's name. */
		return sprintf( __( '%s, from Zinn Digital®', 'tranzly' ), $brand );
	}

	/**
	 * A plugin card's body.
	 *
	 * @param string $slug  Product slug.
	 * @param string $brand Product name.
	 * @return string
	 */
	private static function pitch( string $slug, string $brand ): string {
		if ( 'tranzly' === $slug ) {
			return __( 'Publish every page in many languages, with translated addresses, SEO data and a language switcher. Works with the pages you build here.', 'tranzly' );
		}
		if ( 'page-builder-sandwich' === $slug ) {
			return __( 'Design pages right in the editor with fast, footprint-free blocks. Everything you build translates with Tranzly.', 'tranzly' );
		}

		/* translators: %s: a plugin's name. */
		return sprintf( __( '%s is made by the same team and works alongside this plugin.', 'tranzly' ), $brand );
	}

	/**
	 * Only a bare https URL is allowed on a card: no query string, no fragment (no tracking).
	 *
	 * @param string $url Candidate.
	 * @return string Empty when refused.
	 */
	public static function safe_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * Is a plugin active on this site (or network)?
	 *
	 * @param string $file Plugin basename.
	 * @return bool
	 */
	private static function is_active( string $file ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $file );
	}

	/**
	 * Is a plugin installed (present, active or not)?
	 *
	 * @param string $file Plugin basename.
	 * @return bool
	 */
	private static function is_installed( string $file ): bool {
		return '' !== $file && file_exists( WP_PLUGIN_DIR . '/' . $file );
	}
}
