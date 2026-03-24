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
 *
 * Content loading strategy:
 * - Blocks Everywhere reads textarea.value exactly once at mount time via onLoad.
 * - There is no API to hot-swap content into a running editor.
 * - To switch drafts, we increment `editorKey` which forces React to unmount
 *   and remount the editor container. The new instance reads the pre-filled
 *   textarea value on initialization.
 */
const ComposePane = ( _props: StudioPaneProps ): ReactElement => {
	const [ title, setTitle ] = useState( '' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );

	// Draft management state.
	const [ drafts, setDrafts ] = useState< WpPost[] >( [] );
	const [ activePostId, setActivePostId ] = useState< number | null >( null );
	const [ isLoadingDrafts, setIsLoadingDrafts ] = useState( true );

	// Editor mount key — incrementing forces a full remount of the editor.
	// This is the same pattern Blocks Everywhere uses internally for draft switching.
	const [ editorKey, setEditorKey ] = useState( 0 );

	// The content to pre-fill the textarea with before the editor mounts.
	const pendingContentRef = useRef( '' );

	// Autosave tracking refs (not state — no re-renders needed).
	const autosaveTimerRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const lastSavedPayloadRef = useRef( '' );
	const isAutosavingRef = useRef( false );
	const activePostIdRef = useRef< number | null >( null );
	const titleRef = useRef( '' );
	const textareaRef = useRef< HTMLTextAreaElement | null >( null );

	// Keep refs in sync with state so autosave callbacks read current values.
	activePostIdRef.current = activePostId;
	titleRef.current = title;

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

	/**
	 * Switch to a draft (or blank). Sets pending content and increments editorKey
	 * to force a full editor remount with the new content.
	 */
	const switchToDraft = useCallback( ( post: WpPost | null ): void => {
		// Clear any pending autosave for the previous draft.
		if ( autosaveTimerRef.current ) {
			clearTimeout( autosaveTimerRef.current );
			autosaveTimerRef.current = null;
		}

		if ( post ) {
			setActivePostId( post.id );
			setTitle( post.title.raw || post.title.rendered || '' );
			pendingContentRef.current = post.content.raw || post.content.rendered || '';
			lastSavedPayloadRef.current = JSON.stringify( {
				title: post.title.raw || post.title.rendered || '',
				content: post.content.raw || post.content.rendered || '',
			} );
		} else {
			setActivePostId( null );
			setTitle( '' );
			pendingContentRef.current = '';
			lastSavedPayloadRef.current = '';
		}

		setError( '' );
		setStatus( '' );

		// Force editor remount — React will unmount the old editor and mount a fresh one.
		setEditorKey( ( k ) => k + 1 );
	}, [] );

	/** Start a new blank post. */
	const startNew = useCallback( (): void => {
		switchToDraft( null );
	}, [ switchToDraft ] );

	/**
	 * Autosave the current draft.
	 * Only fires when an activePostId exists. Skips if content hasn't changed.
	 */
	const performAutosave = useCallback( async (): Promise< void > => {
		const postId = activePostIdRef.current;
		if ( ! postId || isAutosavingRef.current ) {
			return;
		}

		const currentTitle = titleRef.current.trim();
		const currentContent = getContent().trim();

		if ( ! currentTitle && ! currentContent ) {
			return;
		}

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

			// Pre-fill with the most recent draft so the first editor mount has content.
			if ( result.length > 0 ) {
				const post = result[ 0 ];
				setActivePostId( post.id );
				setTitle( post.title.raw || post.title.rendered || '' );
				pendingContentRef.current = post.content.raw || post.content.rendered || '';
				lastSavedPayloadRef.current = JSON.stringify( {
					title: post.title.raw || post.title.rendered || '',
					content: post.content.raw || post.content.rendered || '',
				} );
			}

			// Trigger the first editor mount.
			setEditorKey( 1 );
		};

		init();

		return () => {
			cancelled = true;
		};
	}, [ loadDrafts ] );

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

			// Clear editor and refresh drafts list.
			const refreshed = await loadDrafts();
			setDrafts( refreshed );
			switchToDraft( null );
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
			switchToDraft( post );
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

				// Block editor container — keyed by editorKey to force remount on draft switch.
				// editorKey=0 means "not ready yet" (waiting for draft list to load).
				editorKey > 0
					? createElement( EditorMount, {
						key: editorKey,
						pendingContent: pendingContentRef.current,
						onTextareaReady: ( el: HTMLTextAreaElement ) => {
							textareaRef.current = el;
						},
						onContentChange: scheduleAutosave,
					} )
					: createElement(
						'div',
						{ className: 'ec-studio-compose-editor' },
						createElement( 'p', { className: 'ec-studio-message ec-studio-message--info' }, __( 'Loading editor…', 'extrachill-studio' ) )
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
							disabled: isSubmitting || editorKey === 0,
						},
						isSubmitting ? __( 'Submitting…', 'extrachill-studio' ) : __( 'Submit for Review', 'extrachill-studio' )
					),
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium button-secondary',
							onClick: saveDraft,
							disabled: isSubmitting || editorKey === 0,
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

/**
 * Editor mount component — handles the textarea + Blocks Everywhere lifecycle.
 *
 * This is a separate component so React's `key` prop can force a full unmount/remount
 * when the draft changes. Each mount:
 * 1. Creates a textarea pre-filled with draft content
 * 2. Calls blocksEverywhereCreateEditor which reads textarea.value via onLoad
 * 3. Listens for textarea input events (content changes from BE) for autosave
 */
interface EditorMountProps {
	pendingContent: string;
	onTextareaReady: ( el: HTMLTextAreaElement ) => void;
	onContentChange: () => void;
}

const EditorMount = ( { pendingContent, onTextareaReady, onContentChange }: EditorMountProps ): ReactElement => {
	const containerRef = useRef< HTMLDivElement >( null );
	const textareaRef = useRef< HTMLTextAreaElement >( null );
	const mountedRef = useRef( false );

	useEffect( () => {
		if ( mountedRef.current || ! textareaRef.current ) {
			return;
		}

		// Pre-fill the textarea so BE's onLoad reads it.
		textareaRef.current.value = pendingContent;

		// Expose the textarea ref to the parent for content reads.
		onTextareaReady( textareaRef.current );

		if ( typeof window.blocksEverywhereCreateEditor === 'function' ) {
			window.blocksEverywhereCreateEditor( textareaRef.current );
			mountedRef.current = true;
		}
	}, [] );

	// Listen for content changes (BE writes to textarea on every block change).
	useEffect( () => {
		const textarea = textareaRef.current;
		if ( ! textarea ) {
			return;
		}

		textarea.addEventListener( 'input', onContentChange );
		return () => {
			textarea.removeEventListener( 'input', onContentChange );
		};
	}, [ onContentChange ] );

	return createElement(
		'div',
		{
			className: 'ec-studio-compose-editor',
			ref: containerRef,
		},
		createElement( 'textarea', {
			id: 'ec-studio-compose-content',
			ref: textareaRef,
			style: { display: 'none' },
			defaultValue: '',
		} )
	);
};

export default ComposePane;
