<?php
/**
 * DeepL, with the site owner's own key — the integration Tranzly always had, kept and modernised.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Engines;

use ZinnDigital\Tranzly\Core\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DeepL (tz-e1). What changed from the legacy integration (11-audit-tranzly.md §3):
 *
 * - `POST` with a JSON body and the key in the `Authorization` header, not a `GET` with the key
 *   and the whole text in the URL (which leaked both into access logs and truncated long text);
 * - Free keys work: a key ending `:fx` is a DeepL API Free key and goes to `api-free.deepl.com`
 *   (the legacy plugin only knew the Pro host, so Free keys always failed);
 * - the language list comes from DeepL's own `/v2/languages`, cached for a week, instead of a
 *   fixed nine; regional targets (EN-GB/EN-US, PT-PT/PT-BR, ZH-HANS/ZH-HANT) follow the WordPress
 *   locale;
 * - HTML-aware (`tag_handling: html`), formality, a do-not-translate list (as ignored tags), and
 *   with Pro a glossary created in the owner's DeepL account per language pair;
 * - up to 50 texts per request, far fewer requests than the legacy one-request-per-chunk loop.
 *
 * Price: DeepL's paid API plan is now "Growth": US$27.50 per million characters beyond the 1
 * million it includes (deepl.com/en/pro#api with the US selected, checked 2026-10-04; in Germany
 * the same plan is €22 per million — packageId `api-growth`, `usageBasedPrice` 2200 cents per
 * 1,000,000, read from the page's own data the same day). The "API Pro" $25 it replaced is no
 * longer sold. A published price, not one measured on an account (§2.45). Free keys estimate at
 * $0. The `tranzly_deepl_price_per_million` filter overrides it.
 */
final class DeepL implements Engine {

	/** The secret name. */
	public const SECRET = 'deepl';

	/** US dollars per million characters: DeepL's Growth plan beyond its included million (see above). */
	public const PRICE_PER_MILLION = 27.5;

	/** Tag wrapped around do-not-translate words, listed in `ignore_tags`. */
	private const KEEP = 'zdkeep';

	/** DeepL's limit of texts per request. */
	private const PER_REQUEST = 50;

	/**
	 * Engine id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'deepl';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'DeepL';
	}

	/**
	 * Is a key saved?
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return null !== Secrets::get( self::SECRET );
	}

	/**
	 * Translate.
	 *
	 * @param array<string, string> $texts   key => text.
	 * @param string                $source  Source locale.
	 * @param string                $target  Target locale.
	 * @param array<string, mixed>  $options `format`, `do_not_translate`, `formality`, `glossary`.
	 * @return array<string, string>|\WP_Error
	 */
	public function translate( array $texts, string $source, string $target, array $options = array() ) {
		$key = Secrets::get( self::SECRET );
		if ( null === $key ) {
			return Failure::make( Failure::CREDENTIAL, $this, 'no key saved', admin_url( 'admin.php?page=tranzly#/engines' ) );
		}
		$target_code = $this->target_code( $target, $key );
		if ( is_wp_error( $target_code ) ) {
			return $target_code;
		}
		$source_code = strtoupper( (string) preg_replace( '/[_-].*$/', '', $source ) );

		$html = 'html' === ( $options['format'] ?? 'text' );
		$dnt  = array_values( array_filter( array_map( 'strval', (array) ( $options['do_not_translate'] ?? array() ) ) ) );
		$body = array(
			'source_lang' => $source_code,
			'target_lang' => $target_code,
		);
		if ( $html || array() !== $dnt ) {
			$body['tag_handling'] = $html ? 'html' : 'xml';
		}
		if ( array() !== $dnt ) {
			$body['ignore_tags'] = array( self::KEEP );
		}
		$formality = (string) ( $options['formality'] ?? 'default' );
		if ( in_array( $formality, array( 'more', 'less' ), true ) ) {
			$body['formality'] = 'prefer_' . $formality; // `prefer_` never errors on a language without formality.
		}
		$glossary = (array) ( $options['glossary'] ?? array() );
		if ( array() !== $glossary ) {
			$glossary_id = $this->glossary_id( $key, $source_code, $target_code, $glossary );
			if ( is_string( $glossary_id ) ) {
				$body['glossary_id'] = $glossary_id;
			}
		}

		$keys = array_keys( $texts );
		$out  = array();
		foreach ( array_chunk( $keys, self::PER_REQUEST ) as $chunk ) {
			$payload = array();
			foreach ( $chunk as $k ) {
				$payload[] = $this->protect( (string) $texts[ $k ], $dnt, $html );
			}
			$response = $this->request( $key, 'POST', '/v2/translate', $body + array( 'text' => $payload ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$translations = (array) ( $response['translations'] ?? array() );
			if ( count( $translations ) !== count( $chunk ) ) {
				return Failure::make( Failure::UNKNOWN, $this, 'DeepL returned ' . count( $translations ) . ' translations for ' . count( $chunk ) . ' texts' );
			}
			foreach ( $chunk as $i => $k ) {
				$out[ $k ] = $this->unprotect( (string) ( $translations[ $i ]['text'] ?? '' ), $dnt, $html );
			}
		}

		return $out;
	}

	/**
	 * Estimate.
	 *
	 * @param array<string, string> $texts  key => text.
	 * @param string                $target Target locale.
	 * @return array{characters: int, cost_usd: float|null}
	 */
	public function estimate( array $texts, string $target ): array {
		$characters = (int) array_sum( array_map( 'mb_strlen', $texts ) );
		$key        = (string) Secrets::get( self::SECRET );

		/**
		 * Filters DeepL's price per million characters in US dollars.
		 *
		 * @param float $price 27.5 (DeepL Growth, published; Free keys estimate at 0).
		 */
		$price = str_ends_with( $key, ':fx' ) ? 0.0 : (float) apply_filters( 'tranzly_deepl_price_per_million', self::PRICE_PER_MILLION );

		return array(
			'characters' => $characters,
			'cost_usd'   => round( $characters * $price / 1000000, 6 ),
		);
	}

	/**
	 * The account's usage this billing period: characters used and the limit, or an error.
	 *
	 * @return array{used: int, limit: int}|\WP_Error
	 */
	public function usage() {
		$key = Secrets::get( self::SECRET );
		if ( null === $key ) {
			return Failure::make( Failure::CREDENTIAL, $this, 'no key saved' );
		}
		$response = $this->request( $key, 'GET', '/v2/usage' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'used'  => (int) ( $response['character_count'] ?? 0 ),
			'limit' => (int) ( $response['character_limit'] ?? 0 ),
		);
	}

	/**
	 * DeepL's target code for a WordPress locale, from DeepL's own list.
	 *
	 * @param string $locale A locale.
	 * @param string $key    The API key.
	 * @return string|\WP_Error
	 */
	public function target_code( string $locale, string $key ) {
		$supported = $this->targets( $key );
		if ( is_wp_error( $supported ) ) {
			return $supported;
		}
		$lower   = strtolower( str_replace( '_', '-', $locale ) );
		$primary = (string) preg_replace( '/-.*$/', '', $lower );
		$aliases = array(
			'zh-cn' => 'zh-hans',
			'zh-sg' => 'zh-hans',
			'zh-tw' => 'zh-hant',
			'zh-hk' => 'zh-hant',
			'nb-no' => 'nb',
			'nn-no' => 'nb',
			'en'    => 'en-us',
			'pt'    => 'pt-pt',
		);
		foreach ( array( $lower, $aliases[ $lower ] ?? '', $aliases[ $primary ] ?? '', $primary ) as $candidate ) {
			if ( '' !== $candidate && isset( $supported[ $candidate ] ) ) {
				return $supported[ $candidate ];
			}
		}

		return Failure::make( Failure::UNSUPPORTED, $this, $locale );
	}

	/**
	 * DeepL's target languages: lowercase code => DeepL's code. Cached for a week.
	 *
	 * @param string $key The API key.
	 * @return array<string, string>|\WP_Error
	 */
	private function targets( string $key ) {
		$cached = get_transient( 'tranzly_deepl_targets' );
		if ( is_array( $cached ) && array() !== $cached ) {
			return $cached;
		}
		$response = $this->request( $key, 'GET', '/v2/languages?type=target' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$out = array();
		foreach ( $response as $row ) {
			if ( is_array( $row ) && isset( $row['language'] ) ) {
				$out[ strtolower( (string) $row['language'] ) ] = (string) $row['language'];
			}
		}
		if ( array() !== $out ) {
			set_transient( 'tranzly_deepl_targets', $out, WEEK_IN_SECONDS );
		}

		return $out;
	}

	/**
	 * A glossary in the owner's DeepL account for this language pair and these entries, created
	 * once and reused while the entries stay the same. Null when DeepL cannot make one (not every
	 * pair supports glossaries): the translation goes ahead without it.
	 *
	 * @param string                $key     The API key.
	 * @param string                $source  DeepL source code.
	 * @param string                $target  DeepL target code.
	 * @param array<string, string> $entries source => target.
	 * @return string|null
	 */
	private function glossary_id( string $key, string $source, string $target, array $entries ): ?string {
		ksort( $entries );
		$fingerprint = md5( $source . '|' . $target . '|' . wp_json_encode( $entries ) );
		$known       = get_option( 'tranzly_deepl_glossaries', array() );
		$known       = is_array( $known ) ? $known : array();
		if ( isset( $known[ $fingerprint ] ) ) {
			return (string) $known[ $fingerprint ];
		}
		$tsv = '';
		foreach ( $entries as $from => $to ) {
			$tsv .= str_replace( array( "\t", "\n" ), ' ', (string) $from ) . "\t" . str_replace( array( "\t", "\n" ), ' ', (string) $to ) . "\n";
		}
		$made = $this->request(
			$key,
			'POST',
			'/v2/glossaries',
			array(
				'name'           => 'Tranzly ' . $source . '-' . $target,
				'source_lang'    => strtolower( $source ),
				'target_lang'    => strtolower( (string) preg_replace( '/-.*$/', '', $target ) ),
				'entries'        => $tsv,
				'entries_format' => 'tsv',
			)
		);
		if ( is_wp_error( $made ) || empty( $made['glossary_id'] ) ) {
			return null;
		}
		$known[ $fingerprint ] = (string) $made['glossary_id'];
		update_option( 'tranzly_deepl_glossaries', array_slice( $known, -50, null, true ), false );

		return (string) $made['glossary_id'];
	}

	/**
	 * Wrap do-not-translate words in the ignored tag (escaping plain text for XML first).
	 *
	 * @param string             $text The text.
	 * @param array<int, string> $dnt  Words.
	 * @param bool               $html Is the text HTML.
	 * @return string
	 */
	private function protect( string $text, array $dnt, bool $html ): string {
		if ( array() === $dnt ) {
			return $text;
		}
		if ( ! $html ) {
			$text = htmlspecialchars( $text, ENT_NOQUOTES | ENT_XML1, 'UTF-8' );
		}
		foreach ( $dnt as $word ) {
			$escaped = $html ? $word : htmlspecialchars( $word, ENT_NOQUOTES | ENT_XML1, 'UTF-8' );
			$text    = (string) preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $escaped, '/' ) . '(?![\p{L}\p{N}])/u', '<' . self::KEEP . '>$0</' . self::KEEP . '>', $text );
		}

		return $text;
	}

	/**
	 * Remove the ignored tag again.
	 *
	 * @param string             $text The translation.
	 * @param array<int, string> $dnt  Words.
	 * @param bool               $html Is the text HTML.
	 * @return string
	 */
	private function unprotect( string $text, array $dnt, bool $html ): string {
		if ( array() === $dnt ) {
			return $text;
		}
		$text = str_replace( array( '<' . self::KEEP . '>', '</' . self::KEEP . '>' ), '', $text );

		return $html ? $text : htmlspecialchars_decode( $text, ENT_NOQUOTES | ENT_XML1 );
	}

	/**
	 * One API call, with errors classified.
	 *
	 * @param string               $key    The API key.
	 * @param string               $method GET or POST.
	 * @param string               $path   Path under the API host.
	 * @param array<string, mixed> $body   JSON body for POST.
	 * @return array<mixed>|\WP_Error
	 */
	private function request( string $key, string $method, string $path, array $body = array() ) {
		$host = str_ends_with( $key, ':fx' ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';

		/**
		 * Filters the DeepL API host (a proxy, or a test double).
		 *
		 * @param string $host The host for this key.
		 * @param string $key  The key (never log it).
		 */
		$host = (string) apply_filters( 'tranzly_deepl_host', $host, $key );
		$args = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'DeepL-Auth-Key ' . $key,
				'Content-Type'  => 'application/json',
			),
		);
		if ( 'POST' === $method ) {
			$args['body'] = (string) wp_json_encode( $body );
		}
		$response = wp_remote_request( $host . $path, $args );
		if ( is_wp_error( $response ) ) {
			return Failure::make( Failure::UNREACHABLE, $this, $response->get_error_message() );
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		if ( $code >= 200 && $code < 300 && is_array( $decoded ) ) {
			return $decoded;
		}
		$detail = is_array( $decoded ) ? (string) ( $decoded['message'] ?? $raw ) : $raw;
		switch ( true ) {
			case 403 === $code:
				return Failure::make( Failure::CREDENTIAL, $this, $detail, 'https://www.deepl.com/your-account/keys' );
			case 456 === $code:
				return Failure::make( Failure::QUOTA, $this, $detail, 'https://www.deepl.com/your-account/usage' );
			case 429 === $code:
				return Failure::make( Failure::RATE_LIMITED, $this, $detail );
			case 413 === $code:
				return Failure::make( Failure::TOO_LONG, $this, $detail );
			case 400 === $code && false !== stripos( $detail, 'lang' ):
				return Failure::make( Failure::UNSUPPORTED, $this, $detail );
			case $code >= 500:
				return Failure::make( Failure::OUTAGE, $this, $detail, 'https://www.deeplstatus.com' );
			default:
				return Failure::make( Failure::UNKNOWN, $this, 'HTTP ' . $code . ': ' . $detail );
		}
	}
}
