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

interface SelectedImage {
	url: string;
	alt?: string;
	title?: string;
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
	const [ images, setImages ] = useState< SelectedImage[] >( [] );
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

		setImages( ( current ) => [ ...current, { url: nextUrl } ] );
		setImageUrlInput( '' );
		setError( '' );
		setStatus( __( 'External image URL added to publish queue.', 'extrachill-studio' ) );
	};

	const handleMediaSelect = ( url: string, item: NetworkMediaItem ): void => {
		setImages( ( current ) => [
			...current,
			{
				url,
				alt: item.alt || undefined,
				title: item.title || undefined,
			},
		] );
		setError( '' );
		setStatus(
			sprintf(
				/* translators: %s: media item title or filename */
				__( '%s added to publish queue.', 'extrachill-studio' ),
				item.title || item.sourceId
			)
		);
	};

	const removeImageAt = ( index: number ): void => {
		setImages( ( current ) => current.filter( ( _item, itemIndex ) => itemIndex !== index ) );
		setStatus( __( 'Image removed from publish queue.', 'extrachill-studio' ) );
		setError( '' );
	};

	const moveImage = ( index: number, direction: -1 | 1 ): void => {
		setImages( ( current ) => {
			const target = index + direction;
			if ( target < 0 || target >= current.length ) {
				return current;
			}
			const next = [ ...current ];
			const [ moved ] = next.splice( index, 1 );
			next.splice( target, 0, moved );
			return next;
		} );
	};

	/**
	 * Render the selected-images thumbnail row.
	 *
	 * Horizontal flex row of ~80px tiles. Each tile shows the image, a
	 * remove (×) button overlay, and (for carousel-capable platforms) left/right
	 * arrow buttons to reorder the image within the array. Returns null when
	 * the queue is empty so the row collapses entirely.
	 */
	const renderImageThumbnails = (): ReactElement | null => {
		if ( images.length === 0 ) {
			return null;
		}

		const supportsReordering = !! config.supportsCarousel && images.length > 1;
		const lastIndex = images.length - 1;

		return createElement(
			'ul',
			{ className: 'ec-studio-image-thumbs', 'aria-label': __( 'Selected images', 'extrachill-studio' ) },
			...images.map( ( image, index ) => {
				const label = image.title || image.alt || image.url;
				return createElement(
					'li',
					{
						key: `${ image.url }-${ index }`,
						className: 'ec-studio-image-thumbs__tile',
					},
					createElement( 'img', {
						className: 'ec-studio-image-thumbs__image',
						src: image.url,
						alt: image.alt || '',
						title: image.title || image.alt || image.url,
						loading: 'lazy',
					} ),
					createElement(
						'button',
						{
							type: 'button',
							className: 'ec-studio-image-thumbs__remove',
							onClick: () => removeImageAt( index ),
							'aria-label': sprintf(
								/* translators: %s: image title or filename */
								__( 'Remove image: %s', 'extrachill-studio' ),
								label
							),
							title: __( 'Remove image', 'extrachill-studio' ),
						},
						'×'
					),
					supportsReordering
						? createElement(
							'div',
							{ className: 'ec-studio-image-thumbs__reorder' },
							createElement(
								'button',
								{
									type: 'button',
									className: 'ec-studio-image-thumbs__move ec-studio-image-thumbs__move--left',
									onClick: () => moveImage( index, -1 ),
									disabled: index === 0,
									'aria-label': sprintf(
										/* translators: %s: image title or filename */
										__( 'Move image left: %s', 'extrachill-studio' ),
										label
									),
									title: __( 'Move left', 'extrachill-studio' ),
								},
								'‹'
							),
							createElement(
								'button',
								{
									type: 'button',
									className: 'ec-studio-image-thumbs__move ec-studio-image-thumbs__move--right',
									onClick: () => moveImage( index, 1 ),
									disabled: index === lastIndex,
									'aria-label': sprintf(
										/* translators: %s: image title or filename */
										__( 'Move image right: %s', 'extrachill-studio' ),
										label
									),
									title: __( 'Move right', 'extrachill-studio' ),
								},
								'›'
							)
						)
						: null
				);
			} )
		);
	};

	const publishPost = async (): Promise< void > => {
		if ( ! caption.trim() ) {
			setError( __( 'Add a caption before publishing.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		if ( supportsImages && images.length === 0 ) {
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
				images: images.map( ( { url, alt, title } ) => ( { url, alt, title } ) ),
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
			setImages( [] );
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

		if ( supportsImages && images.length === 0 ) {
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
						_studio_social_images: images.map( ( { url, alt, title } ) => ( { url, alt, title } ) ),
						_studio_social_media_kind: images.length > 1 ? 'carousel' : 'image',
					},
				},
			} );

			setStatus(
				sprintf( __( 'Draft #%d submitted for review. An admin will approve it before it goes live.', 'extrachill-studio' ), post.id )
			);
			setCaption( '' );
			setImages( [] );
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
				// 1. Media picker — primary affordance for adding images.
				supportsImages
					? h( MediaPicker, {
						onSelect: handleMediaSelect,
						className: 'ec-studio-pane__media-picker',
					} )
					: null,
				// 2. Selected images thumbnail row (renders nothing when queue is empty).
				supportsImages ? renderImageThumbnails() : null,
				// 3. External URL fallback — paste + Add button.
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
				// 4. Caption textarea.
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
				// 5. Status / error inline messages.
				error ? h( InlineStatusView, { tone: 'error', className: 'ec-studio-message' }, error ) : null,
				! error && status ? h( InlineStatusView, { tone: 'success', className: 'ec-studio-message' }, status ) : null,
				// 6. Action row — Submit for Review / Publish Now.
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
