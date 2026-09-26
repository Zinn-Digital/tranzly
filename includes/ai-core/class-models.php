<?php
/**
 * The model list a site owner picks from.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-models.php by wp/bin/build-ai-core.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\Tranzly\AiCore
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\AiCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model discovery (ai-2), the catalogue's recommendations (ai-3) and hand-added models (ai-4),
 * merged into one list per provider.
 *
 * ⭐ The live list is cached for twelve hours per provider and key, so opening the settings
 * screen does not cost a provider call each time; "Refresh" clears it. A model released today
 * appears on the next refresh with no plugin update.
 */
final class Models {

	/** Cache lifetime of a discovered list. */
	private const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * The transient that caches one provider's list.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function transient( string $provider ): string {
		return 'zinn_ai_models_' . sanitize_key( $provider );
	}

	/**
	 * The live list for a provider, cached.
	 *
	 * @param string $provider Provider id.
	 * @param bool   $fresh    Skip the cache.
	 * @return array<int, array<string, mixed>>|Failure
	 */
	public static function discovered( string $provider, bool $fresh = false ) {
		$settings = Store::provider( $provider );
		$stamp    = md5( (string) ( $settings['key'] ?? '' ) . '|' . (string) ( $settings['base_url'] ?? '' ) );
		$cached   = $fresh ? false : get_transient( self::transient( $provider ) );
		if ( is_array( $cached ) && ( $cached['stamp'] ?? '' ) === $stamp && is_array( $cached['models'] ?? null ) ) {
			return $cached['models'];
		}

		$adapter = Registry::adapter( $provider, $settings );
		if ( null === $adapter || ! Store::configured( $provider ) ) {
			return array();
		}
		$models = $adapter->list_models( (string) Store::key( $provider ) );
		if ( $models instanceof Failure ) {
			return $models;
		}
		set_transient(
			self::transient( $provider ),
			array(
				'stamp'  => $stamp,
				'models' => $models,
			),
			self::TTL
		);

		return $models;
	}

	/**
	 * Everything a site owner can pick for a provider, recommended first.
	 *
	 * @param string $provider Provider id.
	 * @return array<int, array{id: string, label: string, source: string}> `source` is `catalogue`, `live` or `added`.
	 */
	public static function choices( string $provider ): array {
		$out  = array();
		$seen = array();
		foreach ( Catalogue::models( $provider ) as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' !== $id && ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$out[]       = array(
					'id'     => $id,
					'label'  => (string) ( $row['label'] ?? $id ),
					'source' => 'catalogue',
				);
			}
		}
		foreach ( Store::added_models( $provider ) as $row ) {
			if ( ! isset( $seen[ $row['id'] ] ) ) {
				$seen[ $row['id'] ] = true;
				$out[]              = array(
					'id'     => $row['id'],
					'label'  => $row['label'],
					'source' => 'added',
				);
			}
		}
		$live = get_transient( self::transient( $provider ) );
		foreach ( is_array( $live ) && is_array( $live['models'] ?? null ) ? $live['models'] : array() as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' !== $id && ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$out[]       = array(
					'id'     => $id,
					'label'  => (string) ( $row['label'] ?? $id ),
					'source' => 'live',
				);
			}
		}

		return $out;
	}
}
