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
	RadioControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { BASE_LABELS, isValidSegment, modeOptions } from './urls-model';

/**
 * The "Addresses and SEO" tab (T4): how languages appear in URLs, translated address words,
 * the language suggestion banner, and (Pro) hiding untranslated pages from search engines.
 * Self-contained, so the shared admin shell (F3) can wrap it unchanged.
 *
 * @return {Element} The tab.
 */
export default function Urls() {
	const [ settings, setSettings ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( ( result ) => {
		setSettings( result );
		setDraft( {
			mode: result.mode,
			segments: { ...result.segments },
			domains: { ...result.domains },
			bases: JSON.parse( JSON.stringify( result.bases || {} ) ),
			suggest: result.suggest,
			noindex_untranslated: result.noindex_untranslated,
		} );
	}, [] );

	useEffect( () => {
		apiFetch( { path: '/tranzly/v1/urls' } )
			.then( load )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	}, [ load ] );

	if ( ! draft ) {
		return notice ? (
			<Notice status={ notice.status } isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const languages = Object.keys( settings.segments );
	const others = languages.filter( ( code ) => code !== settings.default );
	const set = ( patch ) => setDraft( { ...draft, ...patch } );
	const segmentsValid = languages.every( ( code ) =>
		isValidSegment( draft.segments[ code ] || '' )
	);

	const save = () => {
		setSaving( true );
		setNotice( null );
		const data = { ...draft };
		if ( ! settings.pro ) {
			delete data.noindex_untranslated;
		}
		apiFetch( { path: '/tranzly/v1/urls', method: 'PUT', data } )
			.then( ( result ) => {
				load( result );
				setNotice( {
					status: 'success',
					message: __(
						'Saved. Your language addresses have changed as shown below.',
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
		<div className="tranzly-admin__urls">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<Panel>
				<PanelBody title={ __( 'Language in the address', 'tranzly' ) }>
					<RadioControl
						label={ __(
							'How visitors and search engines reach each language',
							'tranzly'
						) }
						selected={ draft.mode }
						options={ modeOptions( settings.pro ) }
						onChange={ ( mode ) => set( { mode } ) }
					/>
					{ ! settings.pro && (
						<p className="tranzly-admin__hint">
							{ __(
								'Subdomains and separate domains per language are part of Tranzly Pro.',
								'tranzly'
							) }
						</p>
					) }
					{ 'query' !== draft.mode &&
						languages.map( ( code ) => (
							<TextControl
								__next40pxDefaultSize
								key={ code }
								label={ sprintf(
									/* translators: %s: a language code such as de_DE. */
									__( 'Address word for %s', 'tranzly' ),
									code
								) }
								help={
									code === settings.default
										? __(
												'The default language has no word in its addresses.',
												'tranzly'
											)
										: undefined
								}
								value={ draft.segments[ code ] || '' }
								onChange={ ( value ) =>
									set( {
										segments: {
											...draft.segments,
											[ code ]: value.toLowerCase(),
										},
									} )
								}
							/>
						) ) }
					{ 'domain' === draft.mode &&
						others.map( ( code ) => (
							<TextControl
								__next40pxDefaultSize
								key={ 'd-' + code }
								label={ sprintf(
									/* translators: %s: a language code such as de_DE. */
									__( 'Domain for %s (optional)', 'tranzly' ),
									code
								) }
								help={ __(
									'Point the domain at this site first. A language with no domain uses a folder on your main domain.',
									'tranzly'
								) }
								value={ draft.domains[ code ] || '' }
								onChange={ ( value ) =>
									set( {
										domains: {
											...draft.domains,
											[ code ]: value.trim(),
										},
									} )
								}
							/>
						) ) }
					<h3>
						{ __( 'Where each language lives now', 'tranzly' ) }
					</h3>
					<ul className="tranzly-admin__examples">
						{ Object.entries( settings.examples ).map(
							( [ code, url ] ) => (
								<li key={ code }>
									<code>{ code }</code>{ ' ' }
									<ExternalLink href={ url }>
										{ url }
									</ExternalLink>
								</li>
							)
						) }
					</ul>
				</PanelBody>
				<PanelBody
					title={ __( 'Translated address words', 'tranzly' ) }
					initialOpen={ false }
				>
					<p>
						{ __(
							'Page and post addresses come from their translated titles. Here you translate the fixed words, so /de/kategorie/ replaces /de/category/.',
							'tranzly'
						) }
					</p>
					{ others.map( ( code ) => (
						<fieldset key={ 'b-' + code }>
							<legend>
								<strong>{ code }</strong>
							</legend>
							{ Object.entries( settings.bases_known ).map(
								( [ what, original ] ) => (
									<TextControl
										__next40pxDefaultSize
										key={ what }
										label={ sprintf(
											/* translators: 1: what the word is for (Category), 2: the word WordPress uses now. */
											__(
												'%1$s (now “%2$s”)',
												'tranzly'
											),
											BASE_LABELS()[ what ] || what,
											original
										) }
										value={
											( draft.bases[ code ] || {} )[
												what
											] || ''
										}
										onChange={ ( value ) =>
											set( {
												bases: {
													...draft.bases,
													[ code ]: {
														...( draft.bases[
															code
														] || {} ),
														[ what ]: value,
													},
												},
											} )
										}
									/>
								)
							) }
						</fieldset>
					) ) }
				</PanelBody>
				<PanelBody
					title={ __( 'Search engines and visitors', 'tranzly' ) }
					initialOpen={ false }
				>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Offer visitors their own language with a small banner (never a redirect)',
							'tranzly'
						) }
						checked={ !! draft.suggest }
						onChange={ ( suggest ) => set( { suggest } ) }
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Hide untranslated pages from search engines (noindex)',
							'tranzly'
						) }
						help={
							settings.pro
								? __(
										'A translation that still holds the original text is kept out of search results until it is translated.',
										'tranzly'
									)
								: __( 'Part of Tranzly Pro.', 'tranzly' )
						}
						disabled={ ! settings.pro }
						checked={ !! draft.noindex_untranslated }
						onChange={ ( value ) =>
							set( { noindex_untranslated: value } )
						}
					/>
					<p>
						{ __(
							'Tranzly adds hreflang links (with x-default), the page language and direction, and every language to your sitemap:',
							'tranzly'
						) }{ ' ' }
						{ settings.sitemap_urls.map( ( url ) => (
							<ExternalLink key={ url } href={ url }>
								{ url }
							</ExternalLink>
						) ) }
					</p>
					{ settings.seo_plugins.length > 0 && (
						<p>
							{ sprintf(
								/* translators: %s: names of SEO plugins, such as Yoast SEO. */
								__( 'Working together with: %s.', 'tranzly' ),
								settings.seo_plugins.join( ', ' )
							) }
						</p>
					) }
				</PanelBody>
			</Panel>
			<Button
				variant="primary"
				isBusy={ saving }
				disabled={ saving || ! segmentsValid }
				onClick={ save }
			>
				{ __( 'Save', 'tranzly' ) }
			</Button>
			{ ! segmentsValid && (
				<p className="tranzly-admin__hint">
					{ __(
						'Each address word is 2 to 12 lowercase letters, digits or hyphens.',
						'tranzly'
					) }
				</p>
			) }
		</div>
	);
}
