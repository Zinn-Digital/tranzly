import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import {
	CheckboxControl,
	PanelBody,
	SelectControl,
} from '@wordpress/components';
import { Fragment } from '@wordpress/element';

import { useLanguages } from './languages-store';
import { withRule } from './visibility-model';

/*
 * "Show content only in some languages" (tz-r7): a Languages panel on every block. The rule is
 * the block attribute `tranzlyLanguages`; the server drops the block for a visitor whose language
 * it excludes (includes/content/class-visibility.php).
 */

const ATTRIBUTE = 'tranzlyLanguages';

addFilter(
	'blocks.registerBlockType',
	'tranzly/visibility-attribute',
	( settings ) => {
		if ( settings.attributes?.[ ATTRIBUTE ] ) {
			return settings;
		}
		return {
			...settings,
			attributes: {
				...settings.attributes,
				[ ATTRIBUTE ]: { type: 'object', default: {} },
			},
		};
	}
);

function LanguagesPanel( { attributes, setAttributes } ) {
	const languages = useLanguages();
	const rule = attributes[ ATTRIBUTE ] || {};
	const mode = rule.langs?.length ? rule.mode || 'only' : 'all';
	if ( ! languages || languages.length < 2 ) {
		return null;
	}
	const set = ( next ) => setAttributes( { [ ATTRIBUTE ]: next } );
	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'Languages', 'tranzly' ) }
				initialOpen={ 'all' !== mode }
			>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Show this block', 'tranzly' ) }
					value={ mode }
					options={ [
						{
							value: 'all',
							label: __( 'In every language', 'tranzly' ),
						},
						{
							value: 'only',
							label: __(
								'Only in the languages ticked',
								'tranzly'
							),
						},
						{
							value: 'except',
							label: __(
								'In every language except those ticked',
								'tranzly'
							),
						},
					] }
					onChange={ ( value ) =>
						set( withRule( rule, { mode: value } ) )
					}
				/>
				{ 'all' !== mode &&
					languages.map( ( language ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ language.code }
							label={ language.name }
							checked={ ( rule.langs || [] ).includes(
								language.code
							) }
							onChange={ ( on ) =>
								set(
									withRule( rule, {
										mode,
										toggle: language.code,
										on,
									} )
								)
							}
						/>
					) ) }
			</PanelBody>
		</InspectorControls>
	);
}

addFilter(
	'editor.BlockEdit',
	'tranzly/visibility-panel',
	createHigherOrderComponent(
		( BlockEdit ) => ( props ) => (
			<Fragment>
				<BlockEdit { ...props } />
				{ props.isSelected && <LanguagesPanel { ...props } /> }
			</Fragment>
		),
		'withTranzlyLanguages'
	)
);
