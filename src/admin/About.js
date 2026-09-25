import { __, sprintf } from '@wordpress/i18n';
import { ExternalLink, PanelBody } from '@wordpress/components';

/**
 * About and credits.
 *
 * @param {Object} props
 * @param {string} props.version    Plugin version.
 * @param {string} props.productUrl The product site.
 * @param {string} props.companyUrl The company site.
 * @return {Element} The panel.
 */
export default function About( { version, productUrl, companyUrl } ) {
	return (
		<PanelBody title={ __( 'About', 'tranzly' ) } initialOpen>
			<p className="tranzly-admin__credit">
				{ __(
					'Built by Neil Lock, CEO of Zinn Digital® Ltd',
					'tranzly'
				) }
			</p>
			<p>
				{ sprintf(
					/* translators: %s: plugin version number. */
					__( 'Version %s', 'tranzly' ),
					version
				) }
			</p>
			<ul className="tranzly-admin__links">
				<li>
					<ExternalLink href={ productUrl }>
						{ __( 'Tranzly website', 'tranzly' ) }
					</ExternalLink>
				</li>
				<li>
					<ExternalLink href={ companyUrl }>
						{ __( 'Zinn Digital® website', 'tranzly' ) }
					</ExternalLink>
				</li>
			</ul>
		</PanelBody>
	);
}
