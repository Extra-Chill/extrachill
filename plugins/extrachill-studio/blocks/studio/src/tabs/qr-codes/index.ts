import { __ } from '@wordpress/i18n';
import { createElement, useState } from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';

import { studioClient } from '../../app/client';
import type { StudioPaneProps } from '../../types/studio';

const QR_SIZE = 1000;

interface QrCodeResult {
	image_url?: string;
}

const QrCodesPane = ( _props: StudioPaneProps ): ReactElement => {
	const [ url, setUrl ] = useState( '' );
	const [ imageUrl, setImageUrl ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );

	const generateQrCode = async (): Promise< void > => {
		const trimmedUrl = url.trim();

		if ( ! trimmedUrl ) {
			setError( __( 'Paste a URL to generate a QR code.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		setIsLoading( true );
		setError( '' );
		setStatus( __( 'Generating…', 'extrachill-studio' ) );

		try {
			const responseData = await studioClient.admin.generateQrCode( trimmedUrl, QR_SIZE ) as QrCodeResult;
			setImageUrl( responseData.image_url || '' );
			setStatus( '' );
		} catch ( fetchError ) {
			setImageUrl( '' );
			setStatus( '' );
			setError( ( fetchError as Error )?.message || __( 'QR generation failed. Please try again.', 'extrachill-studio' ) );
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
			createElement( 'p', null, __( 'Paste any URL to get a high-res QR code PNG for flyers, merch, and event signage.', 'extrachill-studio' ) ),
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
						onChange: ( event: ChangeEvent< HTMLInputElement > ) => setUrl( event.target.value ),
						placeholder: 'https://extrachill.com/',
						autoComplete: 'url',
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
						isLoading ? __( 'Generating…', 'extrachill-studio' ) : __( 'Generate', 'extrachill-studio' )
					)
				)
			),
			error ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--error' }, error ) : null,
			! error && status ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--success' }, status ) : null
		),
		imageUrl
			? createElement(
				'div',
				{ className: 'ec-studio-panel' },
				createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Result', 'extrachill-studio' ) ),
				createElement(
					'div',
					{ className: 'ec-studio-qr-result' },
					createElement( 'img', {
						className: 'ec-studio-qr-result__image',
						alt: __( 'Generated QR code', 'extrachill-studio' ),
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
			)
			: null
	);
};

export default QrCodesPane;
