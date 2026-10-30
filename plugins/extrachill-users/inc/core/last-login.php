<?php
/**
 * Last Login Tracking
 *
 * Persists a durable `last_login` timestamp on every authentication event.
 *
 * This is DISTINCT from `last_active` (see inc/core/online-users.php):
 *
 *   - `last_active` = last page activity while logged in. A long-lived session
 *     keeps a user "active" without ever re-authenticating. Owned by
 *     ec_record_user_activity() — the single activity writer.
 *   - `last_login`  = last authentication event. Answers "is this user actually
 *     logging in?" — a team-experience / adoption signal that activity can't.
 *
 * WordPress core does not track a durable last-login timestamp: it only fires
 * the `wp_login` action and writes `session_tokens` meta, which is lossy
 * (deleted on expiry/logout) and so cannot answer the question after a token
 * ages out. This listener fills that gap with a timestamp-only meta (no PII).
 *
 * Because this plugin re-fires `wp_login` from every custom auth path (token
 * handoff, Google OAuth, registration, browser handoff) in addition to core,
 * a single `wp_login` listener covers all login paths.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Record the last login timestamp for a user.
 *
 * Hooked on `wp_login`, which fires on core login and is re-fired by this
 * plugin's custom auth flows. Writes a network-wide `last_login` user meta
 * (timestamp only).
 *
 * @param string  $user_login Username (unused; retained for hook signature).
 * @param WP_User $user       Authenticated user object.
 */
function ec_record_last_login( $user_login, $user = null ) {
	if ( ! ( $user instanceof WP_User ) || ! $user->ID ) {
		return;
	}

	update_user_meta( $user->ID, 'last_login', time() );
}
add_action( 'wp_login', 'ec_record_last_login', 10, 2 );
