<?php
/**
 * Creating the post (or term) that holds a translation, and keeping groups tidy when content is
 * deleted.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Core;

use ZinnDigital\Tranzly\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts, pages, custom post types (tz-c1) and categories, tags, custom taxonomies (tz-c3).
 *
 * ⛔ NO BLANKET META COPY. The legacy plugin copied every meta row of the original onto each
 * translation with a raw `$wpdb->insert()` — edit locks, old slugs, other plugins' private state —
 * which is also how every translation came to claim its siblings as its own children. A new
 * translation gets ONLY the keys on an allow-list: the featured image and the page template,
 * plus whatever `tranzly_copy_meta_keys` adds. Protected keys a plugin adds that way are its own
 * decision; nothing is copied by default.
 *
 * ⛔ Every write path checks capability HERE, not only in the REST/CLI layer, so no future caller
 * can forget it (11-audit-tranzly.md §5 findings 2 and 8: the legacy handlers checked nothing).
 */
final class Content {

	/** Meta keys copied onto a new translation. */
	private const COPY_META = array( '_thumbnail_id', '_wp_page_template' );

	/**
	 * Hook the clean-up.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'deleted_post', array( self::class, 'on_deleted_post' ), 10, 1 );
		add_action( 'delete_term', array( self::class, 'on_deleted_term' ), 10, 1 );
	}

	/**
	 * May the current user create a translation of this post?
	 *
	 * @param int $post_id The source post.
	 * @return bool
	 */
	public static function can_translate_post( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_translatable_type( $post->post_type ) ) {
			return false;
		}
		$type = get_post_type_object( $post->post_type );

		return null !== $type
			&& current_user_can( 'edit_post', $post->ID )
			&& current_user_can( $type->cap->create_posts );
	}

	/**
	 * May the current user create a translation of this term?
	 *
	 * @param int $term_id The source term.
	 * @return bool
	 */
	public static function can_translate_term( int $term_id ): bool {
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term || ! self::is_translatable_taxonomy( $term->taxonomy ) ) {
			return false;
		}
		$taxonomy = get_taxonomy( $term->taxonomy );

		return false !== $taxonomy
			&& current_user_can( 'edit_term', $term->term_id )
			&& current_user_can( $taxonomy->cap->edit_terms );
	}

	/**
	 * Is this post type translated? Every public type except attachments (media are translated as
	 * text on the one attachment, see Strings), adjustable with `tranzly_post_types`.
	 *
	 * @param string $post_type A post type.
	 * @return bool
	 */
	public static function is_translatable_type( string $post_type ): bool {
		return in_array( $post_type, self::post_types(), true );
	}

	/**
	 * Every translated post type.
	 *
	 * @return array<int, string>
	 */
	public static function post_types(): array {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

		/**
		 * Filters the post types Tranzly translates.
		 *
		 * @param array<int, string> $types Public post types except attachments.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'tranzly_post_types', $types ) ) );
	}

	/**
	 * Is this taxonomy translated? Every public taxonomy except post formats and nav menus,
	 * adjustable with `tranzly_taxonomies`.
	 *
	 * @param string $taxonomy A taxonomy.
	 * @return bool
	 */
	public static function is_translatable_taxonomy( string $taxonomy ): bool {
		$taxonomies = array_values( array_diff( get_taxonomies( array( 'public' => true ) ), array( 'post_format', 'nav_menu' ) ) );

		/**
		 * Filters the taxonomies Tranzly translates.
		 *
		 * @param array<int, string> $taxonomies Public taxonomies except post formats and menus.
		 */
		return in_array( $taxonomy, (array) apply_filters( 'tranzly_taxonomies', $taxonomies ), true );
	}

	/**
	 * Create the `$lang` translation of a post: a DRAFT copy linked into the post's group, holding
	 * the original text until a person or an engine translates it.
	 *
	 * @param int    $source_id The post to translate.
	 * @param string $lang      A listed language code.
	 * @return int|\WP_Error The new post's ID.
	 */
	public static function create_post_translation( int $source_id, string $lang ) {
		$code = Languages::resolve( $lang );
		if ( null === $code ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'That language is not one of this site\'s languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		if ( ! self::can_translate_post( $source_id ) ) {
			return new \WP_Error( 'tranzly_forbidden', __( 'You are not allowed to translate this item.', 'tranzly' ), array( 'status' => 403 ) );
		}
		$source      = get_post( $source_id );
		$source_lang = Relations::language_of( 'post', $source_id ) ?? Languages::default_code();
		$existing    = Relations::translations( 'post', $source_id )[ $code ] ?? null;
		if ( $source_lang === $code || null !== $existing ) {
			return new \WP_Error(
				'tranzly_language_taken',
				__( 'This item already has a version in that language.', 'tranzly' ),
				array(
					'status' => 409,
					'id'     => $existing ?? $source_id,
				)
			);
		}

		$parent = 0;
		if ( $source->post_parent > 0 ) {
			$parent = Relations::translations( 'post', (int) $source->post_parent )[ $code ] ?? (int) $source->post_parent;
		}

		// ⛔ Creating the draft and linking it are ONE transaction. A worker killed between the two
		// (measured: a SIGKILL right after wp_insert_post) otherwise left a draft in no group, which
		// no later attempt could see and the customer's post list kept for ever. A killed process
		// drops its connection, and InnoDB rolls the half-made translation back.
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, no data to cache.
		$new_id = self::insert_translation( $source, $parent );
		if ( is_wp_error( $new_id ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
			return $new_id;
		}

		/**
		 * Filters the meta keys copied from a post onto its new translation. Nothing else is copied.
		 *
		 * @param array<int, string> $keys      `_thumbnail_id` and `_wp_page_template`.
		 * @param int                $source_id The original post.
		 */
		$keys = (array) apply_filters( 'tranzly_copy_meta_keys', self::COPY_META, $source_id );
		foreach ( array_unique( array_map( 'strval', $keys ) ) as $key ) {
			$value = get_post_meta( $source_id, $key, true );
			if ( '' !== $value && null !== $value ) {
				update_post_meta( $new_id, $key, wp_slash( $value ) );
			}
		}

		foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) || array() === $terms ) {
				continue;
			}
			if ( self::is_translatable_taxonomy( $taxonomy ) ) {
				Relations::prime( 'term', array_map( 'intval', $terms ) );
				$terms = array_map( static fn( $t ) => Relations::translations( 'term', (int) $t )[ $code ] ?? (int) $t, $terms );
			}
			wp_set_object_terms( $new_id, array_map( 'intval', $terms ), $taxonomy );
		}

		$linked = Relations::link( 'post', $source_id, $source_lang, (int) $new_id, $code );
		if ( is_wp_error( $linked ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
			clean_post_cache( (int) $new_id );
			return $linked;
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.

		/**
		 * Fires after Tranzly creates a post translation.
		 *
		 * @param int    $new_id    The translation.
		 * @param int    $source_id The original.
		 * @param string $code      Its language.
		 */
		do_action( 'tranzly_post_translation_created', (int) $new_id, $source_id, $code );

		return (int) $new_id;
	}

	/**
	 * Create the `$lang` translation of a term, linked into the term's group. Name and slug may be
	 * given (the translation); when absent, the original's are used with the language added, since
	 * WordPress refuses two terms with the same name at one level.
	 *
	 * @param int                  $term_id The term to translate.
	 * @param string               $lang    A listed language code.
	 * @param array<string, mixed> $fields  Optional `name`, `slug`, `description`.
	 * @return int|\WP_Error The new term's ID.
	 */
	public static function create_term_translation( int $term_id, string $lang, array $fields = array() ) {
		$code = Languages::resolve( $lang );
		if ( null === $code ) {
			return new \WP_Error( 'tranzly_unknown_language', __( 'That language is not one of this site\'s languages.', 'tranzly' ), array( 'status' => 400 ) );
		}
		if ( ! self::can_translate_term( $term_id ) ) {
			return new \WP_Error( 'tranzly_forbidden', __( 'You are not allowed to translate this item.', 'tranzly' ), array( 'status' => 403 ) );
		}
		$term        = get_term( $term_id );
		$source_lang = Relations::language_of( 'term', $term_id ) ?? Languages::default_code();
		if ( $source_lang === $code || isset( Relations::translations( 'term', $term_id )[ $code ] ) ) {
			return new \WP_Error( 'tranzly_language_taken', __( 'This item already has a version in that language.', 'tranzly' ), array( 'status' => 409 ) );
		}

		$suffix = strtolower( str_replace( '_', '-', $code ) );
		$name   = trim( sanitize_text_field( (string) ( $fields['name'] ?? '' ) ) );
		$name   = '' === $name ? $term->name . ' (' . $code . ')' : $name;
		$slug   = sanitize_title( (string) ( $fields['slug'] ?? '' ) );
		$slug   = '' === $slug ? $term->slug . '-' . $suffix : $slug;
		$parent = 0;
		if ( $term->parent > 0 ) {
			$parent = Relations::translations( 'term', (int) $term->parent )[ $code ] ?? (int) $term->parent;
		}

		$made = wp_insert_term(
			$name,
			$term->taxonomy,
			array(
				'slug'        => $slug,
				'parent'      => $parent,
				'description' => array_key_exists( 'description', $fields ) ? wp_kses_post( (string) $fields['description'] ) : $term->description,
			)
		);
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		$new_id = (int) $made['term_id'];

		$linked = Relations::link( 'term', $term_id, $source_lang, $new_id, $code );
		if ( is_wp_error( $linked ) ) {
			wp_delete_term( $new_id, $term->taxonomy );
			return $linked;
		}

		/**
		 * Fires after Tranzly creates a term translation.
		 *
		 * @param int    $new_id  The translation.
		 * @param int    $term_id The original.
		 * @param string $code    Its language.
		 */
		do_action( 'tranzly_term_translation_created', $new_id, $term_id, $code );

		return $new_id;
	}

	/**
	 * `deleted_post`: drop the post's row so no group points at a post that is gone.
	 *
	 * @param int $post_id The deleted post.
	 * @return void
	 */
	public static function on_deleted_post( $post_id ): void {
		Relations::delete( 'post', (int) $post_id );
	}

	/**
	 * `delete_term`.
	 *
	 * @param int $term_id The deleted term.
	 * @return void
	 */
	public static function on_deleted_term( $term_id ): void {
		Relations::delete( 'term', (int) $term_id );
	}

	/**
	 * Insert the draft copy of `$source`.
	 *
	 * @param \WP_Post $source The original.
	 * @param int      $parent_id The parent the copy gets.
	 * @return int|\WP_Error
	 */
	private static function insert_translation( \WP_Post $source, int $parent_id ) {
		return wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $source->post_type,
					'post_status'    => 'draft',
					'post_title'     => $source->post_title,
					'post_content'   => $source->post_content,
					'post_excerpt'   => $source->post_excerpt,
					'post_parent'    => $parent_id,
					'menu_order'     => $source->menu_order,
					'comment_status' => $source->comment_status,
					'ping_status'    => $source->ping_status,
					'post_password'  => $source->post_password,
					'post_author'    => get_current_user_id() > 0 ? get_current_user_id() : (int) $source->post_author,
				)
			),
			true
		);
	}
}
