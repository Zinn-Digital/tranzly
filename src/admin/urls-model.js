import { __ } from '@wordpress/i18n';

/*
 * The pure half of the "Addresses and SEO" tab: kept apart so a unit test runs the same rules the
 * screen uses. The server (Seo\Url_Settings) is the authority; these only stop an obviously bad
 * value before a round trip.
 */

const RESERVED = [
	'wp-admin',
	'wp-content',
	'wp-includes',
	'wp-json',
	'feed',
	'page',
	'comments',
	'search',
	'author',
	'embed',
	'trackback',
];

/**
 * Is this a usable address word for a language (same rule as Url_Settings::is_valid_segment)?
 *
 * @param {string} segment Candidate.
 * @return {boolean} Whether it is valid.
 */
export function isValidSegment( segment ) {
	return (
		/^[a-z][a-z0-9-]{1,11}$/.test( segment ) &&
		! RESERVED.includes( segment )
	);
}

/**
 * The URL modes. The Pro ones (subdomain, separate domain) are offered only with Pro: the free
 * edition shows no locked option (WordPress.org guideline 5, closure item T-2); the plan
 * comparison is where a free user reads what Pro adds.
 *
 * @param {boolean} pro Whether Pro is active.
 * @return {Array<Object>} RadioControl options.
 */
export function modeOptions( pro ) {
	return [
		{
			value: 'directory',
			label: __( 'A folder per language: example.com/de/', 'tranzly' ),
		},
		...( pro
			? [
					{
						value: 'subdomain',
						label: __(
							'A subdomain per language: de.example.com',
							'tranzly'
						),
					},
					{
						value: 'domain',
						label: __(
							'A separate domain per language: example.de',
							'tranzly'
						),
					},
				]
			: [] ),
		{
			value: 'query',
			label: __(
				'A query parameter: example.com/?lang=de (older sites)',
				'tranzly'
			),
		},
	];
}

/**
 * Plain names for the address words that can be translated.
 *
 * @return {Object<string,string>} Base key => label.
 */
export function BASE_LABELS() {
	return {
		category: __( 'Category', 'tranzly' ),
		post_tag: __( 'Tag', 'tranzly' ),
		product: __( 'Product', 'tranzly' ),
		product_cat: __( 'Product category', 'tranzly' ),
		product_tag: __( 'Product tag', 'tranzly' ),
	};
}
