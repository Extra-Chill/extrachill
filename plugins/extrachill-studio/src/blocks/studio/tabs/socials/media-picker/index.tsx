import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useRef, useState, useCallback } from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';
import { ActionRow, FieldGroup, InlineStatus, Panel } from '@extrachill/components';
import type { NetworkMediaItem } from '@extrachill/api-client';

import { studioClient } from '../../../app/client';

const h = createElement as typeof import( 'react' ).createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const ActionRowView = ActionRow as unknown as ( props: any ) => ReactElement;
const FieldGroupView = FieldGroup as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as ( props: any ) => ReactElement;

const PER_PAGE = 24;
const SEARCH_DEBOUNCE_MS = 300;

export interface MediaPickerProps {
	/** Called when a media item is selected. Receives the canonical URL. */
	onSelect: ( url: string, item: NetworkMediaItem ) => void;
	/** Optional className for the outer wrapper. */
	className?: string;
}

/**
 * Network media picker.
 *
 * Browse and select images from the main editorial media library
 * (blog 1) via the `/extrachill/v1/network-media` endpoint. Supports
 * uploading new files directly into the main library — uploaded items
 * become immediately selectable.
 *
 * Renders a grid of thumbnails with search and pagination ("Load more").
 * The first tile is always the upload affordance.
 *
 * Selection is single-click — the parent receives the chosen URL and
 * decides what to do with it (e.g. add to publish queue).
 */
const MediaPicker = ( { onSelect, className }: MediaPickerProps ): ReactElement => {
	const [ items, setItems ] = useState< NetworkMediaItem[] >( [] );
	const [ page, setPage ] = useState( 1 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ activeSearch, setActiveSearch ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isUploading, setIsUploading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const fileInputRef = useRef< HTMLInputElement | null >( null );
	const searchDebounceRef = useRef< ReturnType< typeof setTimeout > | null >( null );

	const fetchPage = useCallback(
		async ( targetPage: number, search: string, mode: 'replace' | 'append' ): Promise< void > => {
			setIsLoading( true );
			setError( '' );

			try {
				const response = await studioClient.networkMedia.list( {
					media_type: 'image',
					search: search || undefined,
					per_page: PER_PAGE,
					page: targetPage,
				} );

				const fetched = Array.isArray( response?.items ) ? response.items : [];
				setItems( ( current ) => ( mode === 'append' ? [ ...current, ...fetched ] : fetched ) );
				setPage( response?.page || targetPage );
				setTotalPages( response?.total_pages || 1 );
				setTotalItems( response?.total || fetched.length );
			} catch ( fetchError ) {
				setError(
					( fetchError as Error )?.message ||
						__( 'Unable to load media library.', 'extrachill-studio' )
				);
				if ( mode === 'replace' ) {
					setItems( [] );
					setTotalPages( 1 );
					setTotalItems( 0 );
				}
			} finally {
				setIsLoading( false );
			}
		},
		[]
	);

	// Initial load.
	useEffect( () => {
		fetchPage( 1, '', 'replace' );
	}, [ fetchPage ] );

	// Debounced search.
	useEffect( () => {
		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
		}

		searchDebounceRef.current = setTimeout( () => {
			searchDebounceRef.current = null;
			if ( searchInput !== activeSearch ) {
				setActiveSearch( searchInput );
				fetchPage( 1, searchInput, 'replace' );
			}
		}, SEARCH_DEBOUNCE_MS );

		return () => {
			if ( searchDebounceRef.current ) {
				clearTimeout( searchDebounceRef.current );
				searchDebounceRef.current = null;
			}
		};
	}, [ searchInput, activeSearch, fetchPage ] );

	const loadMore = useCallback( (): void => {
		if ( page < totalPages && ! isLoading ) {
			fetchPage( page + 1, activeSearch, 'append' );
		}
	}, [ page, totalPages, isLoading, activeSearch, fetchPage ] );

	const triggerUpload = useCallback( (): void => {
		fileInputRef.current?.click();
	}, [] );

	const handleFileChange = useCallback(
		async ( event: ChangeEvent< HTMLInputElement > ): Promise< void > => {
			const file = event.target.files?.[ 0 ];
			if ( ! file ) return;

			setIsUploading( true );
			setError( '' );

			try {
				const formData = studioClient.networkMedia.buildUploadForm( file );
				const uploaded = await studioClient.networkMedia.upload( formData );

				// Prepend the new upload so it's immediately visible at the top.
				setItems( ( current ) => [ uploaded, ...current ] );
				setTotalItems( ( current ) => current + 1 );

				// Auto-select the freshly uploaded image.
				onSelect( uploaded.url, uploaded );
			} catch ( uploadError ) {
				setError(
					( uploadError as Error )?.message ||
						__( 'Upload failed.', 'extrachill-studio' )
				);
			} finally {
				setIsUploading( false );
				// Reset the input so the same file can be re-selected if needed.
				if ( fileInputRef.current ) {
					fileInputRef.current.value = '';
				}
			}
		},
		[ onSelect ]
	);

	const wrapperClass = [
		'ec-studio-media-picker',
		className || '',
	]
		.filter( Boolean )
		.join( ' ' );

	return h(
		PanelView,
		{ className: `ec-studio-panel ${ wrapperClass }`, compact: true },
		// Search bar
		h(
			FieldGroupView,
			{
				label: __( 'Search media library', 'extrachill-studio' ),
				htmlFor: 'ec-studio-media-picker-search',
				help: totalItems > 0
					? sprintf(
						/* translators: %d: total number of media items */
						__( '%d images in library', 'extrachill-studio' ),
						totalItems
					)
					: null,
			},
			createElement( 'input', {
				id: 'ec-studio-media-picker-search',
				type: 'search',
				value: searchInput,
				onChange: ( event: ChangeEvent< HTMLInputElement > ) => setSearchInput( event.target.value ),
				placeholder: __( 'Search by filename or title…', 'extrachill-studio' ),
				autoComplete: 'off',
			} )
		),

		error
			? h( InlineStatusView, { tone: 'error', className: 'ec-studio-message' }, error )
			: null,

		// Hidden native file input wired to the upload tile.
		createElement( 'input', {
			ref: fileInputRef,
			type: 'file',
			accept: 'image/*',
			onChange: handleFileChange,
			style: { display: 'none' },
		} ),

		// Grid: upload tile + library tiles
		createElement(
			'ul',
			{ className: 'ec-studio-media-picker__grid' },
			// Upload tile (always first)
			createElement(
				'li',
				{ className: 'ec-studio-media-picker__tile ec-studio-media-picker__tile--upload', key: '__upload__' },
				createElement(
					'button',
					{
						type: 'button',
						className: 'ec-studio-media-picker__upload-btn',
						onClick: triggerUpload,
						disabled: isUploading,
						'aria-label': __( 'Upload new image', 'extrachill-studio' ),
					},
					createElement( 'span', { className: 'ec-studio-media-picker__upload-icon', 'aria-hidden': true }, '+' ),
					createElement(
						'span',
						{ className: 'ec-studio-media-picker__upload-label' },
						isUploading
							? __( 'Uploading…', 'extrachill-studio' )
							: __( 'Upload', 'extrachill-studio' )
					)
				)
			),
			// Library tiles
			...items.map( ( item ) =>
				createElement(
					'li',
					{ key: item.sourceId, className: 'ec-studio-media-picker__tile' },
					createElement(
						'button',
						{
							type: 'button',
							className: 'ec-studio-media-picker__select-btn',
							onClick: () => onSelect( item.url, item ),
							'aria-label': sprintf(
								/* translators: %s: media item title */
								__( 'Select %s', 'extrachill-studio' ),
								item.title || item.sourceId
							),
							title: item.title || item.sourceId,
						},
						createElement( 'img', {
							src: item.previewUrl || item.url,
							alt: item.alt || item.title || '',
							loading: 'lazy',
						} )
					)
				)
			)
		),

		// Footer: status + load more
		h(
			ActionRowView,
			{ className: 'ec-studio-media-picker__footer' },
			isLoading && items.length === 0
				? createElement(
					'span',
					{ className: 'ec-studio-composer__hint' },
					__( 'Loading…', 'extrachill-studio' )
				)
				: null,
			! isLoading && items.length === 0
				? createElement(
					'span',
					{ className: 'ec-studio-composer__hint' },
					activeSearch
						? __( 'No matches.', 'extrachill-studio' )
						: __( 'No media yet — upload to get started.', 'extrachill-studio' )
				)
				: null,
			page < totalPages
				? createElement(
					'button',
					{
						type: 'button',
						className: 'button-1 button-medium',
						onClick: loadMore,
						disabled: isLoading,
					},
					isLoading
						? __( 'Loading…', 'extrachill-studio' )
						: __( 'Load more', 'extrachill-studio' )
				)
				: null,
			page >= totalPages && items.length > 0
				? createElement(
					'span',
					{ className: 'ec-studio-composer__hint' },
					__( 'End of library.', 'extrachill-studio' )
				)
				: null
		)
	);
};

export default MediaPicker;
