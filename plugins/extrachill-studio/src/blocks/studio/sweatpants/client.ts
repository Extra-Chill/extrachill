/**
 * Sweatpants HTTP client for Studio.
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

/**
 * Internal dependencies
 */
import { createTokenManager } from './tokenManager';
import type {
	SweatpantsJob,
	SweatpantsJobResults,
	SweatpantsUpload,
} from './types';

/** Single sweatpants worker in production — see the docblock for env-config notes. */
const SWEATPANTS_BASE_URL = 'https://sweatpants.chubes.net';

/** Sweatpants caps uploads at 500 MB by default. Validate client-side. */
export const MAX_UPLOAD_BYTES = 500 * 1024 * 1024;

/** Scopes needed to upload a file, submit a job, and fetch its state/results. */
export const JOB_SCOPE = 'uploads:write jobs:write jobs:read';

interface RequestOptions {
	method?: 'GET' | 'POST' | 'DELETE';
	body?: BodyInit | null;
	headers?: Record< string, string >;
}

/** Generate a unique output directory on the Sweatpants worker. */
export const createOutputDir = (): string => {
	const uniqueId =
		typeof globalThis.crypto !== 'undefined' &&
		'randomUUID' in globalThis.crypto
			? globalThis.crypto.randomUUID()
			: `job-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2, 10 ) }`;
	return `/var/lib/sweatpants/output/${ uniqueId }`;
};

/**
 * Create a worker client with its own scoped token cache.
 * @param scope Space-separated scopes requested from the token ability.
 */
export const createSweatpantsClient = ( scope = JOB_SCOPE ) => {
	const { getToken, invalidateToken } = createTokenManager( scope );

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
					detail = `${ detail } — ${ errorBody.error || errorBody.message }`;
				}
			} catch {
				// Body wasn't JSON; stick with status text.
			}
			throw new Error( `Sweatpants ${ path } failed: ${ detail }` );
		}

		return response.json() as Promise< T >;
	};

	/**
	 * Upload a file directly to sweatpants /uploads.
	 *
	 * @param file Browser File from a file input or drop event.
	 * @return Sweatpants upload metadata, including the `path` passed to the job.
	 */
	const upload = async ( file: File ): Promise< SweatpantsUpload > => {
		const formData = new FormData();
		formData.append( 'file', file, file.name );

		return request< SweatpantsUpload >( '/uploads', {
			method: 'POST',
			body: formData,
			// Don't set Content-Type — the browser sets a multipart boundary.
		} );
	};

	const submitJob = async ( payload: {
		module_id: string;
		inputs: Record< string, unknown >;
	} ): Promise< SweatpantsJob > => {
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
	const getJob = async ( jobId: string ): Promise< SweatpantsJob > => {
		return request< SweatpantsJob >(
			`/jobs/${ encodeURIComponent( jobId ) }`
		);
	};

	/**
	 * Fetch the results envelope for a completed job.
	 * @param jobId
	 */
	const getResults = async < Data >(
		jobId: string
	): Promise< SweatpantsJobResults< Data > > => {
		return request< SweatpantsJobResults< Data > >(
			`/jobs/${ encodeURIComponent( jobId ) }/results`
		);
	};

	return { getToken, upload, submitJob, getJob, getResults };
};
