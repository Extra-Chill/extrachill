/**
 * Token manager for the sweatpants compute worker.
 *
 * Mints a short-lived HMAC-signed bearer token via the WordPress ability
 * `extrachill/sweatpants-token` and caches it in module scope for the
 * lifetime of the page. Re-mints automatically when the cached token is
 * within `EXPIRY_BUFFER_SECONDS` of expiry (clock skew protection).
 *
 * The token does NOT cross between tabs — it's minted lazily on the first
 * sweatpants call from the Transcribe tab.
 *
 * @package ExtraChillStudio
 */

import apiFetch from '@wordpress/api-fetch';
import type { SweatpantsToken } from './types';

// The Abilities API exposes its REST surface under /wp-abilities/v1, NOT
// /wp/v2/abilities, and the action verb is /run, not /execute. Verified
// against the live `/wp-json/` discovery on studio.extrachill.com.
const TOKEN_ABILITY_PATH = '/wp-abilities/v1/abilities/extrachill/sweatpants-token/run';
const DEFAULT_SCOPE = 'uploads:write jobs:write jobs:read';
const DEFAULT_TTL_SECONDS = 900;
const EXPIRY_BUFFER_SECONDS = 60;

let cachedToken: SweatpantsToken | null = null;
let inflight: Promise< SweatpantsToken > | null = null;

/**
 * Returns the current unix time in seconds.
 */
const nowSeconds = (): number => Math.floor( Date.now() / 1000 );

/**
 * Returns true if the cached token exists and has at least
 * `EXPIRY_BUFFER_SECONDS` of life remaining.
 */
const tokenIsFresh = ( token: SweatpantsToken | null ): token is SweatpantsToken => {
	if ( ! token ) {
		return false;
	}
	return token.expires_at - EXPIRY_BUFFER_SECONDS > nowSeconds();
};

/**
 * Mint a new token via the WordPress ability.
 *
 * @throws Error if the ability call fails or returns a malformed payload.
 */
const mintToken = async (): Promise< SweatpantsToken > => {
	const response = await apiFetch< SweatpantsToken >( {
		path: TOKEN_ABILITY_PATH,
		method: 'POST',
		data: {
			scope: DEFAULT_SCOPE,
			ttl: DEFAULT_TTL_SECONDS,
		},
	} );

	if ( ! response || typeof response.token !== 'string' || typeof response.expires_at !== 'number' ) {
		throw new Error( 'Token ability returned an unexpected payload.' );
	}

	return response;
};

/**
 * Get a valid sweatpants bearer token, minting + caching if needed.
 *
 * Concurrent callers share a single in-flight mint promise so we don't
 * thrash the ability with parallel requests during a burst (e.g. file
 * upload + job-create kick off back-to-back).
 */
export const getToken = async (): Promise< SweatpantsToken > => {
	if ( tokenIsFresh( cachedToken ) ) {
		return cachedToken;
	}

	if ( inflight ) {
		return inflight;
	}

	inflight = ( async (): Promise< SweatpantsToken > => {
		try {
			const fresh = await mintToken();
			cachedToken = fresh;
			return fresh;
		} finally {
			inflight = null;
		}
	} )();

	return inflight;
};

/**
 * Forcibly drop the cached token. Useful when the server returns 401 and
 * we want the next call to re-mint instead of trying the expired token
 * again.
 */
export const invalidateToken = (): void => {
	cachedToken = null;
};
