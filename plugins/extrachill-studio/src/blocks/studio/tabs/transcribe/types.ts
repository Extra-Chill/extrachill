/**
 * Type definitions for the Transcribe tab.
 *
 * @package
 */

/**
 * Internal dependencies
 */
import type { SweatpantsJobStatus } from '../../sweatpants/types';

/** Audio-transcription module result data. */
export interface TranscriptionResult {
	status: 'complete';
	files: Record< string, string >;
	content: Record< string, string >;
	stats: {
		segments: number;
		speakers: number | null;
		duration: number;
	};
}

/**
 * Whisper model size.
 *
 * Direct mapping to sweatpants `audio-transcription` module's `model`
 * input. Only base/medium/large surfaced in the UI — tiny is too low
 * quality for any team workflow, and the small/medium gap is narrower
 * than the medium/large gap so we skip small.
 */
export type WhisperModel = 'base' | 'medium' | 'large';

/**
 * Independent transcription options the React tab assembles before
 * submitting a job. Maps 1:1 to the audio-transcription module inputs
 * (model, diarize, remove_fillers).
 *
 * Decoupled per sweatpants-modules#6 — `removeFillers=true` produces a
 * cleaned transcript regardless of `diarize`, so all three combinations
 * are first-class options.
 */
export interface TranscribeOptions {
	model: WhisperModel;
	diarize: boolean;
	removeFillers: boolean;
}

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
	options: TranscribeOptions;
	status: SweatpantsJobStatus;
	startedAt: number;
	completedAt?: number;
	transcript?: string;
	error?: string;
	/**
	 * Post id of the draft this transcript was saved to on
	 * extrachill.com, once persistence succeeds. Undefined until saved.
	 */
	savedPostId?: number;
	/**
	 * Set when saving the transcript to a draft failed. The transcript is
	 * still present in `transcript` so the user can copy it manually, but
	 * it is NOT durable — surfacing this is what prevents the silent loss
	 * described in #193.
	 */
	saveError?: string;
}

/** Response from POST /extrachill/v1/transcribe/draft. */
export interface PersistDraftResponse {
	post_id: number;
	created: boolean;
	edit_url: string;
}
