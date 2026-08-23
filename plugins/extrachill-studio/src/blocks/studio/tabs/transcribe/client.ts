/**
 * Sweatpants HTTP client for the Transcribe tab.
 *
 * All calls go directly browser → `https://sweatpants.chubes.net` using the
 * HMAC-signed bearer token minted by `tokenManager`. WordPress is NOT in
 * the data path — its only role is minting the token.
 *
 * If the sweatpants base URL ever needs to be configurable per environment
 * (staging, dev), hoist `SWEATPANTS_BASE_URL` to a server-injected value
 * via `render.php` data attributes or `wp_localize_script`.
 *
 * @package
 */

import { getToken, invalidateToken } from './tokenManager';
import type {
	SweatpantsJob,
	SweatpantsJobResults,
	SweatpantsUpload,
} from './types';

/** Single sweatpants worker in production — see the docblock for env-config notes. */
const SWEATPANTS_BASE_URL = 'https://sweatpants.chubes.net';

/** Sweatpants caps uploads at 500 MB by default. Validate client-side. */
export const MAX_UPLOAD_BYTES = 500 * 1024 * 1024;

/** Module ID for the audio-transcription pipeline on sweatpants. */
const TRANSCRIPTION_MODULE_ID = 'audio-transcription';

interface RequestOptions {
	method?: 'GET' | 'POST' | 'DELETE';
	body?: BodyInit | null;
	headers?: Record< string, string >;
}

/**
 * Internal: perform an authorized fetch against sweatpants.
 *
 * On 401, drops the cached token and retries once with a fresh mint —
 * covers the case where the cached token expired mid-flight or the
 * sweatpants secret rotated server-side.
 * @param path
 * @param options
 * @param retried
 */
const request = async < T >(
	path: string,
	options: RequestOptions = {},
	retried = false
): Promise< T > => {
	const token = await getToken();
	const headers: Record< string, string > = {
		Authorization: `Bearer ${ token.token }`,
		...( options.headers || {} ),
	};

	const response = await globalThis.fetch(
		`${ SWEATPANTS_BASE_URL }${ path }`,
		{
			method: options.method || 'GET',
			headers,
			body: options.body ?? null,
		}
	);

	if ( response.status === 401 && ! retried ) {
		invalidateToken();
		return request< T >( path, options, true );
	}

	if ( ! response.ok ) {
		let detail = `${ response.status } ${ response.statusText }`;
		try {
			const errorBody = ( await response.json() ) as {
				error?: string;
				message?: string;
			};
			if ( errorBody?.error || errorBody?.message ) {
				detail = `${ detail } — ${
					errorBody.error || errorBody.message
				}`;
			}
		} catch {
			// Body wasn't JSON; stick with status text.
		}
		throw new Error( `Sweatpants ${ path } failed: ${ detail }` );
	}

	return response.json() as Promise< T >;
};

/**
 * Upload an audio file directly to sweatpants /uploads.
 *
 * @param file Browser File from a file input or drop event.
 * @return Sweatpants upload metadata, including the `path` we feed to
 *   the audio-transcription module as `audio_path`.
 */
export const uploadAudio = async (
	file: File
): Promise< SweatpantsUpload > => {
	const formData = new FormData();
	formData.append( 'file', file, file.name );

	return request< SweatpantsUpload >( '/uploads', {
		method: 'POST',
		body: formData,
		// Don't set Content-Type — the browser sets a multipart boundary.
	} );
};

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
 * If the cached token carries the `callback:write` scope (it does by
 * default — see tokenManager.DEFAULT_SCOPE), the callback handoff fields
 * from the token-mint response are passed through to sweatpants. The
 * worker will then sign + POST a completion callback to our REST endpoint
 * when the job finishes, which creates a draft on main extrachill.com and
 * emails the uploader. This is what enables "close the tab, get an email"
 * — the React polling loop is a fallback for users who stay on the page.
 * @param input
 */
export const createJob = async (
	input: CreateJobInput
): Promise< SweatpantsJob > => {
	const uniqueId =
		typeof globalThis.crypto !== 'undefined' &&
		'randomUUID' in globalThis.crypto
			? globalThis.crypto.randomUUID()
			: `job-${ Date.now() }-${ Math.random()
					.toString( 36 )
					.slice( 2, 10 ) }`;

	const token = await getToken();

	const inputs: Record< string, unknown > = {
		audio_path: input.uploadPath,
		output_dir: `/var/lib/sweatpants/output/${ uniqueId }`,
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

	const payload = {
		module_id: TRANSCRIPTION_MODULE_ID,
		inputs,
	};

	return request< SweatpantsJob >( '/jobs', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( payload ),
	} );
};

/**
 * Fetch the latest state for a job.
 * @param jobId
 */
export const getJob = async ( jobId: string ): Promise< SweatpantsJob > => {
	return request< SweatpantsJob >( `/jobs/${ encodeURIComponent( jobId ) }` );
};

/**
 * Fetch the results envelope for a completed job.
 * @param jobId
 */
export const getResults = async (
	jobId: string
): Promise< SweatpantsJobResults > => {
	return request< SweatpantsJobResults >(
		`/jobs/${ encodeURIComponent( jobId ) }/results`
	);
};
