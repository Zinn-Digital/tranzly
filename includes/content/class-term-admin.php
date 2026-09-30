<?php
/**
 * Translations of categories, tags and custom taxonomy terms, from the term's own edit screen
 * (tz-c3): every language, with the linked translation or one click to make it.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Content;

use ZinnDigital\Tranzly\Core\Content;
use ZinnDigital\Tranzly\Core\Relations;
use ZinnDigital\Tranzly\Core\Translator;
use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ Until 3.16 a term could only be translated through the REST API, WP-CLI or PHP: the storage
 * and the engines were there, the screen was not, so a site owner could not do what the readme
 * promises (T9a route inventory, wp/tests/e2e/tranzly/inventory.spec.mjs). The actions reuse the
 * same two calls the REST routes make — Translator::translate_term() (the language's engine) and
 * Content::create_term_translation() (a copy to write by hand) — so the screen adds no second
 * code path to keep correct.
 */
final class Term_Admin {

	/** The admin-post action. */
	public const ACTION = 'tranzly_term_translation';

	/**
	 * Hook every translatable taxonomy's edit screen and the action.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'hook_taxonomies' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * `admin_init`: one row on each translatable taxonomy's edit form.
	 *
	 * @return void
	 */
	public static function hook_taxonomies(): void {
		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $taxonomy ) {
			if ( Content::is_translatable_taxonomy( (string) $taxonomy ) ) {
				add_action( $taxonomy . '_edit_form_fields', array( self::class, 'render' ), 20, 1 );
			}
		}
	}

	/**
	 * The Translations row of a term's edit form.
	 *
	 * @param \WP_Term $term The term being edited.
	 * @return void
	 */
	public static function render( $term ): void {
		if ( ! $term instanceof \WP_Term || ! Content::can_translate_term( (int) $term->term_id ) ) {
			return;
		}
		$own   = Relations::language_of( 'term', (int) $term->term_id ) ?? Languages::default_code();
		$group = Relations::translations( 'term', (int) $term->term_id );
		echo '<tr class="form-field tranzly-term-translations"><th scope="row">' . esc_html__( 'Translations', 'tranzly' ) . '</th><td><ul>';
		foreach ( Languages::all() as $language ) {
			$code = (string) $language['code'];
			$name = (string) ( $language['name'] ?? $code );
			echo '<li><strong lang="' . esc_attr( str_replace( '_', '-', $code ) ) . '">' . esc_html( $name ) . '</strong> ';
			if ( $code === $own ) {
				echo esc_html__( 'This one', 'tranzly' ) . '</li>';
				continue;
			}
			$linked = isset( $group[ $code ] ) ? get_term( (int) $group[ $code ] ) : null;
			if ( $linked instanceof \WP_Term ) {
				$edit = get_edit_term_link( $linked, $linked->taxonomy );
				echo '<a href="' . esc_url( (string) $edit ) . '">' . esc_html( $linked->name ) . '</a> · ';
				echo '<a href="' . esc_url( self::action_url( (int) $term->term_id, $code, 'machine' ) ) . '">' . esc_html__( 'Translate again', 'tranzly' ) . '</a>';
			} else {
				echo '<a class="button button-small" href="' . esc_url( self::action_url( (int) $term->term_id, $code, 'machine' ) ) . '">' . esc_html__( 'Translate', 'tranzly' ) . '</a> ';
				echo '<a href="' . esc_url( self::action_url( (int) $term->term_id, $code, 'copy' ) ) . '">' . esc_html__( 'Create a copy to translate by hand', 'tranzly' ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ul><p class="description">' . esc_html__( 'Translate uses the engine set for that language. A version a person edited is never overwritten.', 'tranzly' ) . '</p></td></tr>';
	}

	/**
	 * The signed address of one action.
	 *
	 * @param int    $term_id The original term.
	 * @param string $lang    Target language.
	 * @param string $how     `machine` or `copy`.
	 * @return string
	 */
	public static function action_url( int $term_id, string $lang, string $how ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'term'   => $term_id,
					'lang'   => $lang,
					'how'    => $how,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $term_id
		);
	}

	/**
	 * `admin-post`: make or refresh the translation, then go to it (a copy) or back (a machine
	 * translation), with the outcome in a notice.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$term_id = isset( $_GET['term'] ) ? absint( wp_unslash( $_GET['term'] ) ) : 0;
		check_admin_referer( self::ACTION . '_' . $term_id );
		$lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
		$how  = isset( $_GET['how'] ) && 'copy' === $_GET['how'] ? 'copy' : 'machine';
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term || ! Content::can_translate_term( $term_id ) ) {
			wp_die( esc_html__( 'You are not allowed to translate this item.', 'tranzly' ), 403 );
		}
		$made = 'copy' === $how
			? Content::create_term_translation( $term_id, $lang )
			: Translator::translate_term( $term_id, $lang );
		$back = (string) get_edit_term_link( $term, $term->taxonomy );
		if ( is_wp_error( $made ) ) {
			self::remember( 'error', $made->get_error_message() );
			wp_safe_redirect( $back );
			exit;
		}
		/* translators: %s: a language's name, e.g. Deutsch. */
		self::remember( 'success', sprintf( __( '%s: translated.', 'tranzly' ), self::language_name( $lang ) ) );
		wp_safe_redirect( 'copy' === $how ? (string) get_edit_term_link( (int) $made, $term->taxonomy ) : $back );
		exit;
	}

	/**
	 * A language's own name ("Deutsch"), or its code when it has none.
	 *
	 * @param string $lang A language code, as given.
	 * @return string
	 */
	public static function language_name( string $lang ): string {
		$code = (string) Languages::resolve( $lang );
		foreach ( Languages::all() as $language ) {
			if ( $code === $language['code'] && '' !== (string) $language['name'] ) {
				return (string) $language['name'];
			}
		}

		return '' !== $code ? $code : $lang;
	}

	/**
	 * Keep an outcome for the next admin screen this user opens (the term and post screens share it).
	 *
	 * @param string $status  `success` or `error`.
	 * @param string $message Plain text.
	 * @return void
	 */
	public static function remember( string $status, string $message ): void {
		set_transient(
			'tranzly_term_notice_' . get_current_user_id(),
			array(
				'status'  => $status,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * `admin_notices`: the outcome of the last action, once.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$key    = 'tranzly_term_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$class = 'error' === ( $notice['status'] ?? '' ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
	}
}
