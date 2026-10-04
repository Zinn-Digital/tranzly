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
	 * The answer one BATCHED call is sized to, in tokens (the platform's translator uses the same
	 * figure, docs/28 §"Batching"): far below any model's ceiling, because a truncated batch costs
	 * every language in it a single call.
	 */
	public const BATCH_OUTPUT_TOKENS = 8000;

	/**
	 * Characters of source per BATCHED call: small, so several languages share one answer. At
	 * CHUNK (6,000) a chunk of shared strings left room for one language and was not batched at all
	 * (measured live 2026-10-04: 720 of ~40,000 strings batched on demo.tranzly.io). The answers are
	 * kept per text, so the per-language calls may group texts differently and still find them.
	 */
	private const BATCH_CHUNK = 1500;

	/**
	 * Answer tokens per source character, by the target's script: pessimistic, because an
	 * under-estimate truncates the whole batch. Measured 2026-10-04 on tranzly.io's own pages:
	 * Latin-script page answers ran at about 0.3 tokens a source character, while batches of
	 * shared strings hit the 16k answer ceiling twice: once sized at a flat 0.4 a character, then
	 * again at 1.5 for the costly scripts with the keys left uncounted (each key and its JSON come
	 * back once per language; now counted as `$frame`).
	 *
	 * @param string $locale Target locale.
	 * @return float
	 */
	private static function tokens_per_char( string $locale ): float {
		$lang = strtolower( (string) strtok( $locale, '_-' ) );
		if ( in_array( $lang, array( 'am', 'my', 'km', 'lo', 'si', 'th', 'ka', 'hy', 'hi', 'mr', 'ne', 'bn', 'gu', 'pa', 'ta', 'te', 'kn', 'ml', 'or', 'as', 'dz', 'bo' ), true ) ) {
			return 2.5;
		}
		if ( in_array( $lang, array( 'ja', 'zh', 'ko' ), true ) ) {
			return 1.0;
		}

		return 0.6;
	}

	/**
	 * Answers of batched calls in this request, waiting for the per-language call that needs them:
	 * cache key ({@see batch_key()}) => translation. Each is taken once.
	 *
	 * @var array<string, string>
	 */
	private static $batched = array();

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
		list( $send, $pieces ) = self::pieces( $texts );

		$got = array();
		// ⭐ What a batched call already answered for this language is used, not paid for twice
		// (owner, 2026-10-01: translation is ALWAYS batched; this per-language call is the retry).
		foreach ( $send as $key => $text ) {
			if ( '' === trim( $text ) ) {
				$got[ $key ] = $text; // Nothing to translate: never a call for whitespace.
				unset( $send[ $key ] );
				continue;
			}
			$bk = self::batch_key( $text, $source, $target, $options );
			if ( isset( self::$batched[ $bk ] ) ) {
				$got[ $key ] = self::$batched[ $bk ];
				unset( self::$batched[ $bk ], $send[ $key ] );
			}
		}

		foreach ( $this->chunks( $send ) as $chunk ) {
			$answer = $this->call( $chunk, $source, $target, $options );
			if ( is_wp_error( $answer ) ) {
				return $answer;
			}
			$got += $answer;
		}

		return self::join( $texts, $pieces, $got );
	}

	/**
	 * Translate the same texts into SEVERAL languages with as few calls as possible (owner,
	 * 2026-10-01: *"all translation jobs here ALWAYS use batching"*). Each call carries one chunk
	 * of the texts and as many languages as fit in {@see BATCH_OUTPUT_TOKENS} of answer; the
	 * instructions and the source are paid once per call instead of once per language.
	 *
	 * Nothing is written here: the answers wait in this request for the per-language
	 * {@see translate()} that the caller runs next, item by item, so translation memory, caps,
	 * protection, the fallback chain and every check stay exactly as they are. A language a batch
	 * did not answer completely is simply not cached, and its own call translates it (the retry
	 * the ruling allows). A chunk too long for two languages in one answer is left to the
	 * per-language calls: batching it would only truncate.
	 *
	 * @param array<string, string>               $texts   key => text (one format).
	 * @param string                              $source  Source locale.
	 * @param array<string, array<string, mixed>> $targets target locale => that language's options
	 *                                                     (as {@see translate()} will receive them).
	 * @param callable|null                       $tick    Called after every call (a queue renews its leases).
	 * @return int Answers cached (texts × languages).
	 */
	public function prefetch( array $texts, string $source, array $targets, ?callable $tick = null ): int {
		if ( ! self::available() || count( $targets ) < 2 ) {
			return 0;
		}
		list( $send ) = self::pieces( $texts );
		$send         = array_filter( $send, static fn( $text ) => '' !== trim( (string) $text ) );
		// Languages that share every option but the glossary go in one call; the glossary is
		// given per language inside it.
		$groups = array();
		foreach ( $targets as $target => $options ) {
			$sig                                = md5( (string) wp_json_encode( array( $options['format'] ?? 'text', $options['do_not_translate'] ?? array(), $options['formality'] ?? 'default', $options['instructions'] ?? '' ) ) );
			$groups[ $sig ][ (string) $target ] = (array) $options;
		}
		$cached = 0;
		foreach ( $groups as $group ) {
			foreach ( $this->chunks( $send, self::BATCH_CHUNK ) as $chunk ) {
				$todo = array();
				foreach ( $group as $target => $options ) {
					foreach ( $chunk as $text ) {
						if ( ! isset( self::$batched[ self::batch_key( $text, $source, $target, $options ) ] ) ) {
							$todo[ $target ] = $options;
							break;
						}
					}
				}
				$chars = (int) array_sum( array_map( 'mb_strlen', $chunk ) );
				// The answer also repeats every key and its JSON (quotes in HTML come back escaped):
				// ASCII, so about the same in every script.
				$frame = 0;
				foreach ( $chunk as $key => $text ) {
					$frame += strlen( (string) $key ) + 24 + substr_count( (string) $text, '"' );
				}
				$batches = array();
				$one     = array();
				$budget  = 0;
				foreach ( $todo as $target => $options ) {
					$need = (int) ceil( $chars * self::tokens_per_char( (string) $target ) + $frame * 0.5 );
					if ( array() !== $one && $budget + $need > self::BATCH_OUTPUT_TOKENS ) {
						$batches[] = $one;
						$one       = array();
						$budget    = 0;
					}
					$one[ $target ] = $options;
					$budget        += $need;
				}
				if ( array() !== $one ) {
					$batches[] = $one;
				}
				foreach ( $batches as $batch ) {
					if ( count( $batch ) < 2 ) {
						continue; // Too long to share an answer: the language's own call takes it.
					}
					$answers = $this->call_many( $chunk, $source, $batch );
					if ( null !== $tick ) {
						$tick();
					}
					foreach ( $answers as $target => $items ) {
						foreach ( $chunk as $key => $text ) {
							self::$batched[ self::batch_key( $text, $source, $target, $batch[ $target ] ) ] = $items[ $key ];
							++$cached;
						}
					}
				}
			}
		}

		return $cached;
	}

	/**
	 * Forget every batched answer not used yet (tests; a long run between items).
	 *
	 * @return void
	 */
	public static function forget_batched(): void {
		self::$batched = array();
	}

	/**
	 * The batched-answer key: the text, both languages and every option the answer depends on.
	 *
	 * @param string               $text    The text sent.
	 * @param string               $source  Source locale.
	 * @param string               $target  Target locale.
	 * @param array<string, mixed> $options Options.
	 * @return string
	 */
	private static function batch_key( string $text, string $source, string $target, array $options ): string {
		return md5( (string) wp_json_encode( array( $source, $target, $options['format'] ?? 'text', $options['do_not_translate'] ?? array(), $options['glossary'] ?? array(), $options['formality'] ?? 'default', $options['instructions'] ?? '', $text ) ) );
	}

	/**
	 * The texts as they are sent: any text longer than one call split into pieces.
	 *
	 * @param array<string, string> $texts key => text.
	 * @return array{0: array<string, string>, 1: array<string, array<int, array{0: string, 1: string, 2: string, 3: bool}>>}
	 */
	private static function pieces( array $texts ): array {
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

		return array( $send, $pieces );
	}

	/**
	 * The answers joined back into the caller's keys (split texts re-assembled in order).
	 *
	 * @param array<string, string>                                                      $texts  The caller's texts.
	 * @param array<string, array<int, array{0: string, 1: string, 2: string, 3: bool}>> $pieces From pieces().
	 * @param array<string, string>                                                      $got    Sent key => translation.
	 * @return array<string, string>
	 */
	private static function join( array $texts, array $pieces, array $got ): array {
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
		$rules = array_merge(
			array( sprintf( 'Translate the "text" of every item from %1$s to %2$s. Return every item with its "key" unchanged.', $source, $target ) ),
			self::rules( $options )
		);
		foreach ( (array) ( $options['glossary'] ?? array() ) as $from => $to ) {
			$rules[] = sprintf( 'Always translate "%1$s" as "%2$s".', $from, $to );
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
					'content' => (string) wp_json_encode( array( 'items' => self::items( $texts ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				),
			),
			array(
				'task'        => 'translate',
				'purpose'     => 'tranzly-translate',
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => array( 'items' => self::items_schema() ),
					'required'             => array( 'items' ),
					'additionalProperties' => false,
				),
				'schema_name' => 'translations',
				'temperature' => 0.2,
				// Translation is mechanical: the least reasoning the model accepts (AI core 1.3.0).
				'thinking'    => 'minimal',
			)
		);

		if ( ! $result->ok() ) {
			return $this->failure( $result->failure );
		}
		$out = self::answers( (array) ( $result->data['items'] ?? array() ), $texts );
		if ( count( $out ) !== count( $texts ) ) {
			return Failure::make( Failure::UNKNOWN, $this, 'the model returned ' . count( $out ) . ' of ' . count( $texts ) . ' translations' );
		}
		$echoed = self::echoed( $texts, $out );
		if ( array() !== $echoed ) {
			if ( ! empty( $options['_retry'] ) ) {
				return Failure::make( Failure::UNKNOWN, $this, 'the model returned ' . count( $echoed ) . ' text(s) untranslated' );
			}
			// Once more, for those texts only, saying what went wrong.
			$again = $this->call(
				array_intersect_key( $texts, array_flip( $echoed ) ),
				$source,
				$target,
				array(
					'_retry'       => true,
					'instructions' => trim( ( (string) ( $options['instructions'] ?? '' ) ) . ' Translate every sentence; never return a sentence in the source language.' ),
				) + $options
			);
			if ( is_wp_error( $again ) ) {
				return $again;
			}
			$out = $again + $out;
		}

		return $out;
	}

	/**
	 * Keys whose answer still carries a sentence of the source verbatim: a run of text between
	 * tags with at least ECHO_WORDS words, outside code. ⛔ Live 2026-10-04: German, French and 55
	 * other translations of pagebuildersandwich.com's e-mail-marketing post kept whole English
	 * paragraphs ("…, but also because it yields…"), because a chunk came back untranslated and
	 * nothing compared the answer with the question.
	 *
	 * @param array<string, string> $texts   key => source.
	 * @param array<string, string> $answers key => answer.
	 * @return array<int, string>
	 */
	public static function echoed( array $texts, array $answers ): array {
		$out = array();
		foreach ( $texts as $key => $text ) {
			$answer = (string) ( $answers[ $key ] ?? '' );
			foreach ( self::sentences( (string) $text ) as $run ) {
				if ( false !== strpos( $answer, $run ) ) {
					$out[] = (string) $key;
					break;
				}
			}
		}

		return $out;
	}

	/** Words a run of source text needs before an identical run in the answer counts as untranslated. */
	private const ECHO_WORDS = 6;

	/**
	 * The runs of human-readable text in a source (between tags, outside pre/code/script/style and
	 * block comments) long enough that an identical run in a translation means it was not translated.
	 *
	 * @param string $text Source text or HTML.
	 * @return array<int, string>
	 */
	private static function sentences( string $text ): array {
		$text = (string) preg_replace( '#<(pre|code|script|style|kbd|samp)\b[^>]*>.*?</\1>|<!--.*?-->#si', '<x>', $text );
		$runs = array();
		foreach ( preg_split( '#<[^>]*>#', $text ) as $run ) {
			$run = trim( (string) $run );
			// A sentence, not a list of names: enough words, three of them in lower case (a run of
			// product names or a title may rightly stay as it is).
			// Nor an enumeration of values ("string: any, draft, pending, publish, private" in an API
			// reference, measured live 2026-10-04): commas between most of the words.
			$words = preg_match_all( '/\p{L}{2,}/u', $run );
			if ( $words >= self::ECHO_WORDS && preg_match_all( '/(?<!\p{L})\p{Ll}{2,}/u', $run ) >= 3 && substr_count( $run, ',' ) * 2 < $words && ! preg_match( '#https?://|[{}=;$]#', $run ) ) {
				$runs[] = $run;
			}
		}

		return $runs;
	}

	/**
	 * One BATCHED call: the same texts into several languages. Only a language answered in full
	 * comes back; anything else (a failed call, an unreadable answer, a missing key) returns
	 * nothing for that language, which its own call then translates.
	 *
	 * @param array<string, string>               $texts   key => text.
	 * @param string                              $source  Source locale.
	 * @param array<string, array<string, mixed>> $targets target => options (same format, list, tone).
	 * @return array<string, array<string, string>> target => key => translation.
	 */
	private function call_many( array $texts, string $source, array $targets ): array {
		$first = (array) reset( $targets );
		$rules = array_merge(
			array(
				sprintf( 'Translate the "text" of every item from %s into EACH of the languages listed. Return one entry per language, with its "lang" exactly as listed, and in it every item with its "key" unchanged.', $source ),
				'Languages: ' . implode( ', ', array_keys( $targets ) ) . '.',
			),
			self::rules( $first )
		);
		foreach ( $targets as $target => $options ) {
			foreach ( (array) ( $options['glossary'] ?? array() ) as $from => $to ) {
				$rules[] = sprintf( 'In %1$s, always translate "%2$s" as "%3$s".', $target, $from, $to );
			}
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
					'content' => (string) wp_json_encode( array( 'items' => self::items( $texts ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				),
			),
			array(
				'task'        => 'translate',
				'purpose'     => 'tranzly-translate-batch',
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'translations' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'lang'  => array( 'type' => 'string' ),
									'items' => self::items_schema(),
								),
								'required'             => array( 'lang', 'items' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'translations' ),
					'additionalProperties' => false,
				),
				'schema_name' => 'translations',
				'temperature' => 0.2,
				// A ceiling, billed only when used: twice the size the batch was planned for.
				'max_tokens'  => 2 * self::BATCH_OUTPUT_TOKENS,
				'thinking'    => 'minimal',
			)
		);
		if ( ! $result->ok() ) {
			return array();
		}
		$out = array();
		foreach ( (array) ( $result->data['translations'] ?? array() ) as $entry ) {
			$lang = is_array( $entry ) ? (string) ( $entry['lang'] ?? '' ) : '';
			if ( ! isset( $targets[ $lang ] ) || isset( $out[ $lang ] ) ) {
				continue;
			}
			$items = self::answers( (array) ( $entry['items'] ?? array() ), $texts );
			// A language that came back incomplete or with source text left in is not used: its
			// own call translates it (and retries an echo once).
			if ( count( $items ) === count( $texts ) && array() === self::echoed( $texts, $items ) ) {
				$out[ $lang ] = $items;
			}
		}

		return $out;
	}

	/**
	 * The rules every call carries: the format, faithfulness, the do-not-translate list, the tone
	 * and the site owner's instructions (the glossary is per language, added by the caller).
	 *
	 * @param array<string, mixed> $options Options.
	 * @return array<int, string>
	 */
	private static function rules( array $options ): array {
		$rules = array(
			'html' === ( $options['format'] ?? 'text' )
				? 'The text is HTML (it may contain WordPress block comments). Keep every tag, attribute, block comment and URL exactly as it is; translate only the human-readable text.'
				: 'The text is plain text.',
			'Translate faithfully. Do not add, remove or explain anything.',
		);
		$dnt   = array_values( array_filter( array_map( 'strval', (array) ( $options['do_not_translate'] ?? array() ) ) ) );
		if ( array() !== $dnt ) {
			$rules[] = 'Never translate these words; copy them exactly: ' . implode( ', ', $dnt ) . '.';
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

		return $rules;
	}

	/**
	 * The request's items.
	 *
	 * @param array<string, string> $texts key => text.
	 * @return array<int, array{key: string, text: string}>
	 */
	private static function items( array $texts ): array {
		$items = array();
		foreach ( $texts as $key => $text ) {
			$items[] = array(
				'key'  => (string) $key,
				'text' => (string) $text,
			);
		}

		return $items;
	}

	/**
	 * The JSON schema of a list of items.
	 *
	 * @return array<string, mixed>
	 */
	private static function items_schema(): array {
		return array(
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
		);
	}

	/**
	 * The answered items that belong to the request, key => text.
	 *
	 * @param array<int, mixed>     $items The model's items.
	 * @param array<string, string> $texts The request.
	 * @return array<string, string>
	 */
	private static function answers( array $items, array $texts ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['key'], $item['text'] ) && array_key_exists( (string) $item['key'], $texts ) ) {
				$out[ (string) $item['key'] ] = (string) $item['text'];
			}
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
	 * Texts in groups of at most `$max` characters ({@see translate()} has already split any longer text).
	 *
	 * @param array<string, string> $texts key => text.
	 * @param int                   $max   Characters per group (CHUNK; BATCH_CHUNK when batching).
	 * @return array<int, array<string, string>>
	 */
	private function chunks( array $texts, int $max = self::CHUNK ): array {
		$chunks = array();
		$size   = 0;
		$one    = array();
		foreach ( $texts as $key => $text ) {
			$len = mb_strlen( $text );
			if ( array() !== $one && $size + $len > $max ) {
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
