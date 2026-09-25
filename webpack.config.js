/**
 * Two bundles: the admin screen and the block editor script. Both are wp-admin only; the
 * front end loads nothing built here (front-end CSS is published from assets/, ADR 0033).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		settings: path.resolve( __dirname, 'src/admin/index.js' ),
		editor: path.resolve( __dirname, 'src/editor/index.js' ),
	},
};
