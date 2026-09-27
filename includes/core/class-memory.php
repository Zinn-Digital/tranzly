<?php
/**
 * Translation memory: a text translated once is never sent to an engine again.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; every read is a primary-key lookup made only while translating.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation memory (tz-e7, Pro): "never pay twice". A memory entry is keyed by a hash of the source text, its format,
 * both languages AND the glossary/tone in force for the target — so changing the glossary makes
 * the old answers stale rather than silently reused.
 *
 * ⭐ Two workers translating the same text at once must not BOTH pay for it. A miss is RESERVED
 * first (a `pending` row, `INSERT IGNORE` on the primary key, so exactly one worker wins); the
 * loser is told the text is in flight and its item waits for the winner's answer. A reservation
 * whose worker died expires after five minutes and can be taken over.
 */
final class Memory {

	/** How long a reservation holds, in seconds. */
	private const RESERVATION = 300;

	/**
	 * Is the memory on? Pro, adjustable with `tranzly_memory_enabled`.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether translation memory is used.
		 *
		 * @param bool $on True with Pro.
		 */
		return (bool) apply_filters( 'tranzly_memory_enabled', Edition::pro() );
	}

	/**
	 * The memory key of one text.
	 *
	 * @param string               $text    Source text.
	 * @param string               $format  `text` or `html`.
	 * @param string               $source  Source language.
	 * @param string               $target  Target language.
	 * @param array<string, mixed> $options The engine options in force (glossary, tone).
	 * @return string
	 */
	public static function key( string $text, string $format, string $source, string $target, array $options ): string {
		ksort( $options );

		return sha1( $format . "\0" . $source . "\0" . $target . "\0" . wp_json_encode( $options ) . "\0" . $text );
	}

	/**
	 * Ready translations for these keys: key => translation.
	 *
	 * @param array<int, string> $keys Keys.
	 * @return array<string, string>
	 */
	public static function lookup( array $keys ): array {
		if ( array() === $keys ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %s per key.
				"SELECT hash, translation FROM %i WHERE state = 'ready' AND hash IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')',
				array_merge( array( Schema::tables()['memory'] ), array_values( $keys ) )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['hash'] ] = (string) $row['translation'];
		}

		return $out;
	}

	/**
	 * Reserve a key for translating. True when this worker holds it now.
	 *
	 * @param string $key    Key.
	 * @param string $source Source language.
	 * @param string $target Target language.
	 * @return bool
	 */
	public static function reserve( string $key, string $source, string $target ): bool {
		global $wpdb;
		$table = Schema::tables()['memory'];
		$until = gmdate( 'Y-m-d H:i:s', time() + self::RESERVATION );
		$made  = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (hash, source_lang, target_lang, translation, state, reserved_until, created_gmt) VALUES (%s, %s, %s, '', 'pending', %s, %s)",
				$table,
				$key,
				$source,
				$target,
				$until,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		if ( 1 === (int) $made ) {
			return true;
		}

		// Someone holds it. Take it over only if their reservation has expired.
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET reserved_until = %s WHERE hash = %s AND state = 'pending' AND reserved_until < %s",
				$table,
				$until,
				$key,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Store a translation (and end the reservation).
	 *
	 * @param string $key         Key.
	 * @param string $translation The translation.
	 * @param string $engine      Engine id.
	 * @return void
	 */
	public static function store( string $key, string $translation, string $engine ): void {
		global $wpdb;
		$wpdb->update(
			Schema::tables()['memory'],
			array(
				'translation'    => $translation,
				'engine'         => $engine,
				'state'          => 'ready',
				'reserved_until' => null,
			),
			array( 'hash' => $key )
		);
	}

	/**
	 * Give a reservation back (the engine failed).
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public static function release( string $key ): void {
		global $wpdb;
		$wpdb->delete(
			Schema::tables()['memory'],
			array(
				'hash'  => $key,
				'state' => 'pending',
			)
		);
	}
}
