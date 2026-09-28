import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	ExternalLink,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';

/**
 * Plain-English names for the audit's findings.
 *
 * @return {Object<string,string>} Issue code => sentence.
 */
export function issueLabels() {
	return {
		missing_meta: __( 'No meta description', 'tranzly' ),
		broken_hreflang: __(
			'A language version visitors cannot open (draft, private or password)',
			'tranzly'
		),
		untranslated_slug: __(
			'The address still uses the original words',
			'tranzly'
		),
		duplicate_content: __( 'Still holds the original text', 'tranzly' ),
	};
}

/**
 * The multilingual SEO audit (Pro, tz-r8). It pages through a language with the server's cursor,
 * so a large site is audited in as many steps as it takes and nothing is silently skipped.
 *
 * @param {Object}  props
 * @param {Array}   props.languages Site languages ({code,name}).
 * @param {boolean} props.pro       Whether Pro is active.
 * @return {Element} The tab.
 */
export function SeoAudit( { languages, pro } ) {
	const [ lang, setLang ] = useState( languages[ 1 ]?.code || '' );
	const [ issues, setIssues ] = useState( [] );
	const [ checked, setChecked ] = useState( 0 );
	const [ next, setNext ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ done, setDone ] = useState( false );
	const [ error, setError ] = useState( null );

	if ( ! pro ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'The multilingual SEO audit is part of Tranzly Pro. It finds missing meta descriptions, broken hreflang, untranslated addresses and duplicate content, per language.',
					'tranzly'
				) }
			</Notice>
		);
	}

	const run = ( after, fresh ) => {
		setBusy( true );
		setError( null );
		apiFetch( {
			path: `/tranzly/v1/seo-audit?lang=${ encodeURIComponent(
				lang
			) }&after=${ after }`,
		} )
			.then( ( page ) => {
				setIssues( ( fresh ? [] : issues ).concat( page.issues ) );
				setChecked( ( fresh ? 0 : checked ) + page.checked );
				setNext( page.next );
				setDone( null === page.next );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const labels = issueLabels();
	return (
		<div className="tranzly-admin__audit">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Language to audit', 'tranzly' ) }
				value={ lang }
				options={ languages.map( ( l ) => ( {
					value: l.code,
					label: l.name,
				} ) ) }
				onChange={ ( value ) => {
					setLang( value );
					setIssues( [] );
					setChecked( 0 );
					setNext( null );
					setDone( false );
				} }
			/>
			<p>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! lang }
					onClick={ () => run( 0, true ) }
				>
					{ __( 'Run the audit', 'tranzly' ) }
				</Button>{ ' ' }
				{ null !== next && (
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () => run( next, false ) }
					>
						{ __( 'Check the next pages', 'tranzly' ) }
					</Button>
				) }
			</p>
			{ busy && <Spinner /> }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ checked > 0 && (
				<p aria-live="polite">
					{ sprintf(
						/* translators: 1: pages checked, 2: problems found. */
						__(
							'%1$d pages checked, %2$d problems found.',
							'tranzly'
						),
						checked,
						issues.length
					) }{ ' ' }
					{ done &&
						__(
							'The whole language has been checked.',
							'tranzly'
						) }
				</p>
			) }
			{ issues.length > 0 && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th scope="col">{ __( 'Page', 'tranzly' ) }</th>
							<th scope="col">{ __( 'Problem', 'tranzly' ) }</th>
							<th scope="col">{ __( 'Fix it', 'tranzly' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ issues.map( ( item ) => (
							<tr key={ item.id + item.issue }>
								<td>
									<ExternalLink href={ item.url }>
										{ item.title || item.url }
									</ExternalLink>
								</td>
								<td>{ labels[ item.issue ] || item.issue }</td>
								<td>
									{ item.edit && (
										<a href={ item.edit }>
											{ __( 'Edit', 'tranzly' ) }
										</a>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

/**
 * The tab: loads the languages and the edition, then shows the audit.
 *
 * @return {Element} The tab.
 */
export default function SeoAuditTab() {
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/tranzly/v1/languages' } ),
			apiFetch( { path: '/tranzly/v1/urls' } ),
		] )
			.then( ( [ langs, urls ] ) =>
				setState( { languages: langs.languages, pro: urls.pro } )
			)
			.catch( ( e ) => setError( e.message ) );
	}, [] );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	return state ? <SeoAudit { ...state } /> : <Spinner />;
}
