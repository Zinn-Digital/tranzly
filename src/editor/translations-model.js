import { __, sprintf } from '@wordpress/i18n';

/*
 * The pure half of the editor's Translations panel, shared with a unit test.
 */

/**
 * May a machine translate into this language from the panel? Not the post's own language, not
 * the original, and never a protected translation (tz-r1) — that needs an explicit unlock first.
 *
 * @param {Object} row A language row from GET posts/{id}/languages.
 * @return {boolean} Whether it can be ticked.
 */
export function translatable( row ) {
	return ! row.is_self && ! row.is_source && ! row.protected;
}

/**
 * The state a person reads next to a language.
 *
 * @param {Object} row A language row.
 * @return {string} Plain words.
 */
export function stateLabel( row ) {
	if ( ! row.id ) {
		return __( 'Not translated', 'tranzly' );
	}
	if ( row.is_source ) {
		return __( 'Original', 'tranzly' );
	}
	const status =
		'publish' === row.status
			? __( 'Published', 'tranzly' )
			: __( 'Draft', 'tranzly' );
	const states = {
		human: __( 'Checked by a person, protected', 'tranzly' ),
		legacy: __( 'Imported, protected', 'tranzly' ),
		machine: __( 'Machine translated', 'tranzly' ),
		copy: __( 'Not translated yet (a copy)', 'tranzly' ),
	};
	return `${ status } · ${ states[ row.state ] || row.state }`;
}

/**
 * What the Site Editor edits without a numeric post: templates, template parts (a Header) and
 * navigation menus. Their words are translated as shared texts, not as a post in each language.
 */
export const SITE_EDITOR_TYPES = [
	'wp_template',
	'wp_template_part',
	'wp_navigation',
];

/**
 * Where the panel reads the languages of what is being edited.
 *
 * ⛔ A template part's id is `theme//header`, so `posts/theme//header/languages` matched no route
 * and the panel showed "No route was found matching the URL and request method" (ZT-STB8XM).
 *
 * @param {string}        postType The edited entity's type.
 * @param {number|string} postId   Its id.
 * @return {string} The REST path.
 */
export function languagesPath( postType, postId ) {
	if ( SITE_EDITOR_TYPES.includes( postType ) ) {
		return `/tranzly/v1/site-editor/languages?type=${ encodeURIComponent(
			postType
		) }&id=${ encodeURIComponent( String( postId ) ) }`;
	}
	return `/tranzly/v1/posts/${ postId }/languages`;
}

/**
 * The state of a template's texts in one language.
 *
 * @param {Object} row A row from GET site-editor/languages.
 * @return {string} Plain words.
 */
export function textsLabel( row ) {
	if ( row.is_default ) {
		return __( 'Original', 'tranzly' );
	}
	return sprintf(
		/* translators: 1: texts translated, 2: texts in the template. */
		__( '%1$d of %2$d texts translated', 'tranzly' ),
		row.translated,
		row.total
	);
}
