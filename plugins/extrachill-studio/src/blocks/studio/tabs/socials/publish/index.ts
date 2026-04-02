import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useState } from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';
import { ActionRow, FieldGroup, InlineStatus, MediaField, Panel, PanelHeader } from '@extrachill/components';

import apiFetch from '@wordpress/api-fetch';
import type { SocialPublishResponse, SocialPublishResult } from '@extrachill/api-client';
import { studioClient, uploadStudioFile } from '../../../app/client';
import type { SocialPlatformConfig } from '../../../types/externals';

const h = createElement as typeof import( 'react' ).createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const ActionRowView = ActionRow as unknown as ( props: any ) => ReactElement;
const FieldGroupView = FieldGroup as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as ( props: any ) => ReactElement;
const MediaFieldView = MediaField as unknown as ( props: any ) => ReactElement;

export interface PlatformPublishPaneProps {
	slug: string;
	label: string;
	username: string | null;
	config: SocialPlatformConfig;
}

interface WpPost {
	id: number;
}

/**
 * Generic social platform publishing pane.
 *
 * Works for any authenticated platform exposed by DM Socials.
 * Provides caption textarea, image management, publish/submit actions,
 * and comment management via the generic comments API.
 */
const PlatformPublishPane = ( { slug, label, username, config }: PlatformPublishPaneProps ): ReactElement => {
	const [ caption, setCaption ] = useState( '' );
	const [ imageUrlInput, setImageUrlInput ] = useState( '' );
	const [ imageUrls, setImageUrls ] = useState< string[] >( [] );
	const [ selectedFile, setSelectedFile ] = useState< File | null >( null );
	const [ selectedFilePreviewUrl, setSelectedFilePreviewUrl ] = useState( '' );
	const [ isUploading, setIsUploading ] = useState( false );
	const [ isPublishing, setIsPublishing ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ publishResult, setPublishResult ] = useState< SocialPublishResponse | null >( null );

	const platformLabel = label || slug;
	const charLimit = config.charLimit || 0;
	const supportsImages = ( config.maxImages || 0 ) > 0 || config.supportsCarousel;

	useEffect( () => {
		if ( ! selectedFile ) {
			setSelectedFilePreviewUrl( '' );
			return;
		}

		const previewUrl = URL.createObjectURL( selectedFile );
		setSelectedFilePreviewUrl( previewUrl );

		return () => {
			URL.revokeObjectURL( previewUrl );
		};
	}, [ selectedFile ] );

	const addImageUrl = (): void => {
		const nextUrl = imageUrlInput.trim();

		if ( ! nextUrl ) {
			setError( __( 'Enter an image URL first.', 'extrachill-studio' ) );
			return;
		}

		try {
			new URL( nextUrl );
		} catch {
			setError( __( 'Please enter a valid image URL.', 'extrachill-studio' ) );
			return;
		}

		setImageUrls( ( current ) => [ ...current, nextUrl ] );
		setImageUrlInput( '' );
		setError( '' );
		setStatus( __( 'Image URL added to publish queue.', 'extrachill-studio' ) );
	};

	const handleUpload = async (): Promise< void > => {
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
			setSelectedFilePreviewUrl( '' );
			setStatus( __( 'Image uploaded and added to publish queue.', 'extrachill-studio' ) );
		} catch ( uploadError ) {
			setError( ( uploadError as Error )?.message || __( 'Image upload failed.', 'extrachill-studio' ) );
			setStatus( '' );
		} finally {
			setIsUploading( false );
		}
	};

	const removeImageUrl = ( index: number ): void => {
		setImageUrls( ( current ) => current.filter( ( _item, itemIndex ) => itemIndex !== index ) );
		setStatus( __( 'Image removed from publish queue.', 'extrachill-studio' ) );
		setError( '' );
	};

	const publishPost = async (): Promise< void > => {
		if ( ! caption.trim() ) {
			setError( __( 'Add a caption before publishing.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		if ( supportsImages && imageUrls.length === 0 ) {
			setError( __( 'Add at least one image before publishing.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		setIsPublishing( true );
		setError( '' );
		setPublishResult( null );
		setStatus( sprintf( __( 'Publishing to %s…', 'extrachill-studio' ), platformLabel ) );

		try {
			const response = await studioClient.socials.crossPost( {
				platforms: [ slug ],
				images: imageUrls.map( ( url ) => ( { url } ) ),
				caption: caption.trim(),
			} );

			setPublishResult( response );

			if ( response?.success ) {
				setStatus( sprintf( __( '%s publish completed.', 'extrachill-studio' ), platformLabel ) );
				setCaption( '' );
				setImageUrls( [] );
			} else {
				setStatus( '' );
				setError( response?.errors?.join( ' ' ) || sprintf( __( '%s publish failed.', 'extrachill-studio' ), platformLabel ) );
			}
		} catch ( publishError ) {
			setStatus( '' );
			setError( ( publishError as Error )?.message || sprintf( __( '%s publish failed.', 'extrachill-studio' ), platformLabel ) );
		} finally {
			setIsPublishing( false );
		}
	};

	const submitForReview = async (): Promise< void > => {
		if ( ! caption.trim() ) {
			setError( __( 'Add a caption before submitting.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		if ( supportsImages && imageUrls.length === 0 ) {
			setError( __( 'Add at least one image before submitting.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		setIsPublishing( true );
		setError( '' );
		setStatus( __( 'Submitting for review…', 'extrachill-studio' ) );

		try {
			const post = await apiFetch< WpPost >( {
				path: '/wp/v2/posts',
				method: 'POST',
				data: {
					title: caption.trim().substring( 0, 80 ) + ( caption.trim().length > 80 ? '…' : '' ),
					content: caption.trim(),
					status: 'pending',
					meta: {
						_studio_social_platforms: [ slug ],
						_studio_social_caption: caption.trim(),
						_studio_social_images: imageUrls.map( ( url ) => ( { url } ) ),
						_studio_social_media_kind: imageUrls.length > 1 ? 'carousel' : 'image',
					},
				},
			} );

			setStatus(
				sprintf( __( 'Draft #%d submitted for review. An admin will approve it before it goes live.', 'extrachill-studio' ), post.id )
			);
			setCaption( '' );
			setImageUrls( [] );
		} catch ( submitError ) {
			setStatus( '' );
			setError( ( submitError as Error )?.message || __( 'Failed to submit draft.', 'extrachill-studio' ) );
		} finally {
			setIsPublishing( false );
		}
	};

	const platformResult: SocialPublishResult | null = Array.isArray( publishResult?.results )
		? publishResult!.results.find( ( result ) => result.platform === slug ) || null
		: null;

	return h(
		'div',
		{ className: `ec-studio-pane ec-studio-pane--platform ec-studio-pane--${ slug }` },
		h(
			PanelView,
			{ className: 'ec-studio-panel', compact: true },
			h( PanelHeader, {
				description: sprintf(
					__( 'Publish to %s as @%s. Write a caption, add images, and publish directly or submit for admin review.', 'extrachill-studio' ),
					platformLabel,
					username || 'unknown'
				),
			} ),
			h(
				'div',
				{ className: 'ec-studio-composer' },
				h(
					FieldGroupView,
					{
						label: __( 'Caption', 'extrachill-studio' ),
						htmlFor: `ec-studio-${ slug }-caption`,
						help: charLimit > 0
							? sprintf( __( '%d / %d characters', 'extrachill-studio' ), caption.length, charLimit )
							: null,
					},
					createElement( 'textarea', {
						id: `ec-studio-${ slug }-caption`,
						rows: 6,
						value: caption,
						onChange: ( event: ChangeEvent< HTMLTextAreaElement > ) => setCaption( event.target.value ),
						placeholder: sprintf( __( 'Write your %s caption here…', 'extrachill-studio' ), platformLabel ),
						maxLength: charLimit > 0 ? charLimit : undefined,
					} )
				),
				supportsImages
					? h(
						FieldGroupView,
						{ label: __( 'Image URL', 'extrachill-studio' ), htmlFor: `ec-studio-${ slug }-image-url` },
						createElement( 'input', {
							id: `ec-studio-${ slug }-image-url`,
							type: 'url',
							value: imageUrlInput,
							onChange: ( event: ChangeEvent< HTMLInputElement > ) => setImageUrlInput( event.target.value ),
							placeholder: 'https://example.com/image.jpg',
							autoComplete: 'url',
						} )
					)
					: null,
				supportsImages
					? h(
						ActionRowView,
						{ className: 'ec-studio-composer__actions' },
						createElement( 'button', { type: 'button', className: 'button-1 button-medium', onClick: addImageUrl }, __( 'Add Image URL', 'extrachill-studio' ) ),
						createElement( 'span', { className: 'ec-studio-composer__hint' }, __( 'Use a public image URL or upload a file below.', 'extrachill-studio' ) )
					)
					: null,
				supportsImages
					? h( MediaFieldView, {
						label: __( 'Upload image', 'extrachill-studio' ),
						htmlFor: `ec-studio-${ slug }-upload`,
						previewUrl: selectedFilePreviewUrl || null,
						previewAlt: selectedFile?.name || __( 'Selected upload preview', 'extrachill-studio' ),
						empty: __( 'No local image selected yet.', 'extrachill-studio' ),
						actions: h(
							ActionRowView,
							null,
							createElement( 'input', {
								id: `ec-studio-${ slug }-upload`,
								type: 'file',
								accept: 'image/*',
								onChange: ( event: ChangeEvent< HTMLInputElement > ) => {
									setSelectedFile( event.target.files?.[ 0 ] || null );
									setError( '' );
									setStatus( '' );
								},
							} ),
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
					} )
					: null,
				error ? h( InlineStatusView, { tone: 'error', className: 'ec-studio-message' }, error ) : null,
				! error && status ? h( InlineStatusView, { tone: 'success', className: 'ec-studio-message' }, status ) : null,
				h(
					ActionRowView,
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
							onClick: publishPost,
							disabled: isPublishing,
						},
						isPublishing ? __( 'Publishing…', 'extrachill-studio' ) : __( 'Publish Now', 'extrachill-studio' )
					),
					createElement( 'span', { className: 'ec-studio-composer__hint' }, __( 'Submit creates a draft for admin approval. Publish Now posts immediately.', 'extrachill-studio' ) )
				)
			)
		),
		supportsImages && imageUrls.length > 0
			? h(
				PanelView,
				{ className: 'ec-studio-panel', compact: true },
				createElement(
					'ul',
					{ className: 'ec-studio-image-list' },
					...imageUrls.map( ( url, index ) => createElement(
						'li',
						{ key: `${ url }-${ index }`, className: 'ec-studio-image-list__item' },
						createElement( 'span', { className: 'ec-studio-image-list__url' }, url ),
						createElement( 'button', { type: 'button', className: 'ec-studio-image-list__remove', onClick: () => removeImageUrl( index ) }, __( 'Remove', 'extrachill-studio' ) )
					) )
				),
				platformResult
					? h(
						'div',
						{ className: 'ec-studio-publish-result' },
						createElement( 'h4', null, __( 'Latest publish result', 'extrachill-studio' ) ),
						platformResult.permalink
							? createElement( 'p', null, createElement( 'a', { href: platformResult.permalink, target: '_blank', rel: 'noreferrer' }, sprintf( __( 'View %s post', 'extrachill-studio' ), platformLabel ) ) )
							: null,
						platformResult.media_id ? createElement( 'p', null, sprintf( __( 'Media ID: %s', 'extrachill-studio' ), platformResult.media_id ) ) : null
					)
					: null
			)
			: null
	);
};

export default PlatformPublishPane;
