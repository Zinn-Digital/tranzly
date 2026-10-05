import { __, sprintf } from '@wordpress/i18n';
import { createRoot, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	Spinner,
	TextareaControl,
} from '@wordpress/components';

import { changedSegments, editable, saveBody, stateText } from './model';
import './compare.scss';

/*
 * The side-by-side translation editor (tz-w4): the original on one side, the translation on the
 * other, piece by piece, each marked machine or checked by a person. Saving writes each corrected
 * piece back into its own place in the translation and protects it (tz-r1).
 */

function Compare( { postId } ) {
	const [ data, setData ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const take = ( result ) => {
		setData( result );
		setDraft(
			Object.fromEntries(
				result.segments.map( ( s ) => [ s.key, editable( s ) ] )
			)
		);
	};

	useEffect( () => {
		apiFetch( { path: `/tranzly/v1/posts/${ postId }/segments` } )
			.then( take )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			);
	}, [ postId ] );

	if ( ! data ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const changes = changedSegments( data.segments, draft );
	const save = () => {
		setSaving( true );
		setNotice( null );
		apiFetch( {
			path: `/tranzly/v1/posts/${ postId }/segments`,
			method: 'PUT',
			data: saveBody( data.segments, changes ),
		} )
			.then( ( result ) => {
				take( result );
				setNotice( {
					status: 'success',
					message: __(
						'Saved. The pieces you changed are marked as checked by a person, and this translation is protected from machine translation.',
						'tranzly'
					),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			)
			.finally( () => setSaving( false ) );
	};

	const targetLang = ( data.lang || '' ).replace( '_', '-' );
	const sourceLang = ( data.source_lang || '' ).replace( '_', '-' );
	return (
		<div className="tranzly-compare">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ ! data.aligned && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The layout of this translation was changed in the editor, so some pieces no longer pair with the original. Pieces without a partner are shown empty; edit the rest in the editor.',
						'tranzly'
					) }{ ' ' }
					<a href={ data.edit }>
						{ __( 'Open the editor', 'tranzly' ) }
					</a>
				</Notice>
			) }
			<table className="widefat tranzly-compare__table">
				<thead>
					<tr>
						<th scope="col">
							{ sprintf(
								/* translators: %s: a language tag such as en-US. */
								__( 'Original (%s)', 'tranzly' ),
								sourceLang
							) }
						</th>
						<th scope="col">
							{ sprintf(
								/* translators: %s: a language tag such as de-DE. */
								__( 'Translation (%s)', 'tranzly' ),
								targetLang
							) }
						</th>
						<th scope="col">{ __( 'State', 'tranzly' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ data.segments.map( ( segment ) => (
						<tr key={ segment.key }>
							<td lang={ sourceLang } dir="auto">
								<div className="tranzly-compare__source">
									{ segment.source_text ?? segment.source }
								</div>
							</td>
							<td>
								<TextareaControl
									__nextHasNoMarginBottom
									label={ __( 'Translation', 'tranzly' ) }
									hideLabelFromVision
									help={
										'html' === segment.view
											? __(
													'This piece has formatting or links, so it is shown with its HTML. Change only the words between the tags.',
													'tranzly'
												)
											: undefined
									}
									lang={ targetLang }
									dir="auto"
									disabled={ null === segment.target }
									value={ draft[ segment.key ] ?? '' }
									onChange={ ( value ) =>
										setDraft( {
											...draft,
											[ segment.key ]: value,
										} )
									}
								/>
							</td>
							<td>
								<span
									className={
										'tranzly-compare__state tranzly-compare__state--' +
										segment.state
									}
								>
									{ stateText( segment.state ) }
								</span>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<p>
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ saving || ! Object.keys( changes ).length }
					onClick={ save }
				>
					{ __( 'Save changes', 'tranzly' ) }
				</Button>{ ' ' }
				<a href={ data.edit }>
					{ __( 'Open in the editor', 'tranzly' ) }
				</a>
			</p>
		</div>
	);
}

const root = document.getElementById( 'tranzly-compare-root' );
if ( root ) {
	createRoot( root ).render(
		<Compare postId={ parseInt( root.dataset.post, 10 ) } />
	);
}
