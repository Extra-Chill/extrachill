import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useRef, useState, useCallback } from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';
import apiFetch from '@wordpress/api-fetch';
import type { StudioPaneProps } from '../../types/studio';

declare global {
	interface Window {
		blocksEverywhereCreateEditor?: ( textarea: HTMLTextAreaElement ) => void;
	}
}

interface WpPost {
	id: number;
	title: { rendered: string; raw?: string };
	content: { rendered: string; raw?: string };
	status: string;
	date: string;
	modified: string;
}

/** Autosave debounce interval in milliseconds. */
const AUTOSAVE_DELAY = 2000;

/**
 * Compose Pane — Block editor for drafting posts.
 *
 * Mounts the Blocks Everywhere isolated block editor on a hidden textarea.
 * Auto-loads the most recent draft on mount. Supports switching between
 * drafts, creating new ones, and updating existing ones in place.
 * Autosaves every 2s after changes when editing an existing draft.
 */
const ComposePane = ( _props: StudioPaneProps ): ReactElement => {
	const editorContainerRef = useRef< HTMLDivElement >( null );
	const textareaRef = useRef< HTMLTextAreaElement >( null );
	const editorMountedRef = useRef( false );

	const [ title, setTitle ] = useState( '' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ editorReady, setEditorReady ] = useState( false );

	// Draft management state.
	const [ drafts, setDrafts ] = useState< WpPost[] >( [] );
	const [ activePostId, setActivePostId ] = useState< number | null >( null );
	const [ isLoadingDrafts, setIsLoadingDrafts ] = useState( true );

	// Autosave tracking refs (not state — no re-renders needed).
	const autosaveTimerRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const lastSavedPayloadRef = useRef( '' );
	const isAutosavingRef = useRef( false );
	const activePostIdRef = useRef< number | null >( null );
	const titleRef = useRef( '' );

	// Keep refs in sync with state so autosave callbacks read current values.
	activePostIdRef.current = activePostId;
	titleRef.current = title;

	/** Push block content into the hidden textarea so Blocks Everywhere picks it up. */
	const setEditorContent = useCallback( ( content: string ): void => {
		if ( textareaRef.current ) {
			textareaRef.current.value = content;
			textareaRef.current.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	}, [] );

	/** Read serialized block content from the hidden textarea. */
	const getContent = (): string => {
		if ( textareaRef.current ) {
			return textareaRef.current.value || '';
		}
		return '';
	};

	/** Fetch user's drafts from the REST API. */
	const loadDrafts = useCallback( async (): Promise< WpPost[] > => {
		try {
			const result = await apiFetch< WpPost[] >( {
				path: '/wp/v2/posts?status=draft&per_page=20&orderby=modified&order=desc&context=edit',
			} );
			return Array.isArray( result ) ? result : [];
		} catch {
			return [];
		}
	}, [] );

	/** Load a specific draft into the editor. */
	const loadDraft = useCallback( ( post: WpPost ): void => {
		// Clear any pending autosave for the previous draft.
		if ( autosaveTimerRef.current ) {
			clearTimeout( autosaveTimerRef.current );
			autosaveTimerRef.current = null;
		}

		setActivePostId( post.id );
		setTitle( post.title.raw || post.title.rendered || '' );
		setEditorContent( post.content.raw || post.content.rendered || '' );
		setError( '' );
		setStatus( '' );

		// Snapshot the loaded state so autosave doesn't fire immediately.
		lastSavedPayloadRef.current = JSON.stringify( {
			title: post.title.raw || post.title.rendered || '',
			content: post.content.raw || post.content.rendered || '',
		} );
	}, [ setEditorContent ] );

	/** Start a new blank post — clears editor and unsets active draft. */
	const startNew = useCallback( (): void => {
		if ( autosaveTimerRef.current ) {
			clearTimeout( autosaveTimerRef.current );
			autosaveTimerRef.current = null;
		}

		setActivePostId( null );
		setTitle( '' );
		setEditorContent( '' );
		setError( '' );
		setStatus( '' );
		lastSavedPayloadRef.current = '';
	}, [ setEditorContent ] );

	/**
	 * Autosave the current draft.
	 *
	 * Only fires when an activePostId exists (i.e., user has saved at least once
	 * or loaded an existing draft). Skips if content hasn't changed since last save.
	 * Runs silently — no status messages unless there's an error.
	 */
	const performAutosave = useCallback( async (): Promise< void > => {
		const postId = activePostIdRef.current;
		if ( ! postId || isAutosavingRef.current ) {
			return;
		}

		const currentTitle = titleRef.current.trim();
		const currentContent = getContent().trim();

		// Don't autosave empty posts.
		if ( ! currentTitle && ! currentContent ) {
			return;
		}

		// Skip if nothing changed.
		const payload = JSON.stringify( { title: currentTitle, content: currentContent } );
		if ( payload === lastSavedPayloadRef.current ) {
			return;
		}

		isAutosavingRef.current = true;

		try {
			await apiFetch< WpPost >( {
				path: `/wp/v2/posts/${ postId }`,
				method: 'POST',
				data: {
					title: currentTitle,
					content: currentContent,
					status: 'draft',
				},
			} );

			lastSavedPayloadRef.current = payload;
		} catch {
			// Silent failure — user can still manually save.
		} finally {
			isAutosavingRef.current = false;
		}
	}, [] );

	/** Schedule a debounced autosave. */
	const scheduleAutosave = useCallback( (): void => {
		if ( autosaveTimerRef.current ) {
			clearTimeout( autosaveTimerRef.current );
		}

		autosaveTimerRef.current = setTimeout( () => {
			autosaveTimerRef.current = null;
			performAutosave();
		}, AUTOSAVE_DELAY );
	}, [ performAutosave ] );

	// Mount the block editor on first render.
	useEffect( () => {
		if ( editorMountedRef.current || ! textareaRef.current ) {
			return;
		}

		if ( typeof window.blocksEverywhereCreateEditor === 'function' ) {
			window.blocksEverywhereCreateEditor( textareaRef.current );
			editorMountedRef.current = true;
			setEditorReady( true );
		} else {
			setError( __( 'Block editor not available. Ensure Blocks Everywhere plugin is active.', 'extrachill-studio' ) );
		}
	}, [] );

	// Listen for content changes on the textarea (Blocks Everywhere fires input events).
	useEffect( () => {
		const textarea = textareaRef.current;
		if ( ! textarea ) {
			return;
		}

		const onContentChange = (): void => {
			scheduleAutosave();
		};

		textarea.addEventListener( 'input', onContentChange );

		return () => {
			textarea.removeEventListener( 'input', onContentChange );
			// Flush any pending autosave on unmount.
			if ( autosaveTimerRef.current ) {
				clearTimeout( autosaveTimerRef.current );
				performAutosave();
			}
		};
	}, [ scheduleAutosave, performAutosave ] );

	// Load drafts on mount, auto-load the most recent one.
	useEffect( () => {
		let cancelled = false;

		const init = async (): Promise< void > => {
			setIsLoadingDrafts( true );
			const result = await loadDrafts();

			if ( cancelled ) {
				return;
			}

			setDrafts( result );
			setIsLoadingDrafts( false );

			// Auto-load the most recent draft if one exists.
			if ( result.length > 0 ) {
				loadDraft( result[ 0 ] );
			}
		};

		init();

		return () => {
			cancelled = true;
		};
	}, [ loadDrafts, loadDraft ] );

	const submitForReview = async (): Promise< void > => {
		const content = getContent();

		if ( ! title.trim() ) {
			setError( __( 'Add a title before submitting.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		if ( ! content.trim() ) {
			setError( __( 'Write some content before submitting.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		// Cancel any pending autosave.
		if ( autosaveTimerRef.current ) {
			clearTimeout( autosaveTimerRef.current );
			autosaveTimerRef.current = null;
		}

		setIsSubmitting( true );
		setError( '' );
		setStatus( __( 'Submitting for review…', 'extrachill-studio' ) );

		try {
			const path = activePostId
				? `/wp/v2/posts/${ activePostId }`
				: '/wp/v2/posts';

			const post = await apiFetch< WpPost >( {
				path,
				method: 'POST',
				data: {
					title: title.trim(),
					content,
					status: 'pending',
				},
			} );

			setStatus(
				sprintf(
					__( 'Post #%d submitted for review.', 'extrachill-studio' ),
					post.id
				)
			);

			startNew();
			const refreshed = await loadDrafts();
			setDrafts( refreshed );
		} catch ( submitError ) {
			setStatus( '' );
			setError( ( submitError as Error )?.message || __( 'Failed to submit post.', 'extrachill-studio' ) );
		} finally {
			setIsSubmitting( false );
		}
	};

	const saveDraft = async (): Promise< void > => {
		const content = getContent();

		if ( ! title.trim() && ! content.trim() ) {
			setError( __( 'Add a title or content before saving.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		// Cancel any pending autosave — we're doing an explicit save.
		if ( autosaveTimerRef.current ) {
			clearTimeout( autosaveTimerRef.current );
			autosaveTimerRef.current = null;
		}

		setIsSubmitting( true );
		setError( '' );
		setStatus( __( 'Saving…', 'extrachill-studio' ) );

		try {
			const path = activePostId
				? `/wp/v2/posts/${ activePostId }`
				: '/wp/v2/posts';

			const post = await apiFetch< WpPost >( {
				path,
				method: 'POST',
				data: {
					title: title.trim(),
					content,
					status: 'draft',
				},
			} );

			setActivePostId( post.id );
			lastSavedPayloadRef.current = JSON.stringify( { title: title.trim(), content } );
			setStatus( __( 'Draft saved.', 'extrachill-studio' ) );

			const refreshed = await loadDrafts();
			setDrafts( refreshed );
		} catch ( saveError ) {
			setStatus( '' );
			setError( ( saveError as Error )?.message || __( 'Failed to save draft.', 'extrachill-studio' ) );
		} finally {
			setIsSubmitting( false );
		}
	};

	/** Handle draft picker change. */
	const onDraftChange = ( e: ChangeEvent< HTMLSelectElement > ): void => {
		const postId = Number.parseInt( e.target.value, 10 );

		if ( ! postId ) {
			return;
		}

		const post = drafts.find( ( d ) => d.id === postId );
		if ( post ) {
			loadDraft( post );
		}
	};

	/** Handle title changes — update state and schedule autosave. */
	const onTitleChange = ( e: ChangeEvent< HTMLInputElement > ): void => {
		setTitle( e.target.value );
		setError( '' );
		setStatus( '' );
		scheduleAutosave();
	};

	return createElement(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--compose' },
		createElement(
			'div',
			{ className: 'ec-studio-pane__grid ec-studio-pane__grid--compose' },

			// Left: Editor
			createElement(
				'div',
				{ className: 'ec-studio-panel ec-studio-panel--editor' },

				// Draft toolbar: picker + new button
				createElement(
					'div',
					{ className: 'ec-studio-compose-toolbar' },
					createElement(
						'div',
						{ className: 'ec-studio-compose-toolbar__controls' },
						drafts.length > 0
							? createElement(
								'select',
								{
									className: 'ec-studio-compose-draft-picker',
									value: activePostId || '',
									onChange: onDraftChange,
									disabled: isLoadingDrafts,
								},
								createElement( 'option', { value: '', disabled: true },
									isLoadingDrafts
										? __( 'Loading drafts…', 'extrachill-studio' )
										: __( 'Select a draft…', 'extrachill-studio' )
								),
								...drafts.map( ( d ) =>
									createElement( 'option', { key: d.id, value: d.id },
										`#${ d.id } — ${ ( d.title.raw || d.title.rendered || __( 'Untitled', 'extrachill-studio' ) ).slice( 0, 50 ) }`
									)
								)
							)
							: ( ! isLoadingDrafts
								? createElement( 'span', { className: 'ec-studio-compose-toolbar__empty' }, __( 'No drafts yet', 'extrachill-studio' ) )
								: createElement( 'span', { className: 'ec-studio-compose-toolbar__empty' }, __( 'Loading…', 'extrachill-studio' ) )
							),
						createElement(
							'button',
							{
								type: 'button',
								className: 'button-1 button-small',
								onClick: startNew,
								disabled: isSubmitting,
							},
							__( 'New', 'extrachill-studio' )
						)
					)
				),

				// Title input
				createElement( 'input', {
					type: 'text',
					className: 'ec-studio-compose-title',
					placeholder: __( 'Post title…', 'extrachill-studio' ),
					value: title,
					onChange: onTitleChange,
				} ),

				// Block editor container
				createElement(
					'div',
					{
						className: 'ec-studio-compose-editor',
						ref: editorContainerRef,
					},
					createElement( 'textarea', {
						id: 'ec-studio-compose-content',
						ref: textareaRef,
						style: { display: 'none' },
						defaultValue: '',
					} )
				),

				// Status messages
				error
					? createElement( 'p', { className: 'ec-studio-message ec-studio-message--error' }, error )
					: null,
				! error && status
					? createElement( 'p', { className: 'ec-studio-message ec-studio-message--success' }, status )
					: null,

				// Actions
				createElement(
					'div',
					{ className: 'ec-studio-composer__actions' },
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium',
							onClick: submitForReview,
							disabled: isSubmitting || ! editorReady,
						},
						isSubmitting ? __( 'Submitting…', 'extrachill-studio' ) : __( 'Submit for Review', 'extrachill-studio' )
					),
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium button-secondary',
							onClick: saveDraft,
							disabled: isSubmitting || ! editorReady,
						},
						isSubmitting ? __( 'Saving…', 'extrachill-studio' ) : (
							activePostId
								? __( 'Update Draft', 'extrachill-studio' )
								: __( 'Save Draft', 'extrachill-studio' )
						)
					)
				)
			),

			// Right: Info panel
			createElement(
				'div',
				{ className: 'ec-studio-panel' },
				createElement( 'h3', null, __( 'How posts get published', 'extrachill-studio' ) ),
				createElement( 'p', null, __( 'Write with the full block editor — paragraphs, images, embeds, and formatting all work here.', 'extrachill-studio' ) ),
				createElement(
					'ul',
					null,
					createElement( 'li', null, __( 'Your work autosaves every few seconds while editing an existing draft.', 'extrachill-studio' ) ),
					createElement( 'li', null, __( 'Save Draft — creates or updates a draft you can return to later.', 'extrachill-studio' ) ),
					createElement( 'li', null, __( 'Submit for Review — flags the post for an admin to approve and publish.', 'extrachill-studio' ) )
				)
			)
		)
	);
};

export default ComposePane;
