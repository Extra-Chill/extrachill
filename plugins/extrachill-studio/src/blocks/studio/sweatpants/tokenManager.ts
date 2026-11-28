/**
 * Token manager for the sweatpants compute worker.
 *
 * Mints a short-lived HMAC-signed bearer token via the WordPress ability
 * `extrachill/sweatpants-token` and caches it in module scope for the
 * lifetime of the page. Re-mints automatically when the cached token is
 * within `EXPIRY_BUFFER_SECONDS` of expiry (clock skew protection).
 *
 * Each client caches its own token so a tab only receives the scopes it needs.
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
import type { SweatpantsToken } from './types';

// The Abilities API exposes its REST surface under /wp-abilities/v1, NOT
// /wp/v2/abilities, and the action verb is /run, not /execute. Verified
// against the live `/wp-json/` discovery on studio.extrachill.com.
const TOKEN_ABILITY_PATH =
	'/wp-abilities/v1/abilities/extrachill/sweatpants-token/run';
const DEFAULT_TTL_SECONDS = 900;
const EXPIRY_BUFFER_SECONDS = 60;

/**
 * Returns the current unix time in seconds.
 */
const nowSeconds = (): number => Math.floor( Date.now() / 1000 );

/**
 * Returns true if the cached token exists and has at least
 * `EXPIRY_BUFFER_SECONDS` of life remaining.
 * @param token
 */
const tokenIsFresh = (
	token: SweatpantsToken | null
): token is SweatpantsToken => {
	if ( ! token ) {
		return false;
	}
	return token.expires_at - EXPIRY_BUFFER_SECONDS > nowSeconds();
};

/**
 * Mint a new token via the WordPress ability.
 *
 * @param scope Space-separated scopes requested from the ability.
 * @throws Error if the ability call fails or returns a malformed payload.
 */
const mintToken = async ( scope: string ): Promise< SweatpantsToken > => {
	// The Abilities API /run controller expects the ability inputs nested
	// under an `input` key in the POST body — see
	// class-wp-rest-abilities-v1-run-controller.php::get_input_from_request().
	// Sending the inputs at the top level makes `$json_params['input']`
	// return null and the ability framework rejects with "input is not of
	// type object."
	const response = await apiFetch< SweatpantsToken >( {
		path: TOKEN_ABILITY_PATH,
		method: 'POST',
		data: {
			input: {
				scope,
				ttl: DEFAULT_TTL_SECONDS,
			},
		},
	} );

	if (
		! response ||
		typeof response.token !== 'string' ||
		typeof response.expires_at !== 'number'
	) {
		throw new Error( 'Token ability returned an unexpected payload.' );
	}

	return response;
};

/**
 * Cache one token per client and share an in-flight mint across requests.
 * @param scope Space-separated scopes requested from the ability.
 */
export const createTokenManager = ( scope: string ) => {
	let cachedToken: SweatpantsToken | null = null;
	let inflight: Promise< SweatpantsToken > | null = null;

	const getToken = async (): Promise< SweatpantsToken > => {
		if ( tokenIsFresh( cachedToken ) ) {
			return cachedToken;
		}
		if ( ! inflight ) {
			inflight = ( async (): Promise< SweatpantsToken > => {
				try {
					cachedToken = await mintToken( scope );
					return cachedToken;
				} finally {
					inflight = null;
				}
			} )();
		}
		return inflight;
	};

	const invalidateToken = (): void => {
		cachedToken = null;
	};

	return { getToken, invalidateToken };
};
