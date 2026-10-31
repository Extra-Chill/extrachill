<?php
/**
 * My Shows — scope token mint + verify.
 *
 * data-machine-events#160 fix. The My Shows calendar + map are the
 * generic data-machine-events `calendar` / `events-map` blocks, embedded
 * on `/my-shows/` and owner-scoped by the query filters in
 * `my-shows-calendar-filter.php` / `my-shows-map-filter.php`.
 *
 * Those filters used to gate on `is_page( 'my-shows' )`, which is TRUE on
 * the initial PHP render but FALSE on the client-side prev/next-month
 * (calendar) and mount/pan (map) REST re-fetches. Over REST the gate
 * returned early, the `post__in` / `include_ids` scoping was dropped, and
 * the endpoint returned the entire network-wide set — a cross-user data
 * leak (issue #160).
 *
 * The fix moves scoping from page-context to a DATA token that travels
 * with every request. data-machine-events exposes generic, opaque
 * `scope_token` seams (filters to emit it on the block root, a passthrough
 * param on the public REST routes, and the token reaching the query
 * filters' `$input`). This file owns the EC-specific half: minting a
 * NON-SPOOFABLE token and verifying it.
 *
 * Security model
 * --------------
 * The calendar / venues REST routes are public (`__return_true`). A plain
 * `?user_id=N` would let anyone read anyone else's tracked-shows calendar.
 * So the token is an HMAC of the owner user id keyed on a WordPress secret
 * (`wp_salt( 'auth' )`). It is tamper-evident: the server can recover the
 * owner id from the token and reject any forged / mutated value, but a
 * client cannot mint a valid token for a user id it does not already own
 * (the secret never leaves the server).
 *
 * Token format: `<uid>.<hmac>` where `hmac = HMAC_SHA256( "<uid>", secret )`.
 * No expiry — a tracked-shows calendar is not sensitive enough to warrant
 * rotation, and the token only ever authorizes READING the owner's own
 * already-public-to-them tracked list. The HMAC binds the token to the
 * single user id it encodes; it cannot be repurposed for another user.
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
 * @return string Token of the form `<uid>.<hmac>`, or '' for an invalid id.
 */
function ec_events_my_shows_mint_scope_token( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return '';
	}

	$hmac = hash_hmac( 'sha256', (string) $user_id, ec_events_my_shows_scope_token_secret() );

	return $user_id . '.' . $hmac;
}

/**
 * Verify a scope token and return the owner user id it encodes.
 *
 * Returns 0 for any token that is empty, malformed, or whose HMAC does not
 * validate (forged / tampered). Uses `hash_equals()` for constant-time
 * comparison so the endpoint does not leak validity via timing.
 *
 * @param string $token Raw token string from the request `$input`.
 * @return int Verified owner user id, or 0 when the token is invalid.
 */
function ec_events_my_shows_verify_scope_token( $token ) {
	if ( ! is_string( $token ) || '' === $token ) {
		return 0;
	}

	$parts = explode( '.', $token, 2 );
	if ( count( $parts ) !== 2 ) {
		return 0;
	}

	list( $uid_raw, $provided_hmac ) = $parts;

	// Reject anything that isn't a clean positive integer id so we never
	// feed surprising values into the HMAC recomputation.
	if ( ! ctype_digit( $uid_raw ) ) {
		return 0;
	}
	$user_id = (int) $uid_raw;
	if ( $user_id < 1 ) {
		return 0;
	}

	$expected_hmac = hash_hmac( 'sha256', (string) $user_id, ec_events_my_shows_scope_token_secret() );

	if ( ! hash_equals( $expected_hmac, (string) $provided_hmac ) ) {
		return 0;
	}

	return $user_id;
}

/**
 * Secret used to key the scope-token HMAC.
 *
 * `wp_salt( 'auth' )` is a per-install secret that never leaves the
 * server, so a client cannot reproduce the HMAC for an arbitrary user id.
 * Wrapped in a filter purely so tests can pin a deterministic secret.
 *
 * @return string HMAC secret.
 */
function ec_events_my_shows_scope_token_secret() {
	/**
	 * Filter the My Shows scope-token HMAC secret.
	 *
	 * @param string $secret Default `wp_salt( 'auth' )`.
	 */
	return (string) apply_filters( 'ec_events_my_shows_scope_token_secret', wp_salt( 'auth' ) );
}
