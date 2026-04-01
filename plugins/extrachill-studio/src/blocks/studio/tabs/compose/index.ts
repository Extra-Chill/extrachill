import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useRef, useState, useCallback } from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';
import apiFetch from '@wordpress/api-fetch';
import {
	getOrCreateClientContextRegistry,
	registerClientContextProvider,
} from '@extrachill/chat';
import { ActionRow, FieldGroup, InlineStatus, Panel, PanelHeader } from '@extrachill/components';
import type { StudioPaneProps } from '../../types/studio';

const h = createElement as typeof import( 'react' ).createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const ActionRowView = ActionRow as unknown as ( props: any ) => ReactElement;
const FieldGroupView = FieldGroup as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as ( props: any ) => ReactElement;

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
const CLIENT_CONTEXT_UPDATE_DELAY = 250;
const CLIENT_CONTEXT_PROVIDER_ID = 'extrachill-studio.compose';

/** Site transfer targets — tag slug to label. */
const SITE_TARGETS: Record< string, string > = {
	blog: 'Blog (extrachill.com)',
	wire: 'Wire (wire.extrachill.com)',
};

function extractPlainText( html: string ): string {
	if ( ! html ) {
		return '';
	}

	if ( typeof window !== 'undefined' && typeof window.DOMParser !== 'undefined' ) {
		const parsed = new window.DOMParser().parseFromString( html, 'text/html' );
		return ( parsed.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	return html.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
}

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
	const [ isSwitching, setIsSwitching ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ editorReady, setEditorReady ] = useState( false );
	const [ hasUnsavedChanges, setHasUnsavedChanges ] = useState( false );

	// Draft management state.
	const [ drafts, setDrafts ] = useState< WpPost[] >( [] );
	const [ activePostId, setActivePostId ] = useState< number | null >( null );
	const [ isLoadingDrafts, setIsLoadingDrafts ] = useState( true );

	// Publish targets state (site targets only — social publishing is in the Socials tab).
	const [ publishTargets, setPublishTargets ] = useState< Set< string > >( new Set() );

	// Autosave tracking refs.
	const autosaveTimerRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const lastSavedPayloadRef = useRef( '' );
	const isAutosavingRef = useRef( false );
	const activePostIdRef = useRef< number | null >( null );
	const titleRef = useRef( '' );
	const contentSnapshotRef = useRef( '' );
	const clientContextTimerRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const unregisterClientContextRef = useRef< ( () => void ) | null >( null );

	// Keep refs in sync with state.
	activePostIdRef.current = activePostId;
	titleRef.current = title;

	const buildClientContext = useCallback( (): Record< string, unknown > => {
		const plainText = extractPlainText( contentSnapshotRef.current );
		const activeDraftId = activePostIdRef.current;
		const currentTitle = titleRef.current.trim();

		return {
			source: CLIENT_CONTEXT_PROVIDER_ID,
			kind: 'editor',
			surface: 'compose',
			resource: {
				entityType: 'post',
				postType: 'post',
				id: activeDraftId,
				status: activeDraftId ? 'draft' : 'unsaved',
				title: currentTitle || __( 'Untitled Draft', 'extrachill-studio' ),
			},
			content: {
				hasContent: plainText.length > 0,
				characterCount: plainText.length,
				excerpt: plainText.slice( 0, 280 ),
			},
		};
	}, [] );

	const scheduleClientContextUpdate = useCallback( (): void => {
		if ( clientContextTimerRef.current ) {
			clearTimeout( clientContextTimerRef.current );
		}

		clientContextTimerRef.current = setTimeout( () => {
			clientContextTimerRef.current = null;
			getOrCreateClientContextRegistry().notify();
		}, CLIENT_CONTEXT_UPDATE_DELAY );
	}, [] );



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

	/**
	 * Flush any unsaved changes for the current draft before switching away.
	 * Returns immediately if nothing to save or no active draft.
	 */
	const flushCurrentDraft = useCallback( async (): Promise< void > => {
		if ( autosaveTimerRef.current ) {
			clearTimeout( autosaveTimerRef.current );
			autosaveTimerRef.current = null;
		}

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
			// Best-effort — don't block the switch.
		} finally {
			isAutosavingRef.current = false;
		}
	}, [] );

	/**
	 * Switch to a draft or start blank. Flushes any unsaved changes to the
	 * current draft first, then replaces editor content via ContentBridge.
	 */
	const switchToDraft = useCallback( async ( post: WpPost | null ): Promise< void > => {
		// Save current draft before switching away.
		await flushCurrentDraft();

		if ( post ) {
			activePostIdRef.current = post.id;
			titleRef.current = post.title.raw || post.title.rendered || '';
			setActivePostId( post.id );
			setTitle( post.title.raw || post.title.rendered || '' );
			const content = post.content.raw || post.content.rendered || '';
			contentSnapshotRef.current = content;
			replaceEditorContent( content );
			lastSavedPayloadRef.current = JSON.stringify( {
				title: post.title.raw || post.title.rendered || '',
				content,
			} );
		} else {
			activePostIdRef.current = null;
			titleRef.current = '';
			contentSnapshotRef.current = '';
			setActivePostId( null );
			setTitle( '' );
			replaceEditorContent( '' );
			lastSavedPayloadRef.current = '';
		}

		setPublishTargets( new Set() );
		setHasUnsavedChanges( false );
		setError( '' );
		setStatus( '' );
		scheduleClientContextUpdate();
	}, [ flushCurrentDraft, scheduleClientContextUpdate ] );

	const startNew = useCallback( async (): Promise< void > => {
		await switchToDraft( null );
	}, [ switchToDraft ] );

	/** Autosave the current draft silently. */
	const performAutosave = useCallback( async (): Promise< void > => {
		const postId = activePostIdRef.current;
		if ( ! postId || isAutosavingRef.current ) {
			return;
		}

		const currentTitle = titleRef.current.trim();
		const currentContent = getContent().trim();
		contentSnapshotRef.current = currentContent;

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
			setHasUnsavedChanges( false );
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

	useEffect( () => {
		unregisterClientContextRef.current = registerClientContextProvider( {
			id: CLIENT_CONTEXT_PROVIDER_ID,
			priority: 100,
			getContext: buildClientContext,
		} );
		getOrCreateClientContextRegistry().notify();

		return () => {
			if ( clientContextTimerRef.current ) {
				clearTimeout( clientContextTimerRef.current );
				clientContextTimerRef.current = null;
			}

			unregisterClientContextRef.current?.();
			unregisterClientContextRef.current = null;
		};
	}, [ buildClientContext ] );

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
				activePostIdRef.current = post.id;
				titleRef.current = post.title.raw || post.title.rendered || '';
				setActivePostId( post.id );
				setTitle( post.title.raw || post.title.rendered || '' );
				textareaRef.current.value = post.content.raw || post.content.rendered || '';
				contentSnapshotRef.current = post.content.raw || post.content.rendered || '';
				lastSavedPayloadRef.current = JSON.stringify( {
					title: post.title.raw || post.title.rendered || '',
					content: post.content.raw || post.content.rendered || '',
				} );
			} else {
				activePostIdRef.current = null;
				titleRef.current = '';
				contentSnapshotRef.current = '';
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

			scheduleClientContextUpdate();
		};

		init();

		return () => {
			cancelled = true;
		};
	}, [ loadDrafts, scheduleClientContextUpdate ] );



	// Listen for content changes on the textarea for autosave.
	useEffect( () => {
		const textarea = textareaRef.current;
		if ( ! textarea ) {
			return;
		}

		const onContentChange = (): void => {
			contentSnapshotRef.current = getContent();
			const payload = JSON.stringify( { title: titleRef.current.trim(), content: contentSnapshotRef.current.trim() } );
			setHasUnsavedChanges( payload !== lastSavedPayloadRef.current );
			scheduleClientContextUpdate();
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
	}, [ scheduleAutosave, performAutosave, scheduleClientContextUpdate ] );

	const toggleTarget = ( key: string ): void => {
		setPublishTargets( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( key ) ) {
				next.delete( key );
			} else {
				next.add( key );
			}
			return next;
		} );
	};

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

			// Build the post data with publish targets.
			const postData: Record< string, unknown > = {
				title: title.trim(),
				content,
				status: 'pending',
			};

			// Site targets → tags. Resolve tag names to IDs (create if needed).
			const siteTagSlugs = Object.keys( SITE_TARGETS ).filter( ( key ) => publishTargets.has( key ) );
			if ( siteTagSlugs.length > 0 ) {
				const tagIds: number[] = [];
				for ( const slug of siteTagSlugs ) {
					try {
						// Try to find existing tag first.
						const existing = await apiFetch< Array< { id: number } > >( {
							path: `/wp/v2/tags?slug=${ slug }`,
						} );
						if ( existing.length > 0 ) {
							tagIds.push( existing[ 0 ].id );
						} else {
							// Create the tag.
							const created = await apiFetch< { id: number } >( {
								path: '/wp/v2/tags',
								method: 'POST',
								data: { name: slug },
							} );
							tagIds.push( created.id );
						}
					} catch {
						// Skip tag if resolution fails.
					}
				}
				if ( tagIds.length > 0 ) {
					postData.tags = tagIds;
				}
			}

			const post = await apiFetch< WpPost >( {
				path,
				method: 'POST',
				data: postData,
			} );

			activePostIdRef.current = null;
			titleRef.current = '';
			contentSnapshotRef.current = '';
			setPublishTargets( new Set() );

			// Build status message showing where the post will go.
			const targetSuffix = siteTagSlugs.length > 0
				? sprintf( __( ' → %s', 'extrachill-studio' ), siteTagSlugs.join( ', ' ) )
				: '';
			setStatus( sprintf( __( 'Post #%d submitted for review.', 'extrachill-studio' ), post.id ) + targetSuffix );

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

			activePostIdRef.current = post.id;
			titleRef.current = title.trim();
			contentSnapshotRef.current = content;
			setActivePostId( post.id );
			lastSavedPayloadRef.current = JSON.stringify( { title: title.trim(), content } );
			setHasUnsavedChanges( false );
			setStatus( __( 'Draft saved.', 'extrachill-studio' ) );

			const refreshed = await loadDrafts();
			setDrafts( refreshed );
			scheduleClientContextUpdate();
		} catch ( saveError ) {
			setStatus( '' );
			setError( ( saveError as Error )?.message || __( 'Failed to save draft.', 'extrachill-studio' ) );
		} finally {
			setIsSubmitting( false );
		}
	};

	const onDraftSelect = async ( e: ChangeEvent< HTMLSelectElement > ): Promise< void > => {
		const value = e.target.value;

		// "New draft" selected.
		if ( value === 'new' ) {
			setIsSwitching( true );
			await startNew();
			setIsSwitching( false );
			return;
		}

		const postId = Number.parseInt( value, 10 );
		if ( ! postId ) {
			return;
		}
		const post = drafts.find( ( d ) => d.id === postId );
		if ( post ) {
			setIsSwitching( true );
			await switchToDraft( post );
			setIsSwitching( false );
		}
	};

	const onTitleChange = ( e: ChangeEvent< HTMLInputElement > ): void => {
		titleRef.current = e.target.value;
		setTitle( e.target.value );
		setError( '' );
		setStatus( '' );
		const currentContent = getContent().trim();
		const payload = JSON.stringify( { title: e.target.value.trim(), content: currentContent } );
		setHasUnsavedChanges( payload !== lastSavedPayloadRef.current );
		scheduleClientContextUpdate();
		scheduleAutosave();
	};

	const draftPicker = createElement(
		'select',
		{
			className: 'ec-studio-compose-draft-picker',
			value: activePostId || 'new',
			onChange: onDraftSelect,
			disabled: isLoadingDrafts || isSwitching,
		},
		createElement(
			'option',
			{ value: 'new' },
			isLoadingDrafts
				? __( 'Loading drafts…', 'extrachill-studio' )
				: __( '+ New draft', 'extrachill-studio' )
		),
		...drafts.map( ( d ) =>
			createElement(
				'option',
				{ key: d.id, value: d.id },
				`#${ d.id } — ${ ( d.title.raw || d.title.rendered || __( 'Untitled', 'extrachill-studio' ) ).slice( 0, 50 ) }`
			)
		)
	);

	return h(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--compose' },
		h(
			'div',
			{ className: 'ec-studio-pane__grid ec-studio-pane__grid--compose' },
			h(
				PanelView,
				{ className: 'ec-studio-panel ec-studio-panel--editor', compact: true },
				h( PanelHeader, {
					description: __( 'Draft content and publish across the Extra Chill network.', 'extrachill-studio' ),
					actions: h(
						ActionRowView,
						{ className: 'ec-studio-compose-toolbar' },
						createElement(
							'div',
							{ className: 'ec-studio-compose-toolbar__controls' },
							draftPicker,
							createElement(
								'button',
								{
									type: 'button',
									className: 'button-1 button-small',
									onClick: startNew,
									disabled: isSubmitting || isSwitching || ! activePostId,
								},
								__( 'New', 'extrachill-studio' )
							),
							hasUnsavedChanges
								? createElement( 'span', { className: 'ec-studio-compose-toolbar__unsaved' }, __( 'Unsaved changes', 'extrachill-studio' ) )
								: null
						)
					)
				} ),
				h(
					FieldGroupView,
					{ label: __( 'Title', 'extrachill-studio' ), htmlFor: 'ec-studio-compose-title' },
					createElement( 'input', {
						id: 'ec-studio-compose-title',
						type: 'text',
						className: 'ec-studio-compose-title',
						placeholder: __( 'Post title…', 'extrachill-studio' ),
						value: title,
						onChange: onTitleChange,
					} )
				),
				h(
					'div',
					{ className: 'ec-studio-compose-editor' },
					createElement( 'textarea', {
						id: 'ec-studio-compose-content',
						ref: textareaRef,
						style: { display: 'none' },
						defaultValue: '',
					} )
				),
				error ? h( InlineStatusView, { tone: 'error', className: 'ec-studio-message' }, error ) : null,
				! error && status
					? h( InlineStatusView, { tone: 'success', className: 'ec-studio-message' }, status )
					: null,
			h(
				'fieldset',
				{ className: 'ec-studio-publish-targets' },
				createElement( 'legend', { className: 'ec-studio-publish-targets__legend' }, __( 'Publish to', 'extrachill-studio' ) ),
				createElement(
					'div',
					{ className: 'ec-studio-publish-targets__grid' },
					...Object.entries( SITE_TARGETS ).map( ( [ key, label ] ) =>
						createElement(
							'label',
							{
								key,
								className: 'ec-studio-publish-targets__item ec-studio-publish-targets__item--site',
							},
							createElement( 'input', {
								type: 'checkbox',
								checked: publishTargets.has( key ),
								onChange: () => toggleTarget( key ),
							} ),
							createElement( 'span', null, label )
						)
					)
				)
			),
				h(
					ActionRowView,
					{ className: 'ec-studio-composer__actions' },
					createElement(
						'button',
						{
						type: 'button',
						className: 'button-1 button-medium',
						onClick: submitForReview,
						disabled: isSubmitting || isSwitching || ! editorReady,
					},
					isSubmitting ? __( 'Submitting…', 'extrachill-studio' ) : __( 'Submit for Review', 'extrachill-studio' )
				),
				createElement(
					'button',
					{
						type: 'button',
						className: 'button-1 button-medium button-secondary',
						onClick: saveDraft,
						disabled: isSubmitting || isSwitching || ! editorReady,
					},
					isSubmitting ? __( 'Saving…', 'extrachill-studio' ) : (
						activePostId
							? __( 'Update Draft', 'extrachill-studio' )
							: __( 'Save Draft', 'extrachill-studio' )
					)
					)
				)
			),
				h(
					PanelView,
					{ className: 'ec-studio-panel ec-studio-panel--compose-sidebar', compact: true },
					h( PanelHeader, {
						description: __( 'Browse blocks and structure without crowding the writing canvas.', 'extrachill-studio' ),
					} ),
					createElement( 'div', {
						className: 'ec-studio-compose-sidebar__slot',
					} )
				)
		)
	);
};

export default ComposePane;
