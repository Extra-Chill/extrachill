/**
 * Compose review-readiness fields — featured image + editorial taxonomies.
 *
 * The Compose pane lets a writer draft a post that is BORN ON MAIN (blog 1),
 * but until Extra-Chill/extrachill-studio#108 it could reach editorial review
 * with only a title + body — no featured image, no category, no artist/venue/
 * location — leaving an editor to dress the post by hand with context they
 * don't have.
 *
 * This module adds the pre-submit gate: a small set of controls that read and
 * write MAIN's featured image and editorial taxonomies through the born-on-main
 * compose proxy (`/extrachill/v1/studio/compose/*`). Every call is made with
 * the `composeApiFetch` wrapper the pane injects so the request carries the
 * compose marker and the server forwards it to main — the featured image and
 * terms therefore land on the MAIN post, never on the Studio subsite (blog 12).
 *
 * Deliberately NO free-tag (`post_tag`) control: Extra Chill does not use
 * free-form tags. The taxonomies surfaced are category (required), artist,
 * venue, and location (prompted, optional).
 */

import { __, sprintf } from '@wordpress/i18n';
import {
	createElement,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';
import type { APIFetchOptions } from '@wordpress/api-fetch';

/** apiFetch wrapper injected by the Compose pane (tags requests for main). */
export type ComposeApiFetch = < T = unknown >(
	options: APIFetchOptions< true >
) => Promise< T >;

const h = createElement;

/** A term as returned by main's `/wp/v2/<rest_base>` REST route. */
export interface WpTerm {
	id: number;
	name: string;
	parent?: number;
}

/** An attachment as returned by main's `/wp/v2/media/<id>` route. */
interface WpMedia {
	id: number;
	source_url?: string;
	alt_text?: string;
	media_details?: {
		sizes?: Record< string, { source_url?: string } >;
	};
}

/**
 * Studio compose taxonomy slugs, mapped to the post-write REST field the main
 * `/wp/v2/posts` controller expects (each taxonomy's rest_base). The category
 * field is `categories`; the platform taxonomies keep their own slug.
 */
export const COMPOSE_TAXONOMIES = [
	{
		slug: 'category',
		field: 'categories',
		label: __( 'Category', 'extrachill-studio' ),
		required: true,
	},
	{
		slug: 'artist',
		field: 'artist',
		label: __( 'Artist', 'extrachill-studio' ),
		required: false,
	},
	{
		slug: 'venue',
		field: 'venue',
		label: __( 'Venue', 'extrachill-studio' ),
		required: false,
	},
	{
		slug: 'location',
		field: 'location',
		label: __( 'Location', 'extrachill-studio' ),
		required: false,
	},
] as const;

/** Proxy route prefix (forwards to main). Mirrors the PHP compose proxy. */
const PROXY_TERMS = '/extrachill/v1/studio/compose/terms';

/**
 * Pull the best available preview URL from a main-site attachment.
 *
 * Prefers the `medium` size, then `thumbnail`, then the full source URL, so the
 * featured-image preview is a reasonable size without a second request.
 * @param media
 */
function pickMediaPreview( media: WpMedia | null ): string {
	if ( ! media ) {
		return '';
	}
	const sizes = media.media_details?.sizes;
	return (
		sizes?.medium?.source_url ||
		sizes?.thumbnail?.source_url ||
		media.source_url ||
		''
	);
}

/**
 * Featured-image control for the Compose pane.
 *
 * Uploads a chosen file to MAIN's media library via the compose proxy (the same
 * `/wp/v2/media` route the inline editor uploads through, rewritten to main),
 * then reports the resulting attachment id up so it is written to the main post
 * as `featured_media`. Hydrates a preview for an already-set featured image.
 * @param props
 * @param props.composeApiFetch
 * @param props.mediaId
 * @param props.onChange
 * @param props.disabled
 */
export function FeaturedImage( props: {
	composeApiFetch: ComposeApiFetch;
	mediaId: number;
	onChange: ( id: number ) => void;
	disabled?: boolean;
} ): ReactElement {
	const { composeApiFetch, mediaId, onChange, disabled } = props;
	const [ previewUrl, setPreviewUrl ] = useState( '' );
	const [ isUploading, setIsUploading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const fileInputRef = useRef< HTMLInputElement >( null );
	// Guards a hydrate against a stale response when mediaId changes rapidly
	// (e.g. draft switch) before the fetch resolves.
	const hydrateSeqRef = useRef( 0 );

	// Hydrate a preview when a featured image id is present (draft load / set).
	useEffect( () => {
		if ( ! mediaId ) {
			setPreviewUrl( '' );
			return;
		}
		const seq = ++hydrateSeqRef.current;
		( async (): Promise< void > => {
			try {
				const media = await composeApiFetch< WpMedia >( {
					path: `/wp/v2/media/${ mediaId }`,
				} );
				if ( seq === hydrateSeqRef.current ) {
					setPreviewUrl( pickMediaPreview( media ) );
				}
			} catch {
				if ( seq === hydrateSeqRef.current ) {
					setPreviewUrl( '' );
				}
			}
		} )();
	}, [ mediaId, composeApiFetch ] );

	const onFileChange = useCallback(
		async ( e: ChangeEvent< HTMLInputElement > ): Promise< void > => {
			const file = e.target.files?.[ 0 ];
			// Reset the input so re-selecting the same file re-triggers change.
			if ( fileInputRef.current ) {
				fileInputRef.current.value = '';
			}
			if ( ! file ) {
				return;
			}

			setIsUploading( true );
			setError( '' );
			try {
				const form = new window.FormData();
				form.append( 'file', file );
				const media = await composeApiFetch< WpMedia >( {
					path: '/wp/v2/media',
					method: 'POST',
					body: form,
				} );
				if ( media?.id ) {
					setPreviewUrl( pickMediaPreview( media ) );
					onChange( media.id );
				}
			} catch ( uploadError ) {
				setError(
					( uploadError as Error )?.message ||
						__(
							'Featured image upload failed.',
							'extrachill-studio'
						)
				);
			} finally {
				setIsUploading( false );
			}
		},
		[ composeApiFetch, onChange ]
	);

	const inputId = 'ec-studio-compose-featured-input';

	let uploadLabel: string = __( 'Upload image', 'extrachill-studio' );
	if ( isUploading ) {
		uploadLabel = __( 'Uploading…', 'extrachill-studio' );
	} else if ( mediaId ) {
		uploadLabel = __( 'Replace image', 'extrachill-studio' );
	}

	return h(
		'div',
		{ className: 'ec-studio-compose-review__field' },
		h(
			'div',
			{ className: 'ec-studio-compose-review__label' },
			__( 'Featured image', 'extrachill-studio' ),
			h(
				'span',
				{ className: 'ec-studio-compose-review__required' },
				__( '(required)', 'extrachill-studio' )
			)
		),
		previewUrl
			? h( 'img', {
					className: 'ec-studio-compose-featured__preview',
					src: previewUrl,
					alt: __( 'Featured image preview', 'extrachill-studio' ),
			  } )
			: h(
					'div',
					{ className: 'ec-studio-compose-featured__empty' },
					__( 'No featured image yet.', 'extrachill-studio' )
			  ),
		h(
			'div',
			{ className: 'ec-studio-compose-featured__actions' },
			h( 'input', {
				id: inputId,
				ref: fileInputRef,
				type: 'file',
				accept: 'image/jpeg,image/png,image/gif,image/webp',
				className: 'ec-studio-compose-featured__file',
				onChange: onFileChange,
				disabled: disabled || isUploading,
			} ),
			h(
				'label',
				{
					htmlFor: inputId,
					className:
						'button-1 button-small ec-studio-compose-featured__button',
				},
				uploadLabel
			),
			mediaId
				? h(
						'button',
						{
							type: 'button',
							className: 'button-1 button-small button-secondary',
							onClick: () => {
								setPreviewUrl( '' );
								onChange( 0 );
							},
							disabled: disabled || isUploading,
						},
						__( 'Remove', 'extrachill-studio' )
				  )
				: null
		),
		error
			? h(
					'div',
					{ className: 'ec-studio-compose-review__error' },
					error
			  )
			: null
	);
}

/**
 * Single-taxonomy term picker for the Compose pane.
 *
 * Reads/searches MAIN's terms through the compose proxy, lets the writer select
 * one or more existing terms, and add a missing term (create-on-main). Reports
 * the selected term ids up so they are written to the main post as the
 * taxonomy's REST field. Category is used as a single-select required field;
 * artist/venue/location are multi-select and optional.
 * @param props
 * @param props.composeApiFetch
 * @param props.taxonomy
 * @param props.label
 * @param props.required
 * @param props.multiple
 * @param props.selected
 * @param props.onChange
 * @param props.disabled
 */
export function TermPicker( props: {
	composeApiFetch: ComposeApiFetch;
	taxonomy: string;
	label: string;
	required?: boolean;
	multiple?: boolean;
	selected: WpTerm[];
	onChange: ( terms: WpTerm[] ) => void;
	disabled?: boolean;
} ): ReactElement {
	const {
		composeApiFetch,
		taxonomy,
		label,
		required,
		multiple,
		selected,
		onChange,
		disabled,
	} = props;
	const [ search, setSearch ] = useState( '' );
	const [ results, setResults ] = useState< WpTerm[] >( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const [ isCreating, setIsCreating ] = useState( false );
	const [ error, setError ] = useState( '' );
	const searchTimerRef = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);
	const searchSeqRef = useRef( 0 );

	const runSearch = useCallback(
		async ( term: string ): Promise< void > => {
			const seq = ++searchSeqRef.current;
			setIsSearching( true );
			try {
				const query = term.trim()
					? `?search=${ encodeURIComponent(
							term.trim()
					  ) }&per_page=20`
					: '?per_page=20&orderby=count&order=desc';
				const found = await composeApiFetch< WpTerm[] >( {
					path: `${ PROXY_TERMS }/${ taxonomy }${ query }`,
				} );
				if ( seq === searchSeqRef.current ) {
					setResults( Array.isArray( found ) ? found : [] );
				}
			} catch {
				if ( seq === searchSeqRef.current ) {
					setResults( [] );
				}
			} finally {
				if ( seq === searchSeqRef.current ) {
					setIsSearching( false );
				}
			}
		},
		[ composeApiFetch, taxonomy ]
	);

	const onSearchChange = useCallback(
		( e: ChangeEvent< HTMLInputElement > ): void => {
			const value = e.target.value;
			setSearch( value );
			setError( '' );
			if ( searchTimerRef.current ) {
				clearTimeout( searchTimerRef.current );
			}
			searchTimerRef.current = setTimeout( () => {
				searchTimerRef.current = null;
				runSearch( value );
			}, 300 );
		},
		[ runSearch ]
	);

	const selectTerm = useCallback(
		( term: WpTerm ): void => {
			if ( selected.some( ( t ) => t.id === term.id ) ) {
				return;
			}
			onChange( multiple ? [ ...selected, term ] : [ term ] );
			setSearch( '' );
			setResults( [] );
		},
		[ selected, onChange, multiple ]
	);

	const removeTerm = useCallback(
		( id: number ): void => {
			onChange( selected.filter( ( t ) => t.id !== id ) );
		},
		[ selected, onChange ]
	);

	const createTerm = useCallback( async (): Promise< void > => {
		const name = search.trim();
		if ( ! name ) {
			return;
		}
		setIsCreating( true );
		setError( '' );
		try {
			const term = await composeApiFetch< WpTerm >( {
				path: `${ PROXY_TERMS }/${ taxonomy }`,
				method: 'POST',
				data: { name },
			} );
			if ( term?.id ) {
				selectTerm( term );
			}
		} catch ( createError ) {
			setError(
				( createError as Error )?.message ||
					__( 'Could not create that term.', 'extrachill-studio' )
			);
		} finally {
			setIsCreating( false );
		}
	}, [ search, composeApiFetch, taxonomy, selectTerm ] );

	useEffect( () => {
		return () => {
			if ( searchTimerRef.current ) {
				clearTimeout( searchTimerRef.current );
			}
		};
	}, [] );

	// Whether the typed search exactly matches an existing result (so we don't
	// offer "create" for something that already exists).
	const exactMatch = results.some(
		( t ) => t.name.trim().toLowerCase() === search.trim().toLowerCase()
	);
	const canCreate = search.trim().length > 0 && ! exactMatch && ! isSearching;

	const createLabel = isCreating
		? __( 'Adding…', 'extrachill-studio' )
		: sprintf(
				/* translators: %s: the term name being created */
				__( 'Add “%s”', 'extrachill-studio' ),
				search.trim()
		  );

	return h(
		'div',
		{ className: 'ec-studio-compose-review__field' },
		h(
			'div',
			{ className: 'ec-studio-compose-review__label' },
			label,
			required
				? h(
						'span',
						{ className: 'ec-studio-compose-review__required' },
						__( '(required)', 'extrachill-studio' )
				  )
				: h(
						'span',
						{ className: 'ec-studio-compose-review__optional' },
						__( '(optional)', 'extrachill-studio' )
				  )
		),
		selected.length
			? h(
					'div',
					{ className: 'ec-studio-compose-review__chips' },
					...selected.map( ( term ) =>
						h(
							'span',
							{
								key: term.id,
								className: 'ec-studio-compose-review__chip',
							},
							term.name,
							h(
								'button',
								{
									type: 'button',
									className:
										'ec-studio-compose-review__chip-remove',
									'aria-label': __(
										'Remove',
										'extrachill-studio'
									),
									onClick: () => removeTerm( term.id ),
									disabled,
								},
								'×'
							)
						)
					)
			  )
			: null,
		h( 'input', {
			type: 'text',
			className: 'ec-studio-compose-review__search',
			placeholder: __( 'Search or add…', 'extrachill-studio' ),
			value: search,
			onChange: onSearchChange,
			disabled: disabled || isCreating,
		} ),
		results.length
			? h(
					'ul',
					{ className: 'ec-studio-compose-review__results' },
					...results.map( ( term ) =>
						h(
							'li',
							{ key: term.id },
							h(
								'button',
								{
									type: 'button',
									className:
										'ec-studio-compose-review__result',
									onClick: () => selectTerm( term ),
									disabled,
								},
								term.name
							)
						)
					)
			  )
			: null,
		canCreate
			? h(
					'button',
					{
						type: 'button',
						className:
							'button-1 button-small ec-studio-compose-review__create',
						onClick: createTerm,
						disabled: disabled || isCreating,
					},
					createLabel
			  )
			: null,
		error
			? h(
					'div',
					{ className: 'ec-studio-compose-review__error' },
					error
			  )
			: null
	);
}
