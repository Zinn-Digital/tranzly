<?php
/**
 * The "Save and test" check.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-tester.php by wp/bin/build-ai-core.php.
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
 * Proves a provider works end to end: the key reads the model list AND one tiny generation
 * succeeds.
 *
 * ⭐ Both halves, because they fail differently. Reading the model list is free and proves the
 * key is valid, but a key with no credit reads its model list perfectly well — the billing
 * problem only appears on a generation. A test that stopped at the list would tell a customer
 * "connected" and let their first real use fail.
 */
final class Tester {

	/**
	 * Run the test.
	 *
	 * @param string $provider Provider id.
	 * @return array{ok: bool, message: string, failure: Failure|null, model: string}
	 */
	public static function run( string $provider ): array {
		$label   = (string) ( Registry::get( $provider )['label'] ?? $provider );
		$adapter = Registry::adapter( $provider, Store::provider( $provider ) );
		if ( null === $adapter || ! Store::configured( $provider ) ) {
			return self::failed( Failure::refused( __( 'Save a key for this provider first.', 'tranzly' ) ) );
		}

		$models = Models::discovered( $provider, true );
		if ( $models instanceof Failure ) {
			return self::failed( $models );
		}

		$model = self::pick( $provider, $models );
		if ( '' === $model ) {
			return self::failed( Failure::refused( __( 'Your key works, but the provider lists no text model for it. Add a model yourself below.', 'tranzly' ) ) );
		}

		$result = $adapter->generate(
			(string) Store::key( $provider ),
			$model,
			array(
				array(
					'role'    => 'user',
					'content' => 'Reply with the single word OK.',
				),
			),
			array( 'max_tokens' => 512 )
		);
		if ( ! $result->ok() && null !== $result->failure ) {
			return self::failed( $result->failure, $model );
		}

		self::adopt( $provider );

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: AI provider name, 2: model name. */
				__( 'Connected. %1$s answered using %2$s. Your AI features are ready to use.', 'tranzly' ),
				$label,
				$model
			),
			'failure' => null,
			'model'   => $model,
		);
	}

	/**
	 * The model to test with: the site's own choice, then the cheapest recommendation the key
	 * can see, then anything the key can see.
	 *
	 * @param string                           $provider Provider id.
	 * @param array<int, array<string, mixed>> $models   Live list.
	 * @return string
	 */
	private static function pick( string $provider, array $models ): string {
		$live = array_map( static fn( array $row ): string => (string) $row['id'], $models );

		$current = Store::default_for( 'general' );
		if ( $current['provider'] === $provider && '' !== $current['model'] ) {
			return $current['model'];
		}
		$recommended = Catalogue::recommended( $provider, 'general' );
		foreach ( array( 'cheap', 'fast', 'best' ) as $tier ) {
			$id = $recommended[ $tier ] ?? '';
			// A recommendation the key cannot see would fail as "model unavailable" and mislead;
			// an empty live list (a server that hides it) trusts the recommendation.
			if ( '' !== $id && ( array() === $live || in_array( $id, $live, true ) ) ) {
				return $id;
			}
		}
		foreach ( Store::added_models( $provider ) as $row ) {
			return $row['id'];
		}

		return $live[0] ?? '';
	}

	/**
	 * When this is the site's first working provider, make it the default for every task,
	 * using the catalogue's best model for each.
	 *
	 * @param string $provider Provider id.
	 * @return void
	 */
	private static function adopt( string $provider ): void {
		$current = Store::default_for( 'general' );
		if ( '' !== $current['provider'] && Store::configured( $current['provider'] ) ) {
			return;
		}
		$defaults = array();
		foreach ( Store::TASKS as $task ) {
			$defaults[ $task ] = array(
				'provider' => $provider,
				'model'    => (string) ( Catalogue::recommended( $provider, $task )['best'] ?? '' ),
			);
		}
		Store::update(
			static function ( array $record ) use ( $defaults ): array {
				$record['defaults'] = $defaults;
				return $record;
			}
		);
	}

	/**
	 * A failed outcome.
	 *
	 * @param Failure $failure Why.
	 * @param string  $model   The model tried.
	 * @return array{ok: bool, message: string, failure: Failure|null, model: string}
	 */
	private static function failed( Failure $failure, string $model = '' ): array {
		return array(
			'ok'      => false,
			'message' => $failure->message,
			'failure' => $failure,
			'model'   => $model,
		);
	}
}
