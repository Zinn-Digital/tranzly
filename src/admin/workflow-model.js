/*
 * The pure half of the Status, Workflow and Integrations tabs (lane L06), shared with a unit test.
 */

/**
 * Walk a cursor-paged endpoint to its end: `fetchPage( cursor )` answers `{ next, ... }`; the pages
 * are handed to `onPage` as they arrive. There is no cap: a site of any size is walked whole.
 *
 * @param {Function} fetchPage `( cursor ) => Promise<Object>`.
 * @param {Function} onPage    `( page ) => void`.
 * @param {*}        start     The first cursor.
 * @return {Promise<number>} How many pages.
 */
export async function walkPages( fetchPage, onPage, start = 0 ) {
	let cursor = start;
	let pages = 0;
	do {
		const page = await fetchPage( cursor );
		onPage( page );
		pages++;
		cursor = page.next;
	} while ( null !== cursor && undefined !== cursor );
	return pages;
}

/**
 * Add one estimate page to a running total. The cost stays unknown (null) once any page's is.
 *
 * @param {Object} total Running total.
 * @param {Object} page  One page from POST workflow/estimate.
 * @return {Object} The new total.
 */
export function addEstimate( total, page ) {
	return {
		items: ( total.items || 0 ) + ( page.items || 0 ),
		characters: ( total.characters || 0 ) + ( page.characters || 0 ),
		cost_usd:
			null === total.cost_usd || null === page.cost_usd
				? null
				: Math.round(
						( ( total.cost_usd || 0 ) + ( page.cost_usd || 0 ) ) *
							10000
					) / 10000,
	};
}

/**
 * Percentage of originals translated in a language (0 when there are none to translate).
 *
 * @param {Object} row A language row from GET status.
 * @return {number} 0..100.
 */
export function coverage( row ) {
	const todo = ( row.translated || 0 ) + ( row.missing || 0 );
	return 0 === todo
		? 100
		: Math.round( ( ( row.translated || 0 ) * 100 ) / todo );
}

/**
 * The export URL for a choice (the base URL already carries its nonce).
 *
 * @param {string} base   `export_url` from the settings.
 * @param {Object} choice `lang`, `format`, `state`, `post_type`.
 * @return {string} URL.
 */
export function exportUrl( base, choice ) {
	const url = new URL( base, 'http://x.invalid' );
	for ( const key of [ 'lang', 'format', 'state', 'post_type' ] ) {
		if ( choice[ key ] ) {
			url.searchParams.set( key, choice[ key ] );
		}
	}
	return base.startsWith( 'http' )
		? url.toString()
		: url.pathname + url.search;
}
