import { __, sprintf } from '@wordpress/i18n';
import { createElement, useState, useRef } from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';
import { ActionRow, FieldGroup, InlineStatus, Panel, PanelHeader } from '@extrachill/components';

import apiFetch from '@wordpress/api-fetch';
import type { NetworkMediaItem, SocialJobPlatformResult, SocialPlatformConfig } from '@extrachill/api-client';
import { studioClient } from '../../../app/client';
import MediaPicker from '../media-picker';

const h = createElement as typeof import( 'react' ).createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const ActionRowView = ActionRow as unknown as ( props: any ) => ReactElement;
const FieldGroupView = FieldGroup as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as ( props: any ) => ReactElement;

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
	const [ isPublishing, setIsPublishing ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ jobResult, setJobResult ] = useState< SocialJobPlatformResult | null >( null );

	/** Ref to allow cancellation of in-flight polling when a new publish starts. */
	const pollAbortRef = useRef< AbortController | null >( null );

	const platformLabel = label || slug;
	const charLimit = config.charLimit || 0;
	const supportsImages = ( config.maxImages || 0 ) > 0 || config.supportsCarousel;

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
		setStatus( __( 'External image URL added to publish queue.', 'extrachill-studio' ) );
	};

	const handleMediaSelect = ( url: string, item: NetworkMediaItem ): void => {
		setImageUrls( ( current ) => [ ...current, url ] );
		setError( '' );
		setStatus(
			sprintf(
				/* translators: %s: media item title or filename */
				__( '%s added to publish queue.', 'extrachill-studio' ),
				item.title || item.sourceId
			)
		);
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

		// Cancel any in-flight poll from a previous attempt.
		pollAbortRef.current?.abort();

		setIsPublishing( true );
		setError( '' );
		setJobResult( null );
		setStatus( sprintf( __( 'Scheduling %s publish…', 'extrachill-studio' ), platformLabel ) );

		let abortController: AbortController | null = null;

		try {
			const response = await studioClient.socials.crossPost( {
				platforms: [ slug ],
				images: imageUrls.map( ( url ) => ( { url } ) ),
				caption: caption.trim(),
			} );

			if ( ! response?.success || ! response?.job_id ) {
				setStatus( '' );
				setError(
					sprintf( __( '%s publish could not be scheduled.', 'extrachill-studio' ), platformLabel )
				);
				setIsPublishing( false );
				return;
			}

			// Job queued — start polling.
			setStatus(
				sprintf(
					/* translators: %s: platform label */
					__( 'Publishing to %s… (queued)', 'extrachill-studio' ),
					platformLabel
				)
			);

			abortController = new AbortController();
			pollAbortRef.current = abortController;

			const job = await studioClient.socials.waitForCrossPostJob( response.job_id, {
				signal: abortController.signal,
				onStatus: () => {
					setStatus(
						sprintf(
							/* translators: %s: platform label */
							__( 'Publishing to %s… (checking status)', 'extrachill-studio' ),
							platformLabel
						)
					);
				},
			} );
			const platformResult = job.engine_data?.results?.find( ( result ) => result.platform === slug ) ?? null;

			if ( platformResult && ! platformResult.success ) {
				throw new Error(
					platformResult.error || sprintf( __( '%s publish failed on the platform.', 'extrachill-studio' ), platformLabel )
				);
			}

			// Success — clear form inputs.
			setJobResult( platformResult );
			setStatus( sprintf( __( '%s publish completed.', 'extrachill-studio' ), platformLabel ) );
			setCaption( '' );
			setImageUrls( [] );
		} catch ( publishError ) {
			if ( abortController?.signal.aborted ) {
				// Silently swallow — a new publish was started.
				return;
			}
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
					? h( MediaPicker, {
						onSelect: handleMediaSelect,
						className: 'ec-studio-pane__media-picker',
					} )
					: null,
				supportsImages
					? h(
						FieldGroupView,
						{
							label: __( 'Or paste an external URL', 'extrachill-studio' ),
							htmlFor: `ec-studio-${ slug }-image-url`,
							help: __( 'Public image URLs (e.g. Dropbox, Drive) — for files not yet in the media library, prefer the Upload tile above.', 'extrachill-studio' ),
						},
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
						createElement(
							'button',
							{
								type: 'button',
								className: 'button-1 button-medium',
								onClick: addImageUrl,
								disabled: ! imageUrlInput.trim(),
							},
							__( 'Add External URL', 'extrachill-studio' )
						)
					)
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
				)
			)
			: null,
		jobResult
			? h(
				PanelView,
				{ className: 'ec-studio-panel', compact: true },
				h(
					'div',
					{ className: 'ec-studio-publish-result' },
					createElement( 'h4', null, __( 'Latest publish result', 'extrachill-studio' ) ),
					jobResult.platform_url
						? createElement( 'p', null, createElement( 'a', { href: jobResult.platform_url, target: '_blank', rel: 'noreferrer' }, sprintf( __( 'View %s post', 'extrachill-studio' ), platformLabel ) ) )
						: null,
					jobResult.platform_post_id
						? createElement( 'p', null, sprintf( __( 'Media ID: %s', 'extrachill-studio' ), jobResult.platform_post_id ) )
						: null
				)
			)
			: null
	);
};

export default PlatformPublishPane;
