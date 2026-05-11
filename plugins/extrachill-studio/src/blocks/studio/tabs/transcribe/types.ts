/**
 * Type definitions for the Transcribe tab.
 *
 * Shapes mirror the sweatpants worker REST contract (https://sweatpants.chubes.net)
 * and the extrachill/sweatpants-token WordPress ability response.
 *
 * @package ExtraChillStudio
 */

/**
 * Bearer token minted by the WordPress ability `extrachill/sweatpants-token`.
 *
 * The token is HMAC-SHA256 signed (JWT-shaped, but NOT a JWT — pass as a
 * literal Bearer). It carries the requested scope claims and an expiry.
 */
export interface SweatpantsToken {
	token: string;
	expires_at: number;
	scope: string;
	/**
	 * Callback handoff fields — populated by the WP token-mint ability when
	 * the requested scope includes `callback:write`. The Transcribe tab
	 * passes these through to sweatpants in the job inputs so the worker
	 * can sign + POST a completion callback to our REST endpoint when the
	 * job finishes. See `inc/transcription/callback.php` for the receiver.
	 *
	 * Returning `callback_secret` to the browser is acceptable because the
	 * requester is already gated to team members; the secret only signs
	 * callbacks the receiver verifies, so a stolen browser-side copy
	 * doesn't escalate beyond what the team member can already do.
	 */
	callback_url: string | null;
	callback_secret: string | null;
	callback_issuer: string | null;
	callback_user_id: number | null;
}

/** Result of POST /uploads on sweatpants. */
export interface SweatpantsUpload {
	upload_id: string;
	path: string;
	filename: string;
	size_bytes: number;
	mime_type: string;
	created_at: number;
}

/** Lifecycle states reported by sweatpants for a job. */
export type SweatpantsJobStatus = 'pending' | 'running' | 'completed' | 'failed' | 'stopped';

/** Result of GET /jobs/{id} on sweatpants. */
export interface SweatpantsJob {
	id: string;
	module_id: string;
	status: SweatpantsJobStatus;
	created_at: string;
	started_at: string | null;
	completed_at: string | null;
	error: string | null;
}

/**
 * Result of GET /jobs/{id}/results on sweatpants.
 *
 * For audio-transcription, `data.content.transcription` is the plain
 * transcript. When `diarize=true`, `data.content.combined_txt` carries
 * speaker labels; when also `remove_fillers=true`,
 * `data.content.combined_txt_clean` is the clean speaker-labelled version.
 */
export interface SweatpantsJobResult {
	id: number;
	data: {
		status: 'complete';
		files: Record< string, string >;
		content: Record< string, string >;
		stats: {
			segments: number;
			speakers: number | null;
			duration: number;
		};
	};
	created_at: string;
}

export interface SweatpantsJobResults {
	results: SweatpantsJobResult[];
	total: number;
}

/**
 * Quality/feature preset surfaced in the UI.
 *
 * Maps to (model, diarize, remove_fillers) tuples — see PRESET_CONFIG in
 * the pane component. A single dropdown is friendlier than three independent
 * controls and the team only needs three sensible combinations.
 */
export type TranscribePreset = 'quick' | 'standard' | 'publish';

/** Stages the pane walks through for a single transcription request. */
export type TranscribeStage =
	| 'idle'
	| 'fileSelected'
	| 'mintingToken'
	| 'uploading'
	| 'submittingJob'
	| 'polling'
	| 'fetchingResults'
	| 'done'
	| 'error';

/** A transcription job tracked in the React component's local state. */
export interface ActiveJob {
	jobId: string;
	filename: string;
	preset: TranscribePreset;
	status: SweatpantsJobStatus;
	startedAt: number;
	completedAt?: number;
	transcript?: string;
	error?: string;
}
