<?php
/**
 * One interface over every AI provider.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-provider.php by wp/bin/build-ai-core.php.
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
 * A provider adapter: list the models a key can use, and generate.
 *
 * Every adapter takes the same request and returns the same `Result`, so a feature written
 * against OpenAI works unchanged on Claude, Gemini or a model on the site owner's own machine.
 */
abstract class Provider {

	/**
	 * Provider id.
	 *
	 * @var string
	 */
	protected string $id;

	/**
	 * Preset row (data/providers.json), with the site owner's base URL applied for custom.
	 *
	 * @var array<string, mixed>
	 */
	protected array $spec;

	/**
	 * Build an adapter.
	 *
	 * @param string               $id   Provider id.
	 * @param array<string, mixed> $spec Preset row.
	 */
	public function __construct( string $id, array $spec ) {
		$this->id   = $id;
		$this->spec = $spec;
	}

	/**
	 * Provider id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The models this key can use, straight from the provider (feature ai-2).
	 *
	 * @param string $key API key ('' when the provider needs none).
	 * @return array<int, array{id: string, label: string, context: int, price: array{input: float, output: float}|null}>|Failure
	 */
	abstract public function list_models( string $key );

	/**
	 * Generate.
	 *
	 * @param string                                           $key     API key.
	 * @param string                                           $model   Model id.
	 * @param array<int, array{role: string, content: string}> $messages Conversation; `system` rows are allowed.
	 * @param array<string, mixed>                             $options `max_tokens` (int), `schema` (JSON Schema array), `schema_name` (string), `temperature` (float).
	 * @return Result
	 */
	abstract public function generate( string $key, string $model, array $messages, array $options = array() ): Result;

	/**
	 * The API base, without a trailing slash.
	 *
	 * @return string
	 */
	protected function base(): string {
		return rtrim( (string) ( $this->spec['api_base'] ?? '' ), '/' );
	}

	/**
	 * Is the base a self-hosted address? Only the custom preset may be.
	 *
	 * @return bool
	 */
	protected function local(): bool {
		return Registry::CUSTOM === $this->id;
	}

	/**
	 * Headers for an authenticated call.
	 *
	 * @param string $key API key.
	 * @return array<string, string>
	 */
	protected function headers( string $key ): array {
		$headers = array( 'Content-Type' => 'application/json' );
		foreach ( (array) ( $this->spec['extra_headers'] ?? array() ) as $name => $value ) {
			$headers[ (string) $name ] = (string) $value;
		}
		if ( '' === $key ) {
			return $headers;
		}
		switch ( (string) ( $this->spec['auth'] ?? 'bearer' ) ) {
			case 'x-api-key':
				$headers['x-api-key'] = $key;
				break;
			case 'x-goog-api-key':
				$headers['x-goog-api-key'] = $key;
				break;
			default:
				$headers['Authorization'] = 'Bearer ' . $key;
		}

		return $headers;
	}

	/**
	 * Send and decode a JSON call.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path below the API base.
	 * @param string                    $key    API key.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array{0: array<mixed>|null, 1: array{status: int, body: string, error: string}} Decoded body (null unless 2xx JSON) and the raw response.
	 */
	protected function call( string $method, string $path, string $key, ?array $body = null ): array {
		$response = Http::send(
			$method,
			$this->base() . $path,
			$this->headers( $key ),
			null === $body ? null : (string) wp_json_encode( $body ),
			$this->local()
		);
		$decoded  = null;
		if ( $response['status'] >= 200 && $response['status'] < 300 ) {
			$json    = json_decode( $response['body'], true );
			$decoded = is_array( $json ) ? $json : null;
		}

		return array( $decoded, $response );
	}

	/**
	 * A 2xx answer we could not read is not the provider's fault to announce: it is ours.
	 *
	 * @param array{status: int, body: string, error: string} $response Raw response.
	 * @return Failure
	 */
	protected function unreadable( array $response ): Failure {
		return new Failure(
			Failure::UNKNOWN,
			'',
			__( 'The AI provider answered in a form this plugin could not read. Try again; if it keeps happening, choose another model or contact support.', 'tranzly' ),
			'',
			substr( $response['body'], 0, 300 )
		);
	}

	/**
	 * Split the system prompt from the conversation.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Conversation.
	 * @return array{0: string, 1: array<int, array{role: string, content: string}>}
	 */
	protected static function split_system( array $messages ): array {
		$system = array();
		$turns  = array();
		foreach ( $messages as $message ) {
			if ( 'system' === $message['role'] ) {
				$system[] = $message['content'];
			} else {
				$turns[] = $message;
			}
		}

		return array( implode( "\n\n", $system ), $turns );
	}

	/**
	 * A provider id or label that looks like a chat model, for the live list.
	 *
	 * ⭐ Discovery returns everything a key can call, including embeddings, speech and image
	 * models that would fail as a text model. They are hidden from the picker; "add a model
	 * yourself" (ai-4) still reaches any of them.
	 *
	 * @param string $id Model id.
	 * @return bool
	 */
	protected static function is_text_model( string $id ): bool {
		return 1 !== preg_match( '/(embed|tts|whisper|transcri|dall-e|image|moderation|audio|realtime|speech|rerank|ocr|search-preview)/i', $id );
	}
}
