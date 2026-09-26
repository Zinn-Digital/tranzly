<?php
/**
 * The Google Gemini API dialect.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-gemini.php by wp/bin/build-ai-core.php.
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
 * Adapter for Google Gemini (AI Studio keys).
 */
final class Gemini extends Provider {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key API key.
	 * @return array<int, array{id: string, label: string, context: int, price: array{input: float, output: float}|null}>|Failure
	 */
	public function list_models( string $key ) {
		list( $body, $response ) = $this->call( 'GET', (string) ( $this->spec['models_path'] ?? '/models' ) . '?pageSize=1000', $key );
		if ( null === $body ) {
			return $response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response );
		}

		$models = array();
		foreach ( (array) ( $body['models'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'] ) || ! is_string( $row['name'] ) ) {
				continue;
			}
			// Only models that can generate text; the list also carries embedding models.
			if ( ! in_array( 'generateContent', (array) ( $row['supportedGenerationMethods'] ?? array() ), true ) ) {
				continue;
			}
			$id = str_starts_with( $row['name'], 'models/' ) ? substr( $row['name'], 7 ) : $row['name'];
			if ( ! self::is_text_model( $id ) ) {
				continue;
			}
			$models[] = array(
				'id'      => $id,
				'label'   => isset( $row['displayName'] ) && is_string( $row['displayName'] ) ? $row['displayName'] : $id,
				'context' => (int) ( $row['inputTokenLimit'] ?? 0 ),
				'price'   => null,
			);
		}

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
		list( $system, $turns ) = self::split_system( $messages );
		$schema                 = isset( $options['schema'] ) && is_array( $options['schema'] ) ? $options['schema'] : null;

		$body = array( 'contents' => array() );
		foreach ( $turns as $turn ) {
			$body['contents'][] = array(
				'role'  => 'assistant' === $turn['role'] ? 'model' : 'user',
				'parts' => array( array( 'text' => $turn['content'] ) ),
			);
		}
		if ( '' !== $system ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system ) ) );
		}
		$config = array();
		if ( isset( $options['max_tokens'] ) ) {
			$config[ (string) ( $this->spec['max_tokens_param'] ?? 'maxOutputTokens' ) ] = (int) $options['max_tokens'];
		}
		if ( isset( $options['temperature'] ) ) {
			$config['temperature'] = (float) $options['temperature'];
		}
		if ( null !== $schema ) {
			$config['responseMimeType'] = 'application/json';
			$config[ (string) ( $this->spec['schema_field'] ?? 'responseJsonSchema' ) ] = $schema;
		}
		if ( array() !== $config ) {
			$body['generationConfig'] = $config;
		}

		$path                    = str_replace( '{model}', rawurlencode( $model ), (string) ( $this->spec['chat_path'] ?? '/models/{model}:generateContent' ) );
		list( $data, $response ) = $this->call( 'POST', $path, $key, $body );
		if ( null === $data ) {
			return Result::failed(
				$response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response, $model ),
				$this->id,
				$model
			);
		}

		$result                = new Result();
		$result->provider      = $this->id;
		$result->model         = isset( $data['modelVersion'] ) && is_string( $data['modelVersion'] ) ? $data['modelVersion'] : $model;
		$result->input_tokens  = (int) ( $data['usageMetadata']['promptTokenCount'] ?? 0 );
		$result->output_tokens = (int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 ) + (int) ( $data['usageMetadata']['thoughtsTokenCount'] ?? 0 );

		$text = array();
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) && empty( $part['thought'] ) ) {
				$text[] = $part['text'];
			}
		}
		$result->text = implode( '', $text );
		if ( null !== $schema ) {
			$result->data = Json::object_from( $result->text );
			if ( null === $result->data ) {
				$result->failure = $this->unreadable( $response );
			}
		}

		return $result;
	}
}
