/*
 * The pure half of the per-language block rule (tz-r7), shared with a unit test. The server's
 * Visibility::visible() reads the same shape: { mode: 'only'|'except', langs: [codes] }.
 */

/**
 * The next rule after a change in the Languages panel.
 *
 * @param {Object}  rule            Current rule.
 * @param {Object}  change
 * @param {string}  change.mode     'all', 'only' or 'except'.
 * @param {string}  [change.toggle] A language code to tick or untick.
 * @param {boolean} [change.on]     Whether it is ticked.
 * @return {Object} The new rule ({} = every language).
 */
export function withRule( rule, { mode, toggle, on } ) {
	if ( 'all' === mode ) {
		return {};
	}
	let langs = [ ...( rule?.langs || [] ) ];
	if ( toggle ) {
		langs = on
			? Array.from( new Set( [ ...langs, toggle ] ) )
			: langs.filter( ( code ) => code !== toggle );
	}
	return { mode, langs };
}

/**
 * Does a rule let a language see the block? (Mirror of Visibility::visible().)
 *
 * @param {Object} rule Rule.
 * @param {string} lang A language code.
 * @return {boolean} Whether it is shown.
 */
export function isVisible( rule, lang ) {
	const langs = rule?.langs || [];
	if ( ! langs.length ) {
		return true;
	}
	const inList = langs.includes( lang );
	return 'except' === rule.mode ? ! inList : inList;
}
