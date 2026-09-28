import { __ } from '@wordpress/i18n';

/*
 * The pure half of the switcher block's controls, shared with the Page Builder Sandwich element
 * through the same option lists (the server's Switcher::STYLES / DISPLAYS are the authority).
 */

/**
 * The designs.
 *
 * @return {Array<{value:string,label:string}>} Options.
 */
export function designOptions() {
	return [
		{ value: 'list', label: __( 'List', 'tranzly' ) },
		{ value: 'pills', label: __( 'Pills', 'tranzly' ) },
		{ value: 'buttons', label: __( 'Buttons', 'tranzly' ) },
		{ value: 'dropdown', label: __( 'Dropdown', 'tranzly' ) },
		{ value: 'codes', label: __( 'Language codes', 'tranzly' ) },
	];
}

/**
 * How each language is named.
 *
 * @return {Array<{value:string,label:string}>} Options.
 */
export function displayOptions() {
	return [
		{ value: 'name', label: __( 'Language name', 'tranzly' ) },
		{ value: 'code', label: __( 'Language code', 'tranzly' ) },
		{ value: 'name_code', label: __( 'Name and code', 'tranzly' ) },
	];
}

/**
 * The style variables with one changed; an empty value removes it (back to the theme's look).
 *
 * @param {Object<string,string>} vars  Current variables.
 * @param {string}                key   Variable name.
 * @param {string|undefined}      value New value.
 * @return {Object<string,string>} New variables.
 */
export function setVar( vars, key, value ) {
	const next = { ...( vars || {} ) };
	if ( value ) {
		next[ key ] = value;
	} else {
		delete next[ key ];
	}
	return next;
}
