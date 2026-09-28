import { __ } from '@wordpress/i18n';

import { mountAdminKit } from '../admin-kit';
import Root from './Root';
import App from './App';
import Engines from './Engines';
import './admin.scss';

const root = document.getElementById( 'tranzly-admin-root' );
const data = window.tranzlyAdmin;

if ( root && data ) {
	// The admin kit is the shell (overview, plans, add-ons, help, the setup wizard); Tranzly's own
	// screens are one route inside it and are unchanged. The wizard's steps ARE those screens, so
	// a choice made during setup is the same setting as on its own tab.
	mountAdminKit(
		root,
		'tranzly',
		[
			{
				id: 'settings',
				label: __( 'Settings', 'tranzly' ),
				render: () => <Root data={ data } />,
			},
		],
		[
			{
				id: 'languages',
				title: __( 'Languages', 'tranzly' ),
				render: () => <App data={ data } />,
			},
			{
				id: 'engines',
				title: __( 'Translation engine', 'tranzly' ),
				render: () => <Engines />,
			},
		]
	);
}
