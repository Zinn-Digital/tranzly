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
		// ⛔ A text longer than one call is SPLIT, never sent whole (live 2026-10-02: a 70 KB page
		// went to Gemini as one call, outlived the core's 60 s HTTP timeout and was reported
		// "unreachable"). Each piece travels under its own key and the answer is joined back in order.
		$pieces = array();
		$send   = array();
		foreach ( $texts as $key => $text ) {
			$text = (string) $text;
			if ( mb_strlen( $text ) <= self::CHUNK ) {
				$send[ $key ] = $text;
				continue;
			}
			foreach ( self::split_long( $text, self::CHUNK ) as $i => $piece ) {
				// The model is asked for the words, so the whitespace around a piece stays ours.
				$body = trim( $piece );
				$lead = '' === $body ? $piece : (string) substr( $piece, 0, (int) strpos( $piece, $body ) );
				$tail = '' === $body ? '' : (string) substr( $piece, strlen( $lead ) + strlen( $body ) );
				$sub  = $key . '#part' . $i;
				while ( array_key_exists( $sub, $texts ) || array_key_exists( $sub, $send ) ) {
					$sub .= '_';
				}
				$pieces[ $key ][] = array( $sub, $lead, $tail, '' === $body );
				if ( '' !== $body ) {
					$send[ $sub ] = $body;
				}
			}
		}

		$got = array();
		foreach ( $this->chunks( $send ) as $chunk ) {
			$answer = $this->call( $chunk, $source, $target, $options );
			if ( is_wp_error( $answer ) ) {
				return $answer;
			}
			$got += $answer;
		}

		$out = array();
		foreach ( $texts as $key => $text ) {
			if ( ! isset( $pieces[ $key ] ) ) {
				$out[ $key ] = $got[ $key ];
				continue;
			}
			$joined = '';
			foreach ( $pieces[ $key ] as list( $sub, $lead, $tail, $blank ) ) {
				$joined .= $blank ? $lead : $lead . $got[ $sub ] . $tail;
			}
			$out[ $key ] = $joined;
		}

		return $out;
	}

	/**
	 * One long text as consecutive pieces of at most `$max` characters, joined back losslessly
	 * (`implode( '', $pieces ) === $text`). A piece ends at the best boundary the window offers:
	 * before a block comment, after a closing block-level tag, at a blank line, a line end, a
	 * sentence end, a space outside a tag, and only then anywhere outside a tag.
	 *
	 * @param string $text The text.
	 * @param int    $max  Characters per piece.
	 * @return array<int, string>
	 */
	public static function split_long( string $text, int $max ): array {
		$max    = max( 1, $max );
		$pieces = array();
		while ( mb_strlen( $text ) > $max ) {
			$window   = mb_substr( $text, 0, $max );
			$cut      = self::boundary( $window );
			$pieces[] = mb_substr( $text, 0, $cut );
			$text     = mb_substr( $text, $cut );
		}
		if ( '' !== $text ) {
			$pieces[] = $text;
		}

		return $pieces;
	}

	/**
	 * Where to end a piece inside `$window` (a character count, at least 1).
	 *
	 * @param string $window The longest the piece may be.
	 * @return int
	 */
	private static function boundary( string $window ): int {
		$len   = mb_strlen( $window );
		$floor = intdiv( $len, 4 );
		$best  = static function ( array $needles, bool $before ) use ( $window, $floor ): int {
			$at = 0;
			foreach ( $needles as $needle ) {
				$pos = mb_strrpos( $window, $needle );
				if ( false === $pos ) {
					continue;
				}
				$cut = $before ? $pos : $pos + mb_strlen( $needle );
				if ( $cut > $at ) {
					$at = $cut;
				}
			}

			return $at > $floor ? $at : 0;
		};
		$rules = array(
			array( array( '<!-- wp:', '<!-- /wp:' ), true ),
			array( array( '</p>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</li>', '</ul>', '</ol>', '</div>', '</blockquote>', '</table>', '</tr>', '</figure>', '</section>', '</pre>', '-->' ), false ),
			array( array( "\n\n" ), false ),
			array( array( "\n" ), false ),
			array( array( '. ', '! ', '? ', '。', '！', '？', '; ' ), false ),
		);
		foreach ( $rules as list( $needles, $before ) ) {
			$cut = $best( $needles, $before );
			if ( $cut > 0 && ! self::inside_tag( mb_substr( $window, 0, $cut ) ) ) {
				return $cut;
			}
		}
		// A space outside a tag, then anywhere outside a tag.
		for ( $cut = $len; $cut > $floor; --$cut ) {
			$head = mb_substr( $window, 0, $cut );
			if ( ' ' === mb_substr( $window, $cut - 1, 1 ) && ! self::inside_tag( $head ) ) {
				return $cut;
			}
		}
		$open = mb_strrpos( $window, '<' );
		if ( self::inside_tag( $window ) && false !== $open && $open > 0 ) {
			return $open;
		}

		return $len;
	}

	/**
	 * Does `$head` end inside an HTML tag or comment (a `<` with no `>` after it)?
	 *
	 * @param string $head Text.
	 * @return bool
	 */
	private static function inside_tag( string $head ): bool {
		$open  = strrpos( $head, '<' );
		$close = strrpos( $head, '>' );

		return false !== $open && ( false === $close || $close < $open );
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
	 * Texts in groups of at most CHUNK characters ({@see translate()} has already split any longer text).
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
