/**
 * QR Code Modal Component
 *
 * Displays QR code for link page URL with download functionality.
 * Uses REST API endpoint: POST /extrachill/v1/tools/qr-code
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const getConfig = () => window.ecLinkPageEditorConfig || {};

export default function QRCodeModal( { isOpen, onClose, publicUrl, artistSlug } ) {
	const config = getConfig();
	const [ imageUrl, setImageUrl ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isDownloading, setIsDownloading ] = useState( false );
	const [ error, setError ] = useState( null );

	const fetchQRCode = useCallback( async ( size = 300 ) => {
		if ( ! publicUrl ) {
			setError( __( 'Public URL is not available.', 'extrachill-artist-platform' ) );
			return null;
		}

		const response = await apiFetch( {
			path: 'extrachill/v1/tools/qr-code',
			method: 'POST',
			data: { url: publicUrl, size },
		} );

		return response.image_url;
	}, [ publicUrl ] );

	useEffect( () => {
		if ( ! isOpen || ! publicUrl ) {
			return;
		}

		setIsLoading( true );
		setError( null );
		setImageUrl( null );

		fetchQRCode( 300 )
			.then( ( url ) => {
				if ( url ) {
					setImageUrl( url );
				}
			} )
			.catch( ( err ) => {
				setError( err.message || __( 'Failed to generate QR code.', 'extrachill-artist-platform' ) );
			} )
			.finally( () => {
				setIsLoading( false );
			} );
	}, [ isOpen, publicUrl, fetchQRCode ] );

	const handleDownload = useCallback( async () => {
		setIsDownloading( true );

		try {
			const hiResUrl = await fetchQRCode( 1000 );

			if ( hiResUrl ) {
				const link = document.createElement( 'a' );
				link.href = hiResUrl;
				link.download = `${ artistSlug || 'link-page' }-qr-code.png`;
				document.body.appendChild( link );
				link.click();
				document.body.removeChild( link );
			}
		} catch ( err ) {
			setError( err.message || __( 'Failed to download QR code.', 'extrachill-artist-platform' ) );
		} finally {
			setIsDownloading( false );
		}
	}, [ fetchQRCode, artistSlug ] );

	const handleBackdropClick = useCallback( ( e ) => {
		if ( e.target === e.currentTarget ) {
			onClose();
		}
	}, [ onClose ] );

	if ( ! isOpen ) {
		return null;
	}

	return (
		<div className="ec-qr-modal" onClick={ handleBackdropClick }>
			<div className="ec-qr-modal__content">
				<button
					type="button"
					className="ec-qr-modal__close"
					onClick={ onClose }
					aria-label={ __( 'Close', 'extrachill-artist-platform' ) }
				>
					&times;
				</button>

				<h2>{ __( 'Your Link Page QR Code', 'extrachill-artist-platform' ) }</h2>

				<div className="ec-qr-modal__image-container">
					{ isLoading && (
						<p className="ec-qr-modal__loading">
							{ __( 'Loading QR Code...', 'extrachill-artist-platform' ) }
						</p>
					) }

					{ error && (
						<p className="notice notice-error">{ error }</p>
					) }

					{ imageUrl && ! isLoading && (
						<img
							src={ imageUrl }
							alt={ __( 'Link Page QR Code', 'extrachill-artist-platform' ) }
						/>
					) }
				</div>

				{ imageUrl && ! isLoading && (
					<div className="ec-qr-modal__actions">
                        <button
                            type="button"
                            className="button-2 button-medium"
                            onClick={ handleDownload }
                            disabled={ isDownloading }
                        >
                            { isDownloading
                                ? __( 'Generating...', 'extrachill-artist-platform' )
                                : __( 'Download for Print', 'extrachill-artist-platform' ) }
                        </button>
					</div>
				) }
			</div>
		</div>
	);
}
