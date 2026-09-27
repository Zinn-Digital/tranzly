import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	ExternalLink,
	Notice,
	Panel,
	PanelBody,
	ProgressBar,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

import { percentDone } from './glossary';

/**
 * The Translation jobs tab: start a background job (with the estimate first), follow it, and fix
 * what failed — retry with another engine, or translate it by hand (tz-f3, tz-e6, tz-r2).
 *
 * The job runs on the server; this screen only reads its progress, so closing it changes nothing.
 *
 * @return {Element} The tab.
 */
export default function Jobs() {
	const [ types, setTypes ] = useState( null );
	const [ engines, setEngines ] = useState( null );
	const [ languages, setLanguages ] = useState( [] );
	const [ names, setNames ] = useState( {} );
	const [ jobs, setJobs ] = useState( [] );
	const [ form, setForm ] = useState( {
		postType: 'post',
		langs: [],
		engine: '',
		publish: false,
	} );
	const [ estimate, setEstimate ] = useState( null );
	const [ open, setOpen ] = useState( null );
	const [ failures, setFailures ] = useState( null );
	const [ retryEngine, setRetryEngine ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	// Stable across renders (only state setters inside), so the effects below do not re-run.
	const fail = useCallback(
		( error ) => setNotice( { status: 'error', message: error.message } ),
		[]
	);

	const loadJobs = useCallback(
		() =>
			apiFetch( { path: '/tranzly/v1/jobs' } )
				.then( setJobs )
				.catch( fail ),
		[ fail ]
	);

	useEffect( () => {
		apiFetch( { path: '/wp/v2/types?context=edit' } )
			.then( ( result ) =>
				setTypes(
					Object.values( result ).filter(
						( type ) => type.viewable && 'attachment' !== type.slug
					)
				)
			)
			.catch( fail );
		apiFetch( { path: '/tranzly/v1/engines' } )
			.then( setEngines )
			.catch( fail );
		apiFetch( { path: '/tranzly/v1/languages' } )
			.then( ( result ) => {
				setLanguages( ( result.languages || [] ).slice( 1 ) );
				setNames(
					Object.fromEntries(
						( result.languages || [] ).map( ( language ) => [
							language.code,
							language.name,
						] )
					)
				);
			} )
			.catch( fail );
		loadJobs();
	}, [ fail, loadJobs ] );

	// While a job runs, refresh its progress every few seconds.
	useEffect( () => {
		if ( ! jobs.some( ( job ) => 'running' === job.status ) ) {
			return undefined;
		}
		const timer = setInterval( loadJobs, 5000 );
		return () => clearInterval( timer );
	}, [ jobs, loadJobs ] );

	if ( null === types || null === engines ) {
		return notice ? (
			<Notice status={ notice.status } isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const configured = engines.filter( ( engine ) => engine.configured );
	const request = () => ( {
		post_type: form.postType,
		langs: form.langs,
		engine: form.engine,
	} );

	const doEstimate = () => {
		setEstimate( null );
		apiFetch( {
			path: '/tranzly/v1/estimate',
			method: 'POST',
			data: request(),
		} )
			.then( setEstimate )
			.catch( fail );
	};

	const start = () => {
		setBusy( true );
		setNotice( null );
		apiFetch( {
			path: '/tranzly/v1/jobs',
			method: 'POST',
			data: { ...request(), status: form.publish ? 'publish' : 'draft' },
		} )
			.then( ( made ) => {
				setNotice( {
					status: 'success',
					message: sprintf(
						/* translators: %d: how many post/language pairs the job holds. */
						__(
							'Job started with %d translations to do. It runs in the background: you can close this page.',
							'tranzly'
						),
						made.items
					),
				} );
				setEstimate( null );
				return loadJobs();
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	const showFailures = ( id ) => {
		setOpen( id );
		setFailures( null );
		apiFetch( { path: `/tranzly/v1/jobs/${ id }/failures` } )
			.then( setFailures )
			.catch( fail );
	};

	const retry = ( item ) =>
		apiFetch( {
			path: `/tranzly/v1/jobs/items/${ item }/retry`,
			method: 'POST',
			data: { engine: retryEngine[ item ] || '' },
		} )
			.then( () => {
				setNotice( {
					status: 'success',
					message: __( 'Queued again.', 'tranzly' ),
				} );
				showFailures( open );
				return loadJobs();
			} )
			.catch( fail );

	const byHand = ( item ) =>
		apiFetch( {
			path: `/tranzly/v1/jobs/items/${ item }/by-hand`,
			method: 'POST',
		} )
			.then( ( result ) => {
				window.location.href = result.edit;
			} )
			.catch( fail );

	const cancel = ( id ) =>
		apiFetch( { path: `/tranzly/v1/jobs/${ id }/cancel`, method: 'POST' } )
			.then( loadJobs )
			.catch( fail );

	const statusLabel = ( status ) =>
		( {
			running: __( 'Running', 'tranzly' ),
			done: __( 'Finished', 'tranzly' ),
			cancelled: __( 'Cancelled', 'tranzly' ),
		} )[ status ] || status;

	return (
		<div className="tranzly-admin tranzly-jobs">
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
					title={ __( 'Translate in the background', 'tranzly' ) }
					initialOpen
				>
					{ 0 === configured.length ? (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Set up a translation engine in the Engines tab first.',
								'tranzly'
							) }
						</Notice>
					) : (
						<>
							<SelectControl
								__next40pxDefaultSize
								label={ __( 'Translate every', 'tranzly' ) }
								value={ form.postType }
								options={ types.map( ( type ) => ( {
									value: type.slug,
									label: type.name,
								} ) ) }
								onChange={ ( value ) =>
									setForm( { ...form, postType: value } )
								}
							/>
							<fieldset>
								<legend>{ __( 'Into', 'tranzly' ) }</legend>
								{ languages.map( ( language ) => (
									<CheckboxControl
										key={ language.code }
										label={ language.name }
										checked={ form.langs.includes(
											language.code
										) }
										onChange={ ( on ) =>
											setForm( {
												...form,
												langs: on
													? [
															...form.langs,
															language.code,
														]
													: form.langs.filter(
															( code ) =>
																code !==
																language.code
														),
											} )
										}
									/>
								) ) }
							</fieldset>
							<SelectControl
								__next40pxDefaultSize
								label={ __( 'With', 'tranzly' ) }
								value={ form.engine }
								options={ [
									{
										value: '',
										label: __(
											'Each language’s own engine',
											'tranzly'
										),
									},
									...configured.map( ( engine ) => ( {
										value: engine.id,
										label: engine.label,
									} ) ),
								] }
								onChange={ ( value ) =>
									setForm( { ...form, engine: value } )
								}
							/>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Publish the translations (otherwise they are saved as drafts)',
									'tranzly'
								) }
								checked={ form.publish }
								onChange={ ( on ) =>
									setForm( { ...form, publish: on } )
								}
							/>
							<Button
								variant="secondary"
								disabled={ 0 === form.langs.length }
								onClick={ doEstimate }
							>
								{ __( 'Estimate the cost', 'tranzly' ) }
							</Button>
							{ estimate && (
								<p className="tranzly-jobs__estimate">
									{ null === estimate.cost_usd
										? sprintf(
												/* translators: 1: number of posts, 2: characters. */
												__(
													'%1$d posts, %2$s characters. The price for this engine is not known in advance.',
													'tranzly'
												),
												estimate.posts,
												estimate.characters.toLocaleString()
											)
										: sprintf(
												/* translators: 1: number of posts, 2: characters, 3: an amount in US dollars. */
												__(
													'%1$d posts, %2$s characters: about $%3$s on your own account.',
													'tranzly'
												),
												estimate.posts,
												estimate.characters.toLocaleString(),
												Number(
													estimate.cost_usd
												).toFixed( 2 )
											) }
								</p>
							) }
							<Button
								variant="primary"
								isBusy={ busy }
								disabled={ busy || 0 === form.langs.length }
								onClick={ start }
							>
								{ __( 'Start translating', 'tranzly' ) }
							</Button>
						</>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Jobs', 'tranzly' ) } initialOpen>
					{ 0 === jobs.length && (
						<p>{ __( 'No translation jobs yet.', 'tranzly' ) }</p>
					) }
					{ jobs.map( ( job ) => (
						<div key={ job.id } className="tranzly-jobs__job">
							<p>
								<strong>
									{ sprintf(
										/* translators: 1: job number, 2: job status. */
										__( 'Job %1$d — %2$s', 'tranzly' ),
										job.id,
										statusLabel( job.status )
									) }
								</strong>{ ' ' }
								{ sprintf(
									/* translators: 1: translated, 2: total, 3: failed, 4: skipped. */
									__(
										'%1$d of %2$d translated, %3$d failed, %4$d skipped (protected or already in that language)',
										'tranzly'
									),
									job.counts.done + job.counts.manual,
									job.total,
									job.counts.failed,
									job.counts.skipped
								) }
							</p>
							<ProgressBar value={ percentDone( job ) } />
							{ 'running' === job.status && (
								<Button
									variant="link"
									isDestructive
									onClick={ () => cancel( job.id ) }
								>
									{ __( 'Stop this job', 'tranzly' ) }
								</Button>
							) }
							{ job.counts.failed > 0 && (
								<Button
									variant="link"
									onClick={ () => showFailures( job.id ) }
								>
									{ __(
										'See what failed and why',
										'tranzly'
									) }
								</Button>
							) }
							{ open === job.id && null === failures && (
								<Spinner />
							) }
							{ open === job.id && failures && (
								<table className="widefat tranzly-jobs__failures">
									<thead>
										<tr>
											<th>
												{ __( 'Content', 'tranzly' ) }
											</th>
											<th>
												{ __( 'Language', 'tranzly' ) }
											</th>
											<th>
												{ __(
													'What went wrong',
													'tranzly'
												) }
											</th>
											<th>
												{ __( 'Fix it', 'tranzly' ) }
											</th>
										</tr>
									</thead>
									<tbody>
										{ failures.items.map( ( item ) => (
											<tr key={ item.item }>
												<td>
													{ item.title ||
														`#${ item.post }` }
												</td>
												<td>
													{ names[ item.lang ] ||
														item.lang }
												</td>
												<td>
													{ item.message }
													{ item.link && (
														<>
															{ ' ' }
															<ExternalLink
																href={
																	item.link
																}
															>
																{ __(
																	'Open',
																	'tranzly'
																) }
															</ExternalLink>
														</>
													) }
													{ item.detail && (
														<details>
															<summary>
																{ __(
																	'Details',
																	'tranzly'
																) }
															</summary>
															<code>
																{ item.detail }
															</code>
														</details>
													) }
												</td>
												<td>
													<SelectControl
														__next40pxDefaultSize
														label={ __(
															'Retry with',
															'tranzly'
														) }
														value={
															retryEngine[
																item.item
															] || ''
														}
														options={ [
															{
																value: '',
																label: __(
																	'The same engine',
																	'tranzly'
																),
															},
															...failures.engines.map(
																(
																	engine
																) => ( {
																	value: engine.id,
																	label: engine.label,
																} )
															),
														] }
														onChange={ ( value ) =>
															setRetryEngine( {
																...retryEngine,
																[ item.item ]:
																	value,
															} )
														}
													/>
													<Button
														variant="secondary"
														onClick={ () =>
															retry( item.item )
														}
													>
														{ __(
															'Retry',
															'tranzly'
														) }
													</Button>{ ' ' }
													<Button
														variant="link"
														onClick={ () =>
															byHand( item.item )
														}
													>
														{ __(
															'Translate by hand',
															'tranzly'
														) }
													</Button>
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) }
						</div>
					) ) }
				</PanelBody>
			</Panel>
		</div>
	);
}
