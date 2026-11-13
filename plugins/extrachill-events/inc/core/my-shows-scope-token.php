<?php
/**
 * My Shows scope token minting and verification.
 *
 * The public calendar and map REST routes carry an opaque scope token so
 * owner scoping survives client-side re-fetches without page context.
 * Purpose-bound signatures prevent forgery and reuse by other token consumers.
 * Tokens bind the owner uid and expire after seven days; old tabs recover on
 * reload. The signing secret never leaves the server. No legacy tokens are
 * accepted.
 *
 * @package ExtraChillEvents
 * @since 0.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mint a scope token for a given owner user id.
 *
 * @param int $user_id Owner user id to encode.
 * @return string Signed token, or '' for an invalid id.
 */
function ec_events_my_shows_mint_scope_token( $user_id ) {
	$user_id = (int) $user_id;
	if ( 1 > $user_id ) {
		return '';
	}

	return extrachill_sign( 'events.my_shows_scope', array( 'uid' => $user_id ), 7 * DAY_IN_SECONDS );
}

/**
 * Verify a purpose-bound, unexpired scope token.
 *
 * @param string $token Raw token string from the request `$input`.
 * @return int Verified positive owner user id, or 0 when invalid.
 */
function ec_events_my_shows_verify_scope_token( $token ) {
	if ( ! is_string( $token ) || '' === $token ) {
		return 0;
	}

	$claims = extrachill_verify( 'events.my_shows_scope', $token );
	if ( null === $claims || ! isset( $claims['uid'] ) || ! is_int( $claims['uid'] ) || 1 > $claims['uid'] ) {
		return 0;
	}

	return $claims['uid'];
}
