import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	Panel,
	PanelBody,
	RangeControl,
	SelectControl,
	Spinner,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { exportUrl, walkPages } from './workflow-model';

/**
 * "Workflow" (Pro): what happens when an original changes (tz-w3), review and approval (tz-w6),
 * export/import for human translators (tz-w7), switching from another plugin (tz-w8), the AI
 * quality check's model (tz-r6) and translated comments (tz-r10).
 *
 * @param {Object}  props
 * @param {boolean} props.pro Whether Pro is active.
 * @return {Element} The tab.
 */
export default function WorkflowTab( { pro } ) {
	if ( ! pro ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Auto-translate on update, reviewer roles, XLIFF export for translators, switching from WPML, Polylang or TranslatePress and the AI quality check come with Tranzly Pro.',
					'tranzly'
				) }
			</Notice>
		);
	}

	return <WorkflowPro />;
}

/**
 * The Pro screen.
 *
 * @return {Element} The tab.
 */
function WorkflowPro() {
	const [ data, setData ] = useState( null );
	const [ languages, setLanguages ] = useState( [] );
	const [ notice, setNotice ] = useState( null );
	const fail = ( e ) => setNotice( { status: 'error', message: e.message } );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/tranzly/v1/workflow/settings' } ),
			apiFetch( { path: '/tranzly/v1/languages' } ),
		] )
			.then( ( [ s, l ] ) => {
				setData( s );
				setLanguages(
					l.languages.filter( ( x ) => x.code !== l.default )
				);
			} )
			.catch( fail );
	}, [] );

	if ( ! data ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const save = ( patch ) =>
		apiFetch( {
			path: '/tranzly/v1/workflow/settings',
			method: 'PUT',
			data: patch,
		} )
			.then( ( s ) => {
				setData( s );
				setNotice( {
					status: 'success',
					message: __( 'Saved.', 'tranzly' ),
				} );
			} )
			.catch( fail );

	const s = data.settings;
	const provider = data.ai_providers.find(
		( p ) => p.id === s.quality_provider
	);

	return (
		<div className="tranzly-admin__workflow">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<Panel>
				<PanelBody
					title={ __( 'When an original changes', 'tranzly' ) }
				>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'When a page is edited', 'tranzly' ) }
						value={ s.on_update }
						options={ [
							{
								value: 'stale',
								label: __(
									'Mark its translations out of date',
									'tranzly'
								),
							},
							{
								value: 'translate',
								label: __(
									'Translate its translations again in the background',
									'tranzly'
								),
							},
							{
								value: 'off',
								label: __( 'Do nothing', 'tranzly' ),
							},
						] }
						onChange={ ( v ) => save( { on_update: v } ) }
						help={ __(
							'A translation a person edited is never overwritten; it is marked out of date instead.',
							'tranzly'
						) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Translate new pages into every language when they are published',
							'tranzly'
						) }
						checked={ s.on_publish }
						onChange={ ( v ) => save( { on_publish: v } ) }
					/>
				</PanelBody>
				<ReviewPanel
					settings={ s }
					save={ save }
					languages={ languages }
					rolesUrl={ data.roles_url }
					fail={ fail }
				/>
				<ExchangePanel
					base={ data.export_url }
					languages={ languages }
					fail={ fail }
					setNotice={ setNotice }
				/>
				<ImportPanel fail={ fail } setNotice={ setNotice } />
				<PanelBody
					title={ __( 'AI quality check', 'tranzly' ) }
					initialOpen={ false }
				>
					<p>
						{ __(
							'A second AI model reads each translation beside its original, scores it and names the mistakes. It uses your own AI key from the AI settings. Start a check from Translation status.',
							'tranzly'
						) }
					</p>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'AI provider', 'tranzly' ) }
						value={ s.quality_provider }
						options={ [
							{
								value: '',
								label: __(
									'The AI settings’ general model',
									'tranzly'
								),
							},
							...data.ai_providers.map( ( p ) => ( {
								value: p.id,
								label: p.label,
							} ) ),
						] }
						onChange={ ( v ) =>
							save( { quality_provider: v, quality_model: '' } )
						}
					/>
					{ provider && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Model', 'tranzly' ) }
							value={ s.quality_model }
							options={ [
								{
									value: '',
									label: __( 'Recommended', 'tranzly' ),
								},
								...provider.models.map( ( m ) => ( {
									value: m,
									label: m,
								} ) ),
							] }
							onChange={ ( v ) => save( { quality_model: v } ) }
						/>
					) }
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'A page needs a human look below this score',
							'tranzly'
						) }
						value={ s.quality_threshold }
						min={ 0 }
						max={ 100 }
						onChange={ ( v ) => save( { quality_threshold: v } ) }
					/>
				</PanelBody>
				<CommentsPanel fail={ fail } />
			</Panel>
		</div>
	);
}

/**
 * Review and approval (tz-w6).
 *
 * @param {Object}   props
 * @param {Object}   props.settings  Settings.
 * @param {Function} props.save      Saver.
 * @param {Array}    props.languages Languages.
 * @param {string}   props.rolesUrl  Users screen.
 * @param {Function} props.fail      Error reporter.
 * @return {Element} The panel.
 */
function ReviewPanel( { settings, save, languages, rolesUrl, fail } ) {
	const [ lang, setLang ] = useState( languages[ 0 ]?.code || '' );
	const [ items, setItems ] = useState( null );
	const [ note, setNote ] = useState( '' );

	const load = useCallback( () => {
		if ( ! lang ) {
			return;
		}
		apiFetch( {
			path: `/tranzly/v1/status/items?lang=${ encodeURIComponent( lang ) }&state=review`,
		} )
			.then( ( page ) => setItems( page.items ) )
			.catch( fail );
	}, [ lang ] );
	useEffect( load, [ load ] );

	const decide = ( id, decision ) =>
		apiFetch( {
			path: `/tranzly/v1/posts/${ id }/review`,
			method: 'POST',
			data: { decision, note },
		} )
			.then( () => {
				setNote( '' );
				load();
			} )
			.catch( fail );

	return (
		<PanelBody
			title={ __( 'Review and approval', 'tranzly' ) }
			initialOpen={ false }
		>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Publish only translations a reviewer approved',
					'tranzly'
				) }
				checked={ settings.review }
				onChange={ ( v ) => save( { review: v } ) }
			/>
			<p>
				{ __(
					'Give people the Translator role (edits translations, never the originals, cannot publish) or the Translation reviewer role (also approves). Reviewers also find everything waiting in the Posts and Pages lists under “Pending”; publishing one there approves it.',
					'tranzly'
				) }{ ' ' }
				<a href={ rolesUrl }>{ __( 'Manage users', 'tranzly' ) }</a>
			</p>
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
			{ null === items && <Spinner /> }
			{ items && ! items.length && (
				<p>{ __( 'Nothing is waiting for review.', 'tranzly' ) }</p>
			) }
			{ items && items.length > 0 && (
				<>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Note for the translator (optional)',
							'tranzly'
						) }
						value={ note }
						onChange={ setNote }
					/>
					<ul className="tranzly-review__list">
						{ items.map( ( item ) => (
							<li key={ item.id }>
								<strong>{ item.title }</strong>{ ' ' }
								{ item.translation?.compare && (
									<a href={ item.translation.compare }>
										{ __( 'Read side by side', 'tranzly' ) }
									</a>
								) }{ ' ' }
								<Button
									variant="primary"
									onClick={ () =>
										decide( item.translation.id, 'approve' )
									}
								>
									{ __( 'Approve', 'tranzly' ) }
								</Button>{ ' ' }
								<Button
									variant="secondary"
									onClick={ () =>
										decide( item.translation.id, 'changes' )
									}
								>
									{ __( 'Ask for changes', 'tranzly' ) }
								</Button>
							</li>
						) ) }
					</ul>
				</>
			) }
		</PanelBody>
	);
}

/**
 * Export and import for human translators (tz-w7).
 *
 * @param {Object}   props
 * @param {string}   props.base      Export URL with its nonce.
 * @param {Array}    props.languages Languages.
 * @param {Function} props.fail      Error reporter.
 * @param {Function} props.setNotice Notice setter.
 * @return {Element} The panel.
 */
function ExchangePanel( { base, languages, fail, setNotice } ) {
	const [ choice, setChoice ] = useState( {
		lang: languages[ 0 ]?.code || '',
		format: 'xliff',
		state: 'missing',
	} );
	const [ busy, setBusy ] = useState( false );

	const upload = async ( file ) => {
		setBusy( true );
		try {
			const body = new window.FormData();
			body.append( 'file', file );
			const started = await apiFetch( {
				path: '/tranzly/v1/import',
				method: 'POST',
				body,
			} );
			let written = 0;
			const errors = [];
			await walkPages(
				( after ) =>
					apiFetch( {
						path: `/tranzly/v1/import/${ started.token }`,
						method: 'POST',
						data: { after },
					} ),
				( page ) => {
					written += page.written;
					errors.push( ...page.errors );
				}
			);
			setNotice( {
				status: errors.length ? 'warning' : 'success',
				message:
					sprintf(
						/* translators: %d: how many pages were written. */
						__(
							'%d translations imported and protected from machine translation.',
							'tranzly'
						),
						written
					) +
					( errors.length
						? ' ' +
							errors
								.map(
									( e ) =>
										`#${ e.post } ${ e.lang }: ${ e.message }`
								)
								.join( ' · ' )
						: '' ),
			} );
		} catch ( e ) {
			fail( e );
		}
		setBusy( false );
	};

	return (
		<PanelBody
			title={ __( 'Export and import for translators', 'tranzly' ) }
			initialOpen={ false }
		>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Language', 'tranzly' ) }
				value={ choice.lang }
				options={ languages.map( ( l ) => ( {
					value: l.code,
					label: l.name,
				} ) ) }
				onChange={ ( lang ) => setChoice( { ...choice, lang } ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Pages', 'tranzly' ) }
				value={ choice.state }
				options={ [
					{
						value: 'missing',
						label: __( 'Not translated yet', 'tranzly' ),
					},
					{ value: 'stale', label: __( 'Out of date', 'tranzly' ) },
					{
						value: 'translated',
						label: __( 'Already translated', 'tranzly' ),
					},
					{ value: 'all', label: __( 'All', 'tranzly' ) },
				] }
				onChange={ ( state ) => setChoice( { ...choice, state } ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Format', 'tranzly' ) }
				value={ choice.format }
				options={ [
					{
						value: 'xliff',
						label: __( 'XLIFF 1.2 (translation tools)', 'tranzly' ),
					},
					{
						value: 'csv',
						label: __( 'CSV (spreadsheets)', 'tranzly' ),
					},
				] }
				onChange={ ( format ) => setChoice( { ...choice, format } ) }
			/>
			<p>
				<Button variant="secondary" href={ exportUrl( base, choice ) }>
					{ __( 'Download the file', 'tranzly' ) }
				</Button>
			</p>
			<p>
				<label htmlFor="tranzly-import-file">
					{ __( 'Import a translated file', 'tranzly' ) }
				</label>{ ' ' }
				<input
					id="tranzly-import-file"
					type="file"
					accept=".xlf,.xliff,.xml,.csv"
					disabled={ busy }
					onChange={ ( e ) =>
						e.target.files[ 0 ] && upload( e.target.files[ 0 ] )
					}
				/>
				{ busy && <Spinner /> }
			</p>
		</PanelBody>
	);
}

/**
 * Switch from WPML, Polylang or TranslatePress (tz-w8).
 *
 * @param {Object}   props
 * @param {Function} props.fail      Error reporter.
 * @param {Function} props.setNotice Notice setter.
 * @return {Element} The panel.
 */
function ImportPanel( { fail, setNotice } ) {
	const [ sources, setSources ] = useState( null );
	const [ busy, setBusy ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/tranzly/v1/import-sources' } )
			.then( ( r ) => setSources( r.sources ) )
			.catch( fail );
	}, [] );

	const run = async ( id, mode ) => {
		setBusy( id );
		const total = {
			linked: 0,
			created: 0,
			strings: 0,
			skipped: 0,
			errors: [],
			languages: [],
		};
		try {
			if ( 'undo' === mode ) {
				const r = await apiFetch( {
					path: `/tranzly/v1/import-sources/${ id }`,
					method: 'POST',
					data: { mode },
				} );
				setNotice( {
					status: 'success',
					message: sprintf(
						/* translators: 1: links removed, 2: pages deleted. */
						__(
							'Undone: %1$d links removed, %2$d imported pages deleted.',
							'tranzly'
						),
						r.removed,
						r.deleted
					),
				} );
			} else {
				await walkPages(
					( cursor ) =>
						apiFetch( {
							path: `/tranzly/v1/import-sources/${ id }`,
							method: 'POST',
							data: { mode, cursor: cursor || '' },
						} ),
					( page ) => {
						for ( const k of [
							'linked',
							'created',
							'strings',
							'skipped',
						] ) {
							total[ k ] += page[ k ] || 0;
						}
						total.errors.push( ...( page.errors || [] ) );
						total.languages.push( ...( page.languages || [] ) );
					},
					''
				);
				setNotice( {
					status: total.errors.length ? 'warning' : 'success',
					message:
						( 'dry-run' === mode
							? __( 'Dry run, nothing changed:', 'tranzly' )
							: __( 'Imported:', 'tranzly' ) ) +
						' ' +
						sprintf(
							/* translators: 1: languages, 2: links, 3: pages built, 4: texts. */
							__(
								'%1$d languages added, %2$d translations linked, %3$d pages built, %4$d texts.',
								'tranzly'
							),
							total.languages.length,
							total.linked,
							total.created,
							total.strings
						) +
						( total.errors.length
							? ' ' + total.errors.join( ' · ' )
							: '' ),
				} );
			}
		} catch ( e ) {
			fail( e );
		}
		setBusy( '' );
	};

	return (
		<PanelBody
			title={ __(
				'Switch from WPML, Polylang or TranslatePress',
				'tranzly'
			) }
			initialOpen={ false }
		>
			<p>
				{ __(
					'Switch the other plugin off first (its addresses and switchers would fight Tranzly). Its data stays where it is, so you can switch back; Undo removes exactly what the import added.',
					'tranzly'
				) }
			</p>
			{ null === sources && <Spinner /> }
			{ sources &&
				sources.map( ( source ) => (
					<div key={ source.id } className="tranzly-import__source">
						<h3>{ source.label }</h3>
						{ ! source.found && (
							<p>
								{ __(
									'No data from this plugin on this site.',
									'tranzly'
								) }
							</p>
						) }
						{ source.found && (
							<>
								<p>
									{ sprintf(
										/* translators: %s: language codes. */
										__( 'Languages: %s', 'tranzly' ),
										source.languages.join( ', ' )
									) }
									{ source.active &&
										' — ' +
											__(
												'still active: switch it off first.',
												'tranzly'
											) }
								</p>
								<Button
									variant="secondary"
									disabled={ !! busy }
									onClick={ () =>
										run( source.id, 'dry-run' )
									}
								>
									{ __( 'Dry run', 'tranzly' ) }
								</Button>{ ' ' }
								<Button
									variant="primary"
									disabled={ !! busy || source.active }
									isBusy={ busy === source.id }
									onClick={ () => run( source.id, 'run' ) }
								>
									{ __( 'Import', 'tranzly' ) }
								</Button>{ ' ' }
								<Button
									variant="tertiary"
									isDestructive
									disabled={ !! busy }
									onClick={ () => run( source.id, 'undo' ) }
								>
									{ __( 'Undo the import', 'tranzly' ) }
								</Button>
							</>
						) }
					</div>
				) ) }
		</PanelBody>
	);
}

/**
 * Translated comments and reviews (tz-r10).
 *
 * @param {Object}   props
 * @param {Function} props.fail Error reporter.
 * @return {Element} The panel.
 */
function CommentsPanel( { fail } ) {
	const [ mode, setMode ] = useState( null );
	useEffect( () => {
		apiFetch( { path: '/tranzly/v1/comments-setting' } )
			.then( ( r ) => setMode( r.mode ) )
			.catch( fail );
	}, [] );
	if ( null === mode ) {
		return null;
	}

	return (
		<PanelBody
			title={ __( 'Comments and reviews', 'tranzly' ) }
			initialOpen={ false }
		>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Comments and product reviews', 'tranzly' ) }
				value={ mode }
				options={ [
					{
						value: 'off',
						label: __( 'Each language keeps its own', 'tranzly' ),
					},
					{
						value: 'show',
						label: __(
							'Show every language’s comments on every version',
							'tranzly'
						),
					},
					{
						value: 'translate',
						label: __(
							'Show them all, translated into the reader’s language',
							'tranzly'
						),
					},
				] }
				onChange={ ( v ) =>
					apiFetch( {
						path: '/tranzly/v1/comments-setting',
						method: 'PUT',
						data: { mode: v },
					} )
						.then( ( r ) => setMode( r.mode ) )
						.catch( fail )
				}
				help={ __(
					'Translating uses your translation engine for every approved comment. Spam and comments waiting for moderation are never sent.',
					'tranzly'
				) }
			/>
		</PanelBody>
	);
}
