/**
 * External dependencies
 */
import type { ReactElement, ChangeEvent } from 'react';
import {
	getOrCreateClientContextRegistry,
	registerClientContextProvider,
} from '@extrachill/chat';
import { ActionRow, FieldGroup, InlineStatus, Panel, PanelHeader } from '@extrachill/components';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { select } from '@wordpress/data';
import { createElement, useEffect, useRef, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { StudioPaneProps } from '../../types/studio';
import { markComposeRequest, registerComposeInstance } from './cross-site-middleware';
import { installChatRefreshAdapter } from './refresh-adapter';
import { openComposePreview } from './preview';
import type { AutosavePreviewResponse, ComposeSnapshot } from './preview';
import { recoverDraftContent } from './draft-recovery';
import type { WpAutosave, WpPost } from './draft-recovery';

/**
 * apiFetch wrapper that tags a request as Compose-pane-originated so the
 * cross-site middleware ALWAYS rewrites it to main (blog 1), with no dependence
 * on the live-instance count and no timing window. Every apiFetch call this
 * pane makes goes through here so a stray call can never escape to blog 12.
 * See cross-site-middleware.ts and Extra-Chill/extrachill-studio#106.
 *
 * @param options API fetch options.
 * @return Tagged API fetch promise.
 */
const composeApiFetch = < T = unknown >(
	options: import('@wordpress/api-fetch').APIFetchOptions< true >
): Promise< T > => apiFetch< T >( markComposeRequest( options ) );

const h = createElement as typeof import( 'react' ).createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const ActionRowView = ActionRow as unknown as ( props: any ) => ReactElement;
const FieldGroupView = FieldGroup as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as ( props: any ) => ReactElement;

declare global {
	interface Window {
		blocksEverywhereCreateEditor?: (
			textarea: HTMLTextAreaElement,
			options?: { settings?: Record< string, unknown > }
		) => void;
		blocksEverywhereGetContentApi?: ( textarea: HTMLTextAreaElement ) => BlocksEverywhereContentApi | null;
	}
}

interface BlocksEverywhereContentApi {
	replaceContent: ( html: string ) => void;
	getContent: () => string;
	getBlocks: () => object[];
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
 *
 * @param props Studio pane props.
 * @return Compose pane element.
 */
const ComposePane = ( props: StudioPaneProps ): ReactElement => {
	const restNonce = props.context.restNonce;
	const textareaRef = useRef< HTMLTextAreaElement >( null );
	const editorMountedRef = useRef( false );

	const [ title, setTitle ] = useState( '' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ isPreviewing, setIsPreviewing ] = useState( false );
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
	const isPreviewingRef = useRef( false );
	const manualSavePromiseRef = useRef< Promise< void > | null >( null );
	const consecutiveFailuresRef = useRef( 0 );
	const autosaveErrorActiveRef = useRef( false );
	// Set while an external-edit refresh is applying (Roadie accept → BE
	// receiver). Blocks autosave from issuing a stale-content write that could
	// land after the refresh resets the baseline and re-clobber the accepted
	// edit. See refreshConfigRef below.
	const isRefreshingRef = useRef( false );
	// Watchdog that force-clears isRefreshingRef if a refresh starts but never
	// settles (e.g. BE's receiver bails before onRefreshed because the content
	// API was transiently unavailable). Prevents a stuck guard from disabling
	// autosave permanently.
	const refreshWatchdogRef = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);
	// Monotonic edit counter. Incremented on every title/content edit. Captured
	// at the start of an autosave so we can detect edits that land during the
	// in-flight window and avoid marking those keystrokes as "saved".
	const editSeqRef = useRef( 0 );
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
	const getContentApi = useCallback( (): BlocksEverywhereContentApi | null => {
		if ( ! textareaRef.current || ! window.blocksEverywhereGetContentApi ) {
			return null;
		}
		return window.blocksEverywhereGetContentApi( textareaRef.current );
	}, [] );

	/** Read serialized block content — prefer content API, fall back to textarea. */
	const getContent = useCallback( (): string => {
		const api = getContentApi();
		if ( api ) {
			return api.getContent();
		}
		return textareaRef.current?.value || '';
	}, [ getContentApi ] );

	/**
	 * Replace editor content using the ContentBridge API.
	 *
	 * @param html Serialized block content.
	 */
	const replaceEditorContent = useCallback( ( html: string ): void => {
		const api = getContentApi();
		if ( api ) {
			api.replaceContent( html );
		}
	}, [ getContentApi ] );

	/**
	 * Refetch the current content of a post from main via the compose
	 * cross-site proxy (active while the Blog tab is mounted). Returns the
	 * post's raw block markup, or an empty string on any failure.
	 */
	const fetchPostContent = useCallback(
		async ( postId: number ): Promise< string > => {
			try {
				const post = await composeApiFetch< WpPost >( {
					path: `/wp/v2/posts/${ postId }?context=edit`,
				} );
				return post?.content?.raw || post?.content?.rendered || '';
			} catch {
				return '';
			}
		},
		[]
	);

	/**
	 * Blocks Everywhere external-edit refresh wiring.
	 *
	 * When Roadie writes the active draft (via the chat accept path → the
	 * refresh adapter → BE's receiver), the open editor refetches and replaces
	 * its content. Studio owns post identity, the fetch, and the autosave
	 * baseline, so it supplies all four hooks; BE owns the replace + post-id
	 * matching. Held in a ref so the object identity is stable across renders
	 * (BE reads it once at mount) while the getters read live values.
	 */
	const refreshConfigRef = useRef( {
		// Getter — the active draft changes via the draft picker over the
		// editor's lifetime, so BE must read the current id per event.
		watchPostId: (): number | null => activePostIdRef.current,
		fetchContent: async (): Promise< string > => {
			const postId = activePostIdRef.current;
			if ( ! postId ) {
				return '';
			}
			return fetchPostContent( postId );
		},
		beforeRefresh: async (): Promise< void > => {
			// Mark the refresh in progress BEFORE awaiting anything. This makes
			// performAutosave (and the in-flight save's finally re-run) bail, so
			// no stale-content write can be issued while we refetch + replace.
			// The flag is cleared in onRefreshed after the baseline is reset.
			isRefreshingRef.current = true;

			// Arm a watchdog: if onRefreshed never fires (BE bailed before the
			// replace), force-clear the guard so autosave isn't disabled forever.
			if ( refreshWatchdogRef.current ) {
				clearTimeout( refreshWatchdogRef.current );
			}
			refreshWatchdogRef.current = setTimeout( () => {
				refreshWatchdogRef.current = null;
				isRefreshingRef.current = false;
			}, 15000 );

			// Cancel a pending debounced autosave.
			if ( autosaveTimerRef.current ) {
				clearTimeout( autosaveTimerRef.current );
				autosaveTimerRef.current = null;
			}

			// Drain any in-flight save to quiescence. A save that completes here
			// could, in its finally, synchronously start one more (pendingRerun)
			// BEFORE the guard above takes effect for that closure; loop until no
			// save is in flight so the wire is clear before we replace content.
			// Bounded so a pathological save loop cannot hang the refresh.
			for ( let i = 0; i < 5; i++ ) {
				const inFlight = inFlightPromiseRef.current;
				if ( ! inFlight ) {
					break;
				}
				try {
					await inFlight;
				} catch {
					// In-flight save handled its own errors; proceed.
				}
			}
		},
		onRefreshed: ( html: string ): void => {
			// Reset the autosave baseline to the freshly-applied content so the
			// NEXT autosave carries the external edit forward instead of
			// re-clobbering it with the stale in-memory snapshot.
			contentSnapshotRef.current = html;
			lastSavedPayloadRef.current = JSON.stringify( {
				title: titleRef.current.trim(),
				content: html.trim(),
			} );
			// Advance the edit sequence so any autosave snapshot captured before
			// the refresh is treated as superseded and won't mark the draft clean
			// against stale content if it somehow completes late.
			editSeqRef.current += 1;
			setHasUnsavedChanges( false );
			scheduleClientContextUpdate();

			// Release the autosave guard now the baseline reflects the applied
			// content. Subsequent edits autosave normally from the new baseline.
			if ( refreshWatchdogRef.current ) {
				clearTimeout( refreshWatchdogRef.current );
				refreshWatchdogRef.current = null;
			}
			isRefreshingRef.current = false;
		},
	} );

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
			const result = await composeApiFetch< WpPost[] >( { path } );
			return Array.isArray( result ) ? result : [];
		} catch {
			return [];
		}
	}, [] );

	const loadDraftContent = useCallback( async ( post: WpPost ) => {
		const currentUser = (
			select( 'core' ) as {
				getCurrentUser?: () => { id?: number } | undefined;
			}
		).getCurrentUser?.();

		return recoverDraftContent( post, currentUser?.id, ( postId ) =>
			composeApiFetch< WpAutosave[] >( {
				path: `/wp/v2/posts/${ postId }/autosaves?context=edit`,
			} )
		);
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
					await composeApiFetch( {
						path: `/wp/v2/posts/${ postId }/autosaves`,
						method: 'POST',
						data: { title: currentTitle, content: currentContent },
					} );
				} else {
					const post = await composeApiFetch< WpPost >( {
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
	}, [ getContent ] );

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
			const {
				title: recoveredTitle,
				content: recoveredContent,
			} = await loadDraftContent( post );

			titleRef.current = recoveredTitle;
			setTitle( recoveredTitle );
			contentSnapshotRef.current = recoveredContent;
			replaceEditorContent( recoveredContent );
			lastSavedPayloadRef.current = JSON.stringify( {
				title: recoveredTitle,
				content: recoveredContent,
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

		setHasUnsavedChanges( false );
		setError( '' );
		setStatus( '' );
		scheduleClientContextUpdate();
	}, [ flushCurrentDraft, loadDraftContent, replaceEditorContent, scheduleClientContextUpdate ] );

	const startNew = useCallback( async (): Promise< void > => {
		if ( isPreviewingRef.current ) {
			return;
		}
		await switchToDraft( null );
	}, [ switchToDraft ] );

	/**
	 * Autosave the current draft silently. Creates a new draft on first
	 * activity when no active post ID exists, then captures the ID so
	 * subsequent autosaves update the same draft.
	 */
	const performAutosave = useCallback( async (): Promise< void > => {
		// An external-edit refresh is applying the accepted content. Suppress
		// this save entirely — issuing a stale-content write now would race the
		// refresh's baseline reset and could re-clobber the accepted edit. Do
		// NOT set pendingRerunRef: the refresh's onRefreshed makes the editor
		// the new baseline, so there is nothing stale left to flush afterward.
		if ( isRefreshingRef.current ) {
			return;
		}

		// Preview owns a complete live snapshot write. Queue normal autosave so
		// it cannot race the preview autosave or duplicate a first draft create.
		if ( isPreviewingRef.current ) {
			pendingRerunRef.current = true;
			return;
		}

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

		// Capture the edit sequence for this snapshot. If the user types during
		// the in-flight await, editSeqRef advances past this value and we must
		// NOT mark the draft clean — the trailing keystrokes aren't on the
		// server yet.
		const savedSeq = editSeqRef.current;

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
					await composeApiFetch( {
						path: `/wp/v2/posts/${ postId }/autosaves`,
						method: 'POST',
						data: { title: currentTitle, content: currentContent },
					} );
				} else {
					const post = await composeApiFetch< WpPost >( {
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
				// Only record this payload as the saved baseline and clear the
				// unsaved flag if no edit landed during the in-flight window.
				// If editSeqRef advanced, the user typed while we were saving —
				// those keystrokes are unsaved, so keep hasUnsavedChanges true
				// and schedule a re-run to flush them.
				if ( editSeqRef.current === savedSeq ) {
					lastSavedPayloadRef.current = payload;
					setHasUnsavedChanges( false );
				} else {
					pendingRerunRef.current = true;
				}
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
				// Suppress the re-run while an external-edit refresh is applying:
				// re-running here would issue a stale-content write that races the
				// refresh and re-clobbers the accepted edit. performAutosave's own
				// guard also bails, but clearing the flag here keeps the pending
				// state clean so a post-refresh edit is not treated as already
				// queued.
				if ( pendingRerunRef.current ) {
					pendingRerunRef.current = false;
					if ( ! isRefreshingRef.current ) {
						performAutosave();
					}
				}
			}
		} )();

		inFlightPromiseRef.current = operation;
		await operation;
	}, [ getContent ] );

	const scheduleAutosave = useCallback( (): void => {
		if ( autosaveTimerRef.current ) {
			clearTimeout( autosaveTimerRef.current );
		}
		autosaveTimerRef.current = setTimeout( () => {
			autosaveTimerRef.current = null;
			performAutosave();
		}, AUTOSAVE_DELAY );
	}, [ performAutosave ] );

	// Register a live cross-site compose instance for the lifetime of this pane.
	// Declared before the draft-load/editor-mount effect so the instance is
	// live before the first /wp/v2/posts fetch — or the block editor's own
	// internal autosave/upload calls — can fire. The disposer decrements the
	// reference count on unmount. This covers the block editor's OWN internal
	// calls (which we can't mark at their source); the pane's own calls are
	// additionally tagged via composeApiFetch so they route to main even if the
	// count were somehow zero. See Extra-Chill/extrachill-studio#106.
	useEffect( () => {
		const dispose = registerComposeInstance();
		return dispose;
	}, [] );

	// Bridge the chat's action-resolved event to BE's refresh-content event for
	// the lifetime of this pane. The adapter is the only place both event names
	// co-exist; BE's receiver (configured via refreshConfigRef below) matches
	// the post id and ignores events for any other draft.
	useEffect( () => {
		const cleanupAdapter = installChatRefreshAdapter();
		return () => {
			cleanupAdapter();
			if ( refreshWatchdogRef.current ) {
				clearTimeout( refreshWatchdogRef.current );
				refreshWatchdogRef.current = null;
			}
		};
	}, [] );

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
			const initialDraft = result.length > 0
				? await loadDraftContent( result[ 0 ] )
				: null;

			if ( cancelled ) {
				return;
			}

			setDrafts( result );
			setIsLoadingDrafts( false );

			// Pre-fill textarea with the most recent draft.
			if ( result.length > 0 && textareaRef.current ) {
				const post = result[ 0 ];
				const { title: initialTitle, content: initialContent } = initialDraft!;
				activePostIdRef.current = post.id;
				titleRef.current = initialTitle;
				setActivePostId( post.id );
				setTitle( initialTitle );
				textareaRef.current.value = initialContent;
				contentSnapshotRef.current = initialContent;
				lastSavedPayloadRef.current = JSON.stringify( {
					title: initialTitle,
					content: initialContent,
				} );
			} else {
				activePostIdRef.current = null;
				titleRef.current = '';
				contentSnapshotRef.current = '';
			}

			// Mount the editor — it reads textarea.value via onLoad. Pass the
			// external-edit refresh wiring so an accepted Roadie edit refreshes
			// the open editor instead of being clobbered by the next autosave.
			if ( ! editorMountedRef.current && textareaRef.current ) {
				if ( typeof window.blocksEverywhereCreateEditor === 'function' ) {
					window.blocksEverywhereCreateEditor( textareaRef.current, {
						settings: {
							blocksEverywhere: {
								refresh: refreshConfigRef.current,
							},
						},
					} );
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
	}, [ loadDraftContent, loadDrafts, scheduleClientContextUpdate ] );



	// Listen for content changes on the textarea for autosave.
	useEffect( () => {
		const textarea = textareaRef.current;
		if ( ! textarea ) {
			return;
		}

		const onContentChange = (): void => {
			editSeqRef.current += 1;
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
	}, [ getContent, scheduleAutosave, performAutosave, scheduleClientContextUpdate ] );

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
				// The draft lives on main (blog 1), so this unload beacon must
				// target the Studio cross-site proxy route — not the bare
				// /wp/v2 endpoint, which would write to Studio (blog 12). The
				// apiFetch middleware can't help here: sendBeacon bypasses
				// apiFetch entirely, so the proxy path is hardcoded.
				const url = `/wp-json/extrachill/v1/studio/compose/posts/${ postId }/autosaves?_wpnonce=${ encodeURIComponent( restNonce ) }`;
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

	const previewPost = async (): Promise< void > => {
		// This ref changes synchronously, unlike button disabled state, so a fast
		// double click cannot create two first drafts or two preview writes.
		if ( isPreviewingRef.current ) {
			return;
		}

		isPreviewingRef.current = true;
		setIsPreviewing( true );
		setError( '' );
		setStatus( __( 'Preparing preview…', 'extrachill-studio' ) );

		try {
			const result = await openComposePreview( {
				openWindow: () => window.open( '', 'ec-studio-compose-preview' ),
				cancelPendingSave: () => {
					if ( autosaveTimerRef.current ) {
						clearTimeout( autosaveTimerRef.current );
						autosaveTimerRef.current = null;
					}
				},
				waitForPendingSaves: async () => {
					// An autosave can schedule one trailing rerun in its finally block.
					// Drain the live refs until both write paths are quiescent.
					while ( inFlightPromiseRef.current || manualSavePromiseRef.current ) {
						await ( inFlightPromiseRef.current || manualSavePromiseRef.current );
					}
				},
				getSnapshot: (): ComposeSnapshot => ( {
					title: titleRef.current.trim(),
					content: getContent().trim(),
				} ),
				getParentId: () => activePostIdRef.current,
				createDraft: async ( snapshot ) => {
					const post = await composeApiFetch< WpPost >( {
						path: '/wp/v2/posts',
						method: 'POST',
						data: { ...snapshot, status: 'draft' },
					} );
					return post.id;
				},
				setParentId: ( postId ) => {
					activePostIdRef.current = postId;
					setActivePostId( postId );
				},
				createAutosave: ( postId, snapshot ) => composeApiFetch< AutosavePreviewResponse >( {
					path: `/wp/v2/posts/${ postId }/autosaves`,
					method: 'POST',
					data: snapshot,
				} ),
			} );

			const savedPayload = JSON.stringify( result.snapshot );
			const currentPayload = JSON.stringify( {
				title: titleRef.current.trim(),
				content: getContent().trim(),
			} );
			lastSavedPayloadRef.current = savedPayload;
			setHasUnsavedChanges( currentPayload !== savedPayload );
			if ( currentPayload !== savedPayload ) {
				pendingRerunRef.current = true;
			}
			setStatus( __( 'Preview opened in a new tab.', 'extrachill-studio' ) );
		} catch ( previewError ) {
			setStatus( '' );
			setError(
				( previewError as Error )?.message ||
				__( 'Failed to open the post preview. Try again.', 'extrachill-studio' )
			);
		} finally {
			isPreviewingRef.current = false;
			setIsPreviewing( false );
			if ( pendingRerunRef.current ) {
				pendingRerunRef.current = false;
				performAutosave();
			}
		}
	};

	const submitForReview = async (): Promise< void > => {
		if ( isPreviewingRef.current ) {
			return;
		}

		if ( ! title.trim() ) {
			setError( __( 'Add a title before submitting.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		const content = getContent();

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

			await composeApiFetch< WpPost >( {
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
		if ( isPreviewingRef.current ) {
			return;
		}

		const content = getContent();
		const currentTitle = titleRef.current.trim();

		if ( ! currentTitle && ! content.trim() ) {
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

		const operation = ( async (): Promise< void > => {
			try {
				const postId = activePostIdRef.current;
				const path = postId ? `/wp/v2/posts/${ postId }` : '/wp/v2/posts';
				const post = await composeApiFetch< WpPost >( {
					path,
					method: 'POST',
					data: { title: currentTitle, content, status: 'draft' },
				} );

				activePostIdRef.current = post.id;
				titleRef.current = currentTitle;
				contentSnapshotRef.current = content;
				setActivePostId( post.id );
				lastSavedPayloadRef.current = JSON.stringify( { title: currentTitle, content } );
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
				manualSavePromiseRef.current = null;
			}
		} )();

		manualSavePromiseRef.current = operation;
		await operation;
	};

	const onDraftSelect = async ( e: ChangeEvent< HTMLSelectElement > ): Promise< void > => {
		if ( isPreviewingRef.current ) {
			return;
		}

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
		editSeqRef.current += 1;
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
			disabled: isLoadingDrafts || isSwitching || isPreviewing,
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
	let saveButtonLabel: string = __( 'Save Draft', 'extrachill-studio' );
	if ( isSubmitting ) {
		saveButtonLabel = __( 'Saving…', 'extrachill-studio' );
	} else if ( activePostId ) {
		saveButtonLabel = __( 'Update Draft', 'extrachill-studio' );
	}

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
							createElement(
								'a',
								{
									className: 'button-1 button-small button-secondary',
									href: 'https://docs.extrachill.com/studio/team-contribution-guide/',
									target: '_blank',
									rel: 'noopener noreferrer',
								},
								__( 'Contribution Guide', 'extrachill-studio' )
							),
							draftPicker,
							createElement(
								'button',
								{
									type: 'button',
									className: 'button-1 button-small',
									onClick: startNew,
									disabled: isSubmitting || isSwitching || isPreviewing || ! activePostId,
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
							disabled: isSubmitting || isSwitching || isPreviewing || ! editorReady,
						},
						isSubmitting ? __( 'Submitting…', 'extrachill-studio' ) : __( 'Submit for Review', 'extrachill-studio' )
					),
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium button-secondary',
							onClick: previewPost,
							disabled: isSubmitting || isSwitching || isPreviewing || ! editorReady,
							'aria-label': __( 'Preview post on extrachill.com in a new tab', 'extrachill-studio' ),
						},
						isPreviewing ? __( 'Preparing Preview…', 'extrachill-studio' ) : __( 'Preview in New Tab', 'extrachill-studio' )
					),
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium button-secondary',
							onClick: saveDraft,
							disabled: isSubmitting || isSwitching || isPreviewing || ! editorReady,
						},
						saveButtonLabel
					)
				)
			),
				h(
					PanelView,
					{ className: 'ec-studio-panel ec-studio-panel--compose-sidebar', compact: true },
					h( PanelHeader, {
						description: __( 'Add blocks to build your post.', 'extrachill-studio' ),
					} ),
					createElement( 'div', {
						className: 'ec-studio-compose-sidebar__slot',
					} )
				)
		)
	);
};

export default ComposePane;
