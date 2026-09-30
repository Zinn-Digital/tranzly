/**
 * The language picker's options (tz-f7): every language WordPress itself is translated into, so a
 * site owner chooses "Deutsch" instead of having to know the code `de_DE`.
 *
 * `locales.json` is WordPress.org's own list of core translations
 * (https://api.wordpress.org/translations/core/1.0/, read 2026-09-30) plus `en_US`, reduced to the
 * code and the language's name in itself. It is bundled rather than fetched, so the screen makes no
 * outbound request (T9a: nothing leaves the site before consent). The name in the ADMIN's own
 * language comes from the browser (`Intl.DisplayNames`), so the picker reads naturally in every one
 * of the 58 interface languages with no English-only text.
 */
import locales from './locales.json';

/**
 * A language's name in the given interface language, or '' when the browser cannot say.
 *
 * @param {string} code   WordPress locale code (`de_DE`).
 * @param {string} uiLang BCP 47 tag of the admin's language (`fr-FR`).
 * @return {string} Name.
 */
export function displayName( code, uiLang ) {
	try {
		const names = new Intl.DisplayNames( [ uiLang || 'en' ], {
			type: 'language',
			fallback: 'none',
		} );
		const tag = code.split( '_' ).slice( 0, 2 ).join( '-' );
		const name = names.of( tag );
		return name && name !== tag ? name : '';
	} catch {
		return '';
	}
}

/**
 * Options for the picker: every known language not on the site yet.
 *
 * @param {Array<{code:string}>}       existing Languages already on the site.
 * @param {string}                     uiLang   The admin's language.
 * @param {Array<{c:string,n:string}>} [list]   Locale list (tests pass their own).
 * @return {Array<{value:string,label:string,name:string}>} Options, value = code.
 */
export function localeOptions( existing, uiLang, list = locales ) {
	const have = new Set(
		( existing || [] ).map( ( l ) => String( l.code ).toLowerCase() )
	);
	return list
		.filter( ( l ) => ! have.has( l.c.toLowerCase() ) )
		.map( ( l ) => {
			const local = displayName( l.c, uiLang );
			const parts = [ l.n ];
			if ( local && local !== l.n ) {
				parts.push( local );
			}
			return {
				value: l.c,
				label: `${ parts.join( ' — ' ) } (${ l.c })`,
				name: l.n,
			};
		} );
}
