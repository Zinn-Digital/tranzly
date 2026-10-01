/* Generated from wp/packages/zinn-admin-kit/src/js/Support.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	ExternalLink,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { kitFetch, errorMessage } from './api';

/**
 * Get help / Report a bug (feature sh-r6) and Feedback / Request a feature (sh-r3).
 *
 * Everything goes to ONE place: Zinn Digital's support inbox (owner, D17), from the SERVER of this
 * site (`/<ns>/kit/support/*`), never from the browser. Nothing is sent before the site owner has
 * agreed (WordPress.org guideline 7), diagnostics only when ticked, with the exact payload shown
 * first. ⛔ A request never carries a login: no user is created and no credentials are sent
 * (WordPress.org review 2026-10-01, CLAUDE.md §2.56). No link, token or endpoint logs anybody in.
 *
 * @param {Object} props
 * @param {Object} props.kit  Boot data.
 * @param {string} props.mode `help` or `feedback`.
 * @return {Element} The screen.
 */
export default function Support( { kit, mode } ) {
	const support = kit.support || {};
	const [ consented, setConsented ] = useState( !! support.consented );
	const [ notice, setNotice ] = useState( null );

	const agree = () =>
		kitFetch( kit, 'support/consent', { method: 'POST' } )
			.then( () => setConsented( true ) )
			.catch( ( error ) =>
				setNotice( {
					status: 'error',
					message: errorMessage(
						error,
						__( 'That could not be saved.', 'tranzly' )
					),
				} )
			);

	return (
		<div className="zak-stack">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ ! consented ? (
				<Consent kit={ kit } onAgree={ agree } />
			) : (
				<>
					{ 'help' === mode && <ConnectionCard kit={ kit } /> }
					<TicketForm kit={ kit } mode={ mode } />
				</>
			) }
		</div>
	);
}

/**
 * What is sent, to whom, and the privacy link — shown before anything leaves the site.
 *
 * @param {Object}   props
 * @param {Object}   props.kit     Boot data.
 * @param {Function} props.onAgree Called when the owner agrees.
 * @return {Element} The panel.
 */
function Consent( { kit, onAgree } ) {
	return (
		<Card className="zak-card">
			<CardHeader>
				<h2 className="zak-card__title">
					{ __( 'Before you contact support', 'tranzly' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ sprintf(
						/* translators: %s: company name. */
						__(
							'Your request is sent from this site to %s, who make this plugin. We send:',
							'tranzly'
						),
						kit.support.recipient
					) }
				</p>
				<ul className="zak-list">
					<li>
						{ __(
							'your e-mail address and name, so we can reply;',
							'tranzly'
						) }
					</li>
					<li>
						{ __(
							'the subject and message you write;',
							'tranzly'
						) }
					</li>
					<li>
						{ __(
							'your licence ID, so paying customers get priority;',
							'tranzly'
						) }
					</li>
					<li>
						{ __(
							'only if you tick it: site details (WordPress and PHP versions, theme, plugins, an excerpt of the error log with passwords and e-mail addresses removed), which you can read before sending;',
							'tranzly'
						) }
					</li>
				</ul>
				<p>
					<ExternalLink href={ kit.privacyUrl }>
						{ __( 'Privacy policy', 'tranzly' ) }
					</ExternalLink>
				</p>
				<Button variant="primary" onClick={ onAgree }>
					{ __( 'I agree, continue', 'tranzly' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

/**
 * Connect this site to Zinn Digital support, or disconnect it. A connected site files requests
 * without typing an e-mail address each time, and the connection can be revoked from either side.
 *
 * @param {Object} props
 * @param {Object} props.kit Boot data.
 * @return {Element} The card.
 */
function ConnectionCard( { kit } ) {
	const [ state, setState ] = useState( null );
	const [ email, setEmail ] = useState( kit.support.email || '' );
	const [ name, setName ] = useState( kit.support.name || '' );
	const [ agreed, setAgreed ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		kitFetch( kit, 'support/connection?refresh=1' )
			.then( setState )
			.catch( ( e ) =>
				setError(
					errorMessage(
						e,
						__(
							'The connection could not be checked.',
							'tranzly'
						)
					)
				)
			);
	}, [ kit ] );

	const run = ( promise ) => {
		setBusy( true );
		setError( '' );
		promise
			.then( ( result ) => {
				setState( result.connection || result );
				if ( result.ok === false && result.message ) {
					setError( result.message );
				}
			} )
			.catch( ( e ) =>
				setError(
					errorMessage(
						e,
						__( 'That did not work. Try again.', 'tranzly' )
					)
				)
			)
			.finally( () => setBusy( false ) );
	};

	const reasons = {
		revoked_by_zinn: __(
			'Zinn Digital® support disconnected this site.',
			'tranzly'
		),
		unknown_to_zinn: __(
			'Zinn Digital® support no longer recognises this site.',
			'tranzly'
		),
		revoked_here: __( 'You disconnected this site.', 'tranzly' ),
	};

	return (
		<Card className="zak-card" data-zak-tour="connection">
			<CardHeader>
				<h2 className="zak-card__title">
					{ __( 'Connect this site to support', 'tranzly' ) }
				</h2>
			</CardHeader>
			<CardBody>
				{ null === state && ! error && <Spinner /> }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ state && state.connected && (
					<>
						<p className="zak-status is-ok">
							{ sprintf(
								/* translators: %s: e-mail address. */
								__(
									'Connected. Replies go to %s.',
									'tranzly'
								),
								state.email
							) }
						</p>
						<Button
							variant="secondary"
							isDestructive
							disabled={ busy }
							onClick={ () =>
								run(
									kitFetch( kit, 'support/connection', {
										method: 'DELETE',
									} )
								)
							}
						>
							{ __( 'Disconnect', 'tranzly' ) }
						</Button>
					</>
				) }
				{ state && ! state.connected && (
					<>
						<p className="zak-status">
							{ reasons[ state.reason ] ||
								__(
									'Not connected. You can still send a request with your e-mail address below; connecting saves typing it each time.',
									'tranzly'
								) }
						</p>
						<TextControl
							__next40pxDefaultSize
							type="email"
							label={ __( 'E-mail address', 'tranzly' ) }
							value={ email }
							onChange={ setEmail }
						/>
						<TextControl
							__next40pxDefaultSize
							label={ __(
								'Your name (optional)',
								'tranzly'
							) }
							value={ name }
							onChange={ setName }
						/>
						<CheckboxControl
							label={ __(
								'I agree to connect this site to Zinn Digital® support. It sends this site’s address, WordPress and PHP versions and the plugin version, and can be disconnected at any time from here or by Zinn Digital®.',
								'tranzly'
							) }
							checked={ agreed }
							onChange={ setAgreed }
						/>
						<Button
							variant="primary"
							disabled={ busy || ! agreed || ! email }
							isBusy={ busy }
							onClick={ () =>
								run(
									kitFetch( kit, 'support/connection', {
										method: 'POST',
										data: { email, name, consent: true },
									} )
								)
							}
						>
							{ __( 'Connect', 'tranzly' ) }
						</Button>
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The request itself.
 *
 * @param {Object} props
 * @param {Object} props.kit  Boot data.
 * @param {string} props.mode `help` or `feedback`.
 * @return {Element} The form.
 */
function TicketForm( { kit, mode } ) {
	const help = 'help' === mode;
	const kinds = help
		? [
				{ value: 'help', label: __( 'I need help', 'tranzly' ) },
				{ value: 'bug', label: __( 'Report a bug', 'tranzly' ) },
			]
		: [
				{
					value: 'feedback',
					label: __( 'Send feedback', 'tranzly' ),
				},
				{
					value: 'feature_request',
					label: __( 'Request a feature', 'tranzly' ),
				},
			];
	const [ kind, setKind ] = useState( kinds[ 0 ].value );
	const [ email, setEmail ] = useState( kit.support.email || '' );
	const [ name, setName ] = useState( kit.support.name || '' );
	const [ subject, setSubject ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ withDiagnostics, setWithDiagnostics ] = useState( help );
	const [ preview, setPreview ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );

	const loadPreview = () =>
		kitFetch( kit, 'support/diagnostics' )
			.then( ( data ) => setPreview( JSON.stringify( data, null, 2 ) ) )
			.catch( ( e ) =>
				setPreview(
					errorMessage(
						e,
						__(
							'The site details could not be read.',
							'tranzly'
						)
					)
				)
			);

	const send = () => {
		setBusy( true );
		setResult( null );
		kitFetch( kit, 'support/ticket', {
			method: 'POST',
			data: {
				kind,
				email,
				name,
				subject,
				message,
				diagnostics: help && withDiagnostics,
			},
		} )
			.then( ( r ) => {
				setResult( r );
				setSubject( '' );
				setMessage( '' );
			} )
			.catch( ( e ) =>
				setResult( {
					ok: false,
					message: errorMessage(
						e,
						__(
							'Your request could not be sent. Try again in a few minutes.',
							'tranzly'
						)
					),
				} )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<Card className="zak-card" data-zak-tour="ticket">
			<CardHeader>
				<h2 className="zak-card__title">
					{ help
						? __( 'Get help or report a bug', 'tranzly' )
						: __(
								'Feedback and feature requests',
								'tranzly'
							) }
				</h2>
			</CardHeader>
			<CardBody>
				{ result && result.ok && (
					<Notice status="success" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: request reference, e.g. ZT-ABC234. */
							__(
								'Sent. Your reference is %s, and our reply will come by e-mail.',
								'tranzly'
							),
							result.reference
						) }{ ' ' }
						{ 'high' === result.priority &&
							__(
								'Your plan includes priority support.',
								'tranzly'
							) }
					</Notice>
				) }
				{ result && ! result.ok && (
					<Notice status="error" isDismissible={ false }>
						{ result.message }
					</Notice>
				) }
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'What is it about?', 'tranzly' ) }
					value={ kind }
					options={ kinds }
					onChange={ setKind }
				/>
				<TextControl
					__next40pxDefaultSize
					type="email"
					label={ __( 'Reply to (e-mail)', 'tranzly' ) }
					value={ email }
					onChange={ setEmail }
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Your name (optional)', 'tranzly' ) }
					value={ name }
					onChange={ setName }
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Subject', 'tranzly' ) }
					value={ subject }
					onChange={ setSubject }
				/>
				<TextareaControl
					label={ __( 'Message', 'tranzly' ) }
					help={
						'bug' === kind
							? __(
									'What did you do, what did you expect, and what happened instead?',
									'tranzly'
								)
							: undefined
					}
					value={ message }
					onChange={ setMessage }
					rows={ 6 }
				/>
				{ help && (
					<>
						<CheckboxControl
							label={ __(
								'Include site details (versions, theme, plugins, error log excerpt)',
								'tranzly'
							) }
							checked={ withDiagnostics }
							onChange={ setWithDiagnostics }
						/>
						{ withDiagnostics && (
							<details
								className="zak-details"
								onToggle={ ( e ) =>
									e.target.open &&
									null === preview &&
									loadPreview()
								}
							>
								<summary>
									{ __(
										'See exactly what is sent',
										'tranzly'
									) }
								</summary>
								<pre className="zak-pre" dir="ltr">
									{ preview ||
										__( 'Loading…', 'tranzly' ) }
								</pre>
							</details>
						) }
					</>
				) }
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! subject.trim() || ! message.trim() }
					onClick={ send }
				>
					{ __( 'Send', 'tranzly' ) }
				</Button>
			</CardBody>
		</Card>
	);
}
