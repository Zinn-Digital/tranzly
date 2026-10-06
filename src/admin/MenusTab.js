import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { changedStrings } from './menus-model';

/**
 * "Menus and templates" (tz-c4, Pro tz-c12): a separate menu per language, the words of menus
 * (and, with Pro, of block-theme templates and patterns) translated as shared text, and each
 * translation editable by hand — a hand edit is protected from the machine.
 *
 * @param {Object}  props
 * @param {boolean} props.pro Whether Pro is active.
 * @return {Element} The tab.
 */
export default function MenusTab( { pro } ) {
	const [ menus, setMenus ] = useState( null );
	const [ languages, setLanguages ] = useState( [] );
	const [ lang, setLang ] = useState( '' );
	const [ scope, setScope ] = useState( 'menus' );
	const [ strings, setStrings ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const fail = ( e ) => setNotice( { status: 'error', message: e.message } );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/tranzly/v1/menus' } ),
			apiFetch( { path: '/tranzly/v1/languages' } ),
		] )
			.then( ( [ m, l ] ) => {
				setMenus( m );
				const others = l.languages.filter(
					( x ) => x.code !== l.default
				);
				setLanguages( others );
				setLang( others[ 0 ]?.code || '' );
			} )
			.catch( fail );
	}, [] );

	const loadStrings = useCallback( () => {
		if ( ! lang ) {
			return;
		}
		setStrings( null );
		apiFetch( {
			path: `/tranzly/v1/shared-strings?lang=${ encodeURIComponent(
				lang
			) }&scope=${ scope }`,
		} )
			.then( ( result ) => {
				setStrings( result.strings );
				setDraft(
					Object.fromEntries(
						result.strings.map( ( s ) => [ s.key, s.translation ] )
					)
				);
			} )
			.catch( fail );
	}, [ lang, scope ] );

	useEffect( loadStrings, [ loadStrings ] );

	if ( ! menus ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const saveMap = ( location, code, menu ) => {
		const map = JSON.parse( JSON.stringify( menus.map || {} ) );
		map[ location ] = { ...( map[ location ] || {} ), [ code ]: menu };
		apiFetch( { path: '/tranzly/v1/menus', method: 'PUT', data: { map } } )
			.then( ( result ) => {
				setMenus( result );
				setNotice( {
					status: 'success',
					message: __( 'Menus saved.', 'tranzly' ),
				} );
			} )
			.catch( fail );
	};

	const translateMissing = async () => {
		setBusy( true );
		setNotice( null );
		let after = 0;
		let total = 0;
		try {
			while ( null !== after ) {
				const page = await apiFetch( {
					path: '/tranzly/v1/shared-strings',
					method: 'POST',
					data: { lang, scope, after },
				} );
				total += page.translated;
				after = page.next;
			}
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
		loadStrings();
	};

	const saveStrings = () => {
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
				loadStrings();
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	const menuOptions = [
		{ value: '0', label: __( 'The same menu, translated', 'tranzly' ) },
		...menus.menus.map( ( m ) => ( {
			value: String( m.id ),
			label: m.name,
		} ) ),
	];

	return (
		<div className="tranzly-admin__menus">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<Panel>
				{ menus.locations.length > 0 && (
					<PanelBody
						title={ __(
							'A separate menu per language',
							'tranzly'
						) }
					>
						<p>
							{ __(
								'By default each menu is translated: its links lead to the translated pages, with their titles. Choose a menu of its own for a language here instead.',
								'tranzly'
							) }
						</p>
						{ menus.locations.map( ( location ) => (
							<fieldset key={ location.slug }>
								<legend>
									<strong>{ location.name }</strong>
								</legend>
								{ languages.map( ( language ) => (
									<SelectControl
										__next40pxDefaultSize
										__nextHasNoMarginBottom
										key={ language.code }
										label={ language.name }
										value={ String(
											menus.map?.[ location.slug ]?.[
												language.code
											] || 0
										) }
										options={ menuOptions }
										onChange={ ( value ) =>
											saveMap(
												location.slug,
												language.code,
												parseInt( value, 10 )
											)
										}
									/>
								) ) }
							</fieldset>
						) ) }
					</PanelBody>
				) }
				<PanelBody title={ __( 'Shared text', 'tranzly' ) }>
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
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Which text', 'tranzly' ) }
						value={ scope }
						options={ [
							{
								value: 'menus',
								label: __( 'Menu labels', 'tranzly' ),
							},
							{
								value: 'site',
								label: __(
									'Site title, tagline and widgets',
									'tranzly'
								),
							},
							// Pro only, and then simply offered: the free edition shows no locked
							// option (WordPress.org guideline 5, closure item T-2).
							...( pro
								? [
										{
											value: 'templates',
											label: __(
												'Templates, headers, footers and patterns',
												'tranzly'
											),
										},
									]
								: [] ),
						] }
						onChange={ setScope }
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
					{ ! strings && <Spinner /> }
					{ strings && ! strings.length && (
						<p>
							{ __(
								'There is no text to translate here.',
								'tranzly'
							) }
						</p>
					) }
					{ strings &&
						strings.map( ( row ) => (
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
					{ strings && strings.length > 0 && (
						<Button
							variant="secondary"
							disabled={
								busy ||
								! Object.keys(
									changedStrings( strings, draft )
								).length
							}
							onClick={ saveStrings }
						>
							{ __( 'Save corrections', 'tranzly' ) }
						</Button>
					) }
				</PanelBody>
			</Panel>
		</div>
	);
}
