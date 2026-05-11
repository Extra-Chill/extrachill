/**
 * Transcribe Pane — Direct headless audio transcription via sweatpants.
 *
 * Flow (browser ↔ sweatpants directly, NO server-side polling):
 *   1. Mint short-lived HMAC token via `extrachill/sweatpants-token` ability.
 *   2. POST audio multipart → `https://sweatpants.chubes.net/uploads`.
 *   3. POST job spec → `https://sweatpants.chubes.net/jobs` (audio-transcription).
 *   4. Poll GET /jobs/{id} every 5s until terminal status.
 *   5. GET /jobs/{id}/results and pull `data.content.transcription`
 *      (or `combined_txt` / `combined_txt_clean` for diarized presets).
 *
 * Persistence: jobs live only in this component's React state — no local
 * store. Past transcriptions disappear when the user navigates away.
 *
 * @package ExtraChillStudio
 */

import { __, sprintf } from '@wordpress/i18n';
import { createElement, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import type { ChangeEvent, DragEvent, ReactElement } from 'react';
import { ActionRow, FieldGroup, InlineStatus, Panel, PanelHeader } from '@extrachill/components';

import type { StudioPaneProps } from '../../types/studio';
import { createJob, getJob, getResults, MAX_UPLOAD_BYTES, uploadAudio } from './client';
import type {
	ActiveJob,
	SweatpantsJobStatus,
	TranscribePreset,
} from './types';

const h = createElement as typeof import( 'react' ).createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const ActionRowView = ActionRow as unknown as ( props: any ) => ReactElement;
const FieldGroupView = FieldGroup as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as ( props: any ) => ReactElement;

/** Polling interval while jobs are pending or running (ms). */
const POLL_INTERVAL_MS = 5000;

/**
 * After this many ms of being on the page with an in-flight job, stop the
 * polling loop. The user is told up-front that they can close the tab and
 * we'll email them — at that point the polling adds no value (it just
 * burns network on a job that may take hours). Short jobs (`base` model
 * on ~30s audio) finish well before this threshold so polling still
 * delivers instant feedback for users who DO stay on the page.
 */
const POLLING_HANDOFF_MS = 90 * 1000;

/** Time to flash "Copied" inline feedback after a successful clipboard write. */
const COPY_FEEDBACK_MS = 2000;

interface PresetConfig {
	model: 'base' | 'medium' | 'large';
	diarize: boolean;
	removeFillers: boolean;
	label: string;
	description: string;
}

/**
 * Map preset → sweatpants module inputs.
 *
 * The estimates are approximate and only used as user-visible hints.
 */
const PRESET_CONFIG: Record< TranscribePreset, PresetConfig > = {
	quick: {
		model: 'base',
		diarize: false,
		removeFillers: false,
		label: __( 'Quick draft', 'extrachill-studio' ),
		description: __( 'base model · ~10 min for 16-min audio · lower quality', 'extrachill-studio' ),
	},
	standard: {
		model: 'medium',
		diarize: false,
		removeFillers: false,
		label: __( 'Standard', 'extrachill-studio' ),
		description: __( 'medium model · ~90 min · good quality', 'extrachill-studio' ),
	},
	publish: {
		model: 'large',
		diarize: true,
		removeFillers: true,
		label: __( 'Publish quality', 'extrachill-studio' ),
		description: __( 'large model · ~3 hr · best quality, speaker labels, fillers removed', 'extrachill-studio' ),
	},
};

const TERMINAL_STATUSES: ReadonlyArray< SweatpantsJobStatus > = [ 'completed', 'failed', 'stopped' ];

const isTerminal = ( status: SweatpantsJobStatus ): boolean => TERMINAL_STATUSES.includes( status );

/**
 * Pick the best transcript field from the results `content` map.
 *
 * Priority (most-processed first):
 *   1. combined_txt_clean (diarized + filler-stripped)
 *   2. combined_txt (diarized)
 *   3. transcription (plain)
 */
const pickTranscript = ( content: Record< string, string > ): string => {
	if ( typeof content.combined_txt_clean === 'string' && content.combined_txt_clean.length > 0 ) {
		return content.combined_txt_clean;
	}
	if ( typeof content.combined_txt === 'string' && content.combined_txt.length > 0 ) {
		return content.combined_txt;
	}
	if ( typeof content.transcription === 'string' && content.transcription.length > 0 ) {
		return content.transcription;
	}
	return '';
};

/** Format byte count as MB with one decimal. */
const formatMB = ( bytes: number ): string => `${ ( bytes / 1024 / 1024 ).toFixed( 1 ) } MB`;

/** Format elapsed seconds as "M:SS". */
const formatElapsed = ( startedAt: number, completedAt?: number ): string => {
	const end = completedAt || Date.now();
	const seconds = Math.max( 0, Math.floor( ( end - startedAt ) / 1000 ) );
	const m = Math.floor( seconds / 60 );
	const s = seconds % 60;
	return `${ m }:${ s.toString().padStart( 2, '0' ) }`;
};

/** Strip extension off a filename for the .transcript.txt download name. */
const stripExtension = ( filename: string ): string => filename.replace( /\.\w+$/, '' );

/**
 * Trigger a browser blob download for a plain-text transcript.
 */
const downloadTranscriptBlob = ( transcript: string, originalFilename: string ): void => {
	const blob = new Blob( [ transcript ], { type: 'text/plain' } );
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = `${ stripExtension( originalFilename ) }.transcript.txt`;
	document.body.appendChild( a );
	a.click();
	document.body.removeChild( a );
	URL.revokeObjectURL( url );
};

const TranscribePane = ( _props: StudioPaneProps ): ReactElement => {
	const fileInputRef = useRef< HTMLInputElement >( null );

	const [ selectedFile, setSelectedFile ] = useState< File | null >( null );
	const [ preset, setPreset ] = useState< TranscribePreset >( 'standard' );
	const [ isDragging, setIsDragging ] = useState( false );

	/** Currently-running job, if any. Only one concurrent job in v1. */
	const [ activeJob, setActiveJob ] = useState< ActiveJob | null >( null );
	/** Completed/failed jobs from this session, newest first. */
	const [ history, setHistory ] = useState< ActiveJob[] >( [] );

	const [ stageMessage, setStageMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ copiedJobId, setCopiedJobId ] = useState< string | null >( null );
	const [ expandedJobId, setExpandedJobId ] = useState< string | null >( null );

	const pollTimerRef = useRef< ReturnType< typeof setInterval > | null >( null );
	const activeJobRef = useRef< ActiveJob | null >( null );
	activeJobRef.current = activeJob;

	const isBusy = !! activeJob && ! isTerminal( activeJob.status );

	// ── File selection ────────────────────────────────────────────────

	const validateAndSetFile = useCallback( ( file: File | null ): void => {
		setError( '' );
		setStageMessage( '' );

		if ( ! file ) {
			setSelectedFile( null );
			return;
		}

		if ( file.size > MAX_UPLOAD_BYTES ) {
			setSelectedFile( null );
			setError( sprintf(
				/* translators: %s: human-readable file size, e.g. "612.4 MB" */
				__( 'File is %s — sweatpants caps uploads at 500 MB.', 'extrachill-studio' ),
				formatMB( file.size )
			) );
			return;
		}

		setSelectedFile( file );
	}, [] );

	const onFilePickerChange = ( event: ChangeEvent< HTMLInputElement > ): void => {
		const file = event.target.files && event.target.files[ 0 ] ? event.target.files[ 0 ] : null;
		validateAndSetFile( file );
	};

	const onPickButtonClick = (): void => {
		fileInputRef.current?.click();
	};

	const onDragOver = ( event: DragEvent< HTMLDivElement > ): void => {
		event.preventDefault();
		event.stopPropagation();
		if ( ! isDragging ) {
			setIsDragging( true );
		}
	};

	const onDragLeave = ( event: DragEvent< HTMLDivElement > ): void => {
		event.preventDefault();
		event.stopPropagation();
		setIsDragging( false );
	};

	const onDrop = ( event: DragEvent< HTMLDivElement > ): void => {
		event.preventDefault();
		event.stopPropagation();
		setIsDragging( false );
		const file = event.dataTransfer.files && event.dataTransfer.files[ 0 ] ? event.dataTransfer.files[ 0 ] : null;
		validateAndSetFile( file );
	};

	// ── Polling lifecycle ─────────────────────────────────────────────

	const stopPolling = useCallback( (): void => {
		if ( pollTimerRef.current ) {
			clearInterval( pollTimerRef.current );
			pollTimerRef.current = null;
		}
	}, [] );

	/**
	 * Promote the active job into history once it reaches a terminal state.
	 * Pulls results for completed jobs; pulls error detail for failed jobs.
	 */
	const finalizeJob = useCallback( async ( job: ActiveJob ): Promise< void > => {
		stopPolling();

		if ( job.status === 'completed' ) {
			setStageMessage( __( 'Fetching transcript…', 'extrachill-studio' ) );
			try {
				const results = await getResults( job.jobId );
				const first = results.results && results.results[ 0 ];
				const transcript = first ? pickTranscript( first.data.content ) : '';
				const finalized: ActiveJob = {
					...job,
					completedAt: Date.now(),
					transcript: transcript || __( '(empty transcript)', 'extrachill-studio' ),
				};
				setActiveJob( null );
				setHistory( ( prev ) => [ finalized, ...prev ] );
				setExpandedJobId( finalized.jobId );
				setStageMessage( '' );
			} catch ( resultsErr ) {
				const failed: ActiveJob = {
					...job,
					status: 'failed',
					completedAt: Date.now(),
					error: ( resultsErr as Error )?.message || __( 'Failed to fetch results.', 'extrachill-studio' ),
				};
				setActiveJob( null );
				setHistory( ( prev ) => [ failed, ...prev ] );
				setStageMessage( '' );
				setError( failed.error || '' );
			}
			return;
		}

		// failed | stopped
		const failed: ActiveJob = {
			...job,
			completedAt: Date.now(),
			error: job.error || __( 'Job did not complete successfully.', 'extrachill-studio' ),
		};
		setActiveJob( null );
		setHistory( ( prev ) => [ failed, ...prev ] );
		setStageMessage( '' );
		setError( failed.error || '' );
	}, [ stopPolling ] );

	const pollOnce = useCallback( async (): Promise< void > => {
		const current = activeJobRef.current;
		if ( ! current || isTerminal( current.status ) ) {
			return;
		}

		// Pause polling while the browser tab is hidden — resume on focus.
		if ( typeof document !== 'undefined' && document.visibilityState === 'hidden' ) {
			return;
		}

		// After the handoff window, stop polling. The completion email is the
		// canonical notification path; in-tab polling forever is wasted work.
		const elapsedMs = Date.now() - current.startedAt;
		if ( elapsedMs > POLLING_HANDOFF_MS ) {
			stopPolling();
			setStageMessage(
				__(
					'Job is still running on the worker. We\'ll email you when it\'s done — you can close this tab.',
					'extrachill-studio'
				)
			);
			return;
		}

		try {
			const fresh = await getJob( current.jobId );
			const updated: ActiveJob = { ...current, status: fresh.status, error: fresh.error || undefined };
			setActiveJob( updated );
			activeJobRef.current = updated;

			if ( isTerminal( fresh.status ) ) {
				await finalizeJob( updated );
			}
		} catch ( pollErr ) {
			// Network blip — keep polling, but surface the latest error.
			setError( ( pollErr as Error )?.message || __( 'Polling failed.', 'extrachill-studio' ) );
		}
	}, [ finalizeJob, stopPolling ] );

	const startPolling = useCallback( (): void => {
		stopPolling();
		pollTimerRef.current = setInterval( () => {
			pollOnce();
		}, POLL_INTERVAL_MS );
	}, [ pollOnce, stopPolling ] );

	// Resume polling when the tab regains focus.
	useEffect( () => {
		const onVisibilityChange = (): void => {
			if ( document.visibilityState === 'visible' && activeJobRef.current && ! isTerminal( activeJobRef.current.status ) ) {
				pollOnce();
			}
		};
		document.addEventListener( 'visibilitychange', onVisibilityChange );
		return () => {
			document.removeEventListener( 'visibilitychange', onVisibilityChange );
		};
	}, [ pollOnce ] );

	// Clean up the timer on unmount.
	useEffect( () => {
		return () => {
			stopPolling();
		};
	}, [ stopPolling ] );

	// ── Pipeline ──────────────────────────────────────────────────────

	const startTranscription = async (): Promise< void > => {
		if ( ! selectedFile || isBusy ) {
			return;
		}

		setError( '' );
		const config = PRESET_CONFIG[ preset ];
		const startedAt = Date.now();

		try {
			setStageMessage( __( 'Authorizing…', 'extrachill-studio' ) );

			setStageMessage( __( 'Uploading audio…', 'extrachill-studio' ) );
			const upload = await uploadAudio( selectedFile );

			setStageMessage( __( 'Submitting transcription job…', 'extrachill-studio' ) );
			const job = await createJob( {
				uploadPath: upload.path,
				model: config.model,
				diarize: config.diarize,
				removeFillers: config.removeFillers,
			} );

			const tracked: ActiveJob = {
				jobId: job.id,
				filename: selectedFile.name,
				preset,
				status: job.status,
				startedAt,
			};
			setActiveJob( tracked );
			activeJobRef.current = tracked;
			setSelectedFile( null );
			if ( fileInputRef.current ) {
				fileInputRef.current.value = '';
			}

			setStageMessage(
				__(
					'Submitted. We\'ll email you when it\'s done — usually 5–60 minutes depending on length. You can close this tab.',
					'extrachill-studio'
				)
			);
			startPolling();

			// Kick an immediate poll so short jobs flip to "completed" without a 5s wait.
			pollOnce();
		} catch ( pipelineErr ) {
			setStageMessage( '' );
			setError( ( pipelineErr as Error )?.message || __( 'Transcription failed to start.', 'extrachill-studio' ) );
		}
	};

	const retryFromHistory = ( job: ActiveJob ): void => {
		// Drop the failed entry from history so the UI reads clean; user must re-pick the file.
		setHistory( ( prev ) => prev.filter( ( h ) => h.jobId !== job.jobId ) );
		setError( __( 'Re-select the audio file to retry.', 'extrachill-studio' ) );
	};

	// ── Clipboard / download / view ──────────────────────────────────

	const copyTranscript = async ( job: ActiveJob ): Promise< void > => {
		if ( ! job.transcript ) {
			return;
		}
		try {
			await navigator.clipboard.writeText( job.transcript );
			setCopiedJobId( job.jobId );
			setTimeout( () => {
				setCopiedJobId( ( current ) => ( current === job.jobId ? null : current ) );
			}, COPY_FEEDBACK_MS );
		} catch {
			setError( __( 'Clipboard copy failed — your browser may have blocked it.', 'extrachill-studio' ) );
		}
	};

	const downloadTranscript = ( job: ActiveJob ): void => {
		if ( ! job.transcript ) {
			return;
		}
		downloadTranscriptBlob( job.transcript, job.filename );
	};

	const toggleExpanded = ( jobId: string ): void => {
		setExpandedJobId( ( current ) => ( current === jobId ? null : jobId ) );
	};

	// ── Render ────────────────────────────────────────────────────────

	const dropZoneClass = `ec-studio-transcribe__dropzone${ isDragging ? ' is-dragging' : '' }${ selectedFile ? ' has-file' : '' }`;

	const dropZone = h(
		'div',
		{
			className: dropZoneClass,
			onDragOver,
			onDragLeave,
			onDrop,
			onClick: onPickButtonClick,
			role: 'button',
			tabIndex: 0,
		},
		createElement( 'input', {
			ref: fileInputRef,
			// audio/* + video/* covers everything ffmpeg can decode on the
			// worker side — phone recordings (m4a, aac, opus, amr), browser
			// MediaRecorder output (webm), screen-recorded video (mp4, mov,
			// mkv), etc. The audio-transcription module on sweatpants
			// auto-converts to wav via ffmpeg before passing to Whisper, so
			// the frontend only needs to accept what ffmpeg accepts.
			type: 'file',
			accept: 'audio/*,video/*',
			onChange: onFilePickerChange,
			style: { display: 'none' },
		} ),
		selectedFile
			? h(
				'div',
				{ className: 'ec-studio-transcribe__file' },
				createElement( 'strong', null, selectedFile.name ),
				createElement( 'span', { className: 'ec-studio-transcribe__file-meta' }, formatMB( selectedFile.size ) )
			)
			: h(
				'div',
				{ className: 'ec-studio-transcribe__dropzone-prompt' },
				createElement( 'div', { className: 'ec-studio-transcribe__dropzone-headline' },
					__( 'Drag audio here or click to upload', 'extrachill-studio' )
				),
				createElement( 'div', { className: 'ec-studio-transcribe__dropzone-meta' },
					__(
						'Any audio or video file · Max 500 MB · auto-converted via ffmpeg',
						'extrachill-studio'
					)
				)
			)
	);

	const presetSelect = createElement(
		'select',
		{
			id: 'ec-studio-transcribe-preset',
			className: 'ec-studio-transcribe__preset',
			value: preset,
			onChange: ( event: ChangeEvent< HTMLSelectElement > ) => setPreset( event.target.value as TranscribePreset ),
			disabled: isBusy,
		},
		( Object.keys( PRESET_CONFIG ) as TranscribePreset[] ).map( ( key ) =>
			createElement(
				'option',
				{ key, value: key },
				`${ PRESET_CONFIG[ key ].label } — ${ PRESET_CONFIG[ key ].description }`
			)
		)
	);

	const transcribeButton = createElement(
		'button',
		{
			type: 'button',
			className: 'button-1 button-medium',
			onClick: startTranscription,
			disabled: ! selectedFile || isBusy,
		},
		isBusy ? __( 'Working…', 'extrachill-studio' ) : __( 'Transcribe', 'extrachill-studio' )
	);

	const renderActiveJob = (): ReactElement | null => {
		if ( ! activeJob ) {
			return null;
		}
		const elapsed = formatElapsed( activeJob.startedAt );
		return h(
			PanelView,
			{ className: 'ec-studio-panel ec-studio-transcribe__active', compact: true },
			h( PanelHeader, { description: __( 'Active job', 'extrachill-studio' ) } ),
			createElement(
				'div',
				{ className: 'ec-studio-transcribe__active-meta' },
				createElement( 'strong', null, activeJob.filename ),
				createElement( 'span', null, ' · ' ),
				createElement( 'span', { className: 'ec-studio-transcribe__status' }, activeJob.status ),
				createElement( 'span', null, ' · ' ),
				createElement( 'span', null, sprintf(
					/* translators: %s: elapsed M:SS */
					__( '%s elapsed', 'extrachill-studio' ),
					elapsed
				) )
			),
			stageMessage
				? h( InlineStatusView, { tone: 'info', className: 'ec-studio-message' }, stageMessage )
				: null
		);
	};

	const renderHistoryItem = ( job: ActiveJob ): ReactElement => {
		const isExpanded = expandedJobId === job.jobId;
		const elapsed = formatElapsed( job.startedAt, job.completedAt );
		const presetLabel = PRESET_CONFIG[ job.preset ].label;

		const headerLine = createElement(
			'div',
			{ className: 'ec-studio-transcribe__history-header' },
			createElement( 'strong', null, job.filename ),
			createElement( 'span', { className: 'ec-studio-transcribe__history-meta' },
				` · ${ job.status } · ${ presetLabel } · ${ elapsed }`
			)
		);

		if ( job.status === 'failed' || job.status === 'stopped' ) {
			return createElement(
				'li',
				{ key: job.jobId, className: 'ec-studio-transcribe__history-item is-failed' },
				headerLine,
				job.error
					? createElement( 'div', { className: 'ec-studio-transcribe__history-error' },
						sprintf(
							/* translators: %s: server-reported error */
							__( 'Error: %s', 'extrachill-studio' ),
							job.error
						)
					)
					: null,
				createElement(
					'div',
					{ className: 'ec-studio-transcribe__history-actions' },
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-small',
							onClick: () => retryFromHistory( job ),
						},
						__( 'Retry', 'extrachill-studio' )
					)
				)
			);
		}

		// completed
		return createElement(
			'li',
			{ key: job.jobId, className: 'ec-studio-transcribe__history-item' },
			headerLine,
			createElement(
				'div',
				{ className: 'ec-studio-transcribe__history-actions' },
				createElement(
					'button',
					{
						type: 'button',
						className: 'button-1 button-small',
						onClick: () => copyTranscript( job ),
						disabled: ! job.transcript,
					},
					copiedJobId === job.jobId
						? __( 'Copied', 'extrachill-studio' )
						: __( 'Copy', 'extrachill-studio' )
				),
				createElement(
					'button',
					{
						type: 'button',
						className: 'button-1 button-small button-secondary',
						onClick: () => downloadTranscript( job ),
						disabled: ! job.transcript,
					},
					__( 'Download .txt', 'extrachill-studio' )
				),
				createElement(
					'button',
					{
						type: 'button',
						className: 'button-1 button-small button-secondary',
						onClick: () => toggleExpanded( job.jobId ),
						disabled: ! job.transcript,
					},
					isExpanded
						? __( 'Hide transcript', 'extrachill-studio' )
						: __( 'View transcript', 'extrachill-studio' )
				)
			),
			isExpanded && job.transcript
				? createElement(
					'pre',
					{ className: 'ec-studio-transcribe__transcript' },
					job.transcript
				)
				: null
		);
	};

	return h(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--transcribe' },
		h(
			PanelView,
			{ className: 'ec-studio-panel', compact: true },
			h( PanelHeader, {
				description: __( 'Transcribe audio with Whisper. Upload a file, pick a quality preset, and get a plain-text transcript.', 'extrachill-studio' ),
			} ),
			h(
				'div',
				{ className: 'ec-studio-composer' },
				dropZone,
				h(
					FieldGroupView,
					{ label: __( 'Quality', 'extrachill-studio' ), htmlFor: 'ec-studio-transcribe-preset' },
					presetSelect
				),
				h(
					ActionRowView,
					{ className: 'ec-studio-composer__actions' },
					transcribeButton
				)
			),
			error ? h( InlineStatusView, { tone: 'error', className: 'ec-studio-message' }, error ) : null
		),
		renderActiveJob(),
		history.length > 0
			? h(
				PanelView,
				{ className: 'ec-studio-panel', compact: true },
				h( PanelHeader, {
					description: __( 'Recent transcriptions (this session)', 'extrachill-studio' ),
				} ),
				createElement(
					'ul',
					{ className: 'ec-studio-transcribe__history' },
					...history.map( renderHistoryItem )
				)
			)
			: null
	);
};

export default TranscribePane;
