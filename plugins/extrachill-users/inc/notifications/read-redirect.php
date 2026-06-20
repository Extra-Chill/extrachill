<?php
/**
 * Click-to-read redirect for notifications.
 *
 * Replaces the old "mark ALL notifications read the instant the /notifications
 * page is viewed" behavior, which was too blunt: simply opening the page zeroed
 * the bell badge even for notifications the user never looked at, so genuinely
 * unread items silently dropped out of the badge and got buried in "Previously
 * Viewed". This endpoint marks exactly ONE notification read — the one the user
 * actually clicked — then forwards them to the real target.
 *
 * Flow:
 *   - Notification cards link to extrachill/v1/notifications/read?id=<id>&to=<url>
 *     instead of linking straight at the target.
 *   - Hitting the route (GET) verifies an HMAC token bound to that user +
 *     notification, marks that single row read via the canonical substrate
 *     ability (extrachill/mark-notifications-read), then 302-redirects to the
 *     target URL. The bell badge naturally decrements per click.
 *
 * Security (single source of truth — same model as the one-click unsubscribe
 * handler in inc/notifications/unsubscribe.php):
 *   - The authority is an HMAC signature in the URL, NOT REST cookie auth.
 *     WordPress REST cookie auth requires a `_wpnonce`, which a plain
 *     browser-navigation link from a notification card does not carry — so an
 *     `is_user_logged_in()` permission_callback rejects every click with
 *     `rest_forbidden` (401). That was the regression. Instead the link is
 *     signed with an HMAC keyed by wp_salt('auth') binding the recipient
 *     user_id and the notification id, so the route authorizes the single,
 *     scoped "mark this user's notification read" action without depending on
 *     session/nonce state at all.
 *   - The signature binds user_id + notification_id (+ a fixed action context),
 *     so a token minted for one notification can't be replayed against another,
 *     and a user can only ever mark their OWN notifications read (the substrate
 *     UPDATE is keyed by the user_id carried in the verified token).
 *   - The `to` target is validated against wp_validate_redirect() with the
 *     network's own hosts allow-listed, so the redirect can't be turned into an
 *     open-redirect to an arbitrary external site. An invalid/again-empty target
 *     falls back to the community notifications page.
 *   - No AJAX (system-wide rule): a GET REST route that runs before the template
 *     layer, performs one scoped write, and redirects — the same shape as the
 *     one-click unsubscribe handler (inc/notifications/unsubscribe.php).
 *
 * Issues: Extra-Chill/extrachill-users#115 (this — click-to-read).
 * Parent epic: Extra-Chill/extrachill-community#82.
 *
 * @package ExtraChill\Users
 * @since 0.15.3
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/service.php';

/**
 * Action context strings mixed into the HMAC so a signature minted for one
 * notification flow can't be replayed against the other (or any other
 * wp_hash-style use).
 */
const EC_NOTIFICATIONS_READ_ACTION     = 'ec_notifications_read';
const EC_NOTIFICATIONS_READ_ALL_ACTION = 'ec_notifications_read_all';

/**
 * Compute the HMAC signature for a click-to-read link.
 *
 * Keyed by wp_salt('auth') (per-install secret). Binds the recipient user id,
 * the single notification id, and a fixed action context so the signature is
 * non-transferable across users, notifications, and flows.
 *
 * @param int $user_id         Recipient user ID.
 * @param int $notification_id Notification row ID.
 * @return string Hex HMAC signature.
 */
function ec_notifications_read_signature( $user_id, $notification_id ) {
	$message = EC_NOTIFICATIONS_READ_ACTION . '|' . (int) $user_id . '|' . (int) $notification_id;
	return hash_hmac( 'sha256', $message, wp_salt( 'auth' ) );
}

/**
 * Compute the HMAC signature for a mark-all-read link.
 *
 * Keyed by wp_salt('auth'). Binds the recipient user id and a fixed action
 * context (no per-notification id, since this flow clears all unread).
 *
 * @param int $user_id Recipient user ID.
 * @return string Hex HMAC signature.
 */
function ec_notifications_read_all_signature( $user_id ) {
	$message = EC_NOTIFICATIONS_READ_ALL_ACTION . '|' . (int) $user_id;
	return hash_hmac( 'sha256', $message, wp_salt( 'auth' ) );
}

/**
 * Build the click-to-read redirect URL for a single notification.
 *
 * Wraps a notification's real target URL so that visiting it first marks that
 * one notification read, then forwards to the target. The link carries the
 * recipient user id + an HMAC signature so the route can authorize the scoped
 * write without REST cookie/nonce auth. Returns the raw target unchanged when
 * inputs are unusable so callers never emit a broken link.
 *
 * @param int    $notification_id Notification row ID.
 * @param string $target_url      The notification's real destination URL.
 * @param int    $user_id         Optional. Recipient user ID. Defaults to the
 *                                current user (the only context cards render in).
 * @return string Read-redirect URL, or the target unchanged on invalid input.
 */
function ec_notifications_read_redirect_url( $notification_id, $target_url, $user_id = 0 ) {
	$notification_id = (int) $notification_id;
	$target_url      = (string) $target_url;
	$user_id         = (int) $user_id;

	if ( $user_id <= 0 ) {
		$user_id = get_current_user_id();
	}

	if ( $notification_id <= 0 || '' === $target_url || $user_id <= 0 ) {
		return $target_url;
	}

	return add_query_arg(
		array(
			'id'  => $notification_id,
			'uid' => $user_id,
			'sig' => ec_notifications_read_signature( $user_id, $notification_id ),
			'to'  => rawurlencode( $target_url ),
		),
		rest_url( 'extrachill/v1/notifications/read' )
	);
}

/**
 * Build the "mark all as read" URL for the current user.
 *
 * A GET link (no AJAX) into the read-all route that marks every unread
 * notification read, then redirects back to the supplied target (the
 * notifications page). The link carries the recipient user id + an HMAC
 * signature; the token is the authority — no per-request nonce needed.
 *
 * @param string $target_url Where to return after marking all read.
 * @param int    $user_id    Optional. Recipient user ID. Defaults to current user.
 * @return string Mark-all-read URL, or the target unchanged on invalid input.
 */
function ec_notifications_mark_all_read_url( $target_url, $user_id = 0 ) {
	$target_url = (string) $target_url;
	$user_id    = (int) $user_id;

	if ( $user_id <= 0 ) {
		$user_id = get_current_user_id();
	}

	if ( $user_id <= 0 ) {
		return $target_url;
	}

	$args = array(
		'uid' => $user_id,
		'sig' => ec_notifications_read_all_signature( $user_id ),
	);
	if ( '' !== $target_url ) {
		$args['to'] = rawurlencode( $target_url );
	}

	return add_query_arg( $args, rest_url( 'extrachill/v1/notifications/read-all' ) );
}

add_action( 'rest_api_init', 'ec_notifications_register_read_redirect_route' );

/**
 * Register the click-to-read + mark-all-read redirect REST routes.
 *
 * Both GET + public (permission_callback __return_true): the HMAC token in the
 * URL authorizes the scoped per-user write. The token is the authority, so the
 * routes do not depend on REST cookie/nonce auth (which a plain notification
 * card link cannot satisfy).
 *
 * @return void
 */
function ec_notifications_register_read_redirect_route() {
	register_rest_route(
		'extrachill/v1',
		'/notifications/read',
		array(
			'methods'             => 'GET',
			'callback'            => 'ec_notifications_handle_read_redirect',
			'permission_callback' => '__return_true',
			'args'                => array(
				'id'  => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'uid' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'sig' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'to'  => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/notifications/read-all',
		array(
			'methods'             => 'GET',
			'callback'            => 'ec_notifications_handle_read_all',
			'permission_callback' => '__return_true',
			'args'                => array(
				'uid' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'sig' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'to'  => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);
}

/**
 * Handle "mark all as read": clear the verified user's unread, then redirect.
 *
 * The user id is taken from the HMAC-verified token, not the session, so the
 * link works regardless of REST cookie/nonce state. A bad signature is a no-op
 * (still redirects to a safe target) rather than a JSON error, since this is a
 * browser-navigation handoff.
 *
 * @param WP_REST_Request $request Request.
 * @return void Always redirects and exits.
 */
function ec_notifications_handle_read_all( WP_REST_Request $request ) {
	$user_id = (int) $request->get_param( 'uid' );
	$sig     = (string) $request->get_param( 'sig' );
	$target  = (string) $request->get_param( 'to' );

	$expected = ec_notifications_read_all_signature( $user_id );

	if ( $user_id > 0 && '' !== $sig && hash_equals( $expected, $sig ) && get_userdata( $user_id ) ) {
		// The HMAC proves the caller is this user. Establish them as the current
		// user for this request so the substrate ability's session-based checks
		// (is_user_logged_in + own-notifications-only) pass legitimately — a
		// cookie-only REST request carries no nonce, so WordPress would not
		// otherwise set the current user here.
		ec_notifications_mark_read_for_user( $user_id, 0 );
	}

	wp_safe_redirect( ec_notifications_read_redirect_safe_target( $target ) );
	exit;
}

/**
 * Handle the click-to-read redirect: mark one notification read, then redirect.
 *
 * The recipient user id + notification id are authorized by the HMAC token in
 * the URL (not the session), so the link works from a plain browser navigation
 * with no REST nonce. The substrate UPDATE is keyed by the verified user_id and
 * the single notification id, so a foreign or tampered token marks zero rows.
 * Always redirects; never returns a JSON body.
 *
 * @param WP_REST_Request $request Request.
 * @return void Always redirects and exits.
 */
function ec_notifications_handle_read_redirect( WP_REST_Request $request ) {
	$notification_id = (int) $request->get_param( 'id' );
	$user_id         = (int) $request->get_param( 'uid' );
	$sig             = (string) $request->get_param( 'sig' );
	$target          = (string) $request->get_param( 'to' );

	$expected = ec_notifications_read_signature( $user_id, $notification_id );

	if ( $user_id > 0 && $notification_id > 0 && '' !== $sig
		&& hash_equals( $expected, $sig ) && get_userdata( $user_id ) ) {
		// Scoped to this user + this single id. The HMAC proves the caller is
		// this user; the substrate UPDATE is keyed by user_id so even a valid
		// token can only touch its own row.
		ec_notifications_mark_read_for_user( $user_id, $notification_id );
	}

	$destination = ec_notifications_read_redirect_safe_target( $target );

	wp_safe_redirect( $destination );
	exit;
}

/**
 * Mark notifications read for an HMAC-verified recipient.
 *
 * Both GET handlers reach here only AFTER verifying the URL's HMAC signature, so
 * the caller is proven to be $user_id. A cookie-only REST request carries no
 * nonce, so WordPress does not establish the current user for it — meaning the
 * canonical substrate ability (whose permission_callback is is_user_logged_in
 * and whose resolver requires the input user_id to equal the current user)
 * would otherwise reject the write. We bridge that by setting the verified user
 * as the current user for the duration of the call, then restoring the prior
 * state, so the write still flows through the single canonical ability rather
 * than a parallel code path.
 *
 * @param int $user_id         Verified recipient user ID.
 * @param int $notification_id Single notification ID, or 0 for all unread.
 * @return void
 */
function ec_notifications_mark_read_for_user( $user_id, $notification_id ) {
	$user_id         = (int) $user_id;
	$notification_id = (int) $notification_id;

	if ( $user_id <= 0 || ! function_exists( 'wp_get_ability' ) ) {
		return;
	}

	$ability = wp_get_ability( 'extrachill/mark-notifications-read' );
	if ( ! $ability ) {
		return;
	}

	$previous_user = get_current_user_id();
	if ( $previous_user !== $user_id ) {
		wp_set_current_user( $user_id );
	}

	$ability->execute(
		array(
			'user_id'         => $user_id,
			'notification_id' => $notification_id,
		)
	);

	if ( $previous_user !== $user_id ) {
		wp_set_current_user( $previous_user );
	}
}

/**
 * Resolve a safe redirect destination for the click-to-read handler.
 *
 * Validates the requested target against the set of network site hosts (so the
 * redirect can only land on an Extra Chill property), falling back to the
 * community notifications page when the target is empty or disallowed. This
 * keeps the endpoint from being usable as an open redirect.
 *
 * @param string $target Requested target URL.
 * @return string A safe absolute URL to redirect to.
 */
function ec_notifications_read_redirect_safe_target( $target ) {
	$fallback = ec_notifications_read_redirect_fallback_url();

	$target = (string) $target;
	if ( '' === $target ) {
		return $fallback;
	}

	// Allow every network site host as a redirect destination. The notification
	// link can point at any property (community, events, artist, shop, main).
	$allowed_hosts = ec_notifications_read_redirect_allowed_hosts();

	$validated = wp_validate_redirect( $target, '' );
	if ( '' === $validated ) {
		return $fallback;
	}

	$host = wp_parse_url( $validated, PHP_URL_HOST );
	if ( $host && ! in_array( strtolower( $host ), $allowed_hosts, true ) ) {
		return $fallback;
	}

	return $validated;
}

/**
 * The hosts a notification redirect is permitted to land on.
 *
 * Every network site host, lower-cased. Falls back to the current site host
 * when the multisite site list is unavailable.
 *
 * @return string[] Lower-cased hostnames.
 */
function ec_notifications_read_redirect_allowed_hosts() {
	$hosts = array();

	if ( function_exists( 'get_sites' ) && is_multisite() ) {
		$sites = get_sites( array( 'number' => 0 ) );
		foreach ( (array) $sites as $site ) {
			$domain = isset( $site->domain ) ? strtolower( (string) $site->domain ) : '';
			if ( '' !== $domain ) {
				$hosts[] = $domain;
			}
		}
	}

	$current = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( $current ) {
		$hosts[] = strtolower( $current );
	}

	return array_values( array_unique( array_filter( $hosts ) ) );
}

/**
 * Fallback redirect URL when no valid target is supplied.
 *
 * The community notifications page (the canonical network notification feed),
 * with graceful degradation to the current site root.
 *
 * @return string
 */
function ec_notifications_read_redirect_fallback_url() {
	if ( function_exists( 'ec_get_site_url' ) ) {
		$community = ec_get_site_url( 'community' );
		if ( $community ) {
			return trailingslashit( $community ) . 'notifications/';
		}
	}

	return home_url( '/' );
}
