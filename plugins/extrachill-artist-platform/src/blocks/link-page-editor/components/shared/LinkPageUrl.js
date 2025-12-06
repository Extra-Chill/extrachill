/**
 * Link Page URL Component
 *
 * Displays the public link page URL with QR code button.
 * URL is shown without protocol for cleaner display.
 */

import { __ } from '@wordpress/i18n';

export default function LinkPageUrl( { publicUrl, onQRCodeClick } ) {
	if ( ! publicUrl ) {
		return null;
	}

	const displayUrl = publicUrl.replace( /^https?:\/\//, '' );

	return (
		<div className="ec-editor__link-url notice notice-info">
			<a
				href={ publicUrl }
				className="ec-editor__link-url-text"
				target="_blank"
				rel="noopener noreferrer"
			>
				{ displayUrl }
			</a>
			<button
				type="button"
				className="ec-editor__link-url-qr button-2 button-small"
				onClick={ onQRCodeClick }
				title={ __( 'Get QR Code', 'extrachill-artist-platform' ) }
			>
				<i className="fa-solid fa-qrcode"></i>
			</button>
		</div>
	);
}
