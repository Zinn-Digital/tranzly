import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import {
	InspectorControls,
	PanelColorSettings,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from '../../blocks/language-switcher/block.json';
import { designOptions, displayOptions, setVar } from './switcher-model';

/*
 * The language switcher block (tz-l1). Dynamic: the server renders it with the same code as the
 * shortcode, widget, menu item and builder elements (includes/switcher/class-switcher.php), so the
 * editor previews exactly what a visitor gets, and save() stores attributes only.
 */

function Empty() {
	return (
		<p>
			{ __(
				'Add a second language under Tranzly in the admin menu, and the switcher appears here.',
				'tranzly'
			) }
		</p>
	);
}

function Edit( { attributes, setAttributes } ) {
	const vars = attributes.vars || {};
	const colour = ( key, label ) => ( {
		value: vars[ key ],
		onChange: ( value ) =>
			setAttributes( { vars: setVar( vars, key, value ) } ),
		label,
	} );
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Switcher', 'tranzly' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Design', 'tranzly' ) }
						value={ attributes.style }
						options={ designOptions() }
						onChange={ ( style ) => setAttributes( { style } ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Show each language as', 'tranzly' ) }
						value={ attributes.display }
						options={ displayOptions() }
						onChange={ ( display ) => setAttributes( { display } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Flags', 'tranzly' ) }
						help={ __(
							'Off by default: a flag is a country, not a language.',
							'tranzly'
						) }
						checked={ attributes.flags }
						onChange={ ( flags ) => setAttributes( { flags } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Include the current language',
							'tranzly'
						) }
						checked={ attributes.showCurrent }
						onChange={ ( showCurrent ) =>
							setAttributes( { showCurrent } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Hide languages this page is not translated into',
							'tranzly'
						) }
						checked={ attributes.hideMissing }
						onChange={ ( hideMissing ) =>
							setAttributes( { hideMissing } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Stack vertically', 'tranzly' ) }
						checked={ attributes.vertical }
						onChange={ ( vertical ) =>
							setAttributes( { vertical } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Accessible label', 'tranzly' ) }
						help={ __(
							'What screen readers announce, for example “Choose a language”.',
							'tranzly'
						) }
						value={ attributes.label }
						onChange={ ( label ) => setAttributes( { label } ) }
					/>
				</PanelBody>
				<PanelColorSettings
					title={ __( 'Colours', 'tranzly' ) }
					initialOpen={ false }
					colorSettings={ [
						colour( 'color', __( 'Text', 'tranzly' ) ),
						colour( 'background', __( 'Background', 'tranzly' ) ),
						colour(
							'activeColor',
							__( 'Current language text', 'tranzly' )
						),
						colour(
							'activeBg',
							__( 'Current language background', 'tranzly' )
						),
					] }
				/>
			</InspectorControls>
			<ServerSideRender
				block={ metadata.name }
				attributes={ attributes }
				EmptyResponsePlaceholder={ Empty }
			/>
		</div>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
