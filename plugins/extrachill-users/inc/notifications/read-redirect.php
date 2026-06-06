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
 *   - Hitting the route (GET) verifies the notification belongs to the CURRENT
 *     logged-in user, marks that single row read via the canonical substrate
 *     ability (extrachill/mark-notifications-read), then 302-redirects to the
 *     target URL. The bell badge naturally decrements per click.
 *
 * Security:
 *   - Login required (permission_callback checks is_user_logged_in()). The
 *     authority is the session, not a token — a user can only mark their OWN
 *     notifications read because mark-notifications-read is scoped to user_id =
 *     get_current_user_id() and the substrate UPDATE is keyed by user_id.
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
 * Build the click-to-read redirect URL for a single notification.
 *
 * Wraps a notification's real target URL so that visiting it first marks that
 * one notification read, then forwards to the target. Returns the raw target
 * unchanged when inputs are unusable so callers never emit a broken link.
 *
 * @param int    $notification_id Notification row ID.
 * @param string $target_url      The notification's real destination URL.
 * @return string Read-redirect URL, or the target unchanged on invalid input.
 */
function ec_notifications_read_redirect_url( $notification_id, $target_url ) {
	$notification_id = (int) $notification_id;
	$target_url      = (string) $target_url;

	if ( $notification_id <= 0 || '' === $target_url ) {
		return $target_url;
	}

	return add_query_arg(
		array(
			'id' => $notification_id,
			'to' => rawurlencode( $target_url ),
		),
		rest_url( 'extrachill/v1/notifications/read' )
	);
}

/**
 * Build the "mark all as read" URL for the current user.
 *
 * A GET link (no AJAX) into the read-all route that marks every unread
 * notification read, then redirects back to the supplied target (the
 * notifications page). The session is the authority — no per-user token needed.
 *
 * @param string $target_url Where to return after marking all read.
 * @return string Mark-all-read URL.
 */
function ec_notifications_mark_all_read_url( $target_url ) {
	$target_url = (string) $target_url;

	$args = array();
	if ( '' !== $target_url ) {
		$args['to'] = rawurlencode( $target_url );
	}

	return add_query_arg( $args, rest_url( 'extrachill/v1/notifications/read-all' ) );
}

add_action( 'rest_api_init', 'ec_notifications_register_read_redirect_route' );

/**
 * Register the click-to-read + mark-all-read redirect REST routes.
 *
 * Both GET + login-required: they mark notifications read for the CURRENT user
 * then redirect. The session is the authority.
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
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'to' => array(
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
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'to' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);
}

/**
 * Handle "mark all as read": clear the current user's unread, then redirect.
 *
 * @param WP_REST_Request $request Request.
 * @return void Always redirects and exits.
 */
function ec_notifications_handle_read_all( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	$target  = (string) $request->get_param( 'to' );

	if ( $user_id > 0 && function_exists( 'wp_get_ability' ) ) {
		$ability = wp_get_ability( 'extrachill/mark-notifications-read' );
		if ( $ability ) {
			// notification_id 0 marks ALL unread for this user (substrate semantics).
			$ability->execute(
				array(
					'user_id'         => $user_id,
					'notification_id' => 0,
				)
			);
		}
	}

	wp_safe_redirect( ec_notifications_read_redirect_safe_target( $target ) );
	exit;
}

/**
 * Handle the click-to-read redirect: mark one notification read, then redirect.
 *
 * Marks the single notification (scoped to the current user via the substrate
 * ability — a foreign notification id is a no-op), validates the target against
 * the network's allowed hosts, and 302-redirects. Always redirects; never
 * returns a JSON body.
 *
 * @param WP_REST_Request $request Request.
 * @return void Always redirects and exits.
 */
function ec_notifications_handle_read_redirect( WP_REST_Request $request ) {
	$user_id         = get_current_user_id();
	$notification_id = (int) $request->get_param( 'id' );
	$target          = (string) $request->get_param( 'to' );

	if ( $user_id > 0 && $notification_id > 0 && function_exists( 'wp_get_ability' ) ) {
		$ability = wp_get_ability( 'extrachill/mark-notifications-read' );
		if ( $ability ) {
			// Scoped to this user + this single id. A notification that does not
			// belong to the user updates zero rows (the substrate UPDATE is keyed
			// by user_id), so this can't be abused to read other users' state.
			$ability->execute(
				array(
					'user_id'         => $user_id,
					'notification_id' => $notification_id,
				)
			);
		}
	}

	$destination = ec_notifications_read_redirect_safe_target( $target );

	wp_safe_redirect( $destination );
	exit;
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
