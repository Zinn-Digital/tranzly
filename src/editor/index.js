import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import switcher from '../../blocks/fixture-switcher/block.json';

/*
 * The block is dynamic: the server renders it (includes/class-fixture.php), so the editor
 * previews the HTML a visitor gets and save() stores attributes only.
 */

function SwitcherEmpty() {
	return (
		<p>
			{ __(
				'Add a second language under Tranzly in the admin menu, and the switcher appears here.',
				'tranzly'
			) }
		</p>
	);
}

function SwitcherEdit( { attributes, setAttributes } ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Switcher', 'tranzly' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Accessible label', 'tranzly' ) }
						value={ attributes.label }
						onChange={ ( label ) => setAttributes( { label } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block={ switcher.name }
				attributes={ attributes }
				EmptyResponsePlaceholder={ SwitcherEmpty }
			/>
		</div>
	);
}

registerBlockType( switcher.name, { edit: SwitcherEdit, save: () => null } );
