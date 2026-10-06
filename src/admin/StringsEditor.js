import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { changedStrings } from './menus-model';
import { walkPages } from './workflow-model';

/**
 * Translate one scope of shared text (form labels, shop e-mails, a theme's or plugin's strings):
 * the machine translates what is missing, a person corrects any line, and a correction is
 * protected from the machine.
 *
 * @param {Object} props
 * @param {string} props.scope     A shared-strings scope.
 * @param {Array}  props.languages The other languages: `[ { code, name } ]`.
 * @return {Element} The editor.
 */
export default function StringsEditor( { scope, languages } ) {
	const [ lang, setLang ] = useState( languages[ 0 ]?.code || '' );
	const [ strings, setStrings ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ filter, setFilter ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const fail = ( e ) => setNotice( { status: 'error', message: e.message } );

	const load = useCallback( () => {
		if ( ! lang ) {
			return;
		}
		setStrings( null );
		apiFetch( {
			path: `/tranzly/v1/shared-strings?lang=${ encodeURIComponent(
				lang
			) }&scope=${ encodeURIComponent( scope ) }`,
		} )
			.then( ( r ) => {
				setStrings( r.strings );
				setDraft(
					Object.fromEntries(
						r.strings.map( ( s ) => [ s.key, s.translation ] )
					)
				);
			} )
			.catch( fail );
	}, [ lang, scope ] );
	useEffect( load, [ load ] );

	const translateMissing = async () => {
		setBusy( true );
		let total = 0;
		try {
			await walkPages(
				( after ) =>
					apiFetch( {
						path: '/tranzly/v1/shared-strings',
						method: 'POST',
						data: { lang, scope, after },
					} ),
				( page ) => ( total += page.translated )
			);
			setNotice( {
				status: 'success',
				message: sprintf(
					/* translators: %d: how many texts were translated. */
					__( '%d texts translated.', 'tranzly' ),
					total
				),
			} );
		} catch ( e ) {
			fail( e );
		}
		setBusy( false );
		load();
	};

	const save = () => {
		setBusy( true );
		apiFetch( {
			path: '/tranzly/v1/shared-strings',
			method: 'PUT',
			data: { lang, scope, strings: changedStrings( strings, draft ) },
		} )
			.then( () => {
				setNotice( {
					status: 'success',
					message: __(
						'Saved. Your corrections are protected from machine translation.',
						'tranzly'
					),
				} );
				load();
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	const shown = ( strings || [] ).filter(
		( s ) =>
			! filter ||
			s.source.toLowerCase().includes( filter.toLowerCase() ) ||
			( draft[ s.key ] || '' )
				.toLowerCase()
				.includes( filter.toLowerCase() )
	);

	return (
		<div className="tranzly-strings">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Language', 'tranzly' ) }
				value={ lang }
				options={ languages.map( ( l ) => ( {
					value: l.code,
					label: l.name,
				} ) ) }
				onChange={ setLang }
			/>
			<p>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! lang }
					onClick={ translateMissing }
				>
					{ __( 'Translate the missing text', 'tranzly' ) }
				</Button>
			</p>
			{ null === strings && <Spinner /> }
			{ strings && ! strings.length && (
				<p>
					{ __( 'There is no text to translate here.', 'tranzly' ) }
				</p>
			) }
			{ strings && strings.length > 20 && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Find a text', 'tranzly' ) }
					value={ filter }
					onChange={ setFilter }
				/>
			) }
			{ shown.slice( 0, 200 ).map( ( row ) => (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					key={ row.key }
					label={ row.source.replace( /<[^>]+>/g, '' ) }
					help={
						'human' === row.state
							? __(
									'Corrected by a person, protected',
									'tranzly'
								)
							: undefined
					}
					value={ draft[ row.key ] || '' }
					onChange={ ( value ) =>
						setDraft( { ...draft, [ row.key ]: value } )
					}
				/>
			) ) }
			{ shown.length > 200 && (
				<p>
					{ sprintf(
						/* translators: %d: how many more texts match. */
						__(
							'%d more: type in "Find a text" to narrow the list.',
							'tranzly'
						),
						shown.length - 200
					) }
				</p>
			) }
			{ strings && strings.length > 0 && (
				<Button
					variant="secondary"
					disabled={
						busy ||
						! Object.keys( changedStrings( strings, draft ) ).length
					}
					onClick={ save }
				>
					{ __( 'Save corrections', 'tranzly' ) }
				</Button>
			) }
		</div>
	);
}
