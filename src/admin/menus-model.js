/*
 * The pure half of the Menus and templates tab, shared with a unit test.
 */

/**
 * The translations a person changed.
 *
 * @param {Array<Object>}         rows  Rows from GET shared-strings.
 * @param {Object<string,string>} draft Key => text in the form.
 * @return {Object<string,string>} Key => new text.
 */
export function changedStrings( rows, draft ) {
	const out = {};
	for ( const row of rows || [] ) {
		const next = draft[ row.key ];
		if ( 'string' === typeof next && next !== ( row.translation || '' ) ) {
			out[ row.key ] = next;
		}
	}
	return out;
}
