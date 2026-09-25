/**
 * Language-list rules. They mirror Settings::is_valid_code() and the de-duplication in PHP,
 * which is the authority: the server refuses anything this lets through by mistake.
 */

/**
 * @param {string} code Candidate WordPress locale code.
 * @return {boolean} Whether it has the shape of a locale (`fr`, `fr_FR`, `de_CH_informal`).
 */
export function isValidCode( code ) {
	return (
		typeof code === 'string' &&
		/^[a-z]{2,3}(?:_[A-Z]{2}(?:_[a-z0-9]{2,12})?)?$/.test( code )
	);
}

/**
 * Add a language unless it is invalid or already listed (case-insensitively).
 *
 * @param {Array<{code: string, name: string}>} languages Current list.
 * @param {string}                              code      Code to add.
 * @param {string}                              name      Display name, may be empty.
 * @return {Array<{code: string, name: string}>} The new list (the same array when unchanged).
 */
export function addLanguage( languages, code, name ) {
	const trimmed = ( code || '' ).trim();
	if ( ! isValidCode( trimmed ) ) {
		return languages;
	}
	const exists = languages.some(
		( language ) => language.code.toLowerCase() === trimmed.toLowerCase()
	);
	if ( exists ) {
		return languages;
	}
	return [ ...languages, { code: trimmed, name: ( name || '' ).trim() } ];
}
