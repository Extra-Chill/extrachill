import { __, sprintf } from '@wordpress/i18n';
import { createElement, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import type { ChangeEvent, ReactElement } from 'react';
import {
	ActionRow,
	FieldGroup,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';
import type {
	NetworkMediaItem,
	SocialJobPlatformResult,
} from '@extrachill/api-client';

import { studioClient } from '../../../app/client';
import MediaPicker from '../media-picker';
import { markLocalRequest } from '../../compose/cross-site-middleware';
import {
	browserComposerSchema,
	buildComposerRequest,
	normalizePublishOutcome,
	schemaDefaults,
	validateComposerInput,
} from './contract';
import type {
	ComposerPlatformConfig,
	ComposerSchemaProperty,
} from './contract';

const h = createElement as typeof import('react').createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const ActionRowView = ActionRow as unknown as ( props: any ) => ReactElement;
const FieldGroupView = FieldGroup as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as (
	props: any
) => ReactElement;

export interface PlatformPublishPaneProps {
	slug: string;
	label: string;
	username: string | null;
	config: ComposerPlatformConfig;
	draft: PlatformPublishDraft;
	onDraftChange: ( draft: PlatformPublishDraft ) => void;
}

export interface PlatformPublishDraft {
	caption: string;
	images: SelectedImage[];
	mediaKind: string;
	fields: Record< string, unknown >;
}

interface WpPost {
	id: number;
}

interface CrossPostResponse {
	success: boolean;
	job_id?: number;
}

export interface SelectedImage {
	url: string;
	sourceId: string;
	alt?: string;
	title?: string;
}

const fieldLabel = ( name: string ): string =>
	name
		.replace( /_/g, ' ' )
		.replace( /\b\w/g, ( character: string ) => character.toUpperCase() );

const cleanInput = (
	input: Record< string, unknown >
): Record< string, unknown > =>
	Object.fromEntries(
		Object.entries( input ).filter(
			( [ , value ] ) =>
				value !== '' && value !== undefined && value !== null
		)
	);

const PlatformPublishPane = ( {
	slug,
	label,
	username,
	config,
	draft,
	onDraftChange,
}: PlatformPublishPaneProps ): ReactElement => {
	const contract = config.composer;
	const [ isPublishing, setIsPublishing ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ result, setResult ] = useState< Record< string, unknown > | null >(
		null
	);
	const pollAbortRef = useRef< AbortController | null >( null );

	if ( ! contract ) {
		return h(
			InlineStatusView,
			{ tone: 'error' },
			__(
				'This publisher does not expose a composer contract.',
				'extrachill-studio'
			)
		);
	}

	const platformLabel = label || slug;
	const inputSchema = browserComposerSchema( contract.inputSchema );
	const mediaKind = contract.mediaKinds.includes( draft.mediaKind )
		? draft.mediaKind
		: contract.mediaKinds[ 0 ] || '';
	const fields = {
		...schemaDefaults( inputSchema ),
		...draft.fields,
	};
	const requirements = contract.mediaRequirements[ mediaKind ] || {};
	const imageRequired = requirements.required?.includes( 'images' ) || false;
	const imageAllowed =
		imageRequired ||
		requirements.requiredAnyOf?.includes( 'images' ) ||
		false;
	const videoAllowed =
		requirements.required?.includes( 'video_url' ) ||
		requirements.requiredAnyOf?.includes( 'video_url' ) ||
		false;
	const charLimit = config.charLimit || 0;
	const maxImages = config.maxImages || ( mediaKind === 'carousel' ? 10 : 1 );

	const updateDraft = ( next: Partial< PlatformPublishDraft > ): void => {
		onDraftChange( { ...draft, mediaKind, fields, ...next } );
		setError( '' );
	};

	const updateField = ( name: string, value: unknown ): void => {
		updateDraft( { fields: { ...fields, [ name ]: value } } );
	};

	const handleMediaSelect = ( url: string, item: NetworkMediaItem ): void => {
		if ( draft.images.length >= maxImages ) {
			setError(
				sprintf(
					/* translators: 1: platform name, 2: maximum image count. */
					__(
						'%1$s accepts at most %2$d images for this format.',
						'extrachill-studio'
					),
					platformLabel,
					maxImages
				)
			);
			return;
		}
		updateDraft( {
			images: [
				...draft.images,
				{
					url,
					sourceId: item.sourceId,
					alt: item.alt || undefined,
					title: item.title || undefined,
				},
			],
		} );
		setStatus(
			sprintf(
				/* translators: %s: media title or source ID. */
				__( '%s added to publish queue.', 'extrachill-studio' ),
				item.title || item.sourceId
			)
		);
	};

	const removeImageAt = ( index: number ): void => {
		updateDraft( {
			images: draft.images.filter(
				( _item, itemIndex ) => itemIndex !== index
			),
		} );
		setStatus(
			__( 'Image removed from publish queue.', 'extrachill-studio' )
		);
	};

	const moveImage = ( index: number, direction: -1 | 1 ): void => {
		const target = index + direction;
		if ( target < 0 || target >= draft.images.length ) {
			return;
		}
		const images = [ ...draft.images ];
		const [ moved ] = images.splice( index, 1 );
		images.splice( target, 0, moved );
		updateDraft( { images } );
	};

	const renderImageThumbnails = (): ReactElement | null => {
		if ( draft.images.length === 0 ) {
			return null;
		}
		return h(
			'ul',
			{
				className: 'ec-studio-image-thumbs',
				'aria-label': __( 'Selected images', 'extrachill-studio' ),
			},
			...draft.images.map( ( image, index ) =>
				h(
					'li',
					{
						key: `${ image.url }-${ index }`,
						className: 'ec-studio-image-thumbs__tile',
					},
					h( 'img', {
						className: 'ec-studio-image-thumbs__image',
						src: image.url,
						alt: image.alt || '',
						loading: 'lazy',
					} ),
					h(
						'button',
						{
							type: 'button',
							className: 'ec-studio-image-thumbs__remove',
							onClick: () => removeImageAt( index ),
							'aria-label': sprintf(
								/* translators: %s: image title, alt text, or URL. */
								__( 'Remove image: %s', 'extrachill-studio' ),
								image.title || image.alt || image.url
							),
						},
						'×'
					),
					draft.images.length > 1
						? h(
								'div',
								{
									className:
										'ec-studio-image-thumbs__reorder',
								},
								h(
									'button',
									{
										type: 'button',
										className:
											'ec-studio-image-thumbs__move',
										onClick: () => moveImage( index, -1 ),
										disabled: index === 0,
										'aria-label': __(
											'Move image left',
											'extrachill-studio'
										),
									},
									'‹'
								),
								h(
									'button',
									{
										type: 'button',
										className:
											'ec-studio-image-thumbs__move',
										onClick: () => moveImage( index, 1 ),
										disabled:
											index === draft.images.length - 1,
										'aria-label': __(
											'Move image right',
											'extrachill-studio'
										),
									},
									'›'
								)
						  )
						: null
				)
			)
		);
	};

	const renderField = (
		name: string,
		property: ComposerSchemaProperty
	): ReactElement => {
		const id = `ec-studio-${ slug }-${ name }`;
		const value = fields[ name ] ?? '';
		const required = inputSchema.required?.includes( name ) || false;
		const common = {
			id,
			value: String( value ),
			required,
			onChange: (
				event: ChangeEvent<
					HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
				>
			) => {
				const raw = event.target.value;
				if (
					property.type === 'integer' ||
					property.type === 'number'
				) {
					updateField( name, raw === '' ? '' : Number( raw ) );
				} else if ( property.type === 'array' ) {
					updateField(
						name,
						raw
							.split( ',' )
							.map( ( item ) => item.trim() )
							.filter( Boolean )
					);
				} else {
					updateField( name, raw );
				}
			},
		};

		let control: ReactElement;
		if ( property.type === 'boolean' ) {
			control = h( 'input', {
				id,
				type: 'checkbox',
				checked: Boolean( value ),
				onChange: ( event: ChangeEvent< HTMLInputElement > ) =>
					updateField( name, event.target.checked ),
			} );
		} else if ( property.enum ) {
			control = h(
				'select',
				common,
				...property.enum.map( ( option ) =>
					h(
						'option',
						{ key: option, value: option },
						option.replace( /_/g, ' ' )
					)
				)
			);
		} else if ( name === 'content' || name === 'description' ) {
			control = h( 'textarea', {
				...common,
				rows: 5,
				maxLength: property.maxLength,
			} );
		} else {
			let inputType = 'text';
			if ( property.type === 'integer' || property.type === 'number' ) {
				inputType = 'number';
			} else if ( property.format === 'uri' ) {
				inputType = 'url';
			}
			control = h( 'input', {
				...common,
				type: inputType,
				maxLength: property.maxLength,
			} );
		}

		return h(
			FieldGroupView,
			{
				key: name,
				label: `${ fieldLabel( name ) }${ required ? ' *' : '' }`,
				htmlFor: id,
				help: property.description,
			},
			control
		);
	};

	const genericInput = (): Record< string, unknown > =>
		cleanInput( {
			platforms: [ slug ],
			caption: draft.caption.trim(),
			media_kind: mediaKind,
			images: draft.images.map( ( { url, alt, title } ) => ( {
				url,
				alt,
				title,
			} ) ),
			...fields,
		} );

	const specializedInput = (): Record< string, unknown > =>
		cleanInput( fields );

	const validateInput = ( input: Record< string, unknown > ): string[] => {
		const errors: string[] = [];
		if ( contract.crossPostCompatible ) {
			if ( ! contract.mediaKinds.includes( mediaKind ) ) {
				errors.push(
					__(
						'Choose a supported media format.',
						'extrachill-studio'
					)
				);
			}
			if ( imageRequired && draft.images.length === 0 ) {
				errors.push(
					__( 'Add at least one image.', 'extrachill-studio' )
				);
			}
			if (
				requirements.requiredAnyOf &&
				! requirements.requiredAnyOf.some( ( name ) =>
					name === 'images'
						? draft.images.length > 0
						: Boolean( input[ name ] )
				)
			) {
				errors.push(
					sprintf(
						/* translators: %s: comma-separated field labels. */
						__( 'Add one of: %s.', 'extrachill-studio' ),
						requirements.requiredAnyOf
							.map( fieldLabel )
							.join( ', ' )
					)
				);
			}
		}
		return [ ...errors, ...validateComposerInput( inputSchema, input ) ];
	};

	const publishPost = async (): Promise< void > => {
		const input = contract.crossPostCompatible
			? genericInput()
			: specializedInput();
		const validationErrors = validateInput( input );
		if ( validationErrors.length > 0 ) {
			setError( validationErrors.join( ' ' ) );
			setStatus( '' );
			return;
		}

		pollAbortRef.current?.abort();
		setIsPublishing( true );
		setError( '' );
		setResult( null );
		setStatus(
			sprintf(
				/* translators: %s: platform name. */
				__( 'Publishing to %s…', 'extrachill-studio' ),
				platformLabel
			)
		);
		let abortController: AbortController | null = null;

		try {
			const response = await apiFetch<
				CrossPostResponse | Record< string, unknown >
			>( buildComposerRequest( contract, input ) );
			if ( contract.crossPostCompatible ) {
				const queued = response as CrossPostResponse;
				if ( ! queued.success || ! queued.job_id ) {
					throw new Error(
						__(
							'The publish could not be scheduled.',
							'extrachill-studio'
						)
					);
				}
				abortController = new AbortController();
				pollAbortRef.current = abortController;
				const job = await studioClient.socials.waitForCrossPostJob(
					queued.job_id,
					{
						signal: abortController.signal,
						onStatus: () =>
							setStatus(
								sprintf(
									/* translators: %s: platform name. */
									__(
										'Publishing to %s… (checking status)',
										'extrachill-studio'
									),
									platformLabel
								)
							),
					}
				);
				const platformResult = job.engine_data?.results?.find(
					( item ) => item.platform === slug
				) as SocialJobPlatformResult | undefined;
				if ( platformResult && ! platformResult.success ) {
					throw new Error(
						platformResult.error ||
							__(
								'The platform rejected this publish.',
								'extrachill-studio'
							)
					);
				}
				setResult(
					( platformResult as unknown as Record<
						string,
						unknown
					> ) || {
						success: true,
					}
				);
			} else {
				const abilityResult = response as Record< string, unknown >;
				if ( abilityResult.success === false ) {
					throw new Error(
						String(
							abilityResult.error ||
								__(
									'The platform rejected this publish.',
									'extrachill-studio'
								)
						)
					);
				}
				setResult( response as Record< string, unknown > );
			}
			setStatus(
				sprintf(
					/* translators: %s: platform name. */
					__( '%s publish completed.', 'extrachill-studio' ),
					platformLabel
				)
			);
			onDraftChange( {
				caption: '',
				images: [],
				mediaKind: '',
				fields: {},
			} );
		} catch ( publishError ) {
			if ( abortController?.signal.aborted ) {
				return;
			}
			setStatus( '' );
			setError(
				( publishError as Error )?.message ||
					sprintf(
						/* translators: %s: platform name. */
						__( '%s publish failed.', 'extrachill-studio' ),
						platformLabel
					)
			);
		} finally {
			setIsPublishing( false );
		}
	};

	const submitForReview = async (): Promise< void > => {
		const input = genericInput();
		const validationErrors = validateInput( input );
		if ( validationErrors.length > 0 ) {
			setError( validationErrors.join( ' ' ) );
			setStatus( '' );
			return;
		}

		setIsPublishing( true );
		setError( '' );
		setStatus( __( 'Submitting for review…', 'extrachill-studio' ) );
		try {
			const post = await apiFetch< WpPost >(
				markLocalRequest( {
					path: '/wp/v2/posts',
					method: 'POST',
					data: {
						title:
							draft.caption.trim().substring( 0, 80 ) +
							( draft.caption.trim().length > 80 ? '…' : '' ),
						content: draft.caption.trim(),
						status: 'pending',
						meta: {
							_studio_social_platforms: [ slug ],
							_studio_social_caption: draft.caption.trim(),
							_studio_social_images: draft.images.map(
								( { url, sourceId, alt, title } ) => ( {
									url,
									source_id: sourceId,
									alt,
									title,
								} )
							),
							_studio_social_media_kind: mediaKind,
						},
					},
				} )
			);
			setStatus(
				sprintf(
					/* translators: %d: Studio draft post ID. */
					__(
						'Draft #%d submitted for review. An admin will approve it before it goes live.',
						'extrachill-studio'
					),
					post.id
				)
			);
			onDraftChange( {
				caption: '',
				images: [],
				mediaKind: '',
				fields: {},
			} );
		} catch ( submitError ) {
			setStatus( '' );
			setError(
				( submitError as Error )?.message ||
					__( 'Failed to submit draft.', 'extrachill-studio' )
			);
		} finally {
			setIsPublishing( false );
		}
	};

	const excludedGenericFields = new Set( [
		'platforms',
		'caption',
		'media_kind',
		'images',
	] );
	const schemaFields = Object.entries( inputSchema.properties || {} ).filter(
		( [ name ] ) =>
			( ! contract.crossPostCompatible ||
				! excludedGenericFields.has( name ) ) &&
			( ! contract.crossPostCompatible ||
				! [ 'video_url', 'cover_url' ].includes( name ) ||
				videoAllowed )
	);
	const previewUrl =
		draft.images[ 0 ]?.url || String( fields.video_url || '' );
	const previewCaption = contract.crossPostCompatible
		? draft.caption
		: String( fields.content || fields.description || '' );
	const previewCaptionElement = previewCaption
		? h(
				'p',
				{ className: 'ec-studio-publish-preview__caption' },
				previewCaption
		  )
		: null;
	const previewMediaElement = previewUrl
		? h(
				'div',
				{ className: 'ec-studio-publish-preview__media' },
				draft.images[ 0 ]
					? h( 'img', {
							src: previewUrl,
							alt: draft.images[ 0 ].alt || '',
					  } )
					: h(
							'span',
							null,
							__( 'Video preview', 'extrachill-studio' )
					  )
		  )
		: h(
				'div',
				{ className: 'ec-studio-publish-preview__media is-empty' },
				__( 'Media preview', 'extrachill-studio' )
		  );
	const captionAbove = config.preview?.captionPosition === 'above';
	const previewAspectRatio = config.preview?.aspectRatio?.replace(
		':',
		' / '
	);
	const canSubmitForReview =
		contract.crossPostCompatible &&
		! requirements.required?.includes( 'video_url' ) &&
		! fields.video_url;
	const outcome = result ? normalizePublishOutcome( result ) : null;

	return h(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--platform' },
		h(
			PanelView,
			{ className: 'ec-studio-panel', compact: true },
			h( PanelHeader, {
				description: sprintf(
					/* translators: 1: platform name, 2: account username. */
					__( 'Publish to %1$s as @%2$s.', 'extrachill-studio' ),
					platformLabel,
					username || 'unknown'
				),
			} ),
			h(
				'div',
				{ className: 'ec-studio-composer' },
				contract.mediaKinds.length > 1
					? h(
							FieldGroupView,
							{
								label: __(
									'Media format',
									'extrachill-studio'
								),
								htmlFor: `ec-studio-${ slug }-media-kind`,
							},
							h(
								'select',
								{
									id: `ec-studio-${ slug }-media-kind`,
									value: mediaKind,
									onChange: (
										event: ChangeEvent< HTMLSelectElement >
									) =>
										updateDraft( {
											mediaKind: event.target.value,
											images: [],
											fields: {},
										} ),
								},
								...contract.mediaKinds.map( ( kind ) =>
									h(
										'option',
										{ key: kind, value: kind },
										fieldLabel( kind )
									)
								)
							)
					  )
					: null,
				imageAllowed
					? h( MediaPicker, {
							onSelect: handleMediaSelect,
							className: 'ec-studio-pane__media-picker',
					  } )
					: null,
				imageAllowed ? renderImageThumbnails() : null,
				contract.crossPostCompatible
					? h(
							FieldGroupView,
							{
								label: __( 'Caption *', 'extrachill-studio' ),
								htmlFor: `ec-studio-${ slug }-caption`,
								help:
									charLimit > 0
										? sprintf(
												/* translators: 1: current character count, 2: character limit. */
												__(
													'%1$d / %2$d characters',
													'extrachill-studio'
												),
												draft.caption.length,
												charLimit
										  )
										: null,
							},
							h( 'textarea', {
								id: `ec-studio-${ slug }-caption`,
								rows: 6,
								value: draft.caption,
								maxLength: charLimit || undefined,
								onChange: (
									event: ChangeEvent< HTMLTextAreaElement >
								) =>
									updateDraft( {
										caption: event.target.value,
									} ),
							} )
					  )
					: null,
				...schemaFields.map( ( [ name, property ] ) =>
					renderField( name, property )
				),
				videoAllowed && ! inputSchema.properties?.video_url
					? renderField( 'video_url', {
							type: 'string',
							format: 'uri',
							description: __(
								'Public HTTPS video URL.',
								'extrachill-studio'
							),
					  } )
					: null,
				h(
					'section',
					{
						className: `ec-studio-publish-preview ec-studio-publish-preview--${
							config.preview?.previewSurface || 'feed'
						} ec-studio-publish-preview--caption-${
							config.preview?.captionPosition || 'above'
						}`,
					},
					h( 'h4', null, __( 'Preview', 'extrachill-studio' ) ),
					captionAbove ? previewCaptionElement : null,
					h(
						'div',
						{ style: { aspectRatio: previewAspectRatio } },
						previewMediaElement
					),
					captionAbove ? null : previewCaptionElement
				),
				error
					? h(
							InlineStatusView,
							{ tone: 'error', className: 'ec-studio-message' },
							error
					  )
					: null,
				! error && status
					? h(
							InlineStatusView,
							{ tone: 'success', className: 'ec-studio-message' },
							status
					  )
					: null,
				h(
					ActionRowView,
					{ className: 'ec-studio-composer__actions' },
					canSubmitForReview
						? h(
								'button',
								{
									type: 'button',
									className: 'button-1 button-medium',
									onClick: submitForReview,
									disabled: isPublishing,
								},
								isPublishing
									? __( 'Submitting…', 'extrachill-studio' )
									: __(
											'Submit for Review',
											'extrachill-studio'
									  )
						  )
						: null,
					h(
						'button',
						{
							type: 'button',
							className:
								'button-1 button-medium button-secondary',
							onClick: publishPost,
							disabled: isPublishing,
						},
						isPublishing
							? __( 'Publishing…', 'extrachill-studio' )
							: __( 'Publish Now', 'extrachill-studio' )
					),
					h(
						'span',
						{ className: 'ec-studio-composer__hint' },
						contract.crossPostCompatible
							? __(
									'Submit creates a draft for admin approval. Publish Now posts immediately.',
									'extrachill-studio'
							  )
							: __(
									'This specialized publisher executes directly through its declared WordPress Ability.',
									'extrachill-studio'
							  )
					)
				)
			)
		),
		outcome
			? h(
					PanelView,
					{ className: 'ec-studio-panel', compact: true },
					h(
						'div',
						{ className: 'ec-studio-publish-result' },
						h(
							'h4',
							null,
							__( 'Latest publish result', 'extrachill-studio' )
						),
						h(
							'dl',
							{ className: 'ec-studio-publish-result__details' },
							outcome.success !== undefined
								? h(
										'div',
										null,
										h(
											'dt',
											null,
											__( 'Outcome', 'extrachill-studio' )
										),
										h(
											'dd',
											null,
											outcome.success
												? __(
														'Published',
														'extrachill-studio'
												  )
												: __(
														'Failed',
														'extrachill-studio'
												  )
										)
								  )
								: null,
							outcome.status
								? h(
										'div',
										null,
										h(
											'dt',
											null,
											__( 'Status', 'extrachill-studio' )
										),
										h( 'dd', null, outcome.status )
								  )
								: null,
							outcome.id
								? h(
										'div',
										null,
										h(
											'dt',
											null,
											__( 'Post ID', 'extrachill-studio' )
										),
										h( 'dd', null, outcome.id )
								  )
								: null,
							outcome.privacy
								? h(
										'div',
										null,
										h(
											'dt',
											null,
											__( 'Privacy', 'extrachill-studio' )
										),
										h( 'dd', null, outcome.privacy )
								  )
								: null,
							outcome.url
								? h(
										'div',
										null,
										h(
											'dt',
											null,
											__( 'Link', 'extrachill-studio' )
										),
										h(
											'dd',
											null,
											h(
												'a',
												{
													href: outcome.url,
													target: '_blank',
													rel: 'noreferrer',
												},
												__(
													'View published post',
													'extrachill-studio'
												)
											)
										)
								  )
								: null
						)
					)
			  )
			: null
	);
};

export default PlatformPublishPane;
