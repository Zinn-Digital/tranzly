<?php
/**
 * WordPress's own sitemap: one page listing each language's home page.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded only once WordPress's sitemap classes exist (Sitemaps::register_core_provider()).
 */
final class Sitemap_Provider extends \WP_Sitemaps_Provider {

	/**
	 * Set the provider's name.
	 */
	public function __construct() {
		$this->name        = 'languages';
		$this->object_type = 'languages';
	}

	/**
	 * The URLs on one page.
	 *
	 * @param int    $page_num       Page number.
	 * @param string $object_subtype Unused.
	 * @return array<int, array{loc: string}>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		if ( 1 !== (int) $page_num ) {
			return array();
		}

		return array_map( static fn( string $url ): array => array( 'loc' => $url ), Sitemaps::extra_homes() );
	}

	/**
	 * One page is always enough: one URL per language.
	 *
	 * @param string $object_subtype Unused.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return array() === Sitemaps::extra_homes() ? 0 : 1;
	}
}
