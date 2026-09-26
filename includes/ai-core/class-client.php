<?php
/**
 * What a plugin feature calls to use AI.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-client.php by wp/bin/build-ai-core.php.
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
 * The API a feature uses: ask for a task, get a Result.
 *
 * ```php
 * $result = \ZinnDigital\Tranzly\AiCore\Client::generate(
 *     array( array( 'role' => 'user', 'content' => 'Translate …' ) ),
 *     array( 'task' => 'translate', 'purpose' => 'translate-post', 'schema' => $schema )
 * );
 * if ( ! $result->ok() ) { show $result->failure->message and ->link }
 * ```
 *
 * The feature never chooses a provider or holds a key: it names a TASK, and the site owner's
 * default for that task decides (a caller may still pass `provider` and `model`).
 */
final class Client {

	/**
	 * Generate.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Conversation.
	 * @param array<string, mixed>                             $options `task`, `purpose` (for the usage log), `provider`, `model`, `schema`, `schema_name`, `max_tokens`, `temperature`.
	 * @return Result
	 */
	public static function generate( array $messages, array $options = array() ): Result {
		$task     = in_array( $options['task'] ?? '', Store::TASKS, true ) ? (string) $options['task'] : 'general';
		$choice   = Store::default_for( $task );
		$provider = (string) ( $options['provider'] ?? $choice['provider'] );
		$model    = (string) ( $options['model'] ?? ( $provider === $choice['provider'] ? $choice['model'] : '' ) );

		// Before the "not set up" test: a key that exists but cannot be opened IS set up, and the
		// site owner needs to hear why it stopped working, not that it was never there.
		$state = '' === $provider ? 'none' : Store::key_state( $provider );
		if ( 'newer' === $state || 'unreadable' === $state ) {
			$message = 'newer' === $state
				? __( 'Your AI key was saved by a newer version of one of your plugins. Update this plugin, or enter the key again in the AI settings.', 'tranzly' )
				: __( 'Your saved AI key can no longer be opened (the site\'s security keys changed). Enter it again in the AI settings.', 'tranzly' );
			return Result::failed( Failure::refused( $message, Core::settings_url() ), $provider );
		}

		if ( '' === $provider || ! Store::configured( $provider ) ) {
			return Result::failed( Failure::refused( __( 'AI is not set up yet. Add a key for an AI provider in the AI settings.', 'tranzly' ), Core::settings_url() ) );
		}
		if ( '' === $model ) {
			$model = (string) ( Catalogue::recommended( $provider, $task )['best'] ?? '' );
		}
		if ( '' === $model ) {
			return Result::failed( Failure::refused( __( 'No model is chosen for this task. Pick one in the AI settings.', 'tranzly' ), Core::settings_url() ), $provider );
		}

		$context = array(
			'task'     => $task,
			'purpose'  => (string) ( $options['purpose'] ?? $task ),
			'provider' => $provider,
			'model'    => $model,
			'user'     => get_current_user_id(),
		);
		$refusal = Core::policy()->check( $context );
		if ( null !== $refusal ) {
			Core::policy()->record( $context, Result::failed( $refusal, $provider, $model ) );
			return Result::failed( $refusal, $provider, $model );
		}

		$adapter = Registry::adapter( $provider, Store::provider( $provider ) );
		if ( null === $adapter ) {
			return Result::failed( Failure::refused( __( 'That AI provider is not supported by this version of the plugin.', 'tranzly' ) ), $provider, $model );
		}

		$generate = array();
		foreach ( array( 'schema', 'schema_name', 'max_tokens', 'temperature' ) as $name ) {
			if ( isset( $options[ $name ] ) ) {
				$generate[ $name ] = $options[ $name ];
			}
		}
		$result = $adapter->generate( (string) Store::key( $provider ), $model, $messages, $generate );
		Core::policy()->record( $context, $result );

		return $result;
	}

	/**
	 * Is any provider ready?
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		foreach ( array_keys( Registry::all() ) as $provider ) {
			if ( Store::configured( $provider ) ) {
				return true;
			}
		}

		return false;
	}
}
