<?php
/**
 * Page Builder Sandwich, deep (tz-c5, free).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page Builder Sandwich pages are block content, so their words already go through the block
 * parser under the F6 contract (`role: content`, docs/adr/0033). This adds what the contract alone
 * does not reach:
 *
 * - the text inside array/object attributes (Nested_Blocks, which every block benefits from);
 * - `pbs/widget`: a WordPress widget placed in a page keeps its title and text in the `instance`
 *   object, which PBS does not (and should not) mark as content — Tranzly opts it in here;
 * - every post type Page Builder Sandwich registers for its own content (saved sections, theme
 *   templates, popups, forms: `pbsw_*`), translated like pages even though they are not public.
 */
final class Pbs extends Integration {

	/** PBS blocks whose object attributes hold visible text PBS does not mark. */
	private const OPT_IN = array(
		'pbs/widget' => array( 'instance' ),
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'pbs';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Page Builder Sandwich';
	}

	/**
	 * Present?
	 *
	 * @return bool
	 */
	public function available(): bool {
		return defined( 'PBSW_VERSION' );
	}

	/**
	 * Feature.
	 *
	 * @return string
	 */
	public function feature(): string {
		return 'tz-c5';
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'tranzly_translatable_attributes', array( self::class, 'opt_in' ), 10, 2 );
		add_filter( 'tranzly_post_types', array( self::class, 'post_types' ) );
	}

	/**
	 * `tranzly_translatable_attributes`.
	 *
	 * @param array<int, string> $names      Attribute names.
	 * @param string             $block_name The block.
	 * @return array<int, string>
	 */
	public static function opt_in( $names, $block_name = '' ) {
		$names = is_array( $names ) ? $names : array();

		return isset( self::OPT_IN[ $block_name ] ) ? array_values( array_unique( array_merge( $names, self::OPT_IN[ $block_name ] ) ) ) : $names;
	}

	/**
	 * `tranzly_post_types`: Page Builder Sandwich's own content types.
	 *
	 * @param array<int, string> $types Types.
	 * @return array<int, string>
	 */
	public static function post_types( $types ) {
		$types = is_array( $types ) ? $types : array();
		foreach ( get_post_types( array(), 'names' ) as $type ) {
			if ( ( str_starts_with( (string) $type, 'pbsw_' ) || str_starts_with( (string) $type, 'pbs_' ) ) && ! in_array( $type, self::internal(), true ) ) {
				$types[] = (string) $type;
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * PBS types that hold no visitor-facing text (review threads, logs).
	 *
	 * @return array<int, string>
	 */
	private static function internal(): array {
		/**
		 * Filters the Page Builder Sandwich post types Tranzly leaves alone.
		 *
		 * @param array<int, string> $types Post types.
		 */
		return (array) apply_filters( 'tranzly_pbs_internal_post_types', array( 'pbsw_review', 'pbsw_log', 'pbsw_submission', 'pbsw_entry', 'pbsw_form_entry' ) );
	}
}
