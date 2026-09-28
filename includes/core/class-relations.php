<?php
/**
 * Translation groups: which posts (or terms) are translations of each other, and in which
 * language each one is written.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacement lists are array_merge()d to match their placeholders, which the sniff cannot count; the plugin's own tables; reads are cached in the object cache below, writes flush it.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The post-per-language model (tz-f1): every translation is a real post (or term), and a group
 * row ties the translations together. One row per translated object; an object with no row is in
 * the default language and has no translations.
 *
 * ⭐ THE SPEED PROMISE (tz-r14) STARTS HERE. A post's whole group is ONE indexed query, cached in
 * the object cache and in a per-request map, and prime() loads a whole archive's groups in one
 * query too — so a translated page adds at most one query for its own group, not one per language
 * or one per post.
 */
final class Relations {

	/** Object cache group. */
	public const CACHE_GROUP = 'tranzly_relations';

	/**
	 * Per-request memo: "type:id" => lang => object ID ( empty array = no group ).
	 *
	 * @var array<string, array<string, int>>
	 */
	private static array $memo = array();

	/**
	 * Forget everything memoised in this request (tests; after bulk writes).
	 *
	 * @return void
	 */
	public static function reset_memo(): void {
		self::$memo = array();
	}

	/**
	 * The language an object is written in, or null when it has no row (the default language).
	 *
	 * @param string $type `post` or `term`.
	 * @param int    $id   Object ID.
	 * @return string|null
	 */
	public static function language_of( string $type, int $id ): ?string {
		$map = self::translations( $type, $id );
		foreach ( $map as $lang => $object_id ) {
			if ( $object_id === $id ) {
				return $lang;
			}
		}

		return null;
	}

	/**
	 * Every member of the object's group: language => object ID, the object itself included.
	 * Empty when the object is in no group.
	 *
	 * @param string $type `post` or `term`.
	 * @param int    $id   Object ID.
	 * @return array<string, int>
	 */
	public static function translations( string $type, int $id ): array {
		if ( $id <= 0 ) {
			return array();
		}
		$key = $type . ':' . $id;
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			self::$memo[ $key ] = $cached;
			return $cached;
		}

		global $wpdb;
		$table = Schema::tables()['relations'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r2.lang, r2.object_id FROM %i r1 INNER JOIN %i r2 ON r2.group_id = r1.group_id AND r2.object_type = r1.object_type WHERE r1.object_type = %s AND r1.object_id = %d',
				$table,
				$table,
				$type,
				$id
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['lang'] ] = (int) $row['object_id'];
		}
		self::remember( $type, $map, array( $id ) );

		return $map;
	}

	/**
	 * Store a group map another query already read (lane L05's page primer, which reads every group
	 * a page needs in ONE query — the speed promise). Same effect as translations() having run.
	 *
	 * @param string             $type `post` or `term`.
	 * @param array<string, int> $map  Language => object ID (empty: the object is in no group).
	 * @param int                $id   The object the map was read for.
	 * @return void
	 */
	public static function seed( string $type, array $map, int $id ): void {
		if ( array() === $map ) {
			self::$memo[ $type . ':' . $id ] = array();
			return;
		}
		self::remember( $type, $map, array( $id ) );
	}

	/**
	 * Record, for this request only, that these objects have NO relations row — a query that
	 * joined the relations table already saw so (lane L05, the speed promise) — so asking for their
	 * group costs nothing. Objects already known are left alone.
	 *
	 * @param string          $type `post` or `term`.
	 * @param array<int, int> $ids  Object IDs.
	 * @return void
	 */
	public static function note_ungrouped( string $type, array $ids ): void {
		foreach ( $ids as $id ) {
			if ( ! isset( self::$memo[ $type . ':' . (int) $id ] ) ) {
				self::$memo[ $type . ':' . (int) $id ] = array();
			}
		}
	}

	/**
	 * Load the groups of many objects in ONE query (an archive, a menu, a sitemap page).
	 *
	 * @param string          $type `post` or `term`.
	 * @param array<int, int> $ids  Object IDs.
	 * @return void
	 */
	public static function prime( string $type, array $ids ): void {
		$missing = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id > 0 && ! isset( self::$memo[ $type . ':' . $id ] ) ) {
				$cached = wp_cache_get( $type . ':' . $id, self::CACHE_GROUP );
				if ( is_array( $cached ) ) {
					self::$memo[ $type . ':' . $id ] = $cached;
				} else {
					$missing[] = $id;
				}
			}
		}
		if ( array() === $missing ) {
			return;
		}

		global $wpdb;
		$table        = Schema::tables()['relations'];
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a run of %d built above, one per ID.
				"SELECT r1.object_id AS asked, r2.lang, r2.object_id FROM %i r1 INNER JOIN %i r2 ON r2.group_id = r1.group_id AND r2.object_type = r1.object_type WHERE r1.object_type = %s AND r1.object_id IN ({$placeholders})",
				array_merge( array( $table, $table, $type ), $missing )
			),
			ARRAY_A
		);

		$maps = array_fill_keys( $missing, array() );
		foreach ( (array) $rows as $row ) {
			$maps[ (int) $row['asked'] ][ (string) $row['lang'] ] = (int) $row['object_id'];
		}
		foreach ( $maps as $id => $map ) {
			self::$memo[ $type . ':' . $id ] = $map;
			wp_cache_set( $type . ':' . $id, $map, self::CACHE_GROUP );
		}
	}

	/**
	 * Put an object in a language. It joins no group; if it is already in one, its language
	 * changes there — refused when another member already has that language.
	 *
	 * @param string $type   `post` or `term`.
	 * @param int    $id     Object ID.
	 * @param string $lang   Language code (already validated by the caller).
	 * @param string $origin Where the row comes from (`legacy` for the legacy import).
	 * @return true|\WP_Error
	 */
	public static function set_language( string $type, int $id, string $lang, string $origin = '' ) {
		$members = self::translations( $type, $id );
		if ( array() === $members ) {
			return self::insert( $type, $id, self::new_group( $type ), $lang, true, $origin );
		}
		if ( isset( $members[ $lang ] ) && $members[ $lang ] !== $id ) {
			return new \WP_Error(
				'tranzly_language_taken',
				/* translators: 1: language code, 2: the ID of the item that already has it. */
				sprintf( __( 'This item\'s group already has a %1$s version (#%2$d).', 'tranzly' ), $lang, $members[ $lang ] ),
				array( 'status' => 409 )
			);
		}

		global $wpdb;
		$wpdb->update(
			Schema::tables()['relations'],
			array( 'lang' => $lang ),
			array(
				'object_type' => $type,
				'object_id'   => $id,
			)
		);
		self::flush( $type, $members );

		return true;
	}

	/**
	 * Make `$target` the `$target_lang` translation of `$source`.
	 *
	 * @param string $type        `post` or `term`.
	 * @param int    $source      The object being translated.
	 * @param string $source_lang Its language, used when it is not in a group yet.
	 * @param int    $target      The translation.
	 * @param string $target_lang The translation's language.
	 * @param string $origin      Where the row comes from.
	 * @return true|\WP_Error
	 */
	public static function link( string $type, int $source, string $source_lang, int $target, string $target_lang, string $origin = '' ) {
		if ( $source === $target || $source <= 0 || $target <= 0 ) {
			return new \WP_Error( 'tranzly_bad_link', __( 'An item cannot be its own translation.', 'tranzly' ), array( 'status' => 400 ) );
		}

		$group_members = self::translations( $type, $source );
		if ( array() === $group_members ) {
			$group = self::new_group( $type );
			$made  = self::insert( $type, $source, $group, $source_lang, true, $origin );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$group_members = array( $source_lang => $source );
		} else {
			$group = self::group_id( $type, $source );
		}

		if ( isset( $group_members[ $target_lang ] ) ) {
			if ( $group_members[ $target_lang ] === $target ) {
				return true;
			}
			return new \WP_Error(
				'tranzly_language_taken',
				/* translators: 1: language code, 2: the ID of the item that already has it. */
				sprintf( __( 'This item\'s group already has a %1$s version (#%2$d).', 'tranzly' ), $target_lang, $group_members[ $target_lang ] ),
				array( 'status' => 409 )
			);
		}

		$target_members = self::translations( $type, $target );
		if ( count( $target_members ) > 1 ) {
			return new \WP_Error(
				'tranzly_already_linked',
				__( 'That item is already a translation of something else. Unlink it first.', 'tranzly' ),
				array( 'status' => 409 )
			);
		}
		if ( array() !== $target_members ) {
			self::delete( $type, $target );
		}

		$made = self::insert( $type, $target, (int) $group, $target_lang, false, $origin );
		self::flush( $type, $group_members );

		return $made;
	}

	/**
	 * Take an object out of its group. It keeps its language, alone in a new group.
	 *
	 * @param string $type `post` or `term`.
	 * @param int    $id   Object ID.
	 * @return void
	 */
	public static function unlink( string $type, int $id ): void {
		$lang = self::language_of( $type, $id );
		if ( null === $lang ) {
			return;
		}
		self::delete( $type, $id );
		self::insert( $type, $id, self::new_group( $type ), $lang, true, '' );
	}

	/**
	 * Remove an object's row entirely (it was deleted). The legacy plugin never did this, which is
	 * how its sites came to hold links to posts that no longer exist.
	 *
	 * @param string $type `post` or `term`.
	 * @param int    $id   Object ID.
	 * @return void
	 */
	public static function delete( string $type, int $id ): void {
		$members = self::translations( $type, $id );
		if ( array() === $members ) {
			return;
		}
		global $wpdb;
		$wpdb->delete(
			Schema::tables()['relations'],
			array(
				'object_type' => $type,
				'object_id'   => $id,
			)
		);
		self::flush( $type, $members );
	}

	/**
	 * The group an object is in, or 0.
	 *
	 * @param string $type `post` or `term`.
	 * @param int    $id   Object ID.
	 * @return int
	 */
	public static function group_id( string $type, int $id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT group_id FROM %i WHERE object_type = %s AND object_id = %d', Schema::tables()['relations'], $type, $id )
		);
	}

	/**
	 * Every object ID in a language, for a type (used by queries that filter by language).
	 *
	 * @param string $type `post` or `term`.
	 * @param string $lang Language code.
	 * @return array<int, int>
	 */
	public static function objects_in( string $type, string $lang ): array {
		global $wpdb;

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( 'SELECT object_id FROM %i WHERE object_type = %s AND lang = %s', Schema::tables()['relations'], $type, $lang )
			)
		);
	}

	/**
	 * A new, empty group.
	 *
	 * @param string $type `post` or `term`.
	 * @return int
	 */
	public static function new_group( string $type ): int {
		global $wpdb;
		$wpdb->insert(
			Schema::tables()['groups'],
			array(
				'object_type' => $type,
				'created_gmt' => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert one row.
	 *
	 * @param string $type      `post` or `term`.
	 * @param int    $id        Object ID.
	 * @param int    $group     Group ID.
	 * @param string $lang      Language code.
	 * @param bool   $is_source Is this the group's original.
	 * @param string $origin    Where the row comes from.
	 * @return true|\WP_Error
	 */
	private static function insert( string $type, int $id, int $group, string $lang, bool $is_source, string $origin ) {
		global $wpdb;
		$ok = $wpdb->insert(
			Schema::tables()['relations'],
			array(
				'object_type' => $type,
				'object_id'   => $id,
				'group_id'    => $group,
				'lang'        => $lang,
				'is_source'   => $is_source ? 1 : 0,
				'origin'      => $origin,
			)
		);
		self::flush( $type, array( $lang => $id ) );
		if ( false === $ok ) {
			return new \WP_Error( 'tranzly_link_failed', __( 'The translation link could not be saved.', 'tranzly' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Store a group map for each listed object.
	 *
	 * @param string             $type  `post` or `term`.
	 * @param array<string, int> $map   Language => object ID.
	 * @param array<int, int>    $asked IDs the map was asked for (members are included too).
	 * @return void
	 */
	private static function remember( string $type, array $map, array $asked ): void {
		foreach ( array_unique( array_merge( $asked, array_values( $map ) ) ) as $id ) {
			self::$memo[ $type . ':' . $id ] = $map;
			wp_cache_set( $type . ':' . $id, $map, self::CACHE_GROUP );
		}
	}

	/**
	 * Forget the cached group of every listed object.
	 *
	 * @param string             $type    `post` or `term`.
	 * @param array<string, int> $members Language => object ID.
	 * @return void
	 */
	private static function flush( string $type, array $members ): void {
		foreach ( $members as $id ) {
			unset( self::$memo[ $type . ':' . $id ] );
			wp_cache_delete( $type . ':' . $id, self::CACHE_GROUP );
		}

		/**
		 * Fires after the translation links of objects changed: linked, unlinked, moved to another
		 * language or removed. Fires once per group touched, so a single link may fire it twice.
		 *
		 * @since 3.0.9
		 *
		 * @param string     $type `post` or `term`.
		 * @param array<int> $ids  The objects whose links changed.
		 */
		do_action( 'tranzly_relations_changed', $type, array_map( 'intval', array_values( $members ) ) );
	}
}
