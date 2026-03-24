import { __ } from '@wordpress/i18n';
import { createElement, useState } from '@wordpress/element';

import { studioClient } from '../../app/client';

const QrCodesPane = () => {
	const [ url, setUrl ] = useState( '' );
	const [ size, setSize ] = useState( '1000' );
	const [ imageUrl, setImageUrl ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );

	const generateQrCode = async () => {
		const trimmedUrl = url.trim();
		const parsedSize = Number.parseInt( size, 10 );

		if ( ! trimmedUrl ) {
			setError( __( 'Enter a URL to generate a QR code.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		setIsLoading( true );
		setError( '' );
		setStatus( __( 'Generating QR code…', 'extrachill-studio' ) );

		try {
			const responseData = await studioClient.admin.generateQrCode( trimmedUrl, Number.isNaN( parsedSize ) ? 1000 : parsedSize );
			setImageUrl( responseData.image_url || '' );
			setStatus( __( 'QR code ready to preview and download.', 'extrachill-studio' ) );
		} catch ( fetchError ) {
			setImageUrl( '' );
			setStatus( '' );
			setError( fetchError?.message || __( 'QR generation failed. Please try again.', 'extrachill-studio' ) );
		} finally {
			setIsLoading( false );
		}
	};

	return createElement(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--qr-codes' },
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
		createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'QR generator', 'extrachill-studio' ) ),
		createElement( 'h3', null, __( 'Generate a QR code', 'extrachill-studio' ) ),
		createElement( 'p', null, __( 'Enter any URL to generate a high-resolution QR code PNG. Useful for flyers, merch, and event signage.', 'extrachill-studio' ) ),
			createElement(
				'div',
				{ className: 'ec-studio-composer' },
				createElement(
					'div',
					null,
					createElement( 'label', { htmlFor: 'ec-studio-qr-url' }, __( 'URL', 'extrachill-studio' ) ),
					createElement( 'input', {
						id: 'ec-studio-qr-url',
						type: 'url',
						value: url,
						onChange: ( event ) => setUrl( event.target.value ),
						placeholder: 'https://extrachill.com/',
						autoComplete: 'url',
					} )
				),
				createElement(
					'div',
					null,
					createElement( 'label', { htmlFor: 'ec-studio-qr-size' }, __( 'Size', 'extrachill-studio' ) ),
					createElement( 'input', {
						id: 'ec-studio-qr-size',
						type: 'number',
						min: '100',
						max: '2000',
						step: '100',
						value: size,
						onChange: ( event ) => setSize( event.target.value ),
					} )
				),
				createElement(
					'div',
					{ className: 'ec-studio-composer__actions' },
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium',
							onClick: generateQrCode,
							disabled: isLoading,
						},
						isLoading ? __( 'Generating…', 'extrachill-studio' ) : __( 'Generate QR Code', 'extrachill-studio' )
					),
					createElement( 'span', { className: 'ec-studio-composer__hint' }, __( 'Sizes from 100 to 2000 pixels are supported.', 'extrachill-studio' ) )
				)
			),
			error ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--error' }, error ) : null,
			! error && status ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--success' }, status ) : null
		),
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Preview', 'extrachill-studio' ) ),
			imageUrl
				? createElement(
					'div',
					{ className: 'ec-studio-qr-result' },
					createElement( 'img', {
						className: 'ec-studio-qr-result__image',
						alt: __( 'Generated QR code preview', 'extrachill-studio' ),
						src: imageUrl,
					} ),
					createElement(
						'a',
						{
							className: 'button-1 button-medium ec-studio-qr-result__download',
							href: imageUrl,
							download: 'extrachill-qr-code.png',
						},
						__( 'Download PNG', 'extrachill-studio' )
					)
				)
				: createElement( 'div', { className: 'ec-studio-preview' }, __( 'Your QR code preview will appear here after generation.', 'extrachill-studio' ) )
		)
	);
};

export default QrCodesPane;
