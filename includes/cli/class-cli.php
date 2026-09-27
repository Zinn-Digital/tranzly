<?php
/**
 * WP-CLI: `wp tranzly …`.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Cli;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Legacy_Import;
use ZinnDigital\Tranzly\Core\Network;
use ZinnDigital\Tranzly\Core\Queue;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Engines\Registry;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Tranzly translations and the legacy import from the command line.
 */
final class Cli {

	/**
	 * Register the command (only under WP-CLI).
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'tranzly', self::class );
		}
	}

	/**
	 * Show, dry-run, run or undo the import of a legacy Tranzly (2.x) site's translations.
	 *
	 * The import also runs by itself, in the background, the first time the new version loads.
	 * It never changes the legacy data, so undo returns the site to its state before the import.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status | dry-run | run | undo
	 *
	 * [--format=<format>]
	 * : json or yaml.
	 * ---
	 * default: yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tranzly legacy dry-run
	 *     wp tranzly legacy run
	 *     wp tranzly legacy undo
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Flags.
	 * @return void
	 */
	public function legacy( array $args, array $assoc ): void {
		$action = $args[0] ?? 'status';
		switch ( $action ) {
			case 'status':
				$out = Legacy_Import::state();
				unset( $out['options_snapshot'] );
				break;
			case 'dry-run':
				$out = Legacy_Import::dry_run();
				break;
			case 'run':
				Legacy_Import::begin();
				do {
					$state = Legacy_Import::step( 60.0 );
				} while ( in_array( $state['status'], array( 'collecting', 'applying' ), true ) );
				$out = array(
					'status' => $state['status'],
					'report' => $state['report'],
				);
				break;
			case 'undo':
				$out = Legacy_Import::undo();
				break;
			default:
				\WP_CLI::error( 'Unknown action. Use status, dry-run, run or undo.' );
				return;
		}
		self::print( $out, $assoc['format'] ?? 'yaml' );
	}

	/**
	 * List a post's (or term's) translations.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post or term ID.
	 *
	 * [--term]
	 * : The ID is a term.
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Flags.
	 * @return void
	 */
	public function translations( array $args, array $assoc ): void {
		$type = isset( $assoc['term'] ) ? 'term' : 'post';
		$rows = array();
		foreach ( Relations::translations( $type, (int) $args[0] ) as $lang => $id ) {
			$rows[] = array(
				'lang' => $lang,
				'id'   => $id,
			);
		}
		if ( array() === $rows ) {
			\WP_CLI::log( sprintf( 'No translations; %s %d is in the default language (%s).', $type, (int) $args[0], Languages::default_code() ) );
			return;
		}
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'lang', 'id' ) );
	}

	/**
	 * Create a draft translation of a post (or term), linked to it.
	 *
	 * Runs as the user given with --user, and checks that user's permissions like the editor does.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post or term ID.
	 *
	 * --lang=<code>
	 * : One of the site's languages, e.g. de_DE.
	 *
	 * [--term]
	 * : The ID is a term.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tranzly create 42 --lang=de_DE --user=admin
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Flags.
	 * @return void
	 */
	public function create( array $args, array $assoc ): void {
		$made = isset( $assoc['term'] )
			? Content::create_term_translation( (int) $args[0], (string) $assoc['lang'] )
			: Content::create_post_translation( (int) $args[0], (string) $assoc['lang'] );
		if ( is_wp_error( $made ) ) {
			$hint = 'tranzly_forbidden' === $made->get_error_code() && 0 === get_current_user_id() ? ' (pass --user=<admin>)' : '';
			\WP_CLI::error( $made->get_error_message() . $hint );
		}
		\WP_CLI::success( sprintf( 'Created %d.', $made ) );
	}

	/**
	 * Link an existing post (or term) as the translation of another.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : The original's ID.
	 *
	 * <target>
	 * : The translation's ID.
	 *
	 * --lang=<code>
	 * : The translation's language.
	 *
	 * [--term]
	 * : The IDs are terms.
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Flags.
	 * @return void
	 */
	public function link( array $args, array $assoc ): void {
		$type = isset( $assoc['term'] ) ? 'term' : 'post';
		$lang = Languages::resolve( (string) ( $assoc['lang'] ?? '' ) );
		if ( null === $lang ) {
			\WP_CLI::error( 'That language is not one of this site\'s languages.' );
		}
		$source = (int) $args[0];
		$result = Relations::link( $type, $source, Relations::language_of( $type, $source ) ?? Languages::default_code(), (int) $args[1], (string) $lang );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( 'Linked.' );
	}

	/**
	 * Translate posts with an engine.
	 *
	 * Give post IDs, or --post-type to translate every post of that type written in the default
	 * language (all of them: the command pages through the whole type, it does not stop at a
	 * number). Protected translations (edited by a person, or imported) are skipped unless --force.
	 * Runs as the user given with --user, whose permissions apply.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Post IDs.
	 *
	 * --lang=<codes>
	 * : One or more languages, comma-separated: de_DE, or just de when only one German is listed.
	 *
	 * [--post-type=<type>]
	 * : Translate every post of this type.
	 *
	 * [--engine=<id>]
	 * : The engine; the default engine when omitted. See `wp tranzly engines`.
	 *
	 * [--force]
	 * : Also overwrite protected translations.
	 *
	 * [--publish]
	 * : Publish the translations (new ones are drafts otherwise).
	 *
	 * [--dry-run]
	 * : Print the estimate and translate nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tranzly translate --lang=de --post-type=page --user=admin
	 *     wp tranzly translate 42 43 --lang=de_DE,fr_FR --engine=deepl --user=admin
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Flags.
	 * @return void
	 */
	public function translate( array $args, array $assoc ): void {
		$langs = array();
		foreach ( explode( ',', (string) ( $assoc['lang'] ?? '' ) ) as $wanted ) {
			$code = self::language( trim( $wanted ) );
			if ( null === $code ) {
				\WP_CLI::error( sprintf( '"%s" is not one of this site\'s languages (%s).', $wanted, implode( ', ', array_column( Languages::all(), 'code' ) ) ) );
			}
			$langs[] = $code;
		}
		$engine = (string) ( $assoc['engine'] ?? '' );
		$force  = isset( $assoc['force'] );

		$batches = self::batches( $args, (string) ( $assoc['post-type'] ?? '' ) );
		if ( isset( $assoc['dry-run'] ) ) {
			$ids = array();
			foreach ( $batches as $batch ) {
				$ids = array_merge( $ids, $batch );
			}
			$estimate = Translator::estimate( $ids, $langs, $engine );
			if ( is_wp_error( $estimate ) ) {
				\WP_CLI::error( $estimate->get_error_message() );
			}
			\WP_CLI::log( sprintf( '%d post(s) x %d language(s): %d characters, %s on %s.', count( $ids ), count( $langs ), $estimate['characters'], null === $estimate['cost_usd'] ? 'price unknown' : '$' . number_format( $estimate['cost_usd'], 4 ), $estimate['engine'] ) );
			return;
		}

		$done    = 0;
		$skipped = 0;
		$failed  = 0;
		foreach ( $batches as $batch ) {
			foreach ( $batch as $id ) {
				foreach ( $langs as $lang ) {
					$result = Translator::translate_post(
						$id,
						$lang,
						$engine,
						array(
							'force'  => $force,
							'status' => isset( $assoc['publish'] ) ? 'publish' : '',
						)
					);
					if ( is_wp_error( $result ) ) {
						if ( in_array( $result->get_error_code(), array( 'tranzly_protected', 'tranzly_same_language' ), true ) ) {
							++$skipped;
							continue;
						}
						if ( in_array( $result->get_error_code(), array( 'tranzly_no_engine', 'tranzly_engine_not_configured' ), true ) ) {
							\WP_CLI::error( $result->get_error_message() );
						}
						++$failed;
						\WP_CLI::warning( sprintf( '#%d %s: %s', $id, $lang, $result->get_error_message() ) );
						continue;
					}
					++$done;
					\WP_CLI::log( sprintf( '#%d %s -> #%d', $id, $lang, $result ) );
				}
			}
		}
		$line = sprintf( 'translated %d, skipped %d (protected or already that language), failed %d.', $done, $skipped, $failed );
		if ( $failed > 0 ) {
			\WP_CLI::error( $line );
		}
		\WP_CLI::success( $line );
	}

	/**
	 * List the translation engines.
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Flags.
	 * @return void
	 */
	public function engines( array $args, array $assoc ): void {
		unset( $args );
		$default = Registry::instance()->default_engine();
		$rows    = array();
		foreach ( Registry::instance()->all() as $engine ) {
			$rows[] = array(
				'id'         => $engine->id(),
				'name'       => $engine->label(),
				'configured' => $engine->is_configured() ? 'yes' : 'no',
				'default'    => null !== $default && $default->id() === $engine->id() ? 'yes' : '',
			);
		}
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'name', 'configured', 'default' ) );
	}

	/**
	 * Multisite (Agency): set up every site of the network, or set the languages new sites start with.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : setup | languages
	 *
	 * [--languages=<codes>]
	 * : With `languages`: comma-separated locales, default first (e.g. en_US,de_DE).
	 *
	 * ## EXAMPLES
	 *
	 *     wp tranzly network setup
	 *     wp tranzly network languages --languages=en_US,de_DE,fr_FR
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Flags.
	 * @return void
	 */
	public function network( array $args, array $assoc ): void {
		if ( ! is_multisite() ) {
			\WP_CLI::error( 'This is not a multisite network.' );
		}
		if ( ! Network::enabled() ) {
			\WP_CLI::error( 'The network features need the Agency plan. Each site still works on its own.' );
		}
		if ( 'languages' === ( $args[0] ?? '' ) ) {
			$list = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['languages'] ?? '' ) ) ) ) as $code ) {
				if ( ! \ZinnDigital\Tranzly\Settings::is_valid_code( $code ) ) {
					\WP_CLI::error( sprintf( '"%s" is not a WordPress locale code.', $code ) );
				}
				$list[] = array(
					'code' => $code,
					'name' => '',
				);
			}
			update_site_option( Network::OPTION, $list );
			\WP_CLI::success( sprintf( 'New sites start with %d language(s).', count( $list ) ) );
			return;
		}
		$count = 0;
		for ( $offset = 0; ; $offset += 100 ) {
			$sites = get_sites(
				array(
					'number' => 100,
					'offset' => $offset,
					'fields' => 'ids',
				)
			);
			if ( array() === $sites ) {
				break;
			}
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				Network::setup_current_site();
				restore_current_blog();
				++$count;
			}
		}
		\WP_CLI::success( sprintf( '%d site(s) set up.', $count ) );
	}

	/**
	 * Background translation jobs.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : create | status | failures | retry | cancel | run
	 *
	 * [<id>...]
	 * : create: post IDs. status/failures/cancel/run: the job ID. retry: the item ID.
	 *
	 * [--lang=<codes>]
	 * : create: languages, comma-separated.
	 *
	 * [--post-type=<type>]
	 * : create: every post of this type in the default language.
	 *
	 * [--engine=<id>]
	 * : create/retry: the engine (retry: try another one).
	 *
	 * [--publish]
	 * : create: publish the translations.
	 *
	 * [--force]
	 * : create: also overwrite protected translations.
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tranzly jobs create --lang=de,fr --post-type=page --user=admin
	 *     wp tranzly jobs status 12
	 *     wp tranzly jobs failures 12
	 *     wp tranzly jobs retry 345 --engine=deepl
	 *     wp tranzly jobs run 12      # work the job here instead of waiting for the queue runner
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Flags.
	 * @return void
	 */
	public function jobs( array $args, array $assoc ): void {
		$action = array_shift( $args ) ?? '';
		$format = $assoc['format'] ?? 'yaml';
		switch ( $action ) {
			case 'create':
				$langs = array();
				foreach ( explode( ',', (string) ( $assoc['lang'] ?? '' ) ) as $wanted ) {
					$code = self::language( trim( $wanted ) );
					if ( null === $code ) {
						\WP_CLI::error( sprintf( '"%s" is not one of this site\'s languages.', $wanted ) );
					}
					$langs[] = $code;
				}
				$ids = array_map( 'intval', $args );
				if ( isset( $assoc['post-type'] ) ) {
					$ids = array_merge( $ids, \ZinnDigital\Tranzly\Api\Rest_Jobs::sources_of_type( (string) $assoc['post-type'] ) );
				}
				$made = Queue::create(
					$ids,
					$langs,
					(string) ( $assoc['engine'] ?? '' ),
					array(
						'status' => isset( $assoc['publish'] ) ? 'publish' : '',
						'force'  => isset( $assoc['force'] ),
					)
				);
				if ( is_wp_error( $made ) ) {
					\WP_CLI::error( $made->get_error_message() );
				}
				\WP_CLI::success( sprintf( 'Job %d: %d item(s) queued%s.', $made['id'], $made['items'], $made['not_allowed'] > 0 ? sprintf( ', %d post(s) you may not translate left out', $made['not_allowed'] ) : '' ) );
				return;
			case 'status':
				self::print( Queue::progress( (int) ( $args[0] ?? 0 ) ), $format );
				return;
			case 'failures':
				$rows = Queue::failures( (int) ( $args[0] ?? 0 ) );
				\WP_CLI\Utils\format_items( 'yaml' === $format ? 'table' : $format, $rows, array( 'item', 'post', 'lang', 'engine', 'class', 'message' ) );
				return;
			case 'retry':
				$done = Queue::retry( (int) ( $args[0] ?? 0 ), (string) ( $assoc['engine'] ?? '' ) );
				if ( is_wp_error( $done ) ) {
					\WP_CLI::error( $done->get_error_message() );
				}
				\WP_CLI::success( 'Queued again.' );
				return;
			case 'cancel':
				Queue::cancel( (int) ( $args[0] ?? 0 ) );
				\WP_CLI::success( 'Cancelled.' );
				return;
			case 'run':
				$job = (int) ( $args[0] ?? 0 );
				do {
					Queue::work( $job );
					$progress = Queue::progress( $job );
				} while ( null !== $progress && 'running' === $progress['status'] && ( $progress['counts']['queued'] + $progress['counts']['running'] ) > 0 && self::claimable_soon( $job ) );
				self::print( Queue::progress( $job ), $format );
				return;
			default:
				\WP_CLI::error( 'Use create, status, failures, retry, cancel or run.' );
		}
	}

	/**
	 * Is any queued item of a job due within a minute (so `run` should keep going)?
	 *
	 * @param int $job Job ID.
	 * @return bool
	 */
	private static function claimable_soon( int $job ): bool {
		global $wpdb;

		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE job_id = %d AND status IN ('queued','running') AND ( lease_until IS NULL OR lease_until <= %s ) LIMIT 1", \ZinnDigital\Tranzly\Core\Schema::tables()['items'], $job, gmdate( 'Y-m-d H:i:s', time() + 60 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; WP-CLI only.
	}

	/**
	 * A listed language from a full code or an unambiguous short one (`de` for `de_DE`).
	 *
	 * @param string $wanted The code typed.
	 * @return string|null
	 */
	private static function language( string $wanted ): ?string {
		$exact = Languages::resolve( $wanted );
		if ( null !== $exact ) {
			return $exact;
		}
		$matches = array_values( array_filter( array_column( Languages::all(), 'code' ), static fn( $c ) => strtolower( (string) preg_replace( '/_.*/', '', $c ) ) === strtolower( $wanted ) ) );

		return 1 === count( $matches ) ? $matches[0] : null;
	}

	/**
	 * The posts to translate, 100 at a time: the IDs given, or every post of a type in the default
	 * language.
	 *
	 * @param array<int, string> $ids       IDs given.
	 * @param string             $post_type A post type.
	 * @return \Generator<int, array<int, int>>
	 */
	private static function batches( array $ids, string $post_type ): \Generator {
		if ( array() !== $ids ) {
			yield array_map( 'intval', $ids );
			return;
		}
		if ( '' === $post_type ) {
			\WP_CLI::error( 'Give post IDs or --post-type.' );
		}
		$default = Languages::default_code();
		for ( $page = 1; ; ++$page ) {
			$found = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'fields'         => 'ids',
					'posts_per_page' => 100,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			if ( array() === $found ) {
				return;
			}
			Relations::prime( 'post', $found );
			yield array_values( array_filter( array_map( 'intval', $found ), static fn( $id ) => ( Relations::language_of( 'post', $id ) ?? $default ) === $default ) );
		}
	}

	/**
	 * Print a structure.
	 *
	 * @param mixed  $data   The data.
	 * @param string $format json or yaml.
	 * @return void
	 */
	private static function print( $data, string $format ): void {
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		\WP_CLI::print_value( $data, array( 'format' => 'yaml' ) );
	}
}
