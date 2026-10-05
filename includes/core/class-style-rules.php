<?php
/**
 * Each language's own WordPress.org translation-team style rules, applied to every translation.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The MECHANICAL rules of each WordPress.org Polyglots team's style guide (quotation marks,
 * spacing around punctuation, apostrophes, the ellipsis, Japanese half-width rules, the case of a
 * pronoun of address), applied after EVERY engine — AI models and DeepL alike — so a French
 * translation reads `«&#8239;Enregistrer&#8239;»` and a Japanese one `WordPress の設定`, whichever
 * engine wrote it. Plus each team's register and conventions, given to AI models as guidance.
 *
 * ⭐ ONE source of truth: `data/style-rules.json` is a byte-identical copy of the Zinn platform's
 * `engine/engine/i18n/style_rules.json`, and this class is a line-for-line port of
 * `engine/engine/i18n/style_rules.py`. Both must pass the same fixture set
 * (`engine/engine/i18n/style_rules_fixtures.json`, run by pytest AND by PHPUnit), so the plugin and
 * the platform cannot drift apart.
 *
 * ⛔ Code is never touched: printf placeholders, `{name}` / `{{x}}` variables, HTML tags, the
 * contents of `<pre>`, `<code>`, `<script>`, `<style>`, `<kbd>` and `<samp>`, entities, URLs,
 * e-mail addresses, file names and code written in prose are swapped for private-use sentinels
 * before any rule runs and restored after.
 */
final class Style_Rules {

	/** A no-break space. */
	private const NBSP = "\u{00A0}";

	/** The first private-use code point a shielded span is replaced by. */
	private const SENTINEL_BASE = 0xE000;

	/** Characters that may follow a mark for it to be punctuation (not `.NET`, `.8`, a sentinel). */
	private const NOT_A_WORD_START = '(?![^\W_]|[\x{E000}-\x{F8FF}])';

	/** Units a number keeps a no-break space before (fr, de). */
	private const UNITS = '%|‰|€|EUR|kB|KB|MB|GB|TB|km|kg|px|ms';

	/** Arabic letters (U+0600–U+06FF, U+0750–U+077F). */
	private const ARABIC = '\x{0600}-\x{06FF}\x{0750}-\x{077F}';

	/**
	 * What is shielded from every rule. Mirrors `_PROTECTED` in style_rules.py exactly.
	 */
	private const PROTECTED = '~'
		. '<(pre|code|script|style|kbd|samp)\b[^>]*>(?s:.*?)</\1\s*>'
		. '|%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[sdfuxXboeEgGc%]'
		. '|\{\{[^{}]*\}\}|\{[A-Za-z0-9_.:-]*\}'
		. '|<[^<>]*>|&#?\w+;'
		. '|(?:https?://|www\.)[^\s<>"\']*?(?=[.,;:!?)]*(?:[\s<>"\']|$))'
		. '|[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\.[A-Za-z0-9.-]+'
		. '|(?<![A-Za-z0-9_-])[A-Za-z0-9_-]+\.(?:php|js|css|json|txt|html|xml|po|mo|zip)(?![A-Za-z0-9_])'
		. '|`[^`]*`|(?<![A-Za-z0-9_])[A-Za-z_][A-Za-z0-9_]*\([^()<>]*\)'
		. '|(?<![A-Za-z0-9_:-])[A-Za-z0-9_:-]+=(?:"[^"<>\n]*"|\'[^\'<>\n]*\')'
		. '|(?<![\w])[:;]-?[()DPp](?![\w])'
		. '~u';

	/** Locale => the rule's method. */
	private const RULES = array(
		'fr' => 'fr',
		'de' => 'de',
		'es' => 'es',
		'ru' => 'ru',
		'pl' => 'pl',
		'ro' => 'ro',
		'el' => 'el',
		'cs' => 'low_nine',
		'sr' => 'low_nine',
		'bg' => 'low_nine',
		'nl' => 'nl',
		'vi' => 'vi',
		'te' => 'te',
		'ar' => 'ar',
		'ja' => 'ja',
	);

	/**
	 * The shielded spans of the text being styled (ja spaces a shielded Latin word like `Node.js`).
	 *
	 * @var array<int, string>
	 */
	private static array $kept = array();

	/**
	 * The data file, decoded once.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $data = null;

	/**
	 * `$text` with `$locale`'s team rules applied; unchanged for a locale with none.
	 *
	 * @param string $locale A WordPress locale (`fr_FR`, `pt_BR`, `ja`) or a language code.
	 * @param string $text   The translation.
	 * @param string $source The source it was translated from (nl and vi follow its final stop).
	 * @return string
	 */
	public static function apply( string $locale, string $text, string $source = '' ): string {
		$base = self::base( $locale );
		if ( '' === $text || ! isset( self::RULES[ $base ] ) || ! self::enabled( $locale ) ) {
			return $text;
		}
		list( $shielded, $kept ) = self::shield( $text );
		list( $src_shielded )    = self::shield( $source );
		$previous                = self::$kept;
		self::$kept              = $kept;
		try {
			$method = 'rule_' . self::RULES[ $base ];
			$styled = 'low_nine' === self::RULES[ $base ]
				? self::pair_quotes( $shielded, '„', '“' )
				: self::$method( $shielded, $src_shielded );
		} finally {
			self::$kept = $previous;
		}

		return self::unshield( $styled, $kept );
	}

	/**
	 * Every answer of an engine call styled for its target (the source of each beside it).
	 *
	 * @param array<string, string> $answers key => translation.
	 * @param array<string, string> $sources key => source text.
	 * @param string                $locale  Target locale.
	 * @return array<string, string>
	 */
	public static function apply_all( array $answers, array $sources, string $locale ): array {
		foreach ( $answers as $key => $answer ) {
			$answers[ $key ] = self::apply( $locale, (string) $answer, (string) ( $sources[ $key ] ?? '' ) );
		}

		return $answers;
	}

	/**
	 * Are the rules on for this language? On by default; a site may turn them off.
	 *
	 * @param string $locale Target locale.
	 * @return bool
	 */
	private static function enabled( string $locale ): bool {
		/**
		 * Whether Tranzly applies the language's WordPress.org translation-team style rules
		 * (quotation marks, spacing around punctuation, apostrophes, ellipsis, Japanese spacing,
		 * pronouns of address) to each new translation. On by default.
		 *
		 * @param bool   $enabled Apply them.
		 * @param string $locale  The target locale.
		 */
		return (bool) apply_filters( 'tranzly_style_rules_enabled', true, $locale );
	}

	/**
	 * Does `$locale`'s team publish rules a program can apply?
	 *
	 * @param string $locale A locale.
	 * @return bool
	 */
	public static function has_rules( string $locale ): bool {
		return isset( self::RULES[ self::base( $locale ) ] );
	}

	/**
	 * The guidance an AI model is given for a language (the team's register and conventions), or
	 * an empty string. The language's own region entry (`pt_PT`) wins over its base (`pt`).
	 *
	 * @param string $locale Target locale.
	 * @return string
	 */
	public static function guidance( string $locale ): string {
		$row = self::entry( $locale );
		if ( null === $row || '' === trim( (string) ( $row['prompt'] ?? '' ) ) || ! self::enabled( $locale ) ) {
			return '';
		}

		return trim( (string) $row['prompt'] );
	}

	/**
	 * The team's guide a language's rules come from: URL and the date it was read.
	 *
	 * @param string $locale A locale.
	 * @return array{guide: string, accessed: string}|null
	 */
	public static function guide( string $locale ): ?array {
		$row = self::entry( $locale );
		if ( null === $row || ! is_string( $row['guide'] ?? null ) ) {
			return null;
		}

		return array(
			'guide'    => (string) $row['guide'],
			'accessed' => (string) ( $row['accessed'] ?? '' ),
		);
	}

	/**
	 * A locale's row of the data file: the region entry (`pt_PT`), else the base (`pt`).
	 *
	 * @param string $locale A locale.
	 * @return array<string, mixed>|null
	 */
	private static function entry( string $locale ): ?array {
		$all  = (array) ( self::data()['locales'] ?? array() );
		$norm = str_replace( '-', '_', $locale );
		foreach ( array( $norm, self::base( $locale ) ) as $key ) {
			if ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) {
				return $all[ $key ];
			}
		}

		return null;
	}

	/**
	 * The data file.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		if ( null === self::$data ) {
			$raw        = file_get_contents( __DIR__ . '/data/style-rules.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside this plugin.
			$decoded    = is_string( $raw ) ? json_decode( $raw, true ) : null;
			self::$data = is_array( $decoded ) ? $decoded : array();
		}

		return self::$data;
	}

	/**
	 * The base language of a locale: `fr_FR` → `fr`.
	 *
	 * @param string $locale A locale.
	 * @return string
	 */
	private static function base( string $locale ): string {
		return strtolower( explode( '_', str_replace( '-', '_', $locale ) )[0] );
	}

	// ── shielding ─────────────────────────────────────────────────────────────────────────.

	/**
	 * The text with every protected span replaced by a sentinel, and the spans.
	 *
	 * @param string $text Text.
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function shield( string $text ): array {
		$kept = array();
		$out  = preg_replace_callback(
			self::PROTECTED,
			static function ( array $m ) use ( &$kept ): string {
				$kept[] = $m[0];
				return mb_chr( self::SENTINEL_BASE + count( $kept ) - 1, 'UTF-8' );
			},
			$text
		);

		return array( null === $out ? $text : $out, $kept );
	}

	/**
	 * The spans put back.
	 *
	 * @param string             $text Text with sentinels.
	 * @param array<int, string> $kept The spans.
	 * @return string
	 */
	private static function unshield( string $text, array $kept ): string {
		if ( array() === $kept ) {
			return $text;
		}
		$out = preg_replace_callback(
			'/[\x{E000}-\x{F8FF}]/u',
			static function ( array $m ) use ( $kept ): string {
				$i = mb_ord( $m[0], 'UTF-8' ) - self::SENTINEL_BASE;
				return $i >= 0 && $i < count( $kept ) ? $kept[ $i ] : $m[0];
			},
			$text
		);

		return null === $out ? $text : $out;
	}

	/**
	 * Is `$ch` a sentinel?
	 *
	 * @param string $ch One character.
	 * @return bool
	 */
	private static function is_sentinel( string $ch ): bool {
		if ( 1 !== mb_strlen( $ch ) ) {
			return false;
		}
		$o = mb_ord( $ch, 'UTF-8' );

		return $o >= 0xE000 && $o <= 0xF8FF;
	}

	// ── shared mechanics ──────────────────────────────────────────────────────────────────.

	/**
	 * `preg_replace` with /u that never returns null (a bad UTF-8 input is left as it was).
	 *
	 * @param string          $pattern     Pattern.
	 * @param string|callable $replacement Replacement or callback.
	 * @param string          $text        Subject.
	 * @return string
	 */
	private static function re( string $pattern, $replacement, string $text ): string {
		$out = is_callable( $replacement ) && ! is_string( $replacement )
			? preg_replace_callback( $pattern, $replacement, $text )
			: preg_replace( $pattern, (string) $replacement, $text );

		return null === $out ? $text : $out;
	}

	/**
	 * Strip spaces and no-break spaces from both ends.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function strip_spaces( string $text ): string {
		return self::re( '/^[ \x{00A0}]+|[ \x{00A0}]+$/u', '', $text );
	}

	/**
	 * Straight `"…"` and English `“…”` pairs become the locale's own marks — only when the straight
	 * quotes PAIR (an even count). See `_pair_quotes` in style_rules.py.
	 *
	 * @param string $text        Text.
	 * @param string $open_q      Opening mark.
	 * @param string $close_q     Closing mark.
	 * @param string $inner_space Space inside the marks (fr: a no-break space).
	 * @return string
	 */
	private static function pair_quotes( string $text, string $open_q, string $close_q, string $inner_space = '' ): string {
		$wrap = static function ( string $body ) use ( $open_q, $close_q, $inner_space ): string {
			$body = '' !== $inner_space ? self::strip_spaces( $body ) : $body;
			return $open_q . $inner_space . $body . $inner_space . $close_q;
		};

		$held = array();
		if ( '„' === $open_q ) {
			// ⛔ In de/cs/sr/bg `“` CLOSES a pair: a low-9 pair is normalised to the locale's own
			// close and held aside while the English and straight pairs are converted.
			$text = self::re( '/„([^„“”]*)[”“]/u', static fn( array $m ): string => '„' . $m[1] . $close_q, $text );
			$text = self::re(
				'/„[^„“”]*' . preg_quote( $close_q, '/' ) . '/u',
				static function ( array $m ) use ( &$held ): string {
					$held[] = $m[0];
					return "\x00" . ( count( $held ) - 1 ) . "\x00";
				},
				$text
			);
		}
		$text  = self::re( '/“([^“”]*)”/u', static fn( array $m ): string => $wrap( $m[1] ), $text );
		$count = substr_count( $text, '"' );
		if ( $count > 0 && 0 === $count % 2 ) {
			$parts = explode( '"', $text );
			$out   = $parts[0];
			for ( $i = 1, $n = count( $parts ); $i < $n; $i += 2 ) {
				$out .= $wrap( $parts[ $i ] ) . $parts[ $i + 1 ];
			}
			$text = $out;
		}

		return self::re( '/\x00(\d+)\x00/', static fn( array $m ): string => $held[ (int) $m[1] ], $text );
	}

	/**
	 * Three full stops are one ellipsis character (fr, pl, ro).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function ellipsis( string $text ): string {
		return self::re( '/(?<!\.)\.\.\.(?!\.)/u', '…', $text );
	}

	/**
	 * No space before these marks.
	 *
	 * @param string $text  Text.
	 * @param string $marks The marks.
	 * @return string
	 */
	private static function no_space_before( string $text, string $marks ): string {
		return self::re( '/[ \t\x{00A0}]+([' . preg_quote( $marks, '/' ) . '])' . self::NOT_A_WORD_START . '/u', '$1', $text );
	}

	/**
	 * Strip trailing white space (Unicode).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function rstrip( string $text ): string {
		return self::re( '/\s+$/uD', '', $text );
	}

	/**
	 * The translation ends as its source ends (nl, vi).
	 *
	 * @param string $text      Translation.
	 * @param string $source    Source.
	 * @param string $terminals Sentence-ending marks.
	 * @return string
	 */
	private static function mirror_final_stop( string $text, string $source, string $terminals = '.!?' ): string {
		if ( '' === $text || '' === $source ) {
			return $text;
		}
		$src      = self::rstrip( $source );
		$src_end  = '' === self::re( '/^\s+/u', '', $src ) ? '' : mb_substr( $src, -1 );
		$stripped = self::rstrip( $text );
		$tail     = (string) substr( $text, strlen( $stripped ) );
		$last     = mb_substr( $stripped, -1 );
		// A one-word value ending in `.` is an abbreviation (`mnd.`), not a sentence stop.
		$abbreviation = false === strpos( self::re( '/^\s+|\s+$/uD', '', $stripped ), ' ' );
		// As in Python, an empty end counts as "in" the terminals (`"" in ".!?"` is true).
		$is_terminal = '' === $src_end || false !== mb_strpos( $terminals, $src_end );
		if ( ! $is_terminal && '.' === $last && '…' !== $last && ! $abbreviation ) {
			return mb_substr( $stripped, 0, -1 ) . $tail;
		}
		if ( $is_terminal && '' !== $stripped && false === mb_strpos( $terminals . '…:)"»”“', $last ) ) {
			return $stripped . $src_end . $tail;
		}

		return $text;
	}

	/**
	 * A capitalised pronoun of address becomes lower case unless it starts a sentence.
	 *
	 * @param string             $text  Text.
	 * @param array<int, string> $words The pronouns.
	 * @return string
	 */
	private static function lowercase_midsentence( string $text, array $words ): string {
		if ( array() === $words ) {
			return $text;
		}
		$quoted  = array_map( static fn( string $w ): string => preg_quote( $w, '/' ), $words );
		$pattern = '/(?<![\w])(' . implode( '|', $quoted ) . ')(?![\w])/u';
		$out     = preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( $text ): string {
				$word   = $m[0][0];
				$before = self::re( '/[ \x{00A0}]+$/uD', '', (string) substr( $text, 0, (int) $m[0][1] ) );
				$last   = mb_substr( $before, -1 );
				if ( '' === $before || false !== mb_strpos( ".!?…:\n«„\"“(", $last ) || self::is_sentinel( $last ) ) {
					return $word;
				}
				return mb_strtolower( mb_substr( $word, 0, 1 ) ) . mb_substr( $word, 1 );
			},
			$text,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);

		return null === $out ? $text : $out;
	}

	/**
	 * The pronouns of address of a language, from the data file.
	 *
	 * @param string $lang Language.
	 * @return array<int, string>
	 */
	private static function pronouns( string $lang ): array {
		return array_values( array_map( 'strval', (array) ( self::data()['address_pronouns'][ $lang ] ?? array() ) ) );
	}

	// ── per-locale rules (each cites its team's guide in the data file) ──────────────────.

	/**
	 * French: « » with no-break spaces, a no-break space before : ; ? ! » and units, ’, ….
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_fr( string $text, string $source ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- one signature for every rule.
		$n    = self::NBSP;
		$text = self::ellipsis( $text );
		$text = self::re( "/(?<=[^\\W\\d_])'(?=[^\\W\\d_])/u", '’', $text );
		$text = self::pair_quotes( $text, '«', '»', $n );
		$text = self::no_space_before( $text, '.,)]' );
		$text = self::re( '/[ \x{00A0}]*([;?!»])/u', $n . '$1', $text );
		$text = self::re( '/(«)[ \x{00A0}]*/u', '$1' . $n, $text );
		// A colon gets its space only as punctuation (`Note : …`), never `10:30` or `a:b`.
		$text = self::re( '/(?<=[^\s\d\x{00A0}])[ \x{00A0}]*:(?=\s|$)/u', $n . ':', $text );
		$text = self::re( '/(\d)[ \x{00A0}]?(' . self::UNITS . ')(?![\w])/u', '$1' . $n . '$2', $text );

		return self::re( '/^\x{00A0}+/u', '', $text );
	}

	/**
	 * German: „“, a no-break space between a number and % or a unit, and before a dash.
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_de( string $text, string $source ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- one signature for every rule.
		$n    = self::NBSP;
		$text = self::pair_quotes( $text, '„', '“' );
		$text = self::re( '/(\d)[ \x{00A0}]?(' . self::UNITS . ')(?![\w])/u', '$1' . $n . '$2', $text );

		return self::re( '/(\S)[ \x{00A0}]–(?= )/u', '$1' . $n . '–', $text );
	}

	/**
	 * Spanish: «».
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_es( string $text, string $source ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- one signature for every rule.
		return self::pair_quotes( $text, '«', '»' );
	}

	/**
	 * Russian: «», lower-case «вы».
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_ru( string $text, string $source ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- one signature for every rule.
		return self::lowercase_midsentence( self::pair_quotes( $text, '«', '»' ), self::pronouns( 'ru' ) );
	}

	/**
	 * Polish: „”, …, lower-case pronouns.
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_pl( string $text, string $source ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- one signature for every rule.
		$text = self::pair_quotes( self::ellipsis( $text ), '„', '”' );

		return self::lowercase_midsentence( $text, self::pronouns( 'pl' ) );
	}

	/**
	 * Romanian: comma-below ș ț, …, „”, no space before punctuation.
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_ro( string $text, string $source ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- one signature for every rule.
		$map  = array_map( 'strval', (array) ( self::data()['ro_diacritics'] ?? array() ) );
		$text = strtr( $text, $map );
		$text = self::pair_quotes( self::ellipsis( $text ), '„', '”' );

		return self::no_space_before( $text, '.!?,;:…)' );
	}

	/**
	 * Greek: «», no space before ; : ! ?.
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_el( string $text, string $source ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- one signature for every rule.
		return self::no_space_before( self::pair_quotes( $text, '«', '»' ), ';:!?' );
	}

	/**
	 * Dutch: 10%, and the source's final stop.
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_nl( string $text, string $source ): string {
		return self::mirror_final_stop( self::re( '/(\d)[ \x{00A0}]+%/u', '$1%', $text ), $source );
	}

	/**
	 * Vietnamese: the source's final punctuation.
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_vi( string $text, string $source ): string {
		return self::mirror_final_stop( $text, $source );
	}

	/**
	 * Telugu: no space before punctuation, no double spaces.
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_te( string $text, string $source ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- one signature for every rule.
		return self::re( '/(?<=\S) {2,}(?=\S)/u', ' ', self::no_space_before( $text, '.,?!:;' ) );
	}

	/**
	 * Arabic: the Arabic comma in running prose, no space before punctuation.
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_ar( string $text, string $source ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- one signature for every rule.
		$text = self::re( '/(?<=[' . self::ARABIC . '])[ \t]*,(?=\s)/u', '،', $text );

		return self::re( '/(?<=[' . self::ARABIC . '])[ \t]+([،؛؟!.:])' . self::NOT_A_WORD_START . '/u', '$1', $text );
	}

	/**
	 * A Japanese letter (kana, kanji, half-width katakana)?
	 *
	 * @param string $ch One character.
	 * @return bool
	 */
	private static function ja_letter( string $ch ): bool {
		if ( 1 !== mb_strlen( $ch ) ) {
			return false;
		}
		$o = mb_ord( $ch, 'UTF-8' );

		return ( $o >= 0x3040 && $o <= 0x30FF ) || ( $o >= 0x3400 && $o <= 0x4DBF ) || ( $o >= 0x4E00 && $o <= 0x9FFF ) || ( $o >= 0xF900 && $o <= 0xFAFF ) || ( $o >= 0xFF66 && $o <= 0xFF9F );
	}

	/**
	 * An ASCII letter?
	 *
	 * @param string $ch One character.
	 * @return bool
	 */
	private static function latin( string $ch ): bool {
		return 1 === strlen( $ch ) && ctype_alpha( $ch );
	}

	/**
	 * White space?
	 *
	 * @param string $ch One character.
	 * @return bool
	 */
	private static function is_space( string $ch ): bool {
		return '' !== $ch && 1 === preg_match( '/^\s$/u', $ch );
	}

	/**
	 * The Latin word a sentinel stands for, or "" for a placeholder, tag, entity or code.
	 *
	 * @param string $ch One character.
	 * @return string
	 */
	private static function shielded_word( string $ch ): string {
		if ( ! self::is_sentinel( $ch ) ) {
			return '';
		}
		$i    = mb_ord( $ch, 'UTF-8' ) - self::SENTINEL_BASE;
		$word = self::$kept[ $i ] ?? '';

		return '' === $word || false !== strpos( '%<&{`', $word[0] ) ? '' : $word;
	}

	/**
	 * Does the character start a Latin word (itself, or the word it shields)?
	 *
	 * @param string $ch One character.
	 * @return bool
	 */
	private static function latin_start( string $ch ): bool {
		$word = self::shielded_word( $ch );

		return self::latin( $ch ) || ( '' !== $word && self::latin( $word[0] ) );
	}

	/**
	 * Does the character end a Latin word?
	 *
	 * @param string $ch One character.
	 * @return bool
	 */
	private static function latin_end( string $ch ): bool {
		$word = self::shielded_word( $ch );

		return self::latin( $ch ) || ( '' !== $word && self::latin( substr( $word, -1 ) ) );
	}

	/**
	 * Japanese: half-width : ? ! and ( ), a half-width space between Japanese and Latin, none
	 * around 「」『』。、 nor inside parentheses.
	 *
	 * @param string $text   Text.
	 * @param string $source Source.
	 * @return string
	 */
	private static function rule_ja( string $text, string $source ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- one signature for every rule.
		$text  = str_replace( array( '（', '）' ), array( '(', ')' ), $text );
		$chars = mb_str_split( $text, 1, 'UTF-8' );
		$out   = array();
		$n     = count( $chars );
		foreach ( $chars as $i => $ch ) {
			$prev = array() === $out ? '' : $out[ count( $out ) - 1 ];
			$nxt  = $i + 1 < $n ? $chars[ $i + 1 ] : '';
			if ( '：' === $ch || ( ':' === $ch && ( self::ja_letter( $prev ) || self::ja_letter( $nxt ) || ' ' === $prev ) ) ) {
				$len = count( $out );
				while ( $len > 1 && ' ' === $out[ $len - 1 ] && ! self::is_space( $out[ $len - 2 ] ) ) {
					array_pop( $out );
					--$len;
				}
				$out[] = ':';
				if ( '' !== $nxt && ' ' !== $nxt && "\n" !== $nxt ) {
					$out[] = ' ';
				}
				continue;
			}
			if ( '？' === $ch || '！' === $ch || ( ( '?' === $ch || '!' === $ch ) && ( self::ja_letter( $prev ) || in_array( $prev, array( '」', '）', ')' ), true ) ) ) ) {
				if ( '' !== $prev && ' ' !== $prev && "\n" !== $prev ) {
					$out[] = ' ';
				}
				$out[] = ( '?' === $ch || '？' === $ch ) ? '?' : '!';
				continue;
			}
			if ( '(' === $ch && '' !== $prev && ! self::is_space( $prev ) && ! in_array( $prev, array( '「', '『', '（', '(' ), true ) && $i > 0 ) {
				$out[] = ' ';
			}
			if ( ')' === $prev && ! in_array( $ch, array( ' ', "\n", '。', '、', '」', '』', ')' ), true ) && ( self::ja_letter( $ch ) || self::latin( $ch ) ) ) {
				$out[] = ' ';
			}
			if ( '' !== $prev && ( ( self::ja_letter( $prev ) && self::latin_start( $ch ) ) || ( self::latin_end( $prev ) && self::ja_letter( $ch ) ) ) ) {
				$out[] = ' ';
			}
			$out[] = $ch;
		}
		$text = implode( '', $out );
		$text = self::re( '/[ ]+([、。「」『』])/u', '$1', $text );
		$text = self::re( '/([、。「」『』])[ ]+/u', '$1', $text );
		$text = self::re( '/\( +/u', '(', $text );
		$text = self::re( '/ +\)/u', ')', $text );

		return self::re( '/: {2,}/u', ': ', $text );
	}
}
