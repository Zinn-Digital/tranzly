import { __, _n, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	ExternalLink,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

import { stateLabel, translatable } from './translations-model';

/*
 * The editor's "Translations" panel (tz-w1, tz-r1, tz-r3): every language of this post, its
 * state, and one click to translate the ones you tick. A translation a person edited is
 * protected and cannot be ticked until someone unlocks it on purpose.
 */

function TranslationsPanel() {
	const { postId, isNew } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			postId: editor.getCurrentPostId(),
			isNew: editor.isEditedPostNew(),
		};
	}, [] );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ picked, setPicked ] = useState( [] );
	const [ publish, setPublish ] = useState( false );
	const [ busy, setBusy ] = useState( '' );
	const [ results, setResults ] = useState( [] );

	const load = useCallback( () => {
		if ( ! postId || isNew ) {
			return;
		}
		apiFetch( { path: `/tranzly/v1/posts/${ postId }/languages` } )
			.then( ( result ) => {
				setData( result );
				setError( null );
			} )
			.catch( ( e ) => setError( e.message ) );
	}, [ postId, isNew ] );

	useEffect( load, [ load ] );

	if ( isNew ) {
		return (
			<PluginDocumentSettingPanel
				name="tranzly-translations"
				title={ __( 'Translations', 'tranzly' ) }
			>
				<p>
					{ __(
						'Save this post first, then translate it here.',
						'tranzly'
					) }
				</p>
			</PluginDocumentSettingPanel>
		);
	}

	const translate = async () => {
		const out = [];
		for ( const code of picked ) {
			setBusy( code );
			try {
				await apiFetch( {
					path: `/tranzly/v1/posts/${ data.source }/translate`,
					method: 'POST',
					data: { lang: code, status: publish ? 'publish' : 'draft' },
				} );
				out.push( { code, ok: true } );
			} catch ( e ) {
				out.push( { code, ok: false, message: e.message } );
			}
		}
		setBusy( '' );
		setPicked( [] );
		setResults( out );
		load();
	};

	const protect = ( id, on ) =>
		apiFetch( {
			path: `/tranzly/v1/posts/${ id }/protection`,
			method: 'POST',
			data: { protected: on },
		} )
			.then( load )
			.catch( ( e ) => setError( e.message ) );

	return (
		<PluginDocumentSettingPanel
			name="tranzly-translations"
			title={ __( 'Translations', 'tranzly' ) }
			className="tranzly-editor__translations"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<>
					<ul className="tranzly-editor__languages">
						{ data.languages.map( ( row ) => (
							<li key={ row.code }>
								{ translatable( row ) ? (
									<CheckboxControl
										__nextHasNoMarginBottom
										label={ row.name }
										checked={ picked.includes( row.code ) }
										onChange={ ( on ) =>
											setPicked(
												on
													? [ ...picked, row.code ]
													: picked.filter(
															( c ) =>
																c !== row.code
														)
											)
										}
									/>
								) : (
									<strong>{ row.name }</strong>
								) }
								<span className="tranzly-editor__state">
									{ busy === row.code ? (
										<Spinner />
									) : (
										stateLabel( row )
									) }
								</span>
								{ row.edit && ! row.is_self && (
									<a href={ row.edit }>
										{ __( 'Edit', 'tranzly' ) }
									</a>
								) }{ ' ' }
								{ row.compare && (
									<a href={ row.compare }>
										{ __( 'Side by side', 'tranzly' ) }
									</a>
								) }{ ' ' }
								{ row.id && 'publish' === row.status && (
									<ExternalLink href={ row.view }>
										{ __( 'View', 'tranzly' ) }
									</ExternalLink>
								) }
								{ row.protected && ! row.is_source && (
									<Button
										variant="link"
										onClick={ () =>
											protect( row.id, false )
										}
									>
										{ __(
											'Unlock so it can be translated again',
											'tranzly'
										) }
									</Button>
								) }
								{ row.id &&
									! row.protected &&
									! row.is_source && (
										<Button
											variant="link"
											onClick={ () =>
												protect( row.id, true )
											}
										>
											{ __( 'Protect', 'tranzly' ) }
										</Button>
									) }
							</li>
						) ) }
					</ul>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Publish the translations', 'tranzly' ) }
						checked={ publish }
						onChange={ setPublish }
					/>
					<Button
						variant="primary"
						disabled={ ! picked.length || !! busy }
						isBusy={ !! busy }
						onClick={ translate }
					>
						{ sprintf(
							/* translators: %d: how many languages are ticked. */
							_n(
								'Translate into %d language',
								'Translate into %d languages',
								picked.length,
								'tranzly'
							),
							picked.length
						) }
					</Button>
					{ results.map( ( r ) => (
						<Notice
							key={ r.code }
							status={ r.ok ? 'success' : 'error' }
							isDismissible={ false }
						>
							{ r.ok
								? sprintf(
										/* translators: %s: a language code. */
										__( '%s: translated.', 'tranzly' ),
										r.code
									)
								: `${ r.code }: ${ r.message }` }
						</Notice>
					) ) }
				</>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'tranzly-translations', { render: TranslationsPanel } );
