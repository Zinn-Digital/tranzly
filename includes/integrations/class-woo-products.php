<?php
/**
 * WooCommerce products, complete in every edition: a new translation of a product is a product
 * somebody can buy.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⚖️ Owner, 2026-09-30 (L07, WordPress.org closure item T-2): the free edition copies a product's
 * OWN settings onto a NEW translation — price, sale price and its dates, SKU, stock, tax, size and
 * weight, virtual/downloadable and its files, the gallery — so a translated product has its price
 * and can be added to the cart. Before 3.18 only the Pro WooCommerce module did, and a free
 * translation of a product was a product with no price: the shape WordPress.org closed the old
 * plugin for ("product translation is intentionally restricted").
 *
 * What stays with Pro (tz-c7): keeping price and stock in step AFTER the translation exists,
 * variations, attributes, the shop/cart/checkout/account pages and their texts, customer e-mails
 * and currencies. So this copies once, at creation, and never again.
 *
 * The product's type and visibility are taxonomy terms, which every new translation already gets
 * (Core\Content::create_post_translation copies every term of the original).
 */
final class Woo_Products extends Integration {

	/** A product's own settings, copied onto a new translation. */
	public const COPY_META = array(
		'_price',
		'_regular_price',
		'_sale_price',
		'_sale_price_dates_from',
		'_sale_price_dates_to',
		'_sku',
		'_global_unique_id',
		'_stock',
		'_stock_status',
		'_manage_stock',
		'_backorders',
		'_low_stock_amount',
		'_sold_individually',
		'_weight',
		'_length',
		'_width',
		'_height',
		'_tax_status',
		'_tax_class',
		'_virtual',
		'_downloadable',
		'_downloadable_files',
		'_download_limit',
		'_download_expiry',
		'_product_image_gallery',
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'woocommerce-products';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'WooCommerce products', 'tranzly' );
	}

	/**
	 * Present?
	 *
	 * @return bool
	 */
	public function available(): bool {
		return class_exists( '\WooCommerce' );
	}

	/**
	 * Feature: every public post type is translated in the free edition.
	 *
	 * @return string
	 */
	public function feature(): string {
		return 'tz-c1';
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'tranzly_post_translation_created', array( self::class, 'after_created' ), 25, 2 );
	}

	/**
	 * A new product translation gets the product's own settings.
	 *
	 * @param int $source_id The original.
	 * @return array<int, string>
	 */
	public function copy_meta( int $source_id ): array {
		return 'product' === get_post_type( $source_id ) ? self::COPY_META : array();
	}

	/**
	 * `tranzly_post_translation_created`: let WooCommerce read the new product once, so its lookup
	 * table (where it answers "what price?" and "in stock?") has the copied values.
	 *
	 * @param int $target    The translation.
	 * @param int $source_id The original.
	 * @return void
	 */
	public static function after_created( $target, $source_id ): void {
		if ( 'product' !== get_post_type( (int) $source_id ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		// The Pro module keeps products in step and re-saves them itself; one writer at a time.
		if ( isset( Integrations::live()['woocommerce'] ) ) {
			return;
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( (int) $target );
		}
		$product = wc_get_product( (int) $target );
		if ( $product ) {
			$product->save();
		}
	}
}
