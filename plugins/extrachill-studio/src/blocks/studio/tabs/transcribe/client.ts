/**
 * Audio-transcription job submission and draft persistence.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	createOutputDir,
	createSweatpantsClient,
	JOB_SCOPE,
} from '../../sweatpants/client';
import type { SweatpantsJob } from '../../sweatpants/types';
import type { PersistDraftResponse, TranscriptionResult } from './types';

// Transcribe also requests callback material for background draft creation.
const {
	getToken,
	upload: uploadAudio,
	submitJob,
	getJob,
	getResults: getWorkerResults,
} = createSweatpantsClient( `${ JOB_SCOPE } callback:write` );

export { getJob, uploadAudio };
export const getResults = getWorkerResults< TranscriptionResult >;

interface CreateJobInput {
	uploadPath: string;
	model: 'base' | 'medium' | 'large';
	diarize: boolean;
	removeFillers: boolean;
	language?: string;
}

/**
 * Create an audio-transcription job referencing a previously uploaded
 * file path.
 *
 * The `output_dir` uses a UUID-ish unique-id so multiple concurrent jobs
 * never collide on the sweatpants host.
 *
 * The callback handoff fields from the `callback:write` token-mint
 * response are passed through to sweatpants when present. The
 * worker will then sign + POST a completion callback to our REST endpoint
 * when the job finishes, which creates a draft on main extrachill.com and
 * emails the uploader. This is what enables "close the tab, get an email"
 * — the React polling loop is a fallback for users who stay on the page.
 * @param input
 */
export const createJob = async (
	input: CreateJobInput
): Promise< SweatpantsJob > => {
	const outputDir = createOutputDir();
	const token = await getToken();

	const inputs: Record< string, unknown > = {
		audio_path: input.uploadPath,
		output_dir: outputDir,
		model: input.model,
		diarize: input.diarize,
		remove_fillers: input.removeFillers,
		language: input.language || 'en',
	};

	// Wire the completion callback when the token carries the necessary
	// material. All four fields must be present together — sweatpants
	// requires `callback_url`, and the receiver verification requires
	// `callback_secret` + `callback_user_id`.
	if (
		token.callback_url &&
		token.callback_secret &&
		token.callback_user_id !== null
	) {
		inputs.callback_url = token.callback_url;
		inputs.callback_secret = token.callback_secret;
		inputs.callback_issuer = token.callback_issuer || 'extrachill-studio';
		inputs.callback_user_id = token.callback_user_id;
		// `callback_cleanup_on_success` defaults to true on the module side;
		// leaving it unset preserves the headless-pipeline default of
		// "receiver acks → worker deletes audio + outputs".
	}

	return submitJob( { module_id: 'audio-transcription', inputs } );
};

/**
 * Persist a completed transcript as a draft on extrachill.com.
 *
 * This is the durability guarantee for a finished transcription. It does
 * NOT go through sweatpants — it is a first-party authenticated call from
 * the logged-in user's own browser to WordPress.
 *
 * Previously the ONLY path to durable storage was sweatpants signing and
 * POSTing a completion callback. When that callback did not arrive the
 * transcript existed solely in React state and was destroyed on navigation
 * — while the UI told the user they could close the tab. See #193.
 *
 * The server keys on `job_id` and reuses an existing draft when one is
 * already present, so calling this after (or before) a callback for the
 * same job cannot create a duplicate post.
 *
 * @param input             Transcript persistence payload.
 * @param input.jobId       Sweatpants job id; the server's idempotency key.
 * @param input.filename    Original recording filename, used in the title.
 * @param input.transcript  Plain-text transcript to store as the draft body.
 * @param input.segments    Whisper segment count, recorded as draft meta.
 * @param input.durationSec Audio duration in seconds, recorded as draft meta.
 * @param input.hasSpeakers Whether diarization ran for this job.
 */
export const persistTranscriptDraft = async ( input: {
	jobId: string;
	filename: string;
	transcript: string;
	segments?: number;
	durationSec?: number;
	hasSpeakers?: boolean;
} ): Promise< PersistDraftResponse > => {
	return apiFetch< PersistDraftResponse >( {
		path: '/extrachill/v1/transcribe/draft',
		method: 'POST',
		data: {
			job_id: input.jobId,
			filename: input.filename,
			transcript: input.transcript,
			stats: {
				segments: input.segments ?? 0,
				duration: input.durationSec ?? 0,
				speakers: input.hasSpeakers ? 1 : null,
			},
		},
	} );
};
