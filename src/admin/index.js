import { createRoot } from '@wordpress/element';

import App from './App';
import './admin.scss';

const root = document.getElementById( 'tranzly-admin-root' );
if ( root && window.tranzlyAdmin ) {
	createRoot( root ).render( <App data={ window.tranzlyAdmin } /> );
}
