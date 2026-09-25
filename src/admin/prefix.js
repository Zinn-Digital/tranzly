/**
 * The front-end class prefix rule. It mirrors Settings::is_valid_prefix() in PHP, which is the
 * authority: the server refuses anything this lets through by mistake.
 */
const FOOTPRINT_WORDS = [ 'pbs', 'sandwich', 'tranzly', 'builder' ];

/**
 * @param {string} prefix Candidate prefix.
 * @return {boolean} Whether the prefix is usable.
 */
export function isValidPrefix( prefix ) {
	if (
		typeof prefix !== 'string' ||
		! /^[a-z][a-z0-9]{0,7}$/.test( prefix )
	) {
		return false;
	}
	return ! FOOTPRINT_WORDS.some( ( word ) => prefix.includes( word ) );
}
