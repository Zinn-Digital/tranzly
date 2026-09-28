import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import StringsEditor from './StringsEditor';
import { walkPages } from './workflow-model';

/**
 * The integrations Tranzly carries, whether each one's plugin is on this site, and with Pro its
 * settings: custom field rules (tz-c9), WooCommerce currencies (tz-r5), and the text of forms, the
 * shop, and the theme and plugins (tz-r9, tz-c7, tz-c10).
 *
 * @param {Object}  props
 * @param {boolean} props.pro Whether Pro is active.
 * @return {Element} The tab.
 */
export default function IntegrationsTab( { pro } ) {
	const [ list, setList ] = useState( null );
	const [ languages, setLanguages ] = useState( [] );
	const [ notice, setNotice ] = useState( null );
	const fail = ( e ) => setNotice( { status: 'error', message: e.message } );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/tranzly/v1/integrations' } ),
			apiFetch( { path: '/tranzly/v1/languages' } ),
		] )
			.then( ( [ i, l ] ) => {
				setList( i );
				setLanguages(
					l.languages.filter( ( x ) => x.code !== l.default )
				);
			} )
			.catch( fail );
	}, [] );

	if ( ! list ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const has = ( id ) =>
		list.integrations.some( ( i ) => i.id === id && i.available );

	return (
		<div className="tranzly-admin__integrations">
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
					title={ __(
						'What Tranzly translates on this site',
						'tranzly'
					) }
				>
					<ul className="tranzly-integrations__list">
						{ list.integrations.map( ( i ) => (
							<li key={ i.id }>
								<strong>{ i.label }</strong>
								{ ' — ' }
								{ i.available
									? __(
											'found, translated with your content',
											'tranzly'
										)
									: __( 'not on this site', 'tranzly' ) }
							</li>
						) ) }
					</ul>
					{ ! pro && (
						<p>
							{ __(
								'With Tranzly Pro: Elementor, Bricks, Divi, Beaver Builder, Oxygen and WPBakery pages; everything in WooCommerce, with prices in the visitor’s currency; the SEO fields of Yoast SEO, Rank Math, SEOPress and All in One SEO; ACF, Meta Box and Pods fields; forms and their e-mails; your theme’s and plugins’ own text; and comments and reviews.',
								'tranzly'
							) }
						</p>
					) }
				</PanelBody>
				{ pro && has( 'fields' ) && (
					<FieldRules fail={ fail } setNotice={ setNotice } />
				) }
				{ pro && has( 'currencies' ) && (
					<Currencies
						languages={ languages }
						fail={ fail }
						setNotice={ setNotice }
					/>
				) }
				{ pro && has( 'woocommerce' ) && (
					<PanelBody
						title={ __( 'Shop text and e-mails', 'tranzly' ) }
						initialOpen={ false }
					>
						<p>
							{ __(
								'E-mail subjects and headings, payment and shipping method names, and the checkout notices. Each order’s e-mails go out in the language it was placed in.',
								'tranzly'
							) }
						</p>
						<StringsEditor
							scope="woocommerce"
							languages={ languages }
						/>
					</PanelBody>
				) }
				{ pro && has( 'forms' ) && (
					<PanelBody
						title={ __( 'Forms and their e-mails', 'tranzly' ) }
						initialOpen={ false }
					>
						<p>
							{ __(
								'Every form keeps one list of entries; its labels, buttons, messages and e-mails appear in the visitor’s language.',
								'tranzly'
							) }
						</p>
						<StringsEditor scope="forms" languages={ languages } />
					</PanelBody>
				) }
				{ pro && <ThemeText languages={ languages } fail={ fail } /> }
			</Panel>
		</div>
	);
}

/**
 * Custom field rules (tz-c9).
 *
 * @param {Object}   props
 * @param {Function} props.fail      Error reporter.
 * @param {Function} props.setNotice Notice setter.
 * @return {Element} The panel.
 */
function FieldRules( { fail, setNotice } ) {
	const [ fields, setFields ] = useState( null );
	useEffect( () => {
		apiFetch( { path: '/tranzly/v1/fields' } )
			.then( ( r ) => setFields( r.fields ) )
			.catch( fail );
	}, [] );

	const save = ( id, rule ) =>
		apiFetch( {
			path: '/tranzly/v1/fields',
			method: 'PUT',
			data: { rules: { [ id ]: rule } },
		} )
			.then( ( r ) => {
				setFields( r.fields );
				setNotice( {
					status: 'success',
					message: __( 'Saved.', 'tranzly' ),
				} );
			} )
			.catch( fail );

	return (
		<PanelBody
			title={ __( 'Custom fields', 'tranzly' ) }
			initialOpen={ false }
		>
			{ null === fields && <Spinner /> }
			{ fields && ! fields.length && (
				<p>
					{ __(
						'No ACF, Meta Box or Pods fields are defined yet.',
						'tranzly'
					) }
				</p>
			) }
			{ fields && fields.length > 0 && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th scope="col">{ __( 'Field', 'tranzly' ) }</th>
							<th scope="col">{ __( 'Where', 'tranzly' ) }</th>
							<th scope="col">{ __( 'Type', 'tranzly' ) }</th>
							<th scope="col">
								{ __( 'In a translation', 'tranzly' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ fields.map( ( f ) => (
							<tr key={ f.id }>
								<td>{ f.label }</td>
								<td>{ `${ f.plugin } · ${ f.group }` }</td>
								<td>{ f.type }</td>
								<td>
									<SelectControl
										__next40pxDefaultSize
										__nextHasNoMarginBottom
										hideLabelFromVision
										label={ sprintf(
											/* translators: %s: a field's name. */
											__(
												'What happens to %s in a translation',
												'tranzly'
											),
											f.label
										) }
										value={ f.rule }
										options={ [
											{
												value: 'translate',
												label: __(
													'Translate it',
													'tranzly'
												),
											},
											{
												value: 'copy',
												label: __(
													'Copy it as it is',
													'tranzly'
												),
											},
											{
												value: 'ignore',
												label: __(
													'Leave it empty',
													'tranzly'
												),
											},
										] }
										onChange={ ( rule ) =>
											save( f.id, rule )
										}
									/>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</PanelBody>
	);
}

/**
 * WooCommerce currencies (tz-r5).
 *
 * @param {Object}   props
 * @param {Array}    props.languages Languages.
 * @param {Function} props.fail      Error reporter.
 * @param {Function} props.setNotice Notice setter.
 * @return {Element} The panel.
 */
function Currencies( { languages, fail, setNotice } ) {
	const [ data, setData ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ country, setCountry ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/tranzly/v1/currencies' } )
			.then( ( r ) => {
				setData( r );
				setDraft( r.settings );
			} )
			.catch( fail );
	}, [] );
	if ( ! data || ! draft ) {
		return null;
	}
	const codes = draft.currencies.map( ( c ) => c.code );
	const setRow = ( i, patch ) =>
		setDraft( {
			...draft,
			currencies: draft.currencies.map( ( c, j ) =>
				j === i ? { ...c, ...patch } : c
			),
		} );
	const save = () =>
		apiFetch( {
			path: '/tranzly/v1/currencies',
			method: 'PUT',
			data: draft,
		} )
			.then( ( r ) => {
				setData( r );
				setDraft( r.settings );
				setNotice( {
					status: 'success',
					message: __( 'Saved.', 'tranzly' ),
				} );
			} )
			.catch( fail );
	const refresh = () =>
		apiFetch( { path: '/tranzly/v1/currencies/refresh', method: 'POST' } )
			.then( ( r ) => {
				setData( r );
				setDraft( r.settings );
			} )
			.catch( fail );
	const currencyOptions = [
		{ value: '', label: __( 'The shop’s currency', 'tranzly' ) },
		...codes.map( ( c ) => ( { value: c, label: c } ) ),
	];

	return (
		<PanelBody
			title={ __( 'Currencies', 'tranzly' ) }
			initialOpen={ false }
		>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ sprintf(
					/* translators: %s: the shop's currency code. */
					__(
						'Show prices in other currencies (the shop’s own is %s)',
						'tranzly'
					),
					data.base
				) }
				checked={ draft.enabled }
				onChange={ ( enabled ) => setDraft( { ...draft, enabled } ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Exchange rates', 'tranzly' ) }
				value={ draft.source }
				options={ Object.entries( data.sources ).map(
					( [ value, label ] ) => ( { value, label } )
				) }
				onChange={ ( source ) => setDraft( { ...draft, source } ) }
			/>
			{ 'manual' !== draft.source && (
				<p>
					{ draft.refreshed
						? sprintf(
								/* translators: %s: a date and time. */
								__( 'Rates last updated %s.', 'tranzly' ),
								new Date( draft.refreshed ).toLocaleString()
							)
						: __(
								'Rates have not been fetched yet.',
								'tranzly'
							) }{ ' ' }
					<Button variant="link" onClick={ refresh }>
						{ __( 'Update the rates now', 'tranzly' ) }
					</Button>
				</p>
			) }
			{ draft.error && (
				<Notice status="warning" isDismissible={ false }>
					{ draft.error }
				</Notice>
			) }
			{ draft.currencies.map( ( row, i ) => (
				<fieldset key={ i } className="tranzly-currency__row">
					<legend>
						{ row.code || __( 'New currency', 'tranzly' ) }
					</legend>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Currency', 'tranzly' ) }
						value={ row.code }
						options={ [
							{ value: '', label: __( 'Choose…', 'tranzly' ) },
							...Object.entries( data.all )
								.filter( ( [ code ] ) => code !== data.base )
								.map( ( [ value, label ] ) => ( {
									value,
									label: `${ value } — ${ label }`,
								} ) ),
						] }
						onChange={ ( code ) => setRow( i, { code } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: %s: the shop's currency code. */
							__( 'Rate (per 1 %s)', 'tranzly' ),
							data.base
						) }
						value={ row.rate || '' }
						onChange={ ( rate ) =>
							setRow( i, { rate, manual: true } )
						}
						help={
							'manual' !== draft.source
								? __(
										'Typing a rate keeps it: the daily update will not change it.',
										'tranzly'
									)
								: undefined
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Rounding', 'tranzly' ) }
						value={ row.rounding || 'none' }
						options={ [
							{ value: 'none', label: __( 'None', 'tranzly' ) },
							{ value: 'up', label: __( 'Round up', 'tranzly' ) },
							{
								value: 'nearest',
								label: __( 'Round to the nearest', 'tranzly' ),
							},
							{
								value: 'down',
								label: __( 'Round down', 'tranzly' ),
							},
						] }
						onChange={ ( rounding ) => setRow( i, { rounding } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Round to (for example 1, 0.05 or 10)',
							'tranzly'
						) }
						value={ row.round_to || '' }
						onChange={ ( roundTo ) =>
							setRow( i, { round_to: roundTo } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Prices end in (for example 0.99)',
							'tranzly'
						) }
						value={ row.ending || '' }
						onChange={ ( ending ) => setRow( i, { ending } ) }
					/>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () =>
							setDraft( {
								...draft,
								currencies: draft.currencies.filter(
									( c, j ) => j !== i
								),
							} )
						}
					>
						{ __( 'Remove', 'tranzly' ) }
					</Button>
				</fieldset>
			) ) }
			<p>
				<Button
					variant="secondary"
					onClick={ () =>
						setDraft( {
							...draft,
							currencies: [
								...draft.currencies,
								{
									code: '',
									rate: '',
									decimals: 2,
									rounding: 'none',
								},
							],
						} )
					}
				>
					{ __( 'Add a currency', 'tranzly' ) }
				</Button>
			</p>
			<h3>{ __( 'Which visitors see which currency', 'tranzly' ) }</h3>
			{ languages.map( ( l ) => (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					key={ l.code }
					label={ l.name }
					value={ draft.by_language[ l.code ] || '' }
					options={ currencyOptions }
					onChange={ ( c ) =>
						setDraft( {
							...draft,
							by_language: {
								...draft.by_language,
								[ l.code ]: c,
							},
						} )
					}
				/>
			) ) }
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __(
					'By country: two-letter country code (for example CH)',
					'tranzly'
				) }
				value={ country }
				onChange={ ( v ) =>
					setCountry( v.toUpperCase().slice( 0, 2 ) )
				}
			/>
			{ 2 === country.length && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ sprintf(
						/* translators: %s: a country code. */
						__( 'Visitors from %s', 'tranzly' ),
						country
					) }
					value={ draft.by_country[ country ] || '' }
					options={ currencyOptions }
					onChange={ ( c ) =>
						setDraft( {
							...draft,
							by_country: { ...draft.by_country, [ country ]: c },
						} )
					}
				/>
			) }
			{ Object.keys( draft.by_country ).length > 0 && (
				<p>
					{ Object.entries( draft.by_country )
						.map( ( [ k, v ] ) => `${ k } → ${ v }` )
						.join( ' · ' ) }
				</p>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Let visitors choose (shortcode [tranzly_currency_switcher])',
					'tranzly'
				) }
				checked={ draft.switcher }
				onChange={ ( switcher ) => setDraft( { ...draft, switcher } ) }
			/>
			{ ( draft.switcher ||
				Object.keys( draft.by_country ).length > 0 ) && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Prices now depend on a cookie or on the visitor’s country: make sure your page cache varies by the "tranzly_currency" cookie, or excludes shop pages.',
						'tranzly'
					) }
				</Notice>
			) }
			<p>
				<Button variant="primary" onClick={ save }>
					{ __( 'Save currencies', 'tranzly' ) }
				</Button>
			</p>
		</PanelBody>
	);
}

/**
 * Theme and plugin text (tz-c10).
 *
 * @param {Object}   props
 * @param {Array}    props.languages Languages.
 * @param {Function} props.fail      Error reporter.
 * @return {Element} The panel.
 */
function ThemeText( { languages, fail } ) {
	const [ components, setComponents ] = useState( null );
	const [ chosen, setChosen ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );

	const load = () =>
		apiFetch( { path: '/tranzly/v1/theme-strings' } )
			.then( ( r ) => setComponents( r.components ) )
			.catch( fail );
	useEffect( () => {
		load();
	}, [] );

	const scan = async ( id ) => {
		setBusy( id );
		try {
			await walkPages(
				( after ) =>
					apiFetch( {
						path: '/tranzly/v1/theme-strings/scan',
						method: 'POST',
						data: { component: id, after },
					} ),
				() => {}
			);
			await load();
			setChosen( id );
		} catch ( e ) {
			fail( e );
		}
		setBusy( '' );
	};
	const current = ( components || [] ).find( ( c ) => c.id === chosen );

	return (
		<PanelBody
			title={ __( 'Theme and plugin text', 'tranzly' ) }
			initialOpen={ false }
		>
			<p>
				{ __(
					'The words your theme and plugins print themselves (buttons, labels). Read a theme or plugin once, then translate its text here; your translation wins over the plugin’s own.',
					'tranzly'
				) }
			</p>
			{ null === components && <Spinner /> }
			{ components && (
				<ul className="tranzly-theme-text__list">
					{ components.map( ( c ) => (
						<li key={ c.id }>
							<strong>{ c.label }</strong>{ ' ' }
							{ c.scanned
								? sprintf(
										/* translators: %d: how many texts. */
										__( '%d texts', 'tranzly' ),
										c.strings
									)
								: __( 'not read yet', 'tranzly' ) }{ ' ' }
							<Button
								variant="secondary"
								isBusy={ busy === c.id }
								disabled={ !! busy }
								onClick={ () => scan( c.id ) }
							>
								{ c.scanned
									? __( 'Read again', 'tranzly' )
									: __( 'Read its text', 'tranzly' ) }
							</Button>{ ' ' }
							{ c.scanned && (
								<Button
									variant="link"
									onClick={ () => setChosen( c.id ) }
								>
									{ __( 'Translate', 'tranzly' ) }
								</Button>
							) }
						</li>
					) ) }
				</ul>
			) }
			{ current && (
				<>
					<h3>{ current.label }</h3>
					<StringsEditor
						key={ current.id }
						scope={ current.scope }
						languages={ languages }
					/>
				</>
			) }
		</PanelBody>
	);
}
