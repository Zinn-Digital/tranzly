<?php
/**
 * Translation by an AI model with the site owner's own key (OpenAI, Anthropic, Gemini, Mistral,
 * DeepSeek, OpenRouter or any OpenAI-compatible service), through the shared Zinn AI core.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Engines;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI models with your own key (tz-e2). Tranzly never holds an AI key or picks a provider itself: it asks the AI core (F2) for
 * the `translate` task, and the site owner's choice of provider and model for that task decides.
 * The core keeps the key encrypted, logs usage (Pro) and classifies failures; this class turns a
 * core failure into the report's plain-English class.
 *
 * ⭐ Registered only when the AI core is present (`class_exists`), so Tranzly works — with DeepL —
 * on a build that does not ship it.
 *
 * ⭐ Glossary, do-not-translate and tone reach the model as instructions, so they work with AI
 * models too, not only with DeepL (tz-e8, tz-e9). The answer is structured JSON keyed like the
 * request, and a missing or extra key is a failure, never a silent partial translation.
 */
final class Ai implements Engine {

	/** The AI core's client class inside this plugin's copy. */
	public const CLIENT = '\\ZinnDigital\\Tranzly\\AiCore\\Client';

	/** Characters per call: small enough that the answer fits any model's output limit. */
	private const CHUNK = 6000;

	/**
	 * Is the AI core in this build?
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( self::CLIENT );
	}

	/**
	 * Engine id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'ai';
	}

	/**
	 * Name, including the provider and model the owner chose for translation.
	 *
	 * @return string
	 */
	public function label(): string {
		$choice = $this->choice();

		return '' === $choice['provider']
			? __( 'AI model', 'tranzly' )
			/* translators: 1: an AI provider, 2: a model name. */
			: sprintf( __( 'AI model (%1$s %2$s)', 'tranzly' ), $choice['provider'], $choice['model'] );
	}

	/**
	 * Is an AI provider set up for translation?
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		if ( ! self::available() ) {
			return false;
		}
		$store  = '\\ZinnDigital\\Tranzly\\AiCore\\Store';
		$choice = $this->choice();

		return '' !== $choice['provider'] && $store::configured( $choice['provider'] );
	}

	/**
	 * Translate.
	 *
	 * @param array<string, string> $texts   key => text.
	 * @param string                $source  Source locale.
	 * @param string                $target  Target locale.
	 * @param array<string, mixed>  $options `format`, `do_not_translate`, `glossary`, `formality`,
	 *                                       `instructions`.
	 * @return array<string, string>|\WP_Error
	 */
	public function translate( array $texts, string $source, string $target, array $options = array() ) {
		if ( ! self::available() ) {
			return Failure::make( Failure::CREDENTIAL, $this, 'the AI core is not in this build' );
		}
		$out = array();
		foreach ( $this->chunks( $texts ) as $chunk ) {
			$answer = $this->call( $chunk, $source, $target, $options );
			if ( is_wp_error( $answer ) ) {
				return $answer;
			}
			$out += $answer;
		}

		return $out;
	}

	/**
	 * Estimate from the catalogue's per-token price for the chosen model (null when unknown).
	 *
	 * @param array<string, string> $texts  key => text.
	 * @param string                $target Target locale.
	 * @return array{characters: int, cost_usd: float|null}
	 */
	public function estimate( array $texts, string $target ): array {
		$characters = (int) array_sum( array_map( 'mb_strlen', $texts ) );
		$choice     = $this->choice();
		$catalogue  = '\\ZinnDigital\\Tranzly\\AiCore\\Catalogue';
		$price      = self::available() && '' !== $choice['provider'] ? $catalogue::price( $choice['provider'], $choice['model'] ) : null;
		if ( null === $price ) {
			return array(
				'characters' => $characters,
				'cost_usd'   => null,
			);
		}
		// Roughly four characters a token; the instructions add about 400 tokens per call, and a
		// translation is usually a little longer than its source.
		$calls  = max( 1, (int) ceil( $characters / self::CHUNK ) );
		$input  = $characters / 4 + 400 * $calls;
		$output = $characters / 4 * 1.2;

		return array(
			'characters' => $characters,
			'cost_usd'   => round( ( $input * $price['input'] + $output * $price['output'] ) / 1000000, 6 ),
		);
	}

	/**
	 * One call to the model.
	 *
	 * @param array<string, string> $texts   key => text.
	 * @param string                $source  Source locale.
	 * @param string                $target  Target locale.
	 * @param array<string, mixed>  $options Options.
	 * @return array<string, string>|\WP_Error
	 */
	private function call( array $texts, string $source, string $target, array $options ) {
		$items = array();
		foreach ( $texts as $key => $text ) {
			$items[] = array(
				'key'  => (string) $key,
				'text' => (string) $text,
			);
		}
		$rules = array(
			sprintf( 'Translate the "text" of every item from %1$s to %2$s. Return every item with its "key" unchanged.', $source, $target ),
			'html' === ( $options['format'] ?? 'text' )
				? 'The text is HTML (it may contain WordPress block comments). Keep every tag, attribute, block comment and URL exactly as it is; translate only the human-readable text.'
				: 'The text is plain text.',
			'Translate faithfully. Do not add, remove or explain anything.',
		);
		$dnt   = array_values( array_filter( array_map( 'strval', (array) ( $options['do_not_translate'] ?? array() ) ) ) );
		if ( array() !== $dnt ) {
			$rules[] = 'Never translate these words; copy them exactly: ' . implode( ', ', $dnt ) . '.';
		}
		foreach ( (array) ( $options['glossary'] ?? array() ) as $from => $to ) {
			$rules[] = sprintf( 'Always translate "%1$s" as "%2$s".', $from, $to );
		}
		$formality = (string) ( $options['formality'] ?? 'default' );
		if ( 'more' === $formality ) {
			$rules[] = 'Use the formal form of address.';
		} elseif ( 'less' === $formality ) {
			$rules[] = 'Use the informal form of address.';
		}
		if ( '' !== trim( (string) ( $options['instructions'] ?? '' ) ) ) {
			$rules[] = 'Style instructions from the site owner: ' . trim( (string) $options['instructions'] );
		}

		$client = self::CLIENT;
		$result = $client::generate(
			array(
				array(
					'role'    => 'system',
					'content' => 'You are a professional website translator. ' . implode( ' ', $rules ),
				),
				array(
					'role'    => 'user',
					'content' => (string) wp_json_encode( array( 'items' => $items ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				),
			),
			array(
				'task'        => 'translate',
				'purpose'     => 'tranzly-translate',
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'key'  => array( 'type' => 'string' ),
									'text' => array( 'type' => 'string' ),
								),
								'required'             => array( 'key', 'text' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'items' ),
					'additionalProperties' => false,
				),
				'schema_name' => 'translations',
				'temperature' => 0.2,
			)
		);

		if ( ! $result->ok() ) {
			return $this->failure( $result->failure );
		}
		$out = array();
		foreach ( (array) ( $result->data['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) && isset( $item['key'], $item['text'] ) && array_key_exists( (string) $item['key'], $texts ) ) {
				$out[ (string) $item['key'] ] = (string) $item['text'];
			}
		}
		if ( count( $out ) !== count( $texts ) ) {
			return Failure::make( Failure::UNKNOWN, $this, 'the model returned ' . count( $out ) . ' of ' . count( $texts ) . ' translations' );
		}

		return $out;
	}

	/**
	 * The AI core's failure as the report's class.
	 *
	 * @param object|null $failure The core's Failure.
	 * @return \WP_Error
	 */
	private function failure( $failure ): \WP_Error {
		$kind = is_object( $failure ) ? (string) ( $failure->kind ?? '' ) : '';
		$map  = array(
			'provider_outage'    => Failure::OUTAGE,
			'unreachable'        => Failure::UNREACHABLE,
			'credential_billing' => Failure::QUOTA,
			'credential_invalid' => Failure::CREDENTIAL,
			'rate_limited'       => Failure::RATE_LIMITED,
			'model_unavailable'  => Failure::UNSUPPORTED,
			'refused'            => Failure::CREDENTIAL,
		);

		return Failure::make(
			$map[ $kind ] ?? Failure::UNKNOWN,
			$this,
			is_object( $failure ) ? (string) ( $failure->message ?? '' ) : '',
			is_object( $failure ) ? (string) ( $failure->link ?? '' ) : ''
		);
	}

	/**
	 * The provider and model chosen for the `translate` task.
	 *
	 * @return array{provider: string, model: string}
	 */
	private function choice(): array {
		if ( ! self::available() ) {
			return array(
				'provider' => '',
				'model'    => '',
			);
		}
		$store  = '\\ZinnDigital\\Tranzly\\AiCore\\Store';
		$choice = (array) $store::default_for( 'translate' );

		return array(
			'provider' => (string) ( $choice['provider'] ?? '' ),
			'model'    => (string) ( $choice['model'] ?? '' ),
		);
	}

	/**
	 * Texts in groups of at most CHUNK characters (a single longer text travels alone).
	 *
	 * @param array<string, string> $texts key => text.
	 * @return array<int, array<string, string>>
	 */
	private function chunks( array $texts ): array {
		$chunks = array();
		$size   = 0;
		$one    = array();
		foreach ( $texts as $key => $text ) {
			$len = mb_strlen( $text );
			if ( array() !== $one && $size + $len > self::CHUNK ) {
				$chunks[] = $one;
				$one      = array();
				$size     = 0;
			}
			$one[ $key ] = $text;
			$size       += $len;
		}
		if ( array() !== $one ) {
			$chunks[] = $one;
		}

		return $chunks;
	}
}
