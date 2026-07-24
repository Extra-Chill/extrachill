<?php
/**
 * Browser handoff token service.
 *
 * Generates and validates one-time tokens used to bootstrap a WordPress
 * cookie session in a real browser after app authentication.
 *
 * The plaintext token is returned to the client, but the site transient is
 * keyed by sha256(token) so a cache/Redis dump never exposes live tokens.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_BROWSER_HANDOFF_TTL                = 60;
const EXTRACHILL_USERS_BROWSER_HANDOFF_CLAIM_CLEANUP_HOOK = 'extrachill_users_cleanup_browser_handoff_claim';

add_action( EXTRACHILL_USERS_BROWSER_HANDOFF_CLAIM_CLEANUP_HOOK, 'extrachill_users_cleanup_browser_handoff_claim', 10, 2 );

/**
 * Create a single-use browser handoff token.
 *
 * @param int    $user_id      User ID.
 * @param string $redirect_url Destination URL after cookies are set.
 * @return string Token.
 */
function extrachill_users_create_browser_handoff_token( int $user_id, string $redirect_url ): string {
	$token = wp_generate_password( 64, false, false );
	$key   = 'ec_browser_handoff_' . hash( 'sha256', $token );

	set_site_transient(
		$key,
		array(
			'user_id'       => $user_id,
			'redirect_url'  => $redirect_url,
			'created_at_ts' => time(),
		),
		EXTRACHILL_USERS_BROWSER_HANDOFF_TTL
	);

	return $token;
}

/**
 * Consume a browser handoff token.
 *
 * @param string $token Token string.
 * @return array|WP_Error Payload array.
 */
function extrachill_users_consume_browser_handoff_token( string $token ) {
	$token = trim( $token );
	if ( '' === $token ) {
		return new WP_Error( 'invalid_handoff_token', 'Invalid handoff token.', array( 'status' => 400 ) );
	}

	$token_hash = hash( 'sha256', $token );
	$key        = 'ec_browser_handoff_' . $token_hash;
	$claim_key  = 'ec_browser_handoff_claim_' . $token_hash;
	$claim      = array(
		'owner'      => wp_generate_uuid4(),
		'expires_at' => time() + EXTRACHILL_USERS_BROWSER_HANDOFF_TTL,
	);
	$claimed    = extrachill_users_claim_browser_handoff( $claim_key, $claim );

	if ( ! $claimed ) {
		return new WP_Error( 'invalid_handoff_token', 'Invalid or expired handoff token.', array( 'status' => 400 ) );
	}

	$payload  = get_site_transient( $key );
	$deleted  = delete_site_transient( $key );
	$now      = time();
	$released = extrachill_users_cleanup_browser_handoff_claim( $claim_key, $claim );

	if (
		! $released
		|| ! $deleted
		|| ! is_array( $payload )
		|| empty( $payload['user_id'] )
		|| empty( $payload['redirect_url'] )
		|| empty( $payload['created_at_ts'] )
		|| (int) $payload['created_at_ts'] > $now
		|| ( $now - (int) $payload['created_at_ts'] ) >= EXTRACHILL_USERS_BROWSER_HANDOFF_TTL
	) {
		return new WP_Error( 'invalid_handoff_token', 'Invalid or expired handoff token.', array( 'status' => 400 ) );
	}

	return $payload;
}

/**
 * Atomically claim a token through the main site's uniquely indexed options table.
 *
 * @param string $claim_key Hashed claim option name.
 * @param array  $claim     Owner and expiry data.
 * @return bool Whether the claim was acquired.
 */
function extrachill_users_claim_browser_handoff( string $claim_key, array $claim ): bool {
	$main_site_id = is_multisite() ? get_main_site_id() : get_current_blog_id();
	$switched     = get_current_blog_id() !== $main_site_id;

	if ( $switched ) {
		switch_to_blog( $main_site_id );
	}

	try {
		$cleanup_scheduled = wp_schedule_single_event(
			(int) $claim['expires_at'],
			EXTRACHILL_USERS_BROWSER_HANDOFF_CLAIM_CLEANUP_HOOK,
			array( $claim_key, $claim ),
			true
		);

		if ( true !== $cleanup_scheduled ) {
			return false;
		}

		if ( add_option( $claim_key, $claim, '', false ) ) {
			return true;
		}

		wp_unschedule_event(
			(int) $claim['expires_at'],
			EXTRACHILL_USERS_BROWSER_HANDOFF_CLAIM_CLEANUP_HOOK,
			array( $claim_key, $claim )
		);

		$existing_claim = get_option( $claim_key );
		if ( is_array( $existing_claim ) && isset( $existing_claim['expires_at'] ) && (int) $existing_claim['expires_at'] <= time() ) {
			extrachill_users_delete_browser_handoff_claim( $claim_key, $existing_claim );
		}

		return false;
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Remove a claim only when its owner data still matches.
 *
 * This is both the scheduled crash cleanup callback and the normal release path.
 *
 * @param string $claim_key Hashed claim option name.
 * @param array  $claim     Owner and expiry data.
 * @return bool Whether the matching claim was released.
 */
function extrachill_users_cleanup_browser_handoff_claim( string $claim_key, array $claim ): bool {
	$main_site_id = is_multisite() ? get_main_site_id() : get_current_blog_id();
	$switched     = get_current_blog_id() !== $main_site_id;

	if ( $switched ) {
		switch_to_blog( $main_site_id );
	}

	try {
		$deleted = extrachill_users_delete_browser_handoff_claim( $claim_key, $claim );
		if ( $deleted ) {
			wp_unschedule_event(
				(int) $claim['expires_at'],
				EXTRACHILL_USERS_BROWSER_HANDOFF_CLAIM_CLEANUP_HOOK,
				array( $claim_key, $claim )
			);
		}

		return $deleted;
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Conditionally delete a claim without allowing an old owner to release a new one.
 *
 * @param string $claim_key Hashed claim option name.
 * @param array  $claim     Owner and expiry data.
 * @return bool Whether the matching claim was deleted.
 */
function extrachill_users_delete_browser_handoff_claim( string $claim_key, array $claim ): bool {
	global $wpdb;

	$deleted = $wpdb->delete(
		$wpdb->options,
		array(
			'option_name'  => $claim_key,
			'option_value' => maybe_serialize( $claim ),
		),
		array( '%s', '%s' )
	);

	if ( false === $deleted ) {
		return false;
	}

	wp_cache_delete( $claim_key, 'options' );

	return 1 === $deleted;
}
