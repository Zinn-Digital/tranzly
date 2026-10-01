/**
 * Bundles: the admin screen, the block editor script, the side-by-side editor, and (premium only,
 * dropped from the free package by name) the editor's image-per-language panel and the visual
 * front-end editor. All are for people editing the site; a visitor's page loads nothing built here
 * (front-end CSS/JS is published from assets/, ADR 0033).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		settings: path.resolve( __dirname, 'src/admin/index.js' ),
		editor: path.resolve( __dirname, 'src/editor/index.js' ),
		compare: path.resolve( __dirname, 'src/compare/index.js' ),
		editor__premium_only: path.resolve(
			__dirname,
			'src/pro__premium_only/editor/index.js'
		),
		settings__premium_only: path.resolve(
			__dirname,
			'src/pro__premium_only/settings/index.js'
		),
		visual__premium_only: path.resolve(
			__dirname,
			'src/pro__premium_only/visual/index.js'
		),
	},
};
