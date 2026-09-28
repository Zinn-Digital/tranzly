/* Generated from wp/packages/zinn-admin-kit/src/js/Wizard.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';

/**
 * The first-run setup wizard (feature adm-2). The kit supplies the welcome and the finish; the
 * host plugin supplies the steps that actually configure it (`{ id, title, render }`), each of
 * which is one of its own screens, so a setting changed here is the same setting as on its screen.
 *
 * @param {Object}        props
 * @param {Object}        props.kit      Boot data.
 * @param {Array<Object>} props.steps    Host steps.
 * @param {Function}      props.onDone   Mark setup finished.
 * @param {Function}      props.onSkip   Mark setup skipped.
 * @param {Function}      props.navigate Go to a route.
 * @return {Element} The wizard.
 */
/**
 * @param {number} i     A step's position.
 * @param {number} index The current step's position.
 * @return {string} Its class.
 */
function stepClass( i, index ) {
	if ( i === index ) {
		return 'is-current';
	}
	return i < index ? 'is-done' : '';
}

export default function Wizard( {
	kit,
	steps = [],
	onDone,
	onSkip,
	navigate,
} ) {
	const all = [
		{
			id: 'welcome',
			title: __( 'Welcome', 'tranzly' ),
			render: () => (
				<p>
					{ sprintf(
						/* translators: %s: plugin name. */
						__(
							'This takes a few minutes and sets up %s so it works on this site straight away. You can change everything later.',
							'tranzly'
						),
						kit.name
					) }
				</p>
			),
		},
		...steps,
		{
			id: 'finish',
			title: __( 'Done', 'tranzly' ),
			render: () => (
				<>
					<p>
						{ __(
							'You are ready. If anything does not work as expected, we are one click away.',
							'tranzly'
						) }
					</p>
					<p>
						<Button
							variant="link"
							onClick={ () => navigate( 'help' ) }
						>
							{ __( 'Get help', 'tranzly' ) }
						</Button>
					</p>
				</>
			),
		},
	];
	const [ index, setIndex ] = useState( 0 );
	const step = all[ index ];
	const last = index === all.length - 1;

	return (
		<Card className="zak-card zak-wizard">
			<CardHeader>
				<h2 className="zak-card__title">
					{ sprintf(
						/* translators: 1: step number, 2: number of steps, 3: step title. */
						__( 'Step %1$d of %2$d: %3$s', 'tranzly' ),
						index + 1,
						all.length,
						step.title
					) }
				</h2>
			</CardHeader>
			<CardBody>
				<ol className="zak-steps" aria-hidden="true">
					{ all.map( ( s, i ) => (
						<li
							key={ s.id }
							className={ stepClass( i, index ) }
						>
							{ s.title }
						</li>
					) ) }
				</ol>
				<div className="zak-wizard__body">{ step.render() }</div>
				<p className="zak-actions">
					{ index > 0 && (
						<Button
							variant="secondary"
							onClick={ () => setIndex( index - 1 ) }
						>
							{ __( 'Back', 'tranzly' ) }
						</Button>
					) }
					{ last ? (
						<Button variant="primary" onClick={ onDone }>
							{ __( 'Finish', 'tranzly' ) }
						</Button>
					) : (
						<Button
							variant="primary"
							onClick={ () => setIndex( index + 1 ) }
						>
							{ __( 'Next', 'tranzly' ) }
						</Button>
					) }
					{ ! last && (
						<Button variant="tertiary" onClick={ onSkip }>
							{ __( 'Skip setup', 'tranzly' ) }
						</Button>
					) }
				</p>
			</CardBody>
		</Card>
	);
}
