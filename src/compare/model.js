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
		if ( 'string' === typeof next && next !== editable( segment ) ) {
			out[ segment.key ] = next;
		}
	}
	return out;
}

/**
 * What a person edits for a piece: its words when the piece is shown as text (`view: text`, the
 * markup kept by the server), else the piece itself.
 *
 * @param {Object} segment A row from GET posts/{id}/segments.
 * @return {string} The editable value.
 */
export function editable( segment ) {
	if ( 'text' === segment.view && 'string' === typeof segment.target_text ) {
		return segment.target_text;
	}
	return segment.target ?? '';
}

/**
 * The PUT body: pieces shown as text go in `texts` (the server puts them back inside their markup),
 * the rest in `segments`.
 *
 * @param {Array<Object>}         segments Rows.
 * @param {Object<string,string>} changes  Key => new value, from changedSegments().
 * @return {{segments: Object<string,string>, texts: Object<string,string>}} The body.
 */
export function saveBody( segments, changes ) {
	const body = { segments: {}, texts: {} };
	for ( const segment of segments ) {
		if ( ! ( segment.key in changes ) ) {
			continue;
		}
		const bucket =
			'text' === segment.view && 'string' === typeof segment.target_text
				? 'texts'
				: 'segments';
		body[ bucket ][ segment.key ] = changes[ segment.key ];
	}
	return body;
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
