import { createRoot } from '@wordpress/element';

import Root from './Root';
import './admin.scss';

const root = document.getElementById( 'tranzly-admin-root' );
if ( root && window.tranzlyAdmin ) {
	createRoot( root ).render( <Root data={ window.tranzlyAdmin } /> );
}
