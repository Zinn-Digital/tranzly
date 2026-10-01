import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { applyFilters } from '@wordpress/hooks';
import {
	Button,
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

	// ⛔ WordPress.org guideline 5 (2026-10-01): the Pro editors are not in the free plugin. The
	// premium layer's own bundle (src/pro__premium_only/settings) supplies them through these
	// filters; without it, the free screen shows what Pro adds, and nothing locked.
	const ProEngineFields = applyFilters(
		'tranzly.engines.languageFields',
		null
	);
	const ProCapsPanel = applyFilters( 'tranzly.engines.capsPanel', null );
	const ProGlossaryFields = applyFilters(
		'tranzly.glossary.languageFields',
		null
	);
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
				...( ProGlossaryFields
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
					{ ! ProEngineFields && (
						<p className="tranzly-engines__pro">
							{ __(
								'A different engine per language, automatic fallback and monthly spending caps are Tranzly Pro features.',
								'tranzly'
							) }
						</p>
					) }
					{ ProEngineFields && (
						<ProEngineFields
							languages={ languages }
							settings={ settings }
							engineOptions={ engineOptions }
							configured={ configured }
							saveSettings={ saveSettings }
						/>
					) }
				</PanelBody>

				{ ProCapsPanel && (
					<ProCapsPanel
						configured={ configured }
						settings={ settings }
						state={ state }
						setState={ setState }
						saveSettings={ saveSettings }
						busy={ busy }
					/>
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
						{ ! ProGlossaryFields && (
							<p className="tranzly-engines__pro">
								{ __(
									'Preferred translations and tone per language are Tranzly Pro features.',
									'tranzly'
								) }
							</p>
						) }
						{ ProGlossaryFields && (
							<ProGlossaryFields
								languages={ languages }
								glossary={ glossary }
								setGlossary={ setGlossary }
							/>
						) }
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
