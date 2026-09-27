import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { linesToList, linesToTerms, termsToLines } from './glossary';

/** Engines whose key Tranzly stores itself (AI keys are set in the AI settings). */
const KEYED = [ 'deepl', 'google', 'microsoft' ];

/**
 * The Engines tab: keys, which engine translates which language, fallback, monthly caps, and the
 * glossary / do-not-translate list / tone. Self-contained: it talks to the REST API only, so the
 * shared admin kit (F3) can re-skin it without touching this logic.
 *
 * @return {Element} The tab.
 */
export default function Engines() {
	const [ state, setState ] = useState( null );
	const [ glossary, setGlossary ] = useState( null );
	const [ keys, setKeys ] = useState( {} );
	const [ region, setRegion ] = useState( '' );
	const [ usage, setUsage ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	// Stable across renders (only state setters inside), so the effect below runs once.
	const fail = useCallback(
		( error ) => setNotice( { status: 'error', message: error.message } ),
		[]
	);

	const reload = useCallback(
		() =>
			Promise.all( [
				apiFetch( { path: '/tranzly/v1/engines/settings' } ),
				apiFetch( { path: '/tranzly/v1/glossary' } ),
			] )
				.then( ( [ engines, words ] ) => {
					setState( engines );
					setGlossary( {
						dnt: ( words.dnt || [] ).join( '\n' ),
						terms: Object.fromEntries(
							Object.entries( words.terms || {} ).map(
								( [ lang, pairs ] ) => [
									lang,
									termsToLines( pairs ),
								]
							)
						),
						tone: words.tone || {},
					} );
				} )
				.catch( fail ),
		[ fail ]
	);

	useEffect( () => {
		reload();
	}, [ reload ] );

	if ( null === state && null === notice ) {
		return <Spinner />;
	}

	const pro = !! state?.pro;
	const engines = state?.engines || [];
	const languages = ( state?.languages || [] ).slice( 1 );
	const settings = state?.settings || {
		default: '',
		per_lang: {},
		fallback: [],
		caps: {},
	};
	const configured = engines.filter( ( engine ) => engine.configured );
	const engineOptions = [
		{ value: '', label: __( 'First engine that is set up', 'tranzly' ) },
		...configured.map( ( engine ) => ( {
			value: engine.id,
			label: engine.label,
		} ) ),
	];

	const put = ( path, data, message ) => {
		setBusy( true );
		setNotice( null );
		return apiFetch( { path, method: 'PUT', data } )
			.then( () => {
				setNotice( { status: 'success', message } );
				return reload();
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	const saveKey = ( id ) =>
		put(
			`/tranzly/v1/engines/${ id }/key`,
			{ key: keys[ id ] || '', region: 'microsoft' === id ? region : '' },
			__(
				'Key saved. It is stored encrypted and never shown again.',
				'tranzly'
			)
		).then( () => setKeys( { ...keys, [ id ]: '' } ) );

	const saveSettings = ( changes ) =>
		put(
			'/tranzly/v1/engines/settings',
			changes,
			__( 'Engine settings saved.', 'tranzly' )
		);

	const saveGlossary = () =>
		put(
			'/tranzly/v1/glossary',
			{
				dnt: linesToList( glossary.dnt ),
				...( pro
					? {
							terms: Object.fromEntries(
								Object.entries( glossary.terms ).map(
									( [ lang, text ] ) => [
										lang,
										linesToTerms( text ),
									]
								)
							),
							tone: glossary.tone,
						}
					: {} ),
			},
			__( 'Glossary saved.', 'tranzly' )
		);

	const checkUsage = () => {
		setUsage( null );
		apiFetch( { path: '/tranzly/v1/engines/deepl/usage' } )
			.then( setUsage )
			.catch( fail );
	};

	return (
		<div className="tranzly-admin tranzly-engines">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<Panel>
				<PanelBody title={ __( 'Your keys', 'tranzly' ) } initialOpen>
					<p>
						{ __(
							'Tranzly calls the translation services with your own account. Keys are stored encrypted in your database and are only ever sent to the service they belong to. AI model keys are set in the AI settings.',
							'tranzly'
						) }
					</p>
					{ engines
						.filter( ( engine ) => KEYED.includes( engine.id ) )
						.map( ( engine ) => (
							<div
								key={ engine.id }
								className="tranzly-engines__key"
							>
								<TextControl
									__next40pxDefaultSize
									type="password"
									autoComplete="off"
									label={ sprintf(
										/* translators: %s: a translation service's name. */
										__( '%s API key', 'tranzly' ),
										engine.label
									) }
									help={
										engine.key
											? sprintf(
													/* translators: %s: the last characters of a saved key, masked. */
													__(
														'Saved: %s. Enter a new key to replace it, or save an empty field to remove it.',
														'tranzly'
													),
													engine.key
												)
											: __( 'No key saved.', 'tranzly' )
									}
									value={ keys[ engine.id ] || '' }
									onChange={ ( value ) =>
										setKeys( {
											...keys,
											[ engine.id ]: value,
										} )
									}
								/>
								{ 'microsoft' === engine.id && (
									<TextControl
										__next40pxDefaultSize
										label={ __(
											'Azure region (leave empty for a global resource)',
											'tranzly'
										) }
										value={ region }
										onChange={ setRegion }
									/>
								) }
								<Button
									variant="secondary"
									isBusy={ busy }
									disabled={ busy }
									onClick={ () => saveKey( engine.id ) }
								>
									{ __( 'Save key', 'tranzly' ) }
								</Button>
								{ 'deepl' === engine.id &&
									engine.configured && (
										<Button
											variant="link"
											onClick={ checkUsage }
										>
											{ __(
												'Check my DeepL usage',
												'tranzly'
											) }
										</Button>
									) }
								{ 'deepl' === engine.id && usage && (
									<p>
										{ sprintf(
											/* translators: 1: characters used, 2: the account's limit. */
											__(
												'%1$s of %2$s characters used this billing period.',
												'tranzly'
											),
											usage.used.toLocaleString(),
											usage.limit.toLocaleString()
										) }
									</p>
								) }
							</div>
						) ) }
				</PanelBody>

				<PanelBody
					title={ __( 'Which engine translates', 'tranzly' ) }
					initialOpen
				>
					{ 0 === configured.length && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'No engine is set up yet. Save a key above (or set up an AI model) to start translating.',
								'tranzly'
							) }
						</Notice>
					) }
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Default engine', 'tranzly' ) }
						value={ settings.default }
						options={ engineOptions }
						onChange={ ( value ) =>
							saveSettings( { default: value } )
						}
					/>
					{ ! pro && (
						<p className="tranzly-engines__pro">
							{ __(
								'A different engine per language, automatic fallback and monthly spending caps are Tranzly Pro features.',
								'tranzly'
							) }
						</p>
					) }
					{ pro &&
						languages.map( ( language ) => (
							<SelectControl
								key={ language.code }
								__next40pxDefaultSize
								label={ sprintf(
									/* translators: %s: a language name. */
									__( 'Engine for %s', 'tranzly' ),
									language.name
								) }
								value={
									settings.per_lang[ language.code ] || ''
								}
								options={ [
									{
										value: '',
										label: __(
											'The default engine',
											'tranzly'
										),
									},
									...engineOptions.slice( 1 ),
								] }
								onChange={ ( value ) => {
									const perLang = { ...settings.per_lang };
									if ( value ) {
										perLang[ language.code ] = value;
									} else {
										delete perLang[ language.code ];
									}
									saveSettings( { per_lang: perLang } );
								} }
							/>
						) ) }
					{ pro && (
						<fieldset className="tranzly-engines__fallback">
							<legend>
								{ __(
									'If an engine fails or reaches a limit, try these next, in this order',
									'tranzly'
								) }
							</legend>
							{ configured.map( ( engine ) => (
								<CheckboxControl
									key={ engine.id }
									label={ engine.label }
									checked={ settings.fallback.includes(
										engine.id
									) }
									onChange={ ( on ) =>
										saveSettings( {
											fallback: on
												? [
														...settings.fallback,
														engine.id,
													]
												: settings.fallback.filter(
														( id ) =>
															id !== engine.id
													),
										} )
									}
								/>
							) ) }
						</fieldset>
					) }
				</PanelBody>

				{ pro && (
					<PanelBody
						title={ __( 'Monthly spending caps', 'tranzly' ) }
						initialOpen={ false }
					>
						<p>
							{ __(
								'When an engine reaches its cap, nothing more is sent to it until next month and the failed-translation report says why. Leave empty for no cap.',
								'tranzly'
							) }
						</p>
						{ configured.map( ( engine ) => (
							<TextControl
								key={ engine.id }
								__next40pxDefaultSize
								type="number"
								min="0"
								step="0.01"
								label={ sprintf(
									/* translators: 1: an engine's name, 2: dollars spent this month. */
									__(
										'%1$s (spent this month: $%2$s)',
										'tranzly'
									),
									engine.label,
									Number( engine.spent || 0 ).toFixed( 2 )
								) }
								value={ settings.caps[ engine.id ] ?? '' }
								onChange={ ( value ) => {
									const caps = { ...settings.caps };
									if ( '' === value ) {
										delete caps[ engine.id ];
									} else {
										caps[ engine.id ] = Number( value );
									}
									setState( {
										...state,
										settings: { ...settings, caps },
									} );
								} }
							/>
						) ) }
						<Button
							variant="secondary"
							disabled={ busy }
							onClick={ () =>
								saveSettings( { caps: settings.caps } )
							}
						>
							{ __( 'Save caps', 'tranzly' ) }
						</Button>
					</PanelBody>
				) }

				{ glossary && (
					<PanelBody
						title={ __(
							'Glossary and words never translated',
							'tranzly'
						) }
						initialOpen={ false }
					>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __(
								'Never translate these words (one per line)',
								'tranzly'
							) }
							help={ __(
								'Brand and product names stay exactly as they are, with every engine.',
								'tranzly'
							) }
							value={ glossary.dnt }
							onChange={ ( value ) =>
								setGlossary( { ...glossary, dnt: value } )
							}
						/>
						{ ! pro && (
							<p className="tranzly-engines__pro">
								{ __(
									'Preferred translations and tone per language are Tranzly Pro features.',
									'tranzly'
								) }
							</p>
						) }
						{ pro &&
							languages.map( ( language ) => (
								<div
									key={ language.code }
									className="tranzly-engines__language"
								>
									<h3>{ language.name }</h3>
									<TextareaControl
										__nextHasNoMarginBottom
										label={ __(
											'Preferred translations (one per line: term = translation)',
											'tranzly'
										) }
										value={
											glossary.terms[ language.code ] ||
											''
										}
										onChange={ ( value ) =>
											setGlossary( {
												...glossary,
												terms: {
													...glossary.terms,
													[ language.code ]: value,
												},
											} )
										}
									/>
									<SelectControl
										__next40pxDefaultSize
										label={ __( 'Formality', 'tranzly' ) }
										value={
											glossary.tone[ language.code ]
												?.formality || 'default'
										}
										options={ [
											{
												value: 'default',
												label: __(
													'Default',
													'tranzly'
												),
											},
											{
												value: 'more',
												label: __(
													'Formal',
													'tranzly'
												),
											},
											{
												value: 'less',
												label: __(
													'Informal',
													'tranzly'
												),
											},
										] }
										onChange={ ( value ) =>
											setGlossary( {
												...glossary,
												tone: {
													...glossary.tone,
													[ language.code ]: {
														...( glossary.tone[
															language.code
														] || {} ),
														formality: value,
													},
												},
											} )
										}
									/>
									<TextareaControl
										__nextHasNoMarginBottom
										label={ __(
											'Style instructions (audience, brand voice)',
											'tranzly'
										) }
										value={
											glossary.tone[ language.code ]
												?.instructions || ''
										}
										onChange={ ( value ) =>
											setGlossary( {
												...glossary,
												tone: {
													...glossary.tone,
													[ language.code ]: {
														formality:
															glossary.tone[
																language.code
															]?.formality ||
															'default',
														instructions: value,
													},
												},
											} )
										}
									/>
								</div>
							) ) }
						<Button
							variant="primary"
							disabled={ busy }
							onClick={ saveGlossary }
						>
							{ __( 'Save glossary', 'tranzly' ) }
						</Button>
					</PanelBody>
				) }
			</Panel>
		</div>
	);
}
