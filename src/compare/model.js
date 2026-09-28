import { __ } from '@wordpress/i18n';

/*
 * The pure half of the side-by-side editor, shared with a unit test.
 */

/**
 * The pieces a person actually changed (only these are sent, and only these become "human").
 *
 * @param {Array<Object>}         segments Rows from GET posts/{id}/segments.
 * @param {Object<string,string>} draft    Key => text in the form.
 * @return {Object<string,string>} Key => new text.
 */
export function changedSegments( segments, draft ) {
	const out = {};
	for ( const segment of segments ) {
		if ( null === segment.target ) {
			continue;
		}
		const next = draft[ segment.key ];
		if ( 'string' === typeof next && next !== segment.target ) {
			out[ segment.key ] = next;
		}
	}
	return out;
}

/**
 * A piece's state in plain words.
 *
 * @param {string} state `machine`, `human`, `legacy`, `untranslated` or `missing`.
 * @return {string} Words.
 */
export function stateText( state ) {
	return (
		{
			machine: __( 'Machine translated', 'tranzly' ),
			human: __( 'Checked by a person', 'tranzly' ),
			legacy: __( 'Imported', 'tranzly' ),
			untranslated: __( 'Not translated yet', 'tranzly' ),
			missing: __( 'Not in the translation', 'tranzly' ),
		}[ state ] || state
	);
}
