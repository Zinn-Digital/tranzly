<?php
/**
 * Tranzly abilities: languages, translating a post, and translation status for AI agents (MCP)
 * and REST.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Mcp;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Edition;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Engines\Registry;
use ZinnDigital\Tranzly\Languages;
use ZinnDigital\Tranzly\McpKit\Ability;
use ZinnDigital\Tranzly\McpKit\Rest_Bridge;
use ZinnDigital\Tranzly\McpKit\Server;
use ZinnDigital\Tranzly\Settings;
use ZinnDigital\Tranzly\Workflow\Staleness;
use ZinnDigital\Tranzly\Workflow\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The free abilities (owner, 2026-09-30: basic abilities free, bulk and AI in Pro).
 *
 * Abilities: tranzly/list-languages · tranzly/list-engines · tranzly/translate-post · tranzly/translation-status
 *
 * Translating runs through Translator::translate_post(), the one path the editor, WP-CLI and the
 * background queue use, so its own checks apply unchanged: the user must be allowed to translate
 * the post, to change an existing translation, and to publish when publishing is asked for; a
 * translation a person edited is protected unless `force` is sent. Translations use the engine
 * the site owner set up (their own AI or DeepL key); nothing is charged by Tranzly.
 */
final class Abilities {

	/** The ability category. */
	public const CATEGORY = 'tranzly';

	/** Most languages one translate call takes. */
	private const MAX_LANGS = 10;

	/**
	 * Boot the MCP kit for this plugin.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Server::boot(
			array(
				'id'             => 'tranzly',
				'rest_namespace' => \ZinnDigital\Tranzly\Rest::NAMESPACE,
				'name'           => 'Tranzly',
				'description'    => static fn(): string => __( 'Translate this site\'s content and check its translations with Tranzly.', 'tranzly' ),
				'version'        => TRANZLY_VERSION,
				'capability'     => 'edit_posts',
				'category'       => array(
					'slug'        => self::CATEGORY,
					'label'       => static fn(): string => __( 'Tranzly', 'tranzly' ),
					'description' => static fn(): string => __( 'Languages, translation and translation status.', 'tranzly' ),
				),
				'enabled'        => static fn(): bool => Settings::get()['mcp'],
				'abilities'      => array( self::class, 'register' ),
				'vendor_dir'     => TRANZLY_DIR . 'vendor/wordpress',
				'docs'           => array(
					'guide'      => 'https://zinndigital.com/wordpress-plugins/tranzly/mcp',
					'developers' => 'https://zinndigital.com/wordpress-plugins/tranzly/mcp-api',
				),
			)
		);
	}

	/**
	 * Register the abilities (on `wp_abilities_api_init`), then the Pro ones when licensed.
	 *
	 * @return void
	 */
	public static function register(): void {
		Ability::register(
			'tranzly/list-languages',
			array(
				'edition'             => 'free',
				'capability'          => 'edit_posts',
				'label'               => __( 'List languages', 'tranzly' ),
				'description'         => __( 'Lists the site\'s languages. The first one is the language the original content is written in.', 'tranzly' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'default'   => array( 'type' => 'string' ),
						'languages' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'code' => array( 'type' => 'string' ),
									'name' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'list_languages' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'annotations'         => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			)
		);

		Ability::register(
			'tranzly/list-engines',
			array(
				'edition'             => 'free',
				'capability'          => 'edit_posts',
				'label'               => __( 'List translation engines', 'tranzly' ),
				'description'         => __( 'Lists the translation engines, whether each is set up, and which one is used when none is named.', 'tranzly' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'list_engines' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'annotations'         => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			)
		);

		Ability::register(
			'tranzly/translate-post',
			array(
				'edition'             => 'free',
				'capability'          => 'may translate that post (edit_post on it); edit_post on an existing translation; the type\'s publish capability to publish',
				'label'               => __( 'Translate a post or page', 'tranzly' ),
				'description'         => __( 'Translates one post or page into one or more of the site\'s languages with a translation engine, creating each translation or updating it. New translations are drafts unless publish is true. A translation a person edited is skipped unless force is true.', 'tranzly' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'        => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'languages' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'minItems'    => 1,
							'maxItems'    => self::MAX_LANGS,
							'description' => __( 'Language codes from tranzly/list-languages, e.g. de_DE, or just de when only one German is listed.', 'tranzly' ),
						),
						'engine'    => array(
							'type'        => 'string',
							'description' => __( 'An engine id from tranzly/list-engines; the default engine when omitted.', 'tranzly' ),
						),
						'publish'   => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'force'     => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'id', 'languages' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'annotations'         => array( 'destructive' => true ),
				'execute_callback'    => array( self::class, 'translate_post' ),
				'permission_callback' => static fn( array $input ): bool => Content::can_translate_post( Ability::int( $input, 'id' ) ),
			)
		);

		Ability::register(
			'tranzly/translation-status',
			array(
				'edition'             => 'free',
				'capability'          => 'edit_post on that post; edit_posts for the site summary',
				'label'               => __( 'Translation status', 'tranzly' ),
				'description'         => __( 'With a post ID: each language\'s translation of that post, whether it is out of date, and who wrote it. Without one: how much of the site is translated, per language.', 'tranzly' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'        => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'post_type' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'translation_status' ),
				'permission_callback' => static function ( array $input ): bool {
					$id = Ability::int( $input, 'id' );

					return $id > 0 ? current_user_can( 'edit_post', $id ) : Status::can_view();
				},
				'annotations'         => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			)
		);

		Ability::register(
			'tranzly/translate-site',
			array(
				'edition'             => 'free',
				'capability'          => 'edit_posts',
				'mcp_type'            => 'prompt',
				'label'               => __( 'Translate my site', 'tranzly' ),
				'description'         => __( 'A guided workflow: the agent checks the languages and engine, estimates the cost, and translates what is missing, asking before it spends or publishes.', 'tranzly' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'languages' => array(
							'type'        => 'string',
							'description' => __( 'The languages to translate into, e.g. German and French.', 'tranzly' ),
						),
					),
					'required'             => array( 'languages' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( self::class, 'translate_site_prompt' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			)
		);

		// Every other REST route of the plugin, as an ability of its own (includes/mcp/class-rest-map.php).
		require_once __DIR__ . '/class-rest-map.php';
		Rest_Bridge::register( Rest_Map::entries(), self::CATEGORY );

		// ⛔ The premium abilities load only when their file ships AND the licence allows it; the
		// directory name is split so the free package never carries the premium token (CONTRACT §3).
		$pro = __DIR__ . '/pro_' . '_premium_only/class-pro-abilities.php'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- deliberate split, see above.
		if ( is_readable( $pro ) && Edition::pro() && class_exists( '\\ZinnDigital\\Tranzly\\Workflow\\Pro\\Bulk' ) ) {
			require_once $pro;
			Pro\Pro_Abilities::register();
		}
	}

	/**
	 * The `tranzly/translate-site` prompt.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function translate_site_prompt( array $input ): array {
		return array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => sprintf(
							/* translators: %s: the languages the site owner asked for. */
							__( 'Translate this WordPress site into: %s. Steps: 1) call tranzly-list-languages; if a language is missing, ask me before adding it with tranzly-update-settings; 2) call tranzly-list-engines and make sure one is set up (otherwise tell me which key to add); 3) call tranzly-translation-status for the whole site and tranzly-estimate for what is missing, and tell me the cost before spending anything; 4) after I agree, translate with tranzly-bulk-translate if it is available (Pro), otherwise post by post with tranzly-translate-post; 5) new translations stay drafts unless I say to publish; 6) finish with tranzly-translation-status and list what is still missing.', 'tranzly' ),
							sanitize_text_field( (string) ( $input['languages'] ?? '' ) )
						),
					),
				),
			),
		);
	}

	/**
	 * `tranzly/list-languages`.
	 *
	 * @return array<string, mixed>
	 */
	public static function list_languages(): array {
		return array(
			'default'   => Languages::default_code(),
			'languages' => array_map(
				static fn( array $l ): array => array(
					'code' => (string) $l['code'],
					'name' => (string) $l['name'],
				),
				Languages::all()
			),
		);
	}

	/**
	 * `tranzly/list-engines`.
	 *
	 * @return array<string, mixed>
	 */
	public static function list_engines(): array {
		$default = Registry::instance()->default_engine();
		$out     = array();
		foreach ( Registry::instance()->all() as $engine ) {
			$out[] = array(
				'id'         => $engine->id(),
				'name'       => $engine->label(),
				'configured' => $engine->is_configured(),
			);
		}

		return array(
			'default' => null === $default ? '' : $default->id(),
			'engines' => $out,
		);
	}

	/**
	 * `tranzly/translate-post`.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function translate_post( array $input ) {
		$id    = Ability::int( $input, 'id' );
		$langs = array_slice( Ability::strings( $input, 'languages' ), 0, self::MAX_LANGS );
		if ( array() === $langs ) {
			return new \WP_Error( 'tranzly_no_language', __( 'Name at least one language.', 'tranzly' ), array( 'status' => 400 ) );
		}
		$results = array();
		$done    = 0;
		foreach ( $langs as $lang ) {
			$result = Translator::translate_post(
				$id,
				$lang,
				(string) ( $input['engine'] ?? '' ),
				array(
					'force'  => ! empty( $input['force'] ),
					'status' => ! empty( $input['publish'] ) ? 'publish' : '',
				)
			);
			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'language' => $lang,
					'ok'       => false,
					'code'     => $result->get_error_code(),
					'message'  => $result->get_error_message(),
				);
				continue;
			}
			++$done;
			$results[] = array(
				'language'  => (string) Languages::resolve( $lang ),
				'ok'        => true,
				'id'        => (int) $result,
				'status'    => (string) get_post_status( (int) $result ),
				'link'      => (string) get_permalink( (int) $result ),
				'edit_link' => (string) get_edit_post_link( (int) $result, 'raw' ),
			);
		}

		return array(
			'id'           => $id,
			'translated'   => $done,
			'translations' => $results,
		);
	}

	/**
	 * `tranzly/translation-status`.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function translation_status( array $input ) {
		$id = Ability::int( $input, 'id' );
		if ( $id <= 0 ) {
			$types = Status::types( (string) ( $input['post_type'] ?? '' ) );

			return is_wp_error( $types ) ? $types : Status::summary( $types );
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'tranzly_not_found', __( 'That item does not exist.', 'tranzly' ), array( 'status' => 404 ) );
		}
		$members = Relations::translations( 'post', $id );
		$source  = Relations::language_of( 'post', $id ) ?? Languages::default_code();
		$orig    = $members[ $source ] ?? $id;
		$out     = array();
		foreach ( Languages::all() as $language ) {
			$code   = (string) $language['code'];
			$target = $members[ $code ] ?? null;
			if ( $code === $source ) {
				continue;
			}
			if ( null === $target ) {
				$out[] = array(
					'language' => $code,
					'state'    => 'missing',
				);
				continue;
			}
			$mark  = (string) get_post_meta( (int) $target, Translator::STATUS_META, true );
			$out[] = array(
				'language' => $code,
				'state'    => Staleness::is_stale( (int) $orig, (int) $target ) ? 'stale' : 'current',
				'id'       => (int) $target,
				'status'   => (string) get_post_status( (int) $target ),
				'by'       => '' === $mark ? 'unknown' : $mark,
			);
		}

		return array(
			'id'           => $id,
			'language'     => $source,
			'translations' => $out,
		);
	}
}
