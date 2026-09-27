import { __ } from '@wordpress/i18n';
import { TabPanel } from '@wordpress/components';

import App from './App';
import Engines from './Engines';
import Jobs from './Jobs';

/**
 * The Tranzly admin screen: settings, engines and background jobs, one tab each. The tabs are
 * self-contained so the shared admin shell (F3) can wrap them without changing them.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data printed by Admin::data().
 * @return {Element} The screen.
 */
export default function Root( { data } ) {
	return (
		<TabPanel
			className="tranzly-admin__tabs"
			tabs={ [
				{
					name: 'settings',
					title: __( 'Languages and settings', 'tranzly' ),
				},
				{ name: 'engines', title: __( 'Engines', 'tranzly' ) },
				{ name: 'jobs', title: __( 'Translation jobs', 'tranzly' ) },
			] }
			initialTabName={
				( window.location.hash || '' ).replace( '#/', '' ) || 'settings'
			}
		>
			{ ( tab ) => {
				if ( 'engines' === tab.name ) {
					return <Engines />;
				}
				if ( 'jobs' === tab.name ) {
					return <Jobs />;
				}
				return <App data={ data } />;
			} }
		</TabPanel>
	);
}
