/**
 * Pure helpers for the Engines tab: the glossary and the do-not-translate list travel as JSON
 * and are edited as plain text, one entry per line.
 */

/**
 * One word or phrase per line → a clean, de-duplicated list.
 *
 * @param {string} text Textarea contents.
 * @return {string[]} The list.
 */
export function linesToList( text ) {
	const seen = new Set();
	return String( text || '' )
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.filter( ( line ) => {
			if ( '' === line || seen.has( line ) ) {
				return false;
			}
			seen.add( line );
			return true;
		} );
}

/**
 * `source = target` per line → { source: target }. A line without `=` is ignored, and so is a
 * pair with an empty side.
 *
 * @param {string} text Textarea contents.
 * @return {Object<string,string>} The terms.
 */
export function linesToTerms( text ) {
	const terms = {};
	String( text || '' )
		.split( /\r?\n/ )
		.forEach( ( line ) => {
			const at = line.indexOf( '=' );
			if ( at < 1 ) {
				return;
			}
			const from = line.slice( 0, at ).trim();
			const to = line.slice( at + 1 ).trim();
			if ( '' !== from && '' !== to ) {
				terms[ from ] = to;
			}
		} );
	return terms;
}

/**
 * { source: target } → `source = target` per line.
 *
 * @param {Object<string,string>} terms The terms.
 * @return {string} Textarea contents.
 */
export function termsToLines( terms ) {
	return Object.entries( terms || {} )
		.map( ( [ from, to ] ) => `${ from } = ${ to }` )
		.join( '\n' );
}

/**
 * Percentage of a job's items that are finished (done, skipped, failed or handled by hand).
 *
 * @param {Object} progress A job's progress from the REST API.
 * @return {number} 0-100.
 */
export function percentDone( progress ) {
	if ( ! progress || ! progress.total ) {
		return 0;
	}
	const c = progress.counts || {};
	const finished =
		( c.done || 0 ) +
		( c.skipped || 0 ) +
		( c.failed || 0 ) +
		( c.manual || 0 );
	return Math.min( 100, Math.round( ( finished / progress.total ) * 100 ) );
}
