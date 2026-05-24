import { __ } from '@wordpress/i18n';
import { createElement, useEffect, useRef, useState, useCallback } from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { select } from '@wordpress/data';
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
	modified_gmt?: string;
}

interface WpAutosave {
	id: number;
	parent: number;
	author: number;
	title?: { rendered: string; raw?: string };
	content?: { rendered: string; raw?: string };
	modified?: string;
	modified_gmt?: string;
}

/** Autosave debounce interval in milliseconds. */
const AUTOSAVE_DELAY = 2000;
const CLIENT_CONTEXT_UPDATE_DELAY = 250;
const CLIENT_CONTEXT_PROVIDER_ID = 'extrachill-studio.compose';

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
 * Auto-loads the most recent draft on mount. Autosaves every 2s — creates
 * a new draft on first activity when none is active (title or content must
 * be non-empty), then updates the same draft on subsequent autosaves.
 */
const ComposePane = ( props: StudioPaneProps ): ReactElement => {
	const restNonce = props.context.restNonce;
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

	// Autosave tracking refs.
	const autosaveTimerRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const lastSavedPayloadRef = useRef( '' );
	const isAutosavingRef = useRef( false );
	const inFlightPromiseRef = useRef< Promise< void > | null >( null );
	const pendingRerunRef = useRef( false );
	const consecutiveFailuresRef = useRef( 0 );
	const autosaveErrorActiveRef = useRef( false );
	const hasUnsavedChangesRef = useRef( false );
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

	/**
	 * Fetch the current user's drafts from the REST API.
	 *
	 * Scoped to the current user via the author filter so users with
	 * edit_others_posts (editors, admins) do not see and hot-swap into
	 * other authors' drafts via the Compose draft picker.
	 */
	const loadDrafts = useCallback( async (): Promise< WpPost[] > => {
		try {
			const currentUser = ( select( 'core' ) as { getCurrentUser?: () => { id?: number } | undefined } )
				.getCurrentUser?.();
			const userId = currentUser?.id;
			let path = '/wp/v2/posts?status=draft&per_page=20&orderby=modified&order=desc&context=edit';
			if ( userId ) {
				path += `&author=${ userId }`;
			}
			// If userId is genuinely unavailable client-side, fall through without
			// the author filter rather than ship a half-broken query. In practice
			// @wordpress/data core selector should always resolve for logged-in
			// users on Studio (gated by team-member capability check server-side).
			const result = await apiFetch< WpPost[] >( { path } );
			return Array.isArray( result ) ? result : [];
		} catch {
			return [];
		}
	}, [] );

	/**
	 * Flush any unsaved changes for the current draft before switching away.
	 * Creates a new draft on first save if no active post ID exists.
	 * Returns immediately if nothing to save.
	 */
	const flushCurrentDraft = useCallback( async (): Promise< void > => {
		if ( autosaveTimerRef.current ) {
			clearTimeout( autosaveTimerRef.current );
			autosaveTimerRef.current = null;
		}

		// If a save is in flight, wait for it to finish instead of bailing.
		// Bailing here silently discards keystrokes when the user switches
		// drafts during an in-flight save. Capture the promise locally so we
		// don't deadlock on our own subsequent save.
		const inFlight = inFlightPromiseRef.current;
		if ( inFlight ) {
			try {
				await inFlight;
			} catch {
				// In-flight save already handled its own errors; proceed.
			}
		}

		const postId = activePostIdRef.current;
		const currentTitle = titleRef.current.trim();
		const currentContent = getContent().trim();

		// Preserve empty-content guard: never create empty drafts.
		if ( ! currentTitle && ! currentContent ) {
			return;
		}

		const payload = JSON.stringify( { title: currentTitle, content: currentContent } );
		if ( payload === lastSavedPayloadRef.current ) {
			return;
		}

		isAutosavingRef.current = true;

		// Expose our save as the in-flight promise so any concurrent autosave
		// or flush waits for us instead of racing.
		const operation = ( async (): Promise< void > => {
			try {
				// Initial create: POST /wp/v2/posts with status=draft.
				// Subsequent saves: POST /wp/v2/posts/<id>/autosaves (no status — preserves
				// parent status, so out-of-band transitions to pending/publish are not demoted).
				// The /autosaves endpoint returns a revision object (id = revision ID, parent = post ID).
				// Do NOT overwrite activePostIdRef with the revision ID.
				if ( postId ) {
					await apiFetch( {
						path: `/wp/v2/posts/${ postId }/autosaves`,
						method: 'POST',
						data: { title: currentTitle, content: currentContent },
					} );
				} else {
					const post = await apiFetch< WpPost >( {
						path: '/wp/v2/posts',
						method: 'POST',
						data: { title: currentTitle, content: currentContent, status: 'draft' },
					} );
					// Capture new ID on first-create so subsequent saves target the same draft.
					if ( post?.id ) {
						activePostIdRef.current = post.id;
						setActivePostId( post.id );
					}
				}
				lastSavedPayloadRef.current = payload;
			} catch {
				// Best-effort — don't block the switch.
			} finally {
				isAutosavingRef.current = false;
				inFlightPromiseRef.current = null;
			}
		} )();

		inFlightPromiseRef.current = operation;
		await operation;
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
			setActivePostId( post.id );

			// Since autosaves migrated to /wp/v2/posts/<id>/autosaves (per-user
			// revision rows), the parent post body returned in the drafts list
			// is stale relative to in-flight typing. Check for a user autosave
			// newer than the parent and prefer it. Falls back to parent on any
			// error so the picker always works.
			let title = post.title.raw || post.title.rendered || '';
			let content = post.content.raw || post.content.rendered || '';
			try {
				const currentUser = ( select( 'core' ) as { getCurrentUser?: () => { id?: number } | undefined } )
					.getCurrentUser?.();
				const currentUserId = currentUser?.id;
				if ( currentUserId ) {
					const autosaves = await apiFetch< WpAutosave[] >( {
						path: `/wp/v2/posts/${ post.id }/autosaves?context=edit`,
					} );
					const userAutosave = Array.isArray( autosaves )
						? autosaves.find( ( a ) => a?.author === currentUserId )
						: null;
					if (
						userAutosave &&
						userAutosave.modified_gmt &&
						post.modified_gmt &&
						userAutosave.modified_gmt > post.modified_gmt
					) {
						title = userAutosave.title?.raw || title;
						content = userAutosave.content?.raw || content;
					}
				}
			} catch {
				// Best-effort recovery — fall back to parent content silently.
			}

			titleRef.current = title;
			setTitle( title );
			contentSnapshotRef.current = content;
			replaceEditorContent( content );
			lastSavedPayloadRef.current = JSON.stringify( { title, content } );
		} else {
			activePostIdRef.current = null;
			titleRef.current = '';
			contentSnapshotRef.current = '';
			setActivePostId( null );
			setTitle( '' );
			replaceEditorContent( '' );
			lastSavedPayloadRef.current = '';
		}

		setHasUnsavedChanges( false );
		setError( '' );
		setStatus( '' );
		scheduleClientContextUpdate();
	}, [ flushCurrentDraft, scheduleClientContextUpdate ] );

	const startNew = useCallback( async (): Promise< void > => {
		await switchToDraft( null );
	}, [ switchToDraft ] );

	/**
	 * Autosave the current draft silently. Creates a new draft on first
	 * activity when no active post ID exists, then captures the ID so
	 * subsequent autosaves update the same draft.
	 */
	const performAutosave = useCallback( async (): Promise< void > => {
		// If a save is already in flight, mark a rerun and bail; the in-flight
		// save's finally block will pick up the latest content when it completes.
		if ( isAutosavingRef.current ) {
			pendingRerunRef.current = true;
			return;
		}

		const postId = activePostIdRef.current;
		const currentTitle = titleRef.current.trim();
		const currentContent = getContent().trim();
		contentSnapshotRef.current = currentContent;

		// Preserve empty-content guard: never create empty drafts.
		// Opening Compose alone must not bootstrap a post.
		if ( ! currentTitle && ! currentContent ) {
			return;
		}

		const payload = JSON.stringify( { title: currentTitle, content: currentContent } );
		if ( payload === lastSavedPayloadRef.current ) {
			return;
		}

		isAutosavingRef.current = true;

		// Expose the in-flight operation as a promise so flushCurrentDraft (and
		// other callers that need to await a save) can wait for it without
		// re-issuing a duplicate request.
		const operation = ( async (): Promise< void > => {
			try {
				// Initial create: POST /wp/v2/posts with status=draft.
				// Subsequent autosaves: POST /wp/v2/posts/<id>/autosaves (no status — preserves
				// parent status, so out-of-band transitions to pending/publish are not demoted).
				// The /autosaves endpoint returns a revision object (id = revision ID, parent = post ID).
				// Do NOT overwrite activePostIdRef with the revision ID — the parent ID is stable.
				if ( postId ) {
					await apiFetch( {
						path: `/wp/v2/posts/${ postId }/autosaves`,
						method: 'POST',
						data: { title: currentTitle, content: currentContent },
					} );
				} else {
					const post = await apiFetch< WpPost >( {
						path: '/wp/v2/posts',
						method: 'POST',
						data: { title: currentTitle, content: currentContent, status: 'draft' },
					} );
					// Capture new ID on first-create so subsequent autosaves
					// update the same draft instead of creating duplicates.
					if ( post?.id ) {
						activePostIdRef.current = post.id;
						setActivePostId( post.id );
					}
				}
				lastSavedPayloadRef.current = payload;
				setHasUnsavedChanges( false );
				// Successful save — reset failure counter and clear any prior
				// autosave error message (but not manual save errors).
				consecutiveFailuresRef.current = 0;
				if ( autosaveErrorActiveRef.current ) {
					autosaveErrorActiveRef.current = false;
					setError( '' );
				}
			} catch ( err ) {
				consecutiveFailuresRef.current += 1;
				// Surface the error in the UI after 2 consecutive failures so a
				// single transient blip doesn't spook the user, but real
				// outages become visible.
				if ( consecutiveFailuresRef.current >= 2 ) {
					const message = ( err as Error )?.message || __( 'Unknown error', 'extrachill-studio' );
					autosaveErrorActiveRef.current = true;
					setError(
						/* translators: 1: number of consecutive failures, 2: error message */
						`${ __( 'Autosave failed', 'extrachill-studio' ) } (${ consecutiveFailuresRef.current } ${ __( 'attempt(s)', 'extrachill-studio' ) }): ${ message }`
					);
				}
			} finally {
				isAutosavingRef.current = false;
				inFlightPromiseRef.current = null;
				// Re-run if input arrived while we were saving so the latest
				// content reaches the server instead of being silently dropped.
				if ( pendingRerunRef.current ) {
					pendingRerunRef.current = false;
					performAutosave();
				}
			}
		} )();

		inFlightPromiseRef.current = operation;
		await operation;
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

	// Sync hasUnsavedChanges state into a ref so the beforeunload/pagehide
	// listeners (registered once with stale closures) can read the latest
	// value without re-binding on every change.
	useEffect( () => {
		hasUnsavedChangesRef.current = hasUnsavedChanges;
	}, [ hasUnsavedChanges ] );

	// beforeunload guard: native browser prompt when navigating away with
	// unsaved changes. Registered once; the handler reads from a ref.
	useEffect( () => {
		const beforeUnloadHandler = ( e: BeforeUnloadEvent ): void => {
			if ( hasUnsavedChangesRef.current ) {
				e.preventDefault();
				// Legacy property required by some browsers for the prompt to fire.
				e.returnValue = '';
			}
		};

		// pagehide + sendBeacon: best-effort last-write when the tab is being
		// torn down. Fires for tab close, navigation, and bfcache suspension.
		// sendBeacon is the only request that's guaranteed to be delivered
		// during unload — apiFetch/XHR will be cancelled.
		//
		// Limitation: when no postId exists yet (initial draft never created),
		// there's no /autosaves endpoint to target. We accept that loss; the
		// next session will start blank rather than firing a synchronous POST
		// to /wp/v2/posts (which sendBeacon also can't reliably do for create
		// because we'd never see the returned ID).
		const pageHideHandler = (): void => {
			if ( ! hasUnsavedChangesRef.current ) {
				return;
			}
			const postId = activePostIdRef.current;
			if ( ! postId ) {
				return;
			}
			try {
				const body = JSON.stringify( {
					title: titleRef.current.trim(),
					content: contentSnapshotRef.current.trim(),
				} );
				const blob = new Blob( [ body ], { type: 'application/json' } );
				const url = `/wp-json/wp/v2/posts/${ postId }/autosaves?_wpnonce=${ encodeURIComponent( restNonce ) }`;
				navigator.sendBeacon( url, blob );
			} catch {
				// Best-effort — nothing else we can do during unload.
			}
		};

		window.addEventListener( 'beforeunload', beforeUnloadHandler );
		window.addEventListener( 'pagehide', pageHideHandler );
		return () => {
			window.removeEventListener( 'beforeunload', beforeUnloadHandler );
			window.removeEventListener( 'pagehide', pageHideHandler );
		};
	}, [ restNonce ] );

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

			await apiFetch< WpPost >( {
				path,
				method: 'POST',
				data: {
					title: title.trim(),
					content,
					status: 'pending',
				},
			} );

			activePostIdRef.current = null;
			titleRef.current = '';
			contentSnapshotRef.current = '';

			setStatus( __( 'Submitted for review.', 'extrachill-studio' ) );

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
					description: __( 'Draft blog content and submit for editorial review.', 'extrachill-studio' ),
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
