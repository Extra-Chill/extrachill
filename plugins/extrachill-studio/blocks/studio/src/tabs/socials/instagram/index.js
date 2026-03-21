import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useState } from '@wordpress/element';

import apiFetch from '@wordpress/api-fetch';
import { studioClient, studioSocialsApi, uploadStudioFile } from '../../../app/client';

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
	const [ mediaItems, setMediaItems ] = useState( [] );
	const [ selectedMediaId, setSelectedMediaId ] = useState( '' );
	const [ comments, setComments ] = useState( [] );
	const [ isLoadingMedia, setIsLoadingMedia ] = useState( false );
	const [ isLoadingComments, setIsLoadingComments ] = useState( false );
	const [ commentsError, setCommentsError ] = useState( '' );
	const [ commentsStatus, setCommentsStatus ] = useState( '' );
	const [ replyDrafts, setReplyDrafts ] = useState( {} );
	const [ replyingCommentId, setReplyingCommentId ] = useState( '' );

	useEffect( () => {
		const loadAuthStatus = async () => {
			setIsCheckingAuth( true );
			setAuthError( '' );

			try {
				const platforms = await studioClient.socials.getPlatforms();
				const instagram = platforms?.instagram
					? { platform: 'instagram', authenticated: platforms.instagram.authenticated || false, username: platforms.instagram.username || null }
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

	useEffect( () => {
		if ( ! authStatus?.authenticated ) {
			return;
		}

		const loadInstagramMedia = async () => {
			setIsLoadingMedia( true );
			setCommentsError( '' );

			try {
				const response = await studioSocialsApi.getInstagramMedia( { limit: 10 } );
				const nextMediaItems = response?.data?.media || [];
				setMediaItems( nextMediaItems );

				if ( nextMediaItems.length > 0 ) {
					setSelectedMediaId( ( current ) => current || nextMediaItems[ 0 ].id );
				}
			} catch ( fetchError ) {
				setCommentsError( fetchError?.message || __( 'Unable to load recent Instagram posts.', 'extrachill-studio' ) );
			} finally {
				setIsLoadingMedia( false );
			}
		};

		loadInstagramMedia();
	}, [ authStatus?.authenticated ] );

	useEffect( () => {
		if ( ! selectedMediaId || ! authStatus?.authenticated ) {
			return;
		}

		const loadComments = async () => {
			setIsLoadingComments( true );
			setCommentsError( '' );
			setCommentsStatus( '' );

			try {
				const response = await studioSocialsApi.getInstagramComments( selectedMediaId, { limit: 25 } );
				setComments( response?.data?.comments || [] );
			} catch ( fetchError ) {
				setComments( [] );
				setCommentsError( fetchError?.message || __( 'Unable to load comments for this post.', 'extrachill-studio' ) );
			} finally {
				setIsLoadingComments( false );
			}
		};

		loadComments();
	}, [ selectedMediaId, authStatus?.authenticated ] );

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

	const submitForReview = async () => {
		if ( ! caption.trim() ) {
			setError( __( 'Add a caption before submitting.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		if ( imageUrls.length === 0 ) {
			setError( __( 'Add at least one image before submitting.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		setIsPublishing( true );
		setError( '' );
		setStatus( __( 'Submitting for review…', 'extrachill-studio' ) );

		try {
			const post = await apiFetch( {
				path: '/wp/v2/posts',
				method: 'POST',
				data: {
					title: caption.trim().substring( 0, 80 ) + ( caption.trim().length > 80 ? '…' : '' ),
					content: caption.trim(),
					status: 'pending',
					meta: {
						_studio_social_platforms: [ 'instagram' ],
						_studio_social_caption: caption.trim(),
						_studio_social_images: imageUrls.map( ( url ) => ( { url } ) ),
						_studio_social_aspect_ratio: '4:5',
						_studio_social_media_kind: imageUrls.length > 1 ? 'carousel' : 'image',
					},
				},
			} );

			setStatus(
				/* translators: %d: post ID */
				sprintf( __( 'Draft #%d submitted for review. An admin will approve it before it goes live.', 'extrachill-studio' ), post.id )
			);
			setCaption( '' );
			setImageUrls( [] );
		} catch ( submitError ) {
			setStatus( '' );
			setError( submitError?.message || __( 'Failed to submit draft.', 'extrachill-studio' ) );
		} finally {
			setIsPublishing( false );
		}
	};

	const instagramResult = Array.isArray( publishResult?.results )
		? publishResult.results.find( ( result ) => result.platform === 'instagram' )
		: null;

	const selectedMedia = mediaItems.find( ( item ) => item.id === selectedMediaId ) || null;

	const setReplyDraft = ( commentId, value ) => {
		setReplyDrafts( ( current ) => ( {
			...current,
			[ commentId ]: value,
		} ) );
	};

	const replyToComment = async ( commentId ) => {
		const message = ( replyDrafts[ commentId ] || '' ).trim();

		if ( ! message ) {
			setCommentsError( __( 'Write a reply before posting.', 'extrachill-studio' ) );
			setCommentsStatus( '' );
			return;
		}

		setReplyingCommentId( commentId );
		setCommentsError( '' );
		setCommentsStatus( __( 'Posting reply…', 'extrachill-studio' ) );

		try {
			await studioSocialsApi.replyToInstagramComment( commentId, message );
			setReplyDraft( commentId, '' );
			setCommentsStatus( __( 'Reply posted successfully.', 'extrachill-studio' ) );

			const response = await studioSocialsApi.getInstagramComments( selectedMediaId, { limit: 25 } );
			setComments( response?.data?.comments || [] );
		} catch ( replyError ) {
			setCommentsStatus( '' );
			setCommentsError( replyError?.message || __( 'Failed to reply to comment.', 'extrachill-studio' ) );
		} finally {
			setReplyingCommentId( '' );
		}
	};

	return createElement(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--instagram' },
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
							onClick: submitForReview,
							disabled: isPublishing,
						},
						isPublishing ? __( 'Submitting…', 'extrachill-studio' ) : __( 'Submit for Review', 'extrachill-studio' )
					),
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium button-secondary',
							onClick: publishInstagramPost,
							disabled: isPublishing || ! authStatus?.authenticated,
						},
						isPublishing ? __( 'Publishing…', 'extrachill-studio' ) : __( 'Publish Now', 'extrachill-studio' )
					),
					createElement( 'span', { className: 'ec-studio-composer__hint' }, __( 'Submit creates a draft for admin approval. Publish Now posts immediately.', 'extrachill-studio' ) )
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
		),
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Instagram comments', 'extrachill-studio' ) ),
			createElement( 'h3', null, __( 'Review and reply to post comments', 'extrachill-studio' ) ),
			createElement( 'p', null, __( 'This prepares Studio for comment management using the same backend primitives exposed through Data Machine Socials REST, CLI, and chat tools.', 'extrachill-studio' ) ),
			isLoadingMedia ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--info' }, __( 'Loading recent Instagram posts…', 'extrachill-studio' ) ) : null,
			commentsError ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--error' }, commentsError ) : null,
			! commentsError && commentsStatus ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--success' }, commentsStatus ) : null,
			mediaItems.length > 0
				? createElement(
					'div',
					{ className: 'ec-studio-composer' },
					createElement(
						'div',
						null,
						createElement( 'label', { htmlFor: 'ec-studio-instagram-media-selector' }, __( 'Select post', 'extrachill-studio' ) ),
						createElement(
							'select',
							{
								id: 'ec-studio-instagram-media-selector',
								value: selectedMediaId,
								onChange: ( event ) => setSelectedMediaId( event.target.value ),
							},
							...mediaItems.map( ( item ) => createElement(
								'option',
								{ key: item.id, value: item.id },
								item.caption ? item.caption.slice( 0, 60 ) : item.id
							) )
						)
					),
					selectedMedia
						? createElement( 'p', { className: 'ec-studio-composer__hint' }, sprintf( __( 'Selected post has %d tracked comment(s).', 'extrachill-studio' ), selectedMedia.comments_count || 0 ) )
						: null
				)
				: createElement( 'div', { className: 'ec-studio-preview' }, __( 'No recent Instagram posts available yet.', 'extrachill-studio' ) ),
			selectedMediaId
				? (
					isLoadingComments
						? createElement( 'p', { className: 'ec-studio-message ec-studio-message--info' }, __( 'Loading comments…', 'extrachill-studio' ) )
						: comments.length > 0
							? createElement(
								'ul',
								{ className: 'ec-studio-comment-list' },
								...comments.map( ( comment ) => createElement(
									'li',
									{ key: comment.id, className: 'ec-studio-comment-list__item' },
									createElement(
										'div',
										{ className: 'ec-studio-comment-list__meta' },
										createElement( 'strong', null, `@${ comment.username || 'unknown' }` ),
										comment.timestamp ? createElement( 'span', null, comment.timestamp ) : null
									),
									createElement( 'p', { className: 'ec-studio-comment-list__text' }, comment.text || '' ),
									createElement(
										'div',
										{ className: 'ec-studio-composer' },
										createElement( 'textarea', {
											rows: 3,
											value: replyDrafts[ comment.id ] || '',
											onChange: ( event ) => setReplyDraft( comment.id, event.target.value ),
											placeholder: __( 'Write a reply…', 'extrachill-studio' ),
										} ),
										createElement(
											'div',
											{ className: 'ec-studio-composer__actions' },
											createElement(
												'button',
												{
													type: 'button',
													className: 'button-1 button-medium',
													onClick: () => replyToComment( comment.id ),
													disabled: replyingCommentId === comment.id,
												},
												replyingCommentId === comment.id ? __( 'Replying…', 'extrachill-studio' ) : __( 'Reply', 'extrachill-studio' )
											)
										)
									)
								) )
							)
							: createElement( 'div', { className: 'ec-studio-preview' }, __( 'No comments found for the selected post.', 'extrachill-studio' ) )
				)
				: null
		)
	);
};

export default InstagramPane;
