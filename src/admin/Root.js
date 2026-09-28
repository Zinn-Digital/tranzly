import { __ } from '@wordpress/i18n';
import { TabPanel } from '@wordpress/components';

import App from './App';
import Engines from './Engines';
import IntegrationsTab from './IntegrationsTab';
import Jobs from './Jobs';
import MenusTab from './MenusTab';
import SeoAudit from './SeoAudit';
import StatusTab from './StatusTab';
import Switchers from './Switchers';
import Urls from './Urls';
import WorkflowTab from './WorkflowTab';

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
				{ name: 'urls', title: __( 'Addresses and SEO', 'tranzly' ) },
				{
					name: 'switchers',
					title: __( 'Language switchers', 'tranzly' ),
				},
				{ name: 'audit', title: __( 'SEO audit', 'tranzly' ) },
				{
					name: 'menus',
					title: __( 'Menus and shared text', 'tranzly' ),
				},
				{
					name: 'status',
					title: __( 'Translation status', 'tranzly' ),
				},
				{ name: 'workflow', title: __( 'Workflow', 'tranzly' ) },
				{
					name: 'integrations',
					title: __( 'Integrations', 'tranzly' ),
				},
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
				if ( 'urls' === tab.name ) {
					return <Urls />;
				}
				if ( 'switchers' === tab.name ) {
					return <Switchers pro={ 'pro' === data.edition } />;
				}
				if ( 'audit' === tab.name ) {
					return <SeoAudit />;
				}
				if ( 'menus' === tab.name ) {
					return <MenusTab pro={ 'pro' === data.edition } />;
				}
				if ( 'status' === tab.name ) {
					return <StatusTab pro={ 'pro' === data.edition } />;
				}
				if ( 'workflow' === tab.name ) {
					return <WorkflowTab pro={ 'pro' === data.edition } />;
				}
				if ( 'integrations' === tab.name ) {
					return <IntegrationsTab pro={ 'pro' === data.edition } />;
				}
				return <App data={ data } />;
			} }
		</TabPanel>
	);
}
