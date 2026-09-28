import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

/**
 * The "Language switchers" tab (T5): the floating switcher and the automatic one around the
 * content, plus where every other kind of switcher is added. Self-contained, so the shared admin
 * shell (F3) can wrap it unchanged.
 *
 * @param {Object}  props
 * @param {boolean} props.pro Whether Pro is active (builder widgets).
 * @return {Element} The tab.
 */
export default function Switchers( { pro } ) {
	const [ display, setDisplay ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/tranzly/v1/display' } )
			.then( ( result ) => setDisplay( result.switcher ) )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	}, [] );

	if ( ! display ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const set = ( patch ) => setDisplay( { ...display, ...patch } );
	const save = () => {
		setSaving( true );
		setNotice( null );
		apiFetch( {
			path: '/tranzly/v1/display',
			method: 'PUT',
			data: {
				switcher: {
					position: display.position,
					style: display.style,
					floating: display.floating,
					display: display.display,
				},
			},
		} )
			.then( ( result ) => {
				setDisplay( result.switcher );
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

	return (
		<div className="tranzly-admin__switchers">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<Panel>
				<PanelBody title={ __( 'Floating switcher', 'tranzly' ) }>
					<p>
						{ __(
							'A small button in a corner of every page, on any theme, with no set-up.',
							'tranzly'
						) }
					</p>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Where', 'tranzly' ) }
						value={ display.floating }
						options={ [
							{ value: '', label: __( 'Off', 'tranzly' ) },
							{
								value: 'bottom-end',
								label: __(
									'Bottom corner, end side',
									'tranzly'
								),
							},
							{
								value: 'bottom-start',
								label: __(
									'Bottom corner, start side',
									'tranzly'
								),
							},
							{
								value: 'top-end',
								label: __( 'Top corner, end side', 'tranzly' ),
							},
							{
								value: 'top-start',
								label: __(
									'Top corner, start side',
									'tranzly'
								),
							},
						] }
						onChange={ ( floating ) => set( { floating } ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Show each language as', 'tranzly' ) }
						value={ display.display }
						options={ [
							{
								value: 'name',
								label: __( 'Language name', 'tranzly' ),
							},
							{
								value: 'code',
								label: __( 'Language code', 'tranzly' ),
							},
							{
								value: 'name_code',
								label: __( 'Name and code', 'tranzly' ),
							},
						] }
						onChange={ ( value ) => set( { display: value } ) }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Switcher around the content', 'tranzly' ) }
					initialOpen={ false }
				>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Add a switcher to every post and page',
							'tranzly'
						) }
						value={ display.position }
						options={ [
							{ value: 'none', label: __( 'No', 'tranzly' ) },
							{
								value: 'before',
								label: __( 'Before the content', 'tranzly' ),
							},
							{
								value: 'after',
								label: __( 'After the content', 'tranzly' ),
							},
						] }
						onChange={ ( position ) => set( { position } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show flags (a flag is a country, not a language)',
							'tranzly'
						) }
						checked={ 'flags' === display.style }
						onChange={ ( on ) =>
							set( { style: on ? 'flags' : 'names' } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Other ways to add a switcher', 'tranzly' ) }
					initialOpen={ false }
				>
					<ul className="tranzly-admin__ways">
						<li>
							{ __(
								'Block editor and site editor: the “Language switcher” block, with five designs.',
								'tranzly'
							) }
						</li>
						<li>
							{ __(
								'Page Builder Sandwich: the “Language switcher” element.',
								'tranzly'
							) }
						</li>
						<li>
							{ __(
								'Menus: Appearance → Menus → “Language switcher” box.',
								'tranzly'
							) }
						</li>
						<li>
							{ __(
								'Sidebars: the “Language switcher” widget.',
								'tranzly'
							) }
						</li>
						<li>
							{ __( 'Anywhere else, the shortcode:', 'tranzly' ) }{ ' ' }
							<code>
								{ '[tranzly_switcher style="dropdown"]' }
							</code>
						</li>
						<li>
							{ pro
								? __(
										'Elementor and Bricks: the “Language switcher” widget in each builder.',
										'tranzly'
									)
								: __(
										'Elementor and Bricks widgets are part of Tranzly Pro.',
										'tranzly'
									) }
						</li>
					</ul>
				</PanelBody>
			</Panel>
			<Button
				variant="primary"
				isBusy={ saving }
				disabled={ saving }
				onClick={ save }
			>
				{ __( 'Save', 'tranzly' ) }
			</Button>
		</div>
	);
}
