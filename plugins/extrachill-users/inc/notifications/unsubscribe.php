<?php
/**
 * One-click digest-email unsubscribe.
 *
 * CAN-SPAM / good-citizen requirement for the unread-notification digest
 * (inc/notifications/email.php): every digest email carries a tokenized
 * one-click unsubscribe link in its footer. Hitting the link sets the
 * ec_notification_emails_disabled flag for that user WITHOUT requiring login,
 * then renders a small confirmation page.
 *
 * Security:
 *   - The link carries a uid + an HMAC signature, NOT a raw/guessable user_id.
 *     The signature is keyed by wp_salt('auth') (a per-install secret) via
 *     hash_hmac('sha256', ...), so a tampered uid is rejected. We reuse WP's
 *     own signing material rather than rolling a new secret.
 *   - Tokens are time-boxed: the signed payload includes an issue timestamp and
 *     links expire after EC_NOTIFICATIONS_UNSUBSCRIBE_TTL.
 *   - The flag is written ONLY through the canonical setter
 *     ec_users_set_notification_emails_disabled() (defined in email.php) — the
 *     same setter the settings-UI toggle uses.
 *   - No AJAX (system-wide rule): the landing is a GET REST route that runs
 *     before the template layer, renders confirmation HTML, and exits — the
 *     same pattern as the browser-handoff handler.
 *
 * Issues: Extra-Chill/extrachill-users#97 (this), Extra-Chill/extrachill-users#96
 *         (the settings toggle sharing the canonical setter).
 * Parent epic: Extra-Chill/extrachill-community#82.
 *
 * @package ExtraChill\Users
 * @since 0.15.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/email.php';

/**
 * How long a one-click unsubscribe link stays valid (seconds).
 *
 * Digests are infrequent, but a generous window keeps the link working if a
 * user opens an older email. 30 days balances usability and replay surface.
 */
if ( ! defined( 'EC_NOTIFICATIONS_UNSUBSCRIBE_TTL' ) ) {
	define( 'EC_NOTIFICATIONS_UNSUBSCRIBE_TTL', 30 * DAY_IN_SECONDS );
}

/**
 * Action context string mixed into the HMAC so a signature minted for the
 * unsubscribe flow can't be replayed against any other wp_hash-style use.
 */
const EC_NOTIFICATIONS_UNSUBSCRIBE_ACTION = 'ec_notifications_unsubscribe';

/**
 * Compute the HMAC signature for an unsubscribe link.
 *
 * Keyed by wp_salt('auth') (per-install secret). Binds the user id, the issue
 * timestamp, and a fixed action context so the signature is non-transferable.
 *
 * @param int $user_id  User ID.
 * @param int $issued_at Unix timestamp the link was issued.
 * @return string Hex HMAC signature.
 */
function ec_notifications_unsubscribe_signature( $user_id, $issued_at ) {
	$message = EC_NOTIFICATIONS_UNSUBSCRIBE_ACTION . '|' . (int) $user_id . '|' . (int) $issued_at;
	return hash_hmac( 'sha256', $message, wp_salt( 'auth' ) );
}

/**
 * Build a signed one-click unsubscribe URL for a user.
 *
 * Returns a GET URL into the extrachill/v1/notifications/unsubscribe REST route
 * carrying uid, ts (issue time) and sig (HMAC). Returns '' for an invalid user.
 *
 * @param int $user_id User ID.
 * @return string Absolute unsubscribe URL, or '' on invalid input.
 */
function ec_notifications_unsubscribe_url( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return '';
	}

	$issued_at = time();
	$sig       = ec_notifications_unsubscribe_signature( $user_id, $issued_at );

	return add_query_arg(
		array(
			'uid' => $user_id,
			'ts'  => $issued_at,
			'sig' => $sig,
		),
		rest_url( 'extrachill/v1/notifications/unsubscribe' )
	);
}

/**
 * Verify an unsubscribe token triple.
 *
 * Constant-time signature comparison + TTL window check. Returns the verified
 * user ID, or a WP_Error describing why the token is invalid.
 *
 * @param int    $user_id   Claimed user ID.
 * @param int    $issued_at Claimed issue timestamp.
 * @param string $sig       Provided HMAC signature.
 * @return int|WP_Error Verified user ID, or WP_Error on failure.
 */
function ec_notifications_unsubscribe_verify( $user_id, $issued_at, $sig ) {
	$user_id   = (int) $user_id;
	$issued_at = (int) $issued_at;
	$sig       = (string) $sig;

	if ( $user_id <= 0 || $issued_at <= 0 || '' === $sig ) {
		return new WP_Error( 'invalid_unsubscribe_token', 'Invalid unsubscribe link.', array( 'status' => 400 ) );
	}

	// Reject expired or future-dated links.
	$now = time();
	if ( $issued_at > $now + MINUTE_IN_SECONDS || ( $now - $issued_at ) > EC_NOTIFICATIONS_UNSUBSCRIBE_TTL ) {
		return new WP_Error( 'expired_unsubscribe_token', 'This unsubscribe link has expired.', array( 'status' => 400 ) );
	}

	$expected = ec_notifications_unsubscribe_signature( $user_id, $issued_at );
	if ( ! hash_equals( $expected, $sig ) ) {
		return new WP_Error( 'invalid_unsubscribe_token', 'Invalid unsubscribe link.', array( 'status' => 400 ) );
	}

	if ( ! get_userdata( $user_id ) ) {
		return new WP_Error( 'invalid_unsubscribe_token', 'Invalid unsubscribe link.', array( 'status' => 400 ) );
	}

	return $user_id;
}

add_action( 'rest_api_init', 'ec_notifications_register_unsubscribe_route' );

/**
 * Register the one-click unsubscribe REST route.
 *
 * GET + public (no login): the token itself authorizes the single, scoped
 * action of disabling that user's digest emails.
 */
function ec_notifications_register_unsubscribe_route() {
	register_rest_route(
		'extrachill/v1',
		'/notifications/unsubscribe',
		array(
			'methods'             => 'GET',
			'callback'            => 'ec_notifications_handle_unsubscribe',
			'permission_callback' => '__return_true',
			'args'                => array(
				'uid' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'ts'  => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'sig' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}

/**
 * Handle the unsubscribe link: verify token, set opt-out flag, render page.
 *
 * Renders a minimal HTML confirmation page and exits (like the browser-handoff
 * handler), so the user lands on a friendly page rather than a JSON body.
 *
 * @param WP_REST_Request $request Request.
 * @return void Always renders HTML and exits.
 */
function ec_notifications_handle_unsubscribe( WP_REST_Request $request ) {
	$verified = ec_notifications_unsubscribe_verify(
		$request->get_param( 'uid' ),
		$request->get_param( 'ts' ),
		(string) $request->get_param( 'sig' )
	);

	if ( is_wp_error( $verified ) ) {
		ec_notifications_render_unsubscribe_page(
			__( 'Unsubscribe link invalid', 'extrachill-users' ),
			__( 'This unsubscribe link is invalid or has expired. You can manage email preferences from your account settings.', 'extrachill-users' )
		);
		return; // ec_notifications_render_unsubscribe_page() exits.
	}

	// Canonical setter — same path the settings toggle writes through.
	ec_users_set_notification_emails_disabled( $verified, true );

	ec_notifications_render_unsubscribe_page(
		__( 'You\'re unsubscribed', 'extrachill-users' ),
		__( 'You will no longer receive unread-notification emails from Extra Chill. You can turn them back on any time from your account settings.', 'extrachill-users' )
	);
}

/**
 * Render a minimal standalone confirmation page and exit.
 *
 * Standalone (not themed) so it works even when hit before the template layer
 * and regardless of which network site the link resolves through.
 *
 * @param string $title   Page heading.
 * @param string $message Body message.
 * @return void Exits.
 */
function ec_notifications_render_unsubscribe_page( $title, $message ) {
	if ( ! headers_sent() ) {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
	}

	echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<meta name="robots" content="noindex,nofollow">';
	echo '<title>' . esc_html( $title ) . '</title>';
	echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f6f6f6;color:#222;margin:0;padding:0}'
		. '.wrap{max-width:520px;margin:10vh auto;padding:2rem;background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);text-align:center}'
		. 'h1{font-size:1.4rem;margin:0 0 1rem}p{line-height:1.5;margin:0 0 1.5rem}'
		. 'a{display:inline-block;color:#fff;background:#222;text-decoration:none;padding:.65rem 1.25rem;border-radius:6px}</style>';
	echo '</head><body><div class="wrap">';
	echo '<h1>' . esc_html( $title ) . '</h1>';
	echo '<p>' . esc_html( $message ) . '</p>';
	echo '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to Extra Chill', 'extrachill-users' ) . '</a>';
	echo '</div></body></html>';

	exit;
}
