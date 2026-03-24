import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useRef, useState, useCallback } from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';
import apiFetch from '@wordpress/api-fetch';
import type { StudioPaneProps } from '../../types/studio';

declare global {
	interface Window {
		blocksEverywhereCreateEditor?: ( textarea: HTMLTextAreaElement ) => void;
		blocksEverywhereGetContentApi?: ( textarea: HTMLTextAreaElement ) => BlocksEverywhereContentApi | null;
	}
}

interface BlocksEverywhereContentApi {
	replaceContent: ( html: string ) => void;
	getContent: () => string;
	getBlocks: () => object[];
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
 * Mounts the Blocks Everywhere editor once. Uses the ContentBridge API
 * (replaceContent) to hot-swap content when switching between drafts —
 * no editor remounting needed.
 *
 * Auto-loads the most recent draft on mount. Autosaves every 2s.
 */
const ComposePane = ( _props: StudioPaneProps ): ReactElement => {
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

	// Autosave tracking refs.
	const autosaveTimerRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const lastSavedPayloadRef = useRef( '' );
	const isAutosavingRef = useRef( false );
	const activePostIdRef = useRef< number | null >( null );
	const titleRef = useRef( '' );

	// Keep refs in sync with state.
	activePostIdRef.current = activePostId;
	titleRef.current = title;

	/** Get the content API from the Blocks Everywhere ContentBridge. */
	const getContentApi = (): BlocksEverywhereContentApi | null => {
		if ( ! textareaRef.current || ! window.blocksEverywhereGetContentApi ) {
			return null;
		}
		return window.blocksEverywhereGetContentApi( textareaRef.current );
	};

	/** Read serialized block content — prefer content API, fall back to textarea. */
	const getContent = (): string => {
		const api = getContentApi();
		if ( api ) {
			return api.getContent();
		}
		return textareaRef.current?.value || '';
	};

	/** Replace editor content using the ContentBridge API. */
	const replaceEditorContent = ( html: string ): void => {
		const api = getContentApi();
		if ( api ) {
			api.replaceContent( html );
		}
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

	/** Switch to a draft or start blank. Uses replaceContent — no remount. */
	const switchToDraft = useCallback( ( post: WpPost | null ): void => {
		if ( autosaveTimerRef.current ) {
			clearTimeout( autosaveTimerRef.current );
			autosaveTimerRef.current = null;
		}

		if ( post ) {
			setActivePostId( post.id );
			setTitle( post.title.raw || post.title.rendered || '' );
			const content = post.content.raw || post.content.rendered || '';
			replaceEditorContent( content );
			lastSavedPayloadRef.current = JSON.stringify( {
				title: post.title.raw || post.title.rendered || '',
				content,
			} );
		} else {
			setActivePostId( null );
			setTitle( '' );
			replaceEditorContent( '' );
			lastSavedPayloadRef.current = '';
		}

		setError( '' );
		setStatus( '' );
	}, [] );

	const startNew = useCallback( (): void => {
		switchToDraft( null );
	}, [ switchToDraft ] );

	/** Autosave the current draft silently. */
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
				data: { title: currentTitle, content: currentContent, status: 'draft' },
			} );
			lastSavedPayloadRef.current = payload;
		} catch {
			// Silent — user can still manually save.
		} finally {
			isAutosavingRef.current = false;
		}
	}, [] );

	const scheduleAutosave = useCallback( (): void => {
		if ( autosaveTimerRef.current ) {
			clearTimeout( autosaveTimerRef.current );
		}
		autosaveTimerRef.current = setTimeout( () => {
			autosaveTimerRef.current = null;
			performAutosave();
		}, AUTOSAVE_DELAY );
	}, [ performAutosave ] );

	// Mount the editor once and load drafts.
	useEffect( () => {
		let cancelled = false;

		const init = async (): Promise< void > => {
			// Fetch drafts first so we can pre-fill the textarea before mounting the editor.
			setIsLoadingDrafts( true );
			const result = await loadDrafts();

			if ( cancelled ) {
				return;
			}

			setDrafts( result );
			setIsLoadingDrafts( false );

			// Pre-fill textarea with the most recent draft.
			if ( result.length > 0 && textareaRef.current ) {
				const post = result[ 0 ];
				setActivePostId( post.id );
				setTitle( post.title.raw || post.title.rendered || '' );
				textareaRef.current.value = post.content.raw || post.content.rendered || '';
				lastSavedPayloadRef.current = JSON.stringify( {
					title: post.title.raw || post.title.rendered || '',
					content: post.content.raw || post.content.rendered || '',
				} );
			}

			// Mount the editor — it reads textarea.value via onLoad.
			if ( ! editorMountedRef.current && textareaRef.current ) {
				if ( typeof window.blocksEverywhereCreateEditor === 'function' ) {
					window.blocksEverywhereCreateEditor( textareaRef.current );
					editorMountedRef.current = true;
					setEditorReady( true );
				} else {
					setError( __( 'Block editor not available. Ensure Blocks Everywhere plugin is active.', 'extrachill-studio' ) );
				}
			}
		};

		init();

		return () => {
			cancelled = true;
		};
	}, [ loadDrafts ] );

	// Listen for content changes on the textarea for autosave.
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
			if ( autosaveTimerRef.current ) {
				clearTimeout( autosaveTimerRef.current );
				performAutosave();
			}
		};
	}, [ scheduleAutosave, performAutosave ] );

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
			const path = activePostId ? `/wp/v2/posts/${ activePostId }` : '/wp/v2/posts';
			const post = await apiFetch< WpPost >( {
				path,
				method: 'POST',
				data: { title: title.trim(), content, status: 'pending' },
			} );

			setStatus( sprintf( __( 'Post #%d submitted for review.', 'extrachill-studio' ), post.id ) );

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
			const path = activePostId ? `/wp/v2/posts/${ activePostId }` : '/wp/v2/posts';
			const post = await apiFetch< WpPost >( {
				path,
				method: 'POST',
				data: { title: title.trim(), content, status: 'draft' },
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

				// Draft toolbar
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

				// Block editor container — mounted once, content swapped via replaceContent API.
				createElement(
					'div',
					{ className: 'ec-studio-compose-editor' },
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
