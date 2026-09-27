<?php
/**
 * The plugin's own tables, and bringing them up to date after an install OR an update.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the tables.
 *
 * ⛔⛔ WordPress does NOT run an activation hook when a plugin is UPDATED — only when it is
 * activated. Eleven thousand legacy sites reach this code by an update, with the plugin already
 * active, so the tables are created from `plugins_loaded` whenever the stored schema version is
 * older than this file's, never from activation alone (activation calls the same method too).
 */
final class Schema {

	/** Bump when a table definition below changes; dbDelta() applies the difference. */
	public const VERSION = '2';

	/** Option holding the installed schema version. */
	public const OPTION = 'tranzly_schema_version';

	/**
	 * Hook the upgrade check.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'maybe_install' ), 5 );
	}

	/**
	 * Install or upgrade when the stored version differs. One option read on every request, which
	 * is autoloaded, so it costs no query.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( self::VERSION !== (string) get_option( self::OPTION, '' ) ) {
			self::install();
		}
	}

	/**
	 * The table names for the current site (multisite: each site has its own).
	 *
	 * @return array{groups: string, relations: string, staging: string, jobs: string, items: string, memory: string}
	 */
	public static function tables(): array {
		global $wpdb;

		return array(
			'groups'    => $wpdb->prefix . 'tranzly_groups',
			'relations' => $wpdb->prefix . 'tranzly_relations',
			'staging'   => $wpdb->prefix . 'tranzly_legacy_staging',
			'jobs'      => $wpdb->prefix . 'tranzly_jobs',
			'items'     => $wpdb->prefix . 'tranzly_job_items',
			'memory'    => $wpdb->prefix . 'tranzly_memory',
		);
	}

	/**
	 * Create or upgrade every table.
	 *
	 * - `groups`: one row per translation group (a post and its translations, or a term and its).
	 * - `relations`: one row per translated object: which group it is in and what language it is.
	 *   An object with NO row is in the default language and has no translations — so a site's
	 *   untranslated content costs nothing here. `origin` records where a row came from (`legacy`
	 *   rows are the ones the legacy import can undo).
	 * - Field-level translations (site title, tagline, widgets, media text) are NOT here: they live
	 *   where WordPress already loads them for free (an option per language; the attachment's own
	 *   meta), see Strings. A table would cost a query on every translated page.
	 * - `staging`: the legacy import's working table, emptied when an import finishes.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = self::tables();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$t['groups']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(20) NOT NULL DEFAULT 'post',
  created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$t['relations']} (
  object_type varchar(20) NOT NULL DEFAULT 'post',
  object_id bigint(20) unsigned NOT NULL,
  group_id bigint(20) unsigned NOT NULL,
  lang varchar(35) NOT NULL,
  is_source tinyint(1) NOT NULL DEFAULT 0,
  origin varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (object_type,object_id),
  UNIQUE KEY group_lang (group_id,lang),
  KEY lang (object_type,lang),
  KEY origin (origin)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$t['staging']} (
  post_id bigint(20) unsigned NOT NULL,
  lang varchar(35) NOT NULL DEFAULT '',
  peers longtext NOT NULL,
  PRIMARY KEY  (post_id)
) {$charset};"
		);

		// v2 (T2): background translation jobs, one row per (object, language) to do, and the
		// translation memory. `claim` + `lease_until` let several workers share a job without
		// doing an item twice, and let a worker killed mid-item hand it back after its lease.
		dbDelta(
			"CREATE TABLE {$t['jobs']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'queued',
  engine varchar(40) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  finished_gmt datetime DEFAULT NULL,
  options longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$t['items']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NOT NULL,
  object_type varchar(20) NOT NULL DEFAULT 'post',
  object_id bigint(20) unsigned NOT NULL,
  lang varchar(35) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  engine varchar(40) NOT NULL DEFAULT '',
  claim char(32) NOT NULL DEFAULT '',
  lease_until datetime DEFAULT NULL,
  engine_used varchar(40) NOT NULL DEFAULT '',
  result_id bigint(20) unsigned NOT NULL DEFAULT 0,
  error_code varchar(60) NOT NULL DEFAULT '',
  error_message text NOT NULL,
  updated_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY one_item (job_id,object_type,object_id,lang),
  KEY work (job_id,status,lease_until),
  KEY claim (claim)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$t['memory']} (
  hash char(40) NOT NULL,
  source_lang varchar(35) NOT NULL,
  target_lang varchar(35) NOT NULL,
  translation longtext NOT NULL,
  engine varchar(40) NOT NULL DEFAULT '',
  state varchar(10) NOT NULL DEFAULT 'ready',
  reserved_until datetime DEFAULT NULL,
  created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (hash)
) {$charset};"
		);

		update_option( self::OPTION, self::VERSION, true );
		self::warm_sdk_options();
	}

	/**
	 * ⭐ THE SPEED PROMISE (tz-r14) MEASURED THIS: on a site that never opted in to the licensing
	 * SDK, three of its options do not exist, and WordPress runs ONE QUERY PER MISSING OPTION on
	 * every request, because only options that exist can be autoloaded. The SDK treats a missing
	 * option exactly like an empty one (its option manager's load() calls clear() on `false`), so
	 * creating them empty and autoloaded changes nothing it does and removes three queries from
	 * every page. Measured on WordPress 7.1: a translated page went from +5 to +2 queries.
	 * Existing values are never touched; an existing one stored as not-autoloaded is switched.
	 *
	 * @return void
	 */
	public static function warm_sdk_options(): void {
		$options = array(
			'fs_storage_logger'   => 0,
			'fs_clone_management' => array(),
			'fs_cache_6843'       => array(),
		);
		foreach ( $options as $name => $empty ) {
			if ( false === get_option( $name, false ) ) {
				add_option( $name, $empty, '', true );
			} elseif ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( $name, true );
			}
		}
	}

	/**
	 * Remove every table and the version option (uninstall).
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall removes the plugin's own tables.
		}
		delete_option( self::OPTION );
	}
}
