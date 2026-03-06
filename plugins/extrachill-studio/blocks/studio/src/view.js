import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';
import { __, sprintf } from '@wordpress/i18n';
import { createElement, createRoot, render, useEffect, useState } from '@wordpress/element';

const ROOT_SELECTOR = '[data-ec-studio-root]';
const client = new ExtraChillClient( new WpApiFetchTransport( apiFetch ) );

const mountComponent = ( container, component ) => {
	if ( ! container ) {
		return;
	}

	if ( typeof createRoot === 'function' ) {
		const root = createRoot( container );
		root.render( component );
		return;
	}

	if ( typeof render === 'function' ) {
		render( component, container );
	}
};

const uploadFile = async ( file ) => {
	const formData = new FormData();
	formData.append( 'file', file );

	return client.socials.uploadCroppedMedia( formData );
};

const OverviewPane = ( { context } ) => createElement(
	'div',
	{ className: 'ec-studio-pane ec-studio-pane--overview' },
	createElement(
		'div',
		{ className: 'ec-studio-pane__grid' },
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Welcome', 'extrachill-studio' ) ),
			createElement( 'h3', null, sprintf( __( 'Hey %s', 'extrachill-studio' ), context.userName || __( 'team member', 'extrachill-studio' ) ) ),
			createElement( 'p', null, __( 'Studio 0.1.0 is now a working internal shell with real utilities for the team.', 'extrachill-studio' ) )
		),
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Current site', 'extrachill-studio' ) ),
			createElement( 'h3', null, context.siteName || __( 'Extra Chill', 'extrachill-studio' ) ),
			createElement( 'p', null, __( 'Studio stays aligned with the existing multisite theme system, team-member permissions, and shared tabs UI.', 'extrachill-studio' ) )
		),
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'What works now', 'extrachill-studio' ) ),
			createElement(
				'ul',
				null,
				createElement( 'li', null, __( 'Generate print-ready QR codes from any URL.', 'extrachill-studio' ) ),
				createElement( 'li', null, __( 'Upload image files for Instagram posting through Data Machine Socials.', 'extrachill-studio' ) ),
				createElement( 'li', null, __( 'Publish an Instagram post with caption and image URLs using the existing socials backend.', 'extrachill-studio' ) )
			)
		)
	)
);

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
			const responseData = await client.admin.generateQrCode( trimmedUrl, Number.isNaN( parsedSize ) ? 1000 : parsedSize );
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
			createElement( 'h3', null, __( 'Generate a print-ready QR code from any URL', 'extrachill-studio' ) ),
			createElement( 'p', null, __( 'This uses the existing Extra Chill QR code tool through the current REST endpoint and ability.', 'extrachill-studio' ) ),
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

const InstagramPane = ( { context } ) => {
	const [ authStatus, setAuthStatus ] = useState( null );
	const [ authError, setAuthError ] = useState( '' );
	const [ isCheckingAuth, setIsCheckingAuth ] = useState( true );
	const [ caption, setCaption ] = useState( '' );
	const [ imageUrlInput, setImageUrlInput ] = useState( '' );
	const [ imageUrls, setImageUrls ] = useState( [] );
	const [ selectedFile, setSelectedFile ] = useState( null );
	const [ isUploading, setIsUploading ] = useState( false );
	const [ isPublishing, setIsPublishing ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ publishResult, setPublishResult ] = useState( null );

	useEffect( () => {
		const loadAuthStatus = async () => {
			setIsCheckingAuth( true );
			setAuthError( '' );

			try {
				const statuses = await client.socials.getAuthStatus();

				const instagram = Array.isArray( statuses )
					? statuses.find( ( item ) => item.platform === 'instagram' )
					: null;

				setAuthStatus( instagram || null );
			} catch ( fetchError ) {
				setAuthError( fetchError?.message || __( 'Unable to load Instagram auth status.', 'extrachill-studio' ) );
			} finally {
				setIsCheckingAuth( false );
			}
		};

		loadAuthStatus();
	}, [] );

	const addImageUrl = () => {
		const nextUrl = imageUrlInput.trim();

		if ( ! nextUrl ) {
			setError( __( 'Enter an image URL first.', 'extrachill-studio' ) );
			return;
		}

		try {
			new URL( nextUrl );
		} catch ( urlError ) {
			setError( __( 'Please enter a valid image URL.', 'extrachill-studio' ) );
			return;
		}

		setImageUrls( ( current ) => [ ...current, nextUrl ] );
		setImageUrlInput( '' );
		setError( '' );
		setStatus( __( 'Image URL added to publish queue.', 'extrachill-studio' ) );
	};

	const handleUpload = async () => {
		if ( ! selectedFile ) {
			setError( __( 'Choose an image file to upload first.', 'extrachill-studio' ) );
			return;
		}

		setIsUploading( true );
		setError( '' );
		setStatus( __( 'Uploading image…', 'extrachill-studio' ) );

		try {
			const response = await uploadFile( selectedFile );

			if ( ! response?.url ) {
				throw new Error( __( 'Upload did not return an image URL.', 'extrachill-studio' ) );
			}

			setImageUrls( ( current ) => [ ...current, response.url ] );
			setSelectedFile( null );
			setStatus( __( 'Image uploaded and added to publish queue.', 'extrachill-studio' ) );
		} catch ( uploadError ) {
			setError( uploadError?.message || __( 'Image upload failed.', 'extrachill-studio' ) );
			setStatus( '' );
		} finally {
			setIsUploading( false );
		}
	};

	const removeImageUrl = ( index ) => {
		setImageUrls( ( current ) => current.filter( ( item, itemIndex ) => itemIndex !== index ) );
		setStatus( __( 'Image removed from publish queue.', 'extrachill-studio' ) );
		setError( '' );
	};

	const publishInstagramPost = async () => {
		if ( ! caption.trim() ) {
			setError( __( 'Add a caption before publishing.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		if ( imageUrls.length === 0 ) {
			setError( __( 'Add at least one image URL before publishing.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		setIsPublishing( true );
		setError( '' );
		setPublishResult( null );
		setStatus( __( 'Publishing to Instagram…', 'extrachill-studio' ) );

		try {
			const response = await client.socials.crossPost( {
				platforms: [ 'instagram' ],
				images: imageUrls.map( ( url ) => ( { url } ) ),
				caption: caption.trim(),
			} );

			setPublishResult( response );

			if ( response?.success ) {
				setStatus( __( 'Instagram publish completed.', 'extrachill-studio' ) );
				setCaption( '' );
				setImageUrls( [] );
			} else {
				setStatus( '' );
				setError( response?.errors?.join( ' ' ) || __( 'Instagram publish failed.', 'extrachill-studio' ) );
			}
		} catch ( publishError ) {
			setStatus( '' );
			setError( publishError?.message || __( 'Instagram publish failed.', 'extrachill-studio' ) );
		} finally {
			setIsPublishing( false );
		}
	};

	const instagramResult = Array.isArray( publishResult?.results )
		? publishResult.results.find( ( result ) => result.platform === 'instagram' )
		: null;

	return createElement(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--instagram' },
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Instagram publish', 'extrachill-studio' ) ),
			createElement( 'h3', null, __( 'Publish a post through Data Machine Socials', 'extrachill-studio' ) ),
			createElement( 'p', null, __( 'This first Studio flow uses the existing socials auth, upload, and cross-post endpoints without adding new backend primitives.', 'extrachill-studio' ) ),
			isCheckingAuth
				? createElement( 'p', { className: 'ec-studio-message ec-studio-message--info' }, __( 'Checking Instagram authentication…', 'extrachill-studio' ) )
				: null,
			authError ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--error' }, authError ) : null,
			authStatus
				? createElement(
					'p',
					{ className: `ec-studio-message ${ authStatus.authenticated ? 'ec-studio-message--success' : 'ec-studio-message--warning' }` },
					authStatus.authenticated
						? sprintf( __( 'Instagram is authenticated as @%s.', 'extrachill-studio' ), authStatus.username || 'unknown' )
						: __( 'Instagram is not authenticated yet in Data Machine Socials.', 'extrachill-studio' )
				)
				: null,
			createElement(
				'div',
				{ className: 'ec-studio-composer' },
				createElement(
					'div',
					null,
					createElement( 'label', { htmlFor: 'ec-studio-instagram-caption' }, __( 'Caption', 'extrachill-studio' ) ),
					createElement( 'textarea', {
						id: 'ec-studio-instagram-caption',
						rows: 6,
						value: caption,
						onChange: ( event ) => setCaption( event.target.value ),
						placeholder: __( 'Write the Instagram caption here…', 'extrachill-studio' ),
					} )
				),
				createElement(
					'div',
					null,
					createElement( 'label', { htmlFor: 'ec-studio-instagram-image-url' }, __( 'Image URL', 'extrachill-studio' ) ),
					createElement( 'input', {
						id: 'ec-studio-instagram-image-url',
						type: 'url',
						value: imageUrlInput,
						onChange: ( event ) => setImageUrlInput( event.target.value ),
						placeholder: 'https://example.com/image.jpg',
						autoComplete: 'url',
					} )
				),
				createElement(
					'div',
					{ className: 'ec-studio-composer__actions' },
					createElement( 'button', { type: 'button', className: 'button-1 button-medium', onClick: addImageUrl }, __( 'Add Image URL', 'extrachill-studio' ) ),
					createElement( 'span', { className: 'ec-studio-composer__hint' }, __( 'Use a public image URL or upload a file below.', 'extrachill-studio' ) )
				),
				createElement(
					'div',
					null,
					createElement( 'label', { htmlFor: 'ec-studio-instagram-upload' }, __( 'Upload image', 'extrachill-studio' ) ),
					createElement( 'input', {
						id: 'ec-studio-instagram-upload',
						type: 'file',
						accept: 'image/*',
						onChange: ( event ) => {
							setSelectedFile( event.target.files?.[0] || null );
							setError( '' );
							setStatus( '' );
						},
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
							onClick: handleUpload,
							disabled: isUploading || ! selectedFile,
						},
						isUploading ? __( 'Uploading…', 'extrachill-studio' ) : __( 'Upload Image', 'extrachill-studio' )
					),
					selectedFile
						? createElement( 'span', { className: 'ec-studio-composer__hint' }, selectedFile.name )
						: null
				),
				error ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--error' }, error ) : null,
				! error && status ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--success' }, status ) : null,
				createElement(
					'div',
					{ className: 'ec-studio-composer__actions' },
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium',
							onClick: publishInstagramPost,
							disabled: isPublishing || ! authStatus?.authenticated,
						},
						isPublishing ? __( 'Publishing…', 'extrachill-studio' ) : __( 'Publish to Instagram', 'extrachill-studio' )
					),
					createElement( 'span', { className: 'ec-studio-composer__hint' }, __( 'This uses the existing `datamachine-socials/v1/post` route.', 'extrachill-studio' ) )
				)
			)
		),
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Queued images', 'extrachill-studio' ) ),
			imageUrls.length > 0
				? createElement(
					'ul',
					{ className: 'ec-studio-image-list' },
					...imageUrls.map( ( url, index ) => createElement(
						'li',
						{ key: `${ url }-${ index }`, className: 'ec-studio-image-list__item' },
						createElement( 'span', { className: 'ec-studio-image-list__url' }, url ),
						createElement( 'button', { type: 'button', className: 'ec-studio-image-list__remove', onClick: () => removeImageUrl( index ) }, __( 'Remove', 'extrachill-studio' ) )
					) )
				)
				: createElement( 'div', { className: 'ec-studio-preview' }, __( 'No images added yet. Add image URLs or upload files before publishing.', 'extrachill-studio' ) ),
			instagramResult
				? createElement(
					'div',
					{ className: 'ec-studio-instagram-result' },
					createElement( 'h4', null, __( 'Latest publish result', 'extrachill-studio' ) ),
					instagramResult.permalink
						? createElement( 'p', null, createElement( 'a', { href: instagramResult.permalink, target: '_blank', rel: 'noreferrer' }, __( 'View Instagram post', 'extrachill-studio' ) ) )
						: null,
					instagramResult.media_id ? createElement( 'p', null, sprintf( __( 'Media ID: %s', 'extrachill-studio' ), instagramResult.media_id ) ) : null
				)
				: null
		)
	);
};

const initRoot = ( root ) => {
	if ( ! root || root.dataset.ecStudioMounted === 'true' ) {
		return;
	}

	root.dataset.ecStudioMounted = 'true';

	const context = {
		userName: root.dataset.userName || '',
		siteName: root.dataset.siteName || '',
		siteUrl: root.dataset.siteUrl || '',
		restNonce: root.dataset.restNonce || '',
		socialsApiBase: root.dataset.socialsApiBase || '',
	};

	const mounts = root.querySelectorAll( '[data-ec-studio-pane]' );

	mounts.forEach( ( mount ) => {
		const pane = mount.dataset.ecStudioPane;

		if ( pane === 'overview' ) {
			mountComponent( mount, createElement( OverviewPane, { context } ) );
			return;
		}

		if ( pane === 'qr-codes' ) {
			mountComponent( mount, createElement( QrCodesPane ) );
			return;
		}

		if ( pane === 'instagram' ) {
			mountComponent( mount, createElement( InstagramPane, { context } ) );
		}
	} );
};

const init = () => {
	document.querySelectorAll( ROOT_SELECTOR ).forEach( initRoot );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
