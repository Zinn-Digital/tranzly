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
} from '@wordpress/components';

import { addEstimate, coverage, walkPages } from './workflow-model';

/**
 * "Translation status" (tz-w9, free): what is translated, missing or out of date per language,
 * the pages behind each number, and one-click fixes that start a background job. With Pro: the AI
 * quality scores, and bulk translation of the whole site with an estimate first (tz-w2).
 *
 * @param {Object}  props
 * @param {boolean} props.pro Whether Pro is active.
 * @return {Element} The tab.
 */
export default function StatusTab( { pro } ) {
	const [ summary, setSummary ] = useState( null );
	const [ type, setType ] = useState( '' );
	const [ lang, setLang ] = useState( '' );
	const [ state, setState ] = useState( 'missing' );
	const [ items, setItems ] = useState( null );
	const [ next, setNext ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const fail = ( e ) => setNotice( { status: 'error', message: e.message } );
	const typeQuery = type ? `&post_type=${ encodeURIComponent( type ) }` : '';

	const loadSummary = useCallback( () => {
		apiFetch( {
			path: `/tranzly/v1/status?${ typeQuery.slice( 1 ) }`,
		} )
			.then( ( s ) => {
				setSummary( s );
				setLang(
					( current ) =>
						current ||
						s.languages.find( ( l ) => ! l.default )?.code ||
						''
				);
			} )
			.catch( fail );
	}, [ typeQuery ] );

	const loadItems = useCallback(
		( after = 0 ) => {
			if ( ! lang ) {
				return;
			}
			apiFetch( {
				path: `/tranzly/v1/status/items?lang=${ encodeURIComponent(
					lang
				) }&state=${ state }&after=${ after }${ typeQuery }`,
			} )
				.then( ( page ) => {
					setItems( ( prev ) =>
						0 === after ? page.items : [ ...prev, ...page.items ]
					);
					setNext( page.next );
				} )
				.catch( fail );
		},
		[ lang, state, typeQuery ]
	);

	useEffect( loadSummary, [ loadSummary ] );
	useEffect( () => {
		setItems( null );
		loadItems( 0 );
	}, [ loadItems ] );

	if ( ! summary ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const fix = ( fixState ) => {
		setBusy( true );
		apiFetch( {
			path: '/tranzly/v1/status/fix',
			method: 'POST',
			data: { lang, state: fixState, post_type: type },
		} )
			.then( ( job ) =>
				setNotice( {
					status: 'success',
					message: sprintf(
						/* translators: 1: a job number, 2: how many pages it translates. */
						__(
							'Job %1$d started: %2$d pages are being translated in the background. Follow it in Translation jobs.',
							'tranzly'
						),
						job.id,
						job.items
					),
				} )
			)
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	const checkQuality = async () => {
		setBusy( true );
		let scheduled = 0;
		try {
			await walkPages(
				( after ) =>
					apiFetch( {
						path: '/tranzly/v1/quality/check',
						method: 'POST',
						data: { lang, post_type: type, after },
					} ),
				( page ) => ( scheduled += page.scheduled )
			);
			setNotice( {
				status: 'success',
				message: sprintf(
					/* translators: %d: how many translations will be checked. */
					__(
						'%d translations are being checked in the background. Scores appear here as they arrive.',
						'tranzly'
					),
					scheduled
				),
			} );
		} catch ( e ) {
			fail( e );
		}
		setBusy( false );
	};

	const others = summary.languages.filter( ( l ) => ! l.default );
	const states = [
		{ value: 'missing', label: __( 'Missing', 'tranzly' ) },
		{ value: 'stale', label: __( 'Out of date', 'tranzly' ) },
		{ value: 'translated', label: __( 'Translated', 'tranzly' ) },
		{ value: 'human', label: __( 'Edited by a person', 'tranzly' ) },
		{ value: 'machine', label: __( 'Machine translated', 'tranzly' ) },
		...( pro
			? [
					{
						value: 'review',
						label: __( 'Waiting for review', 'tranzly' ),
					},
					{
						value: 'quality',
						label: __( 'Needs a human look', 'tranzly' ),
					},
				]
			: [] ),
		{ value: 'all', label: __( 'Everything', 'tranzly' ) },
	];
	const stateLabel = {
		missing: __( 'Missing', 'tranzly' ),
		stale: __( 'Out of date', 'tranzly' ),
		translated: __( 'Up to date', 'tranzly' ),
	};

	return (
		<div className="tranzly-admin__status">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ summary.backfill && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Tranzly is still working out which older translations are out of date. The "out of date" numbers will grow while it does.',
						'tranzly'
					) }
				</Notice>
			) }
			<Panel>
				<PanelBody title={ __( 'Translation status', 'tranzly' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Content type', 'tranzly' ) }
						value={ type }
						options={ [
							{
								value: '',
								label: __( 'All content', 'tranzly' ),
							},
							...summary.post_types.map( ( t ) => ( {
								value: t.slug,
								label: t.name,
							} ) ),
						] }
						onChange={ setType }
					/>
					<table className="widefat striped tranzly-status__table">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Language', 'tranzly' ) }
								</th>
								<th scope="col">
									{ __( 'Translated', 'tranzly' ) }
								</th>
								<th scope="col">
									{ __( 'Missing', 'tranzly' ) }
								</th>
								<th scope="col">
									{ __( 'Out of date', 'tranzly' ) }
								</th>
								<th scope="col">
									{ __( 'Edited by a person', 'tranzly' ) }
								</th>
								{ pro && (
									<th scope="col">
										{ __( 'Average quality', 'tranzly' ) }
									</th>
								) }
							</tr>
						</thead>
						<tbody>
							{ others.map( ( row ) => (
								<tr key={ row.code }>
									<th scope="row">
										{ /* ⛔ Not aria-pressed: it makes a link Button
										`is-pressed`, painted as a solid dark block (live,
										2026-10-04). The chosen language is the current item. */ }
										<Button
											variant="link"
											className={
												lang === row.code
													? 'tranzly-status__lang is-current'
													: 'tranzly-status__lang'
											}
											onClick={ () =>
												setLang( row.code )
											}
											aria-current={
												lang === row.code
													? 'true'
													: undefined
											}
										>
											{ row.name }
										</Button>
									</th>
									<td>
										{ sprintf(
											/* translators: 1: pages translated, 2: percent of the site. */
											__(
												'%1$d translated (%2$d%%)',
												'tranzly'
											),
											row.translated,
											coverage( row )
										) }
									</td>
									<td>{ row.missing }</td>
									<td>{ row.stale }</td>
									<td>{ row.human }</td>
									{ pro && (
										<td>
											{ null === row.quality_avg
												? '—'
												: row.quality_avg }
										</td>
									) }
								</tr>
							) ) }
						</tbody>
					</table>
				</PanelBody>
				<PanelBody title={ __( 'Pages', 'tranzly' ) }>
					<div className="tranzly-status__filters">
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Language', 'tranzly' ) }
							value={ lang }
							options={ others.map( ( l ) => ( {
								value: l.code,
								label: l.name,
							} ) ) }
							onChange={ setLang }
						/>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Show', 'tranzly' ) }
							value={ state }
							options={ states }
							onChange={ setState }
						/>
					</div>
					<p className="tranzly-status__actions">
						<Button
							variant="primary"
							disabled={ busy || ! lang }
							onClick={ () => fix( 'missing' ) }
						>
							{ __( 'Translate everything missing', 'tranzly' ) }
						</Button>
						<Button
							variant="secondary"
							disabled={ busy || ! lang }
							onClick={ () => fix( 'stale' ) }
						>
							{ __( 'Update everything out of date', 'tranzly' ) }
						</Button>
						{ pro && (
							<>
								<Button
									variant="secondary"
									disabled={ busy || ! lang }
									onClick={ checkQuality }
								>
									{ __(
										'Check the quality with AI',
										'tranzly'
									) }
								</Button>
								<Button
									variant="secondary"
									disabled={ busy || ! lang }
									onClick={ () => fix( 'quality' ) }
								>
									{ __(
										'Translate the low-scoring pages again',
										'tranzly'
									) }
								</Button>
							</>
						) }
					</p>
					{ null === items && <Spinner /> }
					{ items && ! items.length && (
						<p>{ __( 'Nothing here.', 'tranzly' ) }</p>
					) }
					{ items && items.length > 0 && (
						<table className="widefat striped">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Page', 'tranzly' ) }
									</th>
									<th scope="col">
										{ __( 'State', 'tranzly' ) }
									</th>
									{ pro && (
										<th scope="col">
											{ __( 'Quality', 'tranzly' ) }
										</th>
									) }
									<th scope="col">
										{ __( 'Actions', 'tranzly' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( item ) => (
									<tr key={ item.id }>
										<td>
											{ item.edit ? (
												<a href={ item.edit }>
													{ item.title }
												</a>
											) : (
												item.title
											) }
										</td>
										<td>
											{ stateLabel[ item.state ] }
											{ 'human' ===
												item.translation?.made_by &&
												' · ' +
													__(
														'by a person',
														'tranzly'
													) }
											{ 'pending' ===
												item.translation?.review &&
												' · ' +
													__(
														'waiting for review',
														'tranzly'
													) }
										</td>
										{ pro && (
											<td>
												{ null ===
												( item.translation?.quality ??
													null )
													? '—'
													: item.translation.quality }
											</td>
										) }
										<td>
											{ item.translation?.edit && (
												<a
													href={
														item.translation.edit
													}
												>
													{ __( 'Edit', 'tranzly' ) }
												</a>
											) }
											{ item.translation?.compare && (
												<>
													{ ' · ' }
													<a
														href={
															item.translation
																.compare
														}
													>
														{ __(
															'Side by side',
															'tranzly'
														) }
													</a>
												</>
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
					{ null !== next && (
						<Button
							variant="secondary"
							onClick={ () => loadItems( next ) }
						>
							{ __( 'Show more', 'tranzly' ) }
						</Button>
					) }
				</PanelBody>
				<BulkPanel
					pro={ pro }
					summary={ summary }
					fail={ fail }
					setNotice={ setNotice }
				/>
			</Panel>
		</div>
	);
}

/**
 * "Bulk translate the whole site" (tz-w2, Pro).
 *
 * @param {Object}   props
 * @param {boolean}  props.pro       Pro.
 * @param {Object}   props.summary   GET status.
 * @param {Function} props.fail      Error reporter.
 * @param {Function} props.setNotice Notice setter.
 * @return {Element} The panel.
 */
function BulkPanel( { pro, summary, fail, setNotice } ) {
	const [ types, setTypes ] = useState( [] );
	const [ langs, setLangs ] = useState( [] );
	const [ scope, setScope ] = useState( 'missing' );
	const [ estimate, setEstimate ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	if ( ! pro ) {
		return (
			<PanelBody
				title={ __( 'Bulk translate the whole site (Pro)', 'tranzly' ) }
				initialOpen={ false }
			>
				<p>
					{ __(
						'With Tranzly Pro, choose content types and languages, see the estimate, and translate the whole site in one go.',
						'tranzly'
					) }
				</p>
			</PanelBody>
		);
	}
	const toggle = ( list, set, value, on ) =>
		set( on ? [ ...list, value ] : list.filter( ( v ) => v !== value ) );

	const runEstimate = async () => {
		setBusy( true );
		let total = { items: 0, characters: 0, cost_usd: 0 };
		try {
			for ( const lang of langs ) {
				await walkPages(
					( after ) =>
						apiFetch( {
							path: '/tranzly/v1/workflow/estimate',
							method: 'POST',
							data: { lang, post_types: types, scope, after },
						} ),
					( page ) => {
						total = addEstimate( total, page );
						setEstimate( total );
					}
				);
			}
		} catch ( e ) {
			fail( e );
		}
		setBusy( false );
	};

	const start = () => {
		setBusy( true );
		apiFetch( {
			path: '/tranzly/v1/workflow/bulk',
			method: 'POST',
			data: { langs, post_types: types, scope },
		} )
			.then( ( result ) =>
				setNotice( {
					status: 'success',
					message: sprintf(
						/* translators: %d: how many background jobs started. */
						__(
							'%d background jobs started, one per language. Follow them in Translation jobs.',
							'tranzly'
						),
						result.jobs.length
					),
				} )
			)
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	return (
		<PanelBody title={ __( 'Bulk translate the whole site', 'tranzly' ) }>
			<fieldset>
				<legend>{ __( 'Content types', 'tranzly' ) }</legend>
				{ summary.post_types.map( ( t ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ t.slug }
						label={ t.name }
						checked={ types.includes( t.slug ) }
						onChange={ ( on ) =>
							toggle( types, setTypes, t.slug, on )
						}
					/>
				) ) }
			</fieldset>
			<fieldset>
				<legend>{ __( 'Languages', 'tranzly' ) }</legend>
				{ summary.languages
					.filter( ( l ) => ! l.default )
					.map( ( l ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ l.code }
							label={ l.name }
							checked={ langs.includes( l.code ) }
							onChange={ ( on ) =>
								toggle( langs, setLangs, l.code, on )
							}
						/>
					) ) }
			</fieldset>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'What to translate', 'tranzly' ) }
				value={ scope }
				options={ [
					{
						value: 'missing',
						label: __( 'Only what is missing', 'tranzly' ),
					},
					{
						value: 'stale',
						label: __( 'Only what is out of date', 'tranzly' ),
					},
					{
						value: 'all',
						label: __(
							'Everything (pages edited by a person stay as they are)',
							'tranzly'
						),
					},
				] }
				onChange={ setScope }
			/>
			<p>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy || ! langs.length }
					onClick={ runEstimate }
				>
					{ __( 'Estimate', 'tranzly' ) }
				</Button>{ ' ' }
				<Button
					variant="primary"
					disabled={ busy || ! langs.length || ! estimate }
					onClick={ start }
				>
					{ __( 'Start translating', 'tranzly' ) }
				</Button>
			</p>
			{ estimate && (
				<p className="tranzly-status__estimate">
					{ null === estimate.cost_usd
						? sprintf(
								/* translators: 1: pages, 2: characters. */
								__(
									'%1$d pages, %2$s characters. This engine does not publish a price.',
									'tranzly'
								),
								estimate.items,
								estimate.characters.toLocaleString()
							)
						: sprintf(
								/* translators: 1: pages, 2: characters, 3: cost in US dollars. */
								__(
									'%1$d pages, %2$s characters, about $%3$s.',
									'tranzly'
								),
								estimate.items,
								estimate.characters.toLocaleString(),
								estimate.cost_usd.toFixed( 2 )
							) }
				</p>
			) }
		</PanelBody>
	);
}
