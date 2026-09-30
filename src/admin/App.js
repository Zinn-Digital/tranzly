import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	ComboboxControl,
	ExternalLink,
	Flex,
	FlexItem,
	Notice,
	Panel,
	PanelBody,
	Spinner,
	TextControl,
} from '@wordpress/components';

import About from './About';
import { isValidPrefix } from './prefix';
import { addLanguage, isValidCode } from './languages';
import { localeOptions } from './locale-options';

/**
 * The admin screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data printed by Admin::data().
 * @return {Element} The screen.
 */
export default function App( { data } ) {
	const [ settings, setSettings ] = useState( null );
	const [ prefix, setPrefix ] = useState( '' );
	const [ languages, setLanguages ] = useState( [] );
	const [ newCode, setNewCode ] = useState( '' );
	const [ newName, setNewName ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const load = ( result ) => {
		setSettings( result );
		setPrefix( result.prefix );
		setLanguages( result.languages );
	};

	useEffect( () => {
		apiFetch( { path: data.restPath } )
			.then( load )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	}, [ data.restPath ] );

	const save = () => {
		setSaving( true );
		setNotice( null );
		apiFetch( {
			path: data.restPath,
			method: 'POST',
			data: { prefix, languages },
		} )
			.then( ( result ) => {
				load( result );
				setNotice( {
					status: 'success',
					message: __( 'Settings saved.', 'tranzly' ),
				} );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			)
			.finally( () => setSaving( false ) );
	};

	const add = () => {
		setLanguages( addLanguage( languages, newCode, newName ) );
		setNewCode( '' );
		setNewName( '' );
	};

	const remove = ( code ) =>
		setLanguages(
			languages.filter( ( language ) => language.code !== code )
		);

	if ( null === settings && null === notice ) {
		return <Spinner />;
	}

	const canSave = isValidPrefix( prefix ) && languages.length > 0;

	return (
		<div className="tranzly-admin">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<Panel>
				{ data.upgradeUrl && (
					<PanelBody title={ __( 'Upgrade', 'tranzly' ) } initialOpen>
						<p>
							<ExternalLink href={ data.upgradeUrl }>
								{ __(
									'See the Pro plans and pricing',
									'tranzly'
								) }
							</ExternalLink>
						</p>
					</PanelBody>
				) }
				{ settings && (
					<PanelBody
						title={ __( 'Languages', 'tranzly' ) }
						initialOpen
					>
						<p>
							{ __(
								'The first language is the one your content is written in. Visitors switch with the language switcher block.',
								'tranzly'
							) }
						</p>
						<ul className="tranzly-admin__languages">
							{ languages.map( ( language, index ) => (
								<li key={ language.code }>
									<Flex>
										<FlexItem>
											<strong>{ language.name }</strong>{ ' ' }
											<code>{ language.code }</code>
											{ 0 === index &&
												' ' +
													__(
														'(default)',
														'tranzly'
													) }
										</FlexItem>
										<FlexItem>
											<Button
												variant="link"
												isDestructive
												disabled={
													languages.length < 2
												}
												onClick={ () =>
													remove( language.code )
												}
											>
												{ __( 'Remove', 'tranzly' ) }
											</Button>
										</FlexItem>
									</Flex>
								</li>
							) ) }
						</ul>
						<ComboboxControl
							__next40pxDefaultSize
							label={ __( 'Find a language', 'tranzly' ) }
							help={ __(
								'Type its name in your language or in its own, then choose it. The code and name below fill in by themselves.',
								'tranzly'
							) }
							value={ isValidCode( newCode ) ? newCode : null }
							options={ localeOptions(
								languages,
								document.documentElement.lang
							) }
							onChange={ ( code ) => {
								const picked = localeOptions(
									languages,
									document.documentElement.lang
								).find( ( option ) => option.value === code );
								setNewCode( code || '' );
								setNewName( picked ? picked.name : '' );
							} }
						/>
						<Flex align="flex-end">
							<FlexItem>
								<TextControl
									__next40pxDefaultSize
									label={ __( 'Locale code', 'tranzly' ) }
									help={ __(
										'For example fr_FR',
										'tranzly'
									) }
									value={ newCode }
									onChange={ setNewCode }
								/>
							</FlexItem>
							<FlexItem>
								<TextControl
									__next40pxDefaultSize
									label={ __( 'Name (optional)', 'tranzly' ) }
									help={ __(
										'For example Français',
										'tranzly'
									) }
									value={ newName }
									onChange={ setNewName }
								/>
							</FlexItem>
							<FlexItem>
								<Button
									variant="secondary"
									disabled={ ! isValidCode( newCode.trim() ) }
									onClick={ add }
								>
									{ __( 'Add language', 'tranzly' ) }
								</Button>
							</FlexItem>
						</Flex>
					</PanelBody>
				) }
				{ settings && (
					<PanelBody
						title={ __( 'Front-end output', 'tranzly' ) }
						initialOpen
					>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Class name prefix', 'tranzly' ) }
							help={ __(
								'Every class name the plugin prints on your pages starts with this prefix, so the pages do not reveal which plugin built them. 1 to 8 lowercase letters or digits, starting with a letter.',
								'tranzly'
							) }
							value={ prefix }
							onChange={ setPrefix }
						/>
					</PanelBody>
				) }
				{ settings && (
					<PanelBody
						title={ __( 'Beta updates', 'tranzly' ) }
						initialOpen
					>
						{ settings.beta.available ? (
							<>
								<p>
									{ settings.beta.enabled
										? __(
												'This site receives beta versions as updates.',
												'tranzly'
											)
										: __(
												'This site receives stable versions only.',
												'tranzly'
											) }
								</p>
								<p>
									<ExternalLink
										href={ settings.beta.accountUrl }
									>
										{ __(
											'Change this on the Account page',
											'tranzly'
										) }
									</ExternalLink>
								</p>
							</>
						) : (
							<p>
								{ __(
									'Beta versions are offered to licensed Pro installations that are connected to their account. Once connected, you can join from the Account page.',
									'tranzly'
								) }
							</p>
						) }
					</PanelBody>
				) }
				{ settings && (
					<PanelBody>
						<Button
							variant="primary"
							isBusy={ saving }
							disabled={ saving || ! canSave }
							onClick={ save }
						>
							{ __( 'Save settings', 'tranzly' ) }
						</Button>
					</PanelBody>
				) }
				<About
					version={ data.version }
					productUrl={ data.productUrl }
					companyUrl={ data.companyUrl }
				/>
			</Panel>
		</div>
	);
}
