<?php
/**
 * The OpenAI chat-completions dialect: OpenAI, Mistral, DeepSeek, OpenRouter and any compatible server.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-openai-compatible.php by wp/bin/build-ai-core.php.
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
 * Adapter for every provider that speaks `/models` + `/chat/completions`.
 *
 * ⭐ One adapter for five providers is the point: a local Ollama or LM Studio server, a vLLM box
 * or a gateway all speak this dialect, which is what makes "any OpenAI-compatible service"
 * (feature ai-1) true without a line of provider-specific code.
 */
final class Openai_Compatible extends Provider {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key API key.
	 * @return array<int, array{id: string, label: string, context: int, price: array{input: float, output: float}|null}>|Failure
	 */
	public function list_models( string $key ) {
		list( $body, $response ) = $this->call( 'GET', (string) ( $this->spec['models_path'] ?? '/models' ), $key );
		if ( null === $body ) {
			return $response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response );
		}

		$models = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) || ! self::is_text_model( $row['id'] ) ) {
				continue;
			}
			$price = null;
			// OpenRouter publishes its prices in the list, in USD per token.
			if ( isset( $row['pricing']['prompt'], $row['pricing']['completion'] ) && is_numeric( $row['pricing']['prompt'] ) && is_numeric( $row['pricing']['completion'] ) ) {
				$price = array(
					'input'  => round( (float) $row['pricing']['prompt'] * 1000000, 6 ),
					'output' => round( (float) $row['pricing']['completion'] * 1000000, 6 ),
				);
			}
			$models[] = array(
				'id'      => $row['id'],
				'label'   => isset( $row['name'] ) && is_string( $row['name'] ) ? $row['name'] : $row['id'],
				'context' => (int) ( $row['context_length'] ?? $row['max_context_length'] ?? 0 ),
				'price'   => $price,
			);
		}
		usort( $models, static fn( array $a, array $b ): int => strcmp( $a['id'], $b['id'] ) );

		return $models;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                                           $key      API key.
	 * @param string                                           $model    Model id.
	 * @param array<int, array{role: string, content: string}> $messages Conversation.
	 * @param array<string, mixed>                             $options  Options.
	 * @return Result
	 */
	public function generate( string $key, string $model, array $messages, array $options = array() ): Result {
		$schema = isset( $options['schema'] ) && is_array( $options['schema'] ) ? $options['schema'] : null;
		$mode   = (string) ( $this->spec['structured_output'] ?? 'json_schema' );

		$body = array(
			'model'    => $model,
			'messages' => array(),
		);
		if ( null !== $schema && 'json_schema' !== $mode ) {
			// No schema enforcement on this provider: say what we need in the prompt, and ask
			// for JSON mode where it exists. The answer is still validated as JSON below.
			array_unshift(
				$messages,
				array(
					'role'    => 'system',
					'content' => 'Reply with a single JSON object only, no prose, matching this JSON Schema: ' . wp_json_encode( $schema ),
				)
			);
		}
		foreach ( $messages as $message ) {
			$body['messages'][] = array(
				'role'    => $message['role'],
				'content' => $message['content'],
			);
		}
		if ( isset( $options['max_tokens'] ) ) {
			$body[ (string) ( $this->spec['max_tokens_param'] ?? 'max_tokens' ) ] = (int) $options['max_tokens'];
		}
		if ( isset( $options['temperature'] ) ) {
			$body['temperature'] = (float) $options['temperature'];
		}
		if ( null !== $schema && 'json_schema' === $mode ) {
			$body['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => (string) ( $options['schema_name'] ?? 'response' ),
					'schema' => $schema,
					'strict' => true,
				),
			);
		} elseif ( null !== $schema && 'json_object' === $mode ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		list( $data, $response ) = $this->call( 'POST', (string) ( $this->spec['chat_path'] ?? '/chat/completions' ), $key, $body );
		if ( null === $data ) {
			return Result::failed(
				$response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response, $model ),
				$this->id,
				$model
			);
		}

		$result           = new Result();
		$result->provider = $this->id;
		$result->model    = isset( $data['model'] ) && is_string( $data['model'] ) ? $data['model'] : $model;
		$message          = $data['choices'][0]['message'] ?? array();
		$result->text     = is_array( $message ) && isset( $message['content'] ) && is_string( $message['content'] ) ? $message['content'] : '';

		$result->input_tokens  = (int) ( $data['usage']['prompt_tokens'] ?? 0 );
		$result->output_tokens = (int) ( $data['usage']['completion_tokens'] ?? 0 );

		if ( null !== $schema ) {
			$result->data = Json::object_from( $result->text );
			if ( null === $result->data ) {
				$result->failure = $this->unreadable( $response );
			}
		}

		return $result;
	}
}
