import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useState } from '@wordpress/element';

import { studioClient, uploadStudioFile } from '../../../app/client';

const InstagramPane = () => {
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
				const statuses = await studioClient.socials.getAuthStatus();
				const instagram = Array.isArray( statuses )
					? statuses.find( ( item ) => item.platform === 'instagram' )
					: null;

				setAuthStatus( instagram || null );
			} catch ( fetchError ) {
				setAuthStatus( null );
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
			const response = await uploadStudioFile( selectedFile );

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
			const response = await studioClient.socials.crossPost( {
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
		createElement.Fragment,
		null,
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Instagram publish', 'extrachill-studio' ) ),
			createElement( 'h3', null, __( 'Publish a post through Data Machine Socials', 'extrachill-studio' ) ),
			createElement( 'p', null, __( 'This is the first live workflow inside the broader Socials area. It uses the existing socials auth, upload, and cross-post endpoints without adding new backend primitives.', 'extrachill-studio' ) ),
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
							setSelectedFile( event.target.files?.[ 0 ] || null );
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

export default InstagramPane;
