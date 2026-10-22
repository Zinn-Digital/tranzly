<?php
/**
 * Zinn® SEO's fields are translated with the post (zs-130), in every edition of Tranzly.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Seo;

use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Schema;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Integrations\Integration;
use ZinnDigital\Tranzly\Integrations\Placeholders;
use ZinnDigital\Tranzly\Integrations\Tree;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\Workflow\Staleness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The SEO title, the meta description, the titles and texts shown when a page is shared, the
 * focus and related phrases, the key takeaways and the breadcrumb title — of posts AND terms —
 * join the post's own segments, so they travel to the translation engine in the SAME request as
 * its title and content: no extra request per page, and nothing translated twice.
 *
 * ⭐ Zinn® SEO's template variables (`%title%`, `%sep%`, `%sitename%`, `%term(category)%`) are
 * never translated: each is masked before the engine sees the text and put back after
 * (Placeholders), so a translated title still resolves. A field that is ONLY variables holds no
 * words and is not sent at all.
 *
 * The translated value is written through Zinn® SEO's own API after WordPress saved the
 * translation, so Zinn® SEO cleans it by its own rules and everything that follows a change of
 * an SEO field (its llms.txt, its change history, the page cache) hears of it.
 *
 * Which fields exist is Zinn® SEO's to say: it answers `zinn_seo_translation_fields()`. The list
 * below is the same answer for a Zinn® SEO that does not have that function yet.
 */
final class Zinn_Seo_Fields extends Integration {

	/** Zinn® SEO's meta key prefix. */
	private const PREFIX = '_zseo_';

	/** Zinn® SEO's template variables. */
	private const VARIABLES = Zinn_Seo::VARIABLES;

	/**
	 * Fields with words in them: name => `template` (may hold variables), `line` or `text`
	 * (several lines).
	 */
	private const TRANSLATE = array(
		'post' => array(
			'title'     => 'template',
			'desc'      => 'template',
			'og_title'  => 'template',
			'og_desc'   => 'template',
			'x_title'   => 'template',
			'x_desc'    => 'template',
			'crumb'     => 'line',
			'focus'     => 'text',
			'related'   => 'line',
			'takeaways' => 'text',
		),
		'term' => array(
			'title'    => 'template',
			'desc'     => 'template',
			'og_title' => 'template',
			'og_desc'  => 'template',
			'x_title'  => 'template',
			'x_desc'   => 'template',
			'crumb'    => 'line',
		),
	);

	/** Settings a NEW translation takes from its original as they are (never translated). */
	private const COPY = array( 'robots', 'og_image', 'x_image', 'schema_page', 'schema_article', 'cornerstone', 'nopin', 'max_snippet', 'max_image', 'max_video' );

	/**
	 * Translated fields waiting for their post to be saved: translation ID => field => value.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static array $pending = array();

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'zinnseo';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Zinn® SEO';
	}

	/**
	 * Is Zinn® SEO active?
	 *
	 * @return bool
	 */
	public function available(): bool {
		return Zinn_Seo::active();
	}

	/**
	 * Feature.
	 *
	 * @return string
	 */
	public function feature(): string {
		return 'tz-c8';
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'tranzly_post_translated', array( self::class, 'flush' ), 20, 1 );
	}

	/**
	 * The fields of a kind of object, from Zinn® SEO when it says, else the list above.
	 *
	 * @param string $kind `post` or `term`.
	 * @return array{translate: array<string, string>, copy: array<int, string>}
	 */
	public static function fields( string $kind ): array {
		return self::checked( function_exists( 'zinn_seo_translation_fields' ) ? call_user_func( 'zinn_seo_translation_fields', $kind ) : null, $kind );
	}

	/**
	 * Zinn® SEO's answer, checked: only field names of its own shape and the three types this
	 * class knows are taken; an answer of another shape is not an answer, and the list above
	 * stands. Pure.
	 *
	 * @param mixed  $given What `zinn_seo_translation_fields()` returned, or null.
	 * @param string $kind  `post` or `term`.
	 * @return array{translate: array<string, string>, copy: array<int, string>}
	 */
	public static function checked( $given, string $kind ): array {
		if ( ! is_array( $given ) || ! is_array( $given['translate'] ?? null ) || ! is_array( $given['copy'] ?? null ) ) {
			return array(
				'translate' => self::TRANSLATE[ $kind ] ?? array(),
				'copy'      => 'post' === $kind ? self::COPY : array(),
			);
		}
		$out = array(
			'translate' => array(),
			'copy'      => array(),
		);
		foreach ( $given['translate'] as $name => $type ) {
			if ( is_string( $name ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,40}$/', $name ) && in_array( $type, array( 'template', 'line', 'text' ), true ) ) {
				$out['translate'][ $name ] = $type;
			}
		}
		foreach ( $given['copy'] as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,40}$/', $name ) ) {
				$out['copy'][] = $name;
			}
		}

		return $out;
	}

	/**
	 * Segments of a post.
	 *
	 * @param \WP_Post $post The original.
	 * @return array<string, array{text: string, format: string}>
	 */
	public function segments( \WP_Post $post ): array {
		$out = array();
		foreach ( self::fields( 'post' )['translate'] as $name => $type ) {
			$value = get_post_meta( (int) $post->ID, self::PREFIX . $name, true );
			if ( is_string( $value ) && Tree::has_words( self::without_variables( $value, $type ) ) ) {
				$out[ $name ] = self::segment( $value, $type );
			}
		}

		return $out;
	}

	/**
	 * Keep a post's translated fields until WordPress has saved the translation.
	 *
	 * @param array<string, mixed>  $update The update.
	 * @param array<string, string> $texts  Field => translation.
	 * @param \WP_Post              $post   The original.
	 * @param string                $lang   Language.
	 * @return array<string, mixed>
	 */
	public function write( array $update, array $texts, \WP_Post $post, string $lang ): array {
		unset( $lang );
		$target = (int) ( $update['ID'] ?? 0 );
		foreach ( self::fields( 'post' )['translate'] as $name => $type ) {
			if ( $target > 0 && isset( $texts[ $name ] ) ) {
				self::$pending[ $target ][ $name ] = self::restore( $texts[ $name ], (string) get_post_meta( (int) $post->ID, self::PREFIX . $name, true ), $type );
			}
		}

		return $update;
	}

	/**
	 * `tranzly_post_translated`: write the kept fields, through Zinn® SEO.
	 *
	 * @param int $target The translation.
	 * @return void
	 */
	public static function flush( $target ): void {
		$target = (int) $target;
		$values = self::$pending[ $target ] ?? array();
		unset( self::$pending[ $target ] );
		foreach ( $values as $name => $value ) {
			if ( ! Zinn_Seo::set_field( 'post', $target, (string) $name, $value ) ) {
				update_post_meta( $target, self::PREFIX . $name, wp_slash( $value ) );
			}
		}
	}

	/**
	 * `zinn_seo_translate_fields_step`: translations made BEFORE this version (or while this
	 * integration was switched off) catch up. One step looks at the next originals that have an
	 * SEO field with words and, for each of their translations that lacks that field, translates
	 * JUST the missing fields and writes them. A translation's content, and any field it already
	 * has, is never touched — so it is safe on pages a person edited.
	 *
	 * ⭐ Resumable and uncapped: the caller passes the last original it was given (`after`) and
	 * asks again until `next` is null; a site with a hundred thousand originals takes as many
	 * steps as it takes. The fields of one original go to the engine for ALL its languages
	 * together when the engine batches. When no engine can take the work (none set up, a spending
	 * cap reached, the service down) the step stops BEFORE that original and says why in
	 * `error`, in the engine's own plain words: nothing is skipped, and it carries on from there.
	 *
	 * @param mixed $given Another listener's answer (handed on when there is one).
	 * @param mixed $after The last original ID of the previous step (0 to start).
	 * @param mixed $limit Originals to look at in this step.
	 * @return array{next: int|null, originals: int, filled: int, failed: int, error: string}|mixed
	 */
	public static function catch_up( $given = null, $after = 0, $limit = 10 ) {
		if ( is_array( $given ) || ! Zinn_Seo::active() || ! Schema::live() ) {
			return $given;
		}
		global $wpdb;
		$out    = array(
			'next'      => null,
			'originals' => 0,
			'filled'    => 0,
			'failed'    => 0,
			'error'     => '',
		);
		$fields = self::fields( 'post' )['translate'];
		$limit  = max( 1, min( 200, (int) $limit ) );
		if ( array() === $fields ) {
			return $out;
		}
		$keys = array_map( static fn( string $name ): string => self::PREFIX . $name, array_keys( $fields ) );
		$ids  = array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a cursor-driven maintenance pass.
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the values are passed as one array.
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one %s per key, built from literals only.
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ") AND meta_value <> '' AND post_id > %d ORDER BY post_id ASC LIMIT %d",
					array_merge( $keys, array( (int) $after, $limit ) )
				)
			)
		);
		Relations::prime( 'post', $ids );
		$done = (int) $after;
		foreach ( $ids as $id ) {
			$result = self::catch_up_one( $id, $fields );
			if ( is_wp_error( $result ) ) {
				$out['error'] = $result->get_error_message();
				$out['next']  = $done;
				return $out;
			}
			++$out['originals'];
			$out['filled'] += $result['filled'];
			$out['failed'] += $result['failed'];
			$done           = $id;
		}
		$out['next'] = count( $ids ) === $limit ? $done : null;

		return $out;
	}

	/**
	 * One original: fill the SEO fields its translations lack.
	 *
	 * @param int                   $id     A post with at least one SEO field.
	 * @param array<string, string> $fields Field name => type.
	 * @return array{filled: int, failed: int}|\WP_Error An error only when no engine can work.
	 */
	private static function catch_up_one( int $id, array $fields ) {
		$out   = array(
			'filled' => 0,
			'failed' => 0,
		);
		$group = Relations::translations( 'post', $id );
		$post  = get_post( $id );
		if ( count( $group ) < 2 || ! $post instanceof \WP_Post || ! Staleness::is_original( $id ) ) {
			return $out;
		}
		$source = array();
		foreach ( $fields as $name => $type ) {
			$value = get_post_meta( $id, self::PREFIX . $name, true );
			if ( is_string( $value ) && Tree::has_words( self::without_variables( $value, $type ) ) ) {
				$source[ $name ] = $value;
			}
		}
		$missing = self::missing( $id, $group, $source );
		if ( array() === $missing ) {
			return $out;
		}
		$own      = Languages::resolve( (string) Relations::language_of( 'post', $id ) ) ?? Languages::default_code();
		$segments = array();
		foreach ( $missing as $names ) {
			foreach ( $names as $name ) {
				$segments[ $name ] = self::segment( $source[ $name ], $fields[ $name ] );
			}
		}
		if ( count( $missing ) > 1 ) {
			// One request for every language at once, when the engine batches: the answers wait
			// for each language's own turn below. A failure here only means no saving.
			try {
				Translator::prefetch_segments( $segments, $own, array_map( 'strval', array_keys( $missing ) ), '', null, false );
			} catch ( \Throwable $crash ) {
				unset( $crash );
			}
		}
		foreach ( $missing as $lang => $names ) {
			$lang  = (string) $lang;
			$chain = Translator::chain( '', $lang );
			if ( is_wp_error( $chain ) ) {
				return $chain;
			}
			$by_format = array();
			foreach ( $names as $name ) {
				$by_format[ $segments[ $name ]['format'] ][ $name ] = $segments[ $name ]['text'];
			}
			$ran = Translator::through_chain( $chain, $by_format, $own, $lang, Translator::options_for( $lang, $chain[0] ) );
			if ( is_wp_error( $ran ) ) {
				return $ran;
			}
			$target = (int) $group[ $lang ];
			foreach ( $names as $name ) {
				$answer = (string) ( $ran['texts'][ $name ] ?? '' );
				$value  = '' === $answer ? '' : self::restore( $answer, $source[ $name ], $fields[ $name ] );
				if ( '' === $value ) {
					++$out['failed'];
					continue;
				}
				if ( ! Zinn_Seo::set_field( 'post', $target, $name, $value ) ) {
					update_post_meta( $target, self::PREFIX . $name, wp_slash( $value ) );
				}
				++$out['filled'];
			}
		}

		return $out;
	}

	/**
	 * Which of an original's SEO fields each of its translations lacks. Pure but for the reads.
	 *
	 * @param int                   $id     The original.
	 * @param array<string, int>    $group  Language => post ID (the original included).
	 * @param array<string, string> $source The original's fields with words: name => value.
	 * @return array<string, array<int, string>> Language => field names.
	 */
	public static function missing( int $id, array $group, array $source ): array {
		$out = array();
		foreach ( $group as $lang => $member ) {
			$member = (int) $member;
			if ( $member === $id || $member <= 0 || null === Languages::resolve( (string) $lang ) ) {
				continue;
			}
			$status = get_post_status( $member );
			if ( false === $status || 'trash' === $status ) {
				continue;
			}
			foreach ( array_keys( $source ) as $name ) {
				if ( '' === trim( (string) get_post_meta( $member, self::PREFIX . $name, true ) ) ) {
					$out[ (string) $lang ][] = (string) $name;
				}
			}
		}

		return $out;
	}

	/**
	 * Term segments.
	 *
	 * @param \WP_Term $term The original.
	 * @return array<string, array{text: string, format: string}>
	 */
	public function term_segments( \WP_Term $term ): array {
		$out = array();
		foreach ( self::fields( 'term' )['translate'] as $name => $type ) {
			$value = get_term_meta( (int) $term->term_id, self::PREFIX . $name, true );
			if ( is_string( $value ) && Tree::has_words( self::without_variables( $value, $type ) ) ) {
				$out[ $name ] = self::segment( $value, $type );
			}
		}

		return $out;
	}

	/**
	 * Write term fields.
	 *
	 * @param int                   $target The translated term.
	 * @param array<string, string> $texts  Field => translation.
	 * @param \WP_Term              $term   The original.
	 * @param string                $lang   Language.
	 * @return void
	 */
	public function term_write( int $target, array $texts, \WP_Term $term, string $lang ): void {
		unset( $lang );
		foreach ( self::fields( 'term' )['translate'] as $name => $type ) {
			if ( ! isset( $texts[ $name ] ) ) {
				continue;
			}
			$value = self::restore( $texts[ $name ], (string) get_term_meta( (int) $term->term_id, self::PREFIX . $name, true ), $type );
			if ( ! Zinn_Seo::set_field( 'term', $target, $name, $value ) ) {
				update_term_meta( $target, self::PREFIX . $name, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Settings copied onto a new translation.
	 *
	 * @param int $source_id The original.
	 * @return array<int, string>
	 */
	public function copy_meta( int $source_id ): array {
		unset( $source_id );

		return array_map( static fn( string $name ): string => self::PREFIX . $name, self::fields( 'post' )['copy'] );
	}

	/**
	 * A change of any translated field makes the translations out of date.
	 *
	 * @return array<int, string>
	 */
	public function source_meta(): array {
		return array_map( static fn( string $name ): string => self::PREFIX . $name, array_keys( self::fields( 'post' )['translate'] ) );
	}

	/**
	 * A segment: variables masked (sent as HTML), or plain text when the field has none.
	 *
	 * @param string $value The field.
	 * @param string $type  `template`, `line` or `text`.
	 * @return array{text: string, format: string}
	 */
	public static function segment( string $value, string $type ): array {
		if ( 'template' !== $type || ! Placeholders::has( $value, self::VARIABLES ) ) {
			return array(
				'text'   => $value,
				'format' => 'text',
			);
		}

		return array(
			'text'   => Placeholders::mask( $value, self::VARIABLES )[0],
			'format' => 'html',
		);
	}

	/**
	 * The translated field with its variables back.
	 *
	 * @param string $translation The engine's answer.
	 * @param string $source      The original field.
	 * @param string $type        `template`, `line` or `text`.
	 * @return string
	 */
	public static function restore( string $translation, string $source, string $type ): string {
		if ( 'text' === $type ) {
			return sanitize_textarea_field( $translation );
		}
		if ( 'template' !== $type || ! Placeholders::has( $source, self::VARIABLES ) ) {
			return sanitize_text_field( $translation );
		}

		return sanitize_text_field( Placeholders::unmask( $translation, Placeholders::mask( $source, self::VARIABLES )[1] ) );
	}

	/**
	 * A field without its variables (to decide whether it holds words at all).
	 *
	 * @param string $value Value.
	 * @param string $type  Type.
	 * @return string
	 */
	private static function without_variables( string $value, string $type ): string {
		return 'template' === $type ? (string) preg_replace( self::VARIABLES, ' ', $value ) : $value;
	}
}
