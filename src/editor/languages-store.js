import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

/*
 * The site's languages, fetched ONCE per editor session however many blocks ask
 * (GET tranzly/v1/languages is public and small).
 */
let pending = null;

/**
 * The site's languages ({code, name}[]), or null while loading.
 *
 * @return {Array<{code:string,name:string}>|null} Languages.
 */
export function useLanguages() {
	const [ languages, setLanguages ] = useState( null );
	useEffect( () => {
		if ( ! pending ) {
			pending = apiFetch( { path: '/tranzly/v1/languages' } ).then(
				( result ) => result.languages || [],
				() => []
			);
		}
		let live = true;
		pending.then( ( list ) => live && setLanguages( list ) );
		return () => {
			live = false;
		};
	}, [] );
	return languages;
}
