<?php
/**
 * Guest RSVP Service
 *
 * Lets a logged-out visitor RSVP to a perk-enabled event with only an email
 * address. The account behind the RSVP comes from extrachill-users'
 * `extrachill/resolve-account-by-email` primitive (existing account reused,
 * otherwise an unclaimed subscriber is created), and attendance goes through
 * the same `ec_users_mark_event()` path as the logged-in Going button, so
 * the existing pass pipeline (rsvp-pass-service.php) issues and emails the
 * pass.
 *
 * The inbox is the ownership proof: the pass code and the claim
 * (set-password) link are only ever delivered by email. The public response
 * says nothing about the account, the pass, or whether the address was
 * already known.
 *
 * Public exposure is a Turnstile-gated extrachill-api route; this file owns
 * only the domain behavior.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request-scoped claim-link handoff between the guest RSVP and the pass email.
 *
 * The pass email is sent from the `ec_users_event_marked` listener, which
 * only receives user/event/blog IDs. The guest flow stores the claim URL it
 * got from the account primitive here so that same email can carry it: one
 * message, not two. Logged-in Going clicks never set it.
 *
 * @param int         $user_id   User ID.
 * @param string|null $claim_url URL to store, '' to clear, or null to read.
 * @return string Stored claim URL for the user ('' when none).
 */
function extrachill_events_rsvp_claim_url_handoff( int $user_id, ?string $claim_url = null ): string {
	static $urls = array();

	if ( null !== $claim_url ) {
		if ( '' === $claim_url ) {
			unset( $urls[ $user_id ] );
		} else {
			$urls[ $user_id ] = $claim_url;
		}
	}

	return $urls[ $user_id ] ?? '';
}

/**
 * Seconds before a repeat guest RSVP may re-send the pass email.
 *
 * A guest who lost the email can RSVP again to get it re-sent; this bounds
 * how often that can mail the same inbox.
 *
 * @return int
 */
function extrachill_events_guest_rsvp_resend_window(): int {
	/**
	 * Filters the guest RSVP pass re-send window in seconds.
	 *
	 * @param int $window Default 10 minutes.
	 */
	return max( 0, (int) apply_filters( 'extrachill_events_guest_rsvp_resend_window', 10 * MINUTE_IN_SECONDS ) );
}

/**
 * RSVP a guest to an event by email.
 *
 * @param int    $event_id    Event post ID.
 * @param string $email       Guest email address.
 * @param array  $attribution Optional `referrer` (string) and `utm` (array).
 * @return array|WP_Error `{ status: 'rsvped' }` or an error.
 */
function extrachill_events_guest_rsvp( int $event_id, string $email, array $attribution = array() ) {
	$post = get_post( $event_id );
	if ( ! $post || 'data_machine_events' !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	if ( ! extrachill_events_perk_enabled( $event_id ) ) {
		return new WP_Error( 'guest_rsvp_unavailable', __( 'Guest RSVP is not available for this event.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	if ( function_exists( 'ec_users_get_event_timing' ) && 'past' === ec_users_get_event_timing( $event_id, (int) get_current_blog_id() ) ) {
		return new WP_Error( 'guest_rsvp_closed', __( 'This event has already happened.', 'extrachill-events' ), array( 'status' => 410 ) );
	}

	$email = sanitize_email( $email );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$resolve = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/resolve-account-by-email' ) : null;
	if ( ! $resolve || ! function_exists( 'ec_users_mark_event' ) ) {
		return new WP_Error( 'guest_rsvp_unavailable', __( 'Guest RSVP is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	$resolve_input = array(
		'email'               => $email,
		'registration_source' => 'event_rsvp',
	);
	if ( ! empty( $attribution['referrer'] ) ) {
		$resolve_input['referrer'] = (string) $attribution['referrer'];
	}
	if ( ! empty( $attribution['utm'] ) && is_array( $attribution['utm'] ) ) {
		$resolve_input['utm'] = $attribution['utm'];
	}

	$account = $resolve->execute( $resolve_input );
	if ( is_wp_error( $account ) ) {
		return 'invalid_email' === $account->get_error_code()
			? $account
			: new WP_Error( 'guest_rsvp_failed', __( 'Your RSVP could not be saved. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	$user_id = (int) ( $account['user_id'] ?? 0 );
	if ( $user_id <= 0 ) {
		return new WP_Error( 'guest_rsvp_failed', __( 'Your RSVP could not be saved. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	extrachill_events_rsvp_claim_url_handoff( $user_id, (string) ( $account['claim_url'] ?? '' ) );

	try {
		$marked = ec_users_mark_event( $user_id, $event_id, (int) get_current_blog_id() );

		if ( is_wp_error( $marked ) ) {
			return new WP_Error( 'guest_rsvp_failed', __( 'Your RSVP could not be saved. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		// Newly marked: the ec_users_event_marked listener already issued and
		// emailed the pass. Already marked: re-send so a guest who lost the
		// email can get it again, bounded per user and event.
		if ( false === $marked ) {
			extrachill_events_guest_rsvp_resend_pass( $event_id, $user_id );
		} else {
			extrachill_events_guest_rsvp_start_resend_window( $event_id, $user_id );
		}
	} finally {
		extrachill_events_rsvp_claim_url_handoff( $user_id, '' );
	}

	return array( 'status' => 'rsvped' );
}

/**
 * Re-send (or late-issue) the pass for an attendee who is already marked.
 *
 * @param int $event_id Event post ID.
 * @param int $user_id  User ID.
 */
function extrachill_events_guest_rsvp_resend_pass( int $event_id, int $user_id ): void {
	if ( ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		return;
	}

	if ( false !== get_transient( extrachill_events_guest_rsvp_resend_key( $event_id, $user_id ) ) ) {
		return;
	}

	$pass = \ExtraChillEvents\Core\RsvpPassesTable::issue_or_reactivate( $event_id, $user_id );
	if ( ! $pass ) {
		return;
	}

	extrachill_events_guest_rsvp_start_resend_window( $event_id, $user_id );
	extrachill_events_send_rsvp_pass_email( $event_id, $user_id, $pass );
}

/**
 * Transient key bounding pass re-sends for one attendee and event.
 *
 * @param int $event_id Event post ID.
 * @param int $user_id  User ID.
 * @return string
 */
function extrachill_events_guest_rsvp_resend_key( int $event_id, int $user_id ): string {
	return 'ec_guest_rsvp_resend_' . $event_id . '_' . $user_id;
}

/**
 * Start the re-send window after a pass email went out for a guest RSVP.
 *
 * @param int $event_id Event post ID.
 * @param int $user_id  User ID.
 */
function extrachill_events_guest_rsvp_start_resend_window( int $event_id, int $user_id ): void {
	$window = extrachill_events_guest_rsvp_resend_window();
	if ( $window > 0 ) {
		set_transient( extrachill_events_guest_rsvp_resend_key( $event_id, $user_id ), 1, $window );
	}
}
