// GENERATED from wp/packages/zinn-mcp-kit/src/js/McpPanel.js by wp/bin/build-mcp-kit.php. Edit the package, never this copy.
/**
 * The "AI agents (MCP)" settings panel, shared by every plugin that serves MCP.
 *
 * Rendered into each plugin's src/mcp-kit/ by wp/bin/build-mcp-kit.php, which rewrites the text
 * domain to the plugin's own. Self-contained: it reads and saves the plugin's settings route
 * (`mcp` in the payload is McpKit\Server::describe() plus `saved`).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	ExternalLink,
	Notice,
	PanelBody,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

/**
 * The client configuration a person pastes into Claude Desktop, Cursor, VS Code and the like.
 *
 * @param {string} name     Server name.
 * @param {string} endpoint MCP endpoint URL.
 * @return {string} JSON.
 */
export function clientConfig( name, endpoint ) {
	return JSON.stringify(
		{
			mcpServers: {
				[ name ]: {
					command: 'npx',
					args: [ '-y', '@automattic/mcp-wordpress-remote@latest' ],
					env: {
						WP_API_URL: endpoint,
						WP_API_USERNAME: 'your-username',
						WP_API_PASSWORD: 'your-application-password',
					},
				},
			},
		},
		null,
		2
	);
}

/**
 * The panel.
 *
 * @param {Object}  props
 * @param {string}  props.path        The plugin's settings REST path.
 * @param {boolean} props.initialOpen Open at first.
 * @return {Element} The panel.
 */
export default function McpPanel( { path, initialOpen = true } ) {
	const [ mcp, setMcp ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		apiFetch( { path } )
			.then( ( result ) => setMcp( result.mcp || null ) )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	}, [ path ] );

	const toggle = ( value ) => {
		setSaving( true );
		setNotice( null );
		apiFetch( { path, method: 'POST', data: { mcp: value } } )
			.then( ( result ) => {
				setMcp( result.mcp || null );
				setNotice( {
					status: 'success',
					message: value
						? __(
								'AI agents are allowed. Reload this page to see the tools.',
								'tranzly'
							)
						: __(
								'AI agents are turned off. Their connection address no longer answers.',
								'tranzly'
							),
				} );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			)
			.finally( () => setSaving( false ) );
	};

	return (
		<PanelBody
			title={ __( 'AI agents (MCP)', 'tranzly' ) }
			initialOpen={ initialOpen }
			className="zd-mcp-panel"
		>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ null === mcp && ! notice && <Spinner /> }
			{ mcp && (
				<>
					<p>
						{ __(
							'Let AI assistants such as Claude, Cursor or VS Code work on this site for you through MCP (Model Context Protocol). They sign in as a WordPress user (Claude and ChatGPT by approving them here, others with an application password) and can do only what that user is allowed to do.',
							'tranzly'
						) }
					</p>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Allow AI agents (MCP)', 'tranzly' ) }
						help={ __(
							'Off: the tools below and their REST routes are not registered at all.',
							'tranzly'
						) }
						checked={ !! mcp.saved }
						disabled={ saving }
						onChange={ toggle }
					/>
					{ ! mcp.available && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'AI agents need WordPress 6.9 or later. Update WordPress to use them.',
								'tranzly'
							) }
						</Notice>
					) }
					{ mcp.available && mcp.enabled && (
						<>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Connection address (MCP endpoint)',
									'tranzly'
								) }
								value={ mcp.endpoint }
								readOnly
								onChange={ () => {} }
								onFocus={ ( event ) => event.target.select() }
							/>
							{ mcp.oauth && mcp.oauth.available && (
								<>
									<p>
										<strong>
											{ __(
												'Claude and ChatGPT: sign in instead',
												'tranzly'
											) }
										</strong>
										<br />
										{ __(
											'Add the connection address as a custom connector in Claude, or as an app in ChatGPT developer mode. They open this site so you can approve them; no password to copy.',
											'tranzly'
										) }
									</p>
									{ mcp.oauth.apps.length > 0 && (
										<>
											<p>
												{ __(
													'AI apps connected to your account:',
													'tranzly'
												) }
											</p>
											<ul className="zd-mcp-panel__apps">
												{ mcp.oauth.apps.map(
													( app ) => (
														<li key={ app.id }>
															{ app.name }{ ' ' }
															<form
																method="post"
																action={
																	mcp.oauth
																		.action
																}
																style={ {
																	display:
																		'inline',
																} }
															>
																<input
																	type="hidden"
																	name="action"
																	value="zinn_mcp_oauth_disconnect"
																/>
																<input
																	type="hidden"
																	name="app"
																	value={
																		app.id
																	}
																/>
																<input
																	type="hidden"
																	name="_wpnonce"
																	value={
																		app.nonce
																	}
																/>
																<button
																	type="submit"
																	className="button-link"
																>
																	{ __(
																		'Disconnect',
																		'tranzly'
																	) }
																</button>
															</form>
														</li>
													)
												) }
											</ul>
										</>
									) }
									<p>
										<strong>
											{ __(
												'Other AI apps: an application password',
												'tranzly'
											) }
										</strong>
									</p>
								</>
							) }
							<ol>
								<li>
									<ExternalLink href={ mcp.passwords }>
										{ __(
											'Create an application password for your user',
											'tranzly'
										) }
									</ExternalLink>
								</li>
								<li>
									{ __(
										'Add this to your AI app’s MCP settings, with your username and that password:',
										'tranzly'
									) }
								</li>
							</ol>
							<pre className="zd-mcp-panel__config" dir="ltr">
								{ clientConfig( mcp.server, mcp.endpoint ) }
							</pre>
							<p className="description">
								{ __(
									'VS Code: put the same entry under "servers" in .vscode/mcp.json, not under "mcpServers".',
									'tranzly'
								) }
							</p>
							<p>
								{ sprintf(
									/* translators: %d: how many tools an AI agent can use. */
									__(
										'Tools an AI agent can use here (%d):',
										'tranzly'
									),
									mcp.abilities.length
								) }
							</p>
							<ul className="zd-mcp-panel__tools" dir="ltr">
								{ mcp.abilities.map( ( name ) => (
									<li key={ name }>
										<code>{ name }</code>
									</li>
								) ) }
							</ul>
						</>
					) }
					<p>
						{ mcp.docs && mcp.docs.guide && (
							<ExternalLink href={ mcp.docs.guide }>
								{ __(
									'User guide: AI agents and MCP',
									'tranzly'
								) }
							</ExternalLink>
						) }
					</p>
					<p>
						{ mcp.docs && mcp.docs.developers && (
							<ExternalLink href={ mcp.docs.developers }>
								{ __(
									'Developer docs: abilities, REST and MCP',
									'tranzly'
								) }
							</ExternalLink>
						) }
					</p>
				</>
			) }
		</PanelBody>
	);
}
