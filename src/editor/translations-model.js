import { __ } from '@wordpress/i18n';

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
