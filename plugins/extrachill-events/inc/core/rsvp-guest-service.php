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
 * Guest list events (extrachill#433) also require the guest list password
 * and an open spot. Both are checked before any account is resolved or
 * created, so a wrong password or a full list leaves no trace.
 *
 * @param int    $event_id    Event post ID.
 * @param string $email       Guest email address.
 * @param array  $attribution Optional `referrer` (string) and `utm` (array).
 * @param string $password    Guest list password, when the event has one.
 * @return array|WP_Error `{ status: 'rsvped' }` or an error.
 */
function extrachill_events_guest_rsvp( int $event_id, string $email, array $attribution = array(), string $password = '' ) {
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

	$claim_error = extrachill_events_guest_list_claim_error( $event_id, $password );
	if ( $claim_error ) {
		return $claim_error;
	}

	if ( extrachill_events_guest_list_is_full( $event_id ) ) {
		return extrachill_events_guest_list_full_error();
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
	extrachill_events_rsvp_guest_list_authorization( $event_id, $user_id, true );

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
		extrachill_events_rsvp_guest_list_authorization( $event_id, $user_id, false );
	}

	// On a capped list the last spot can go between the pre-check above and
	// the locked issuance. Tell the guest instead of claiming success.
	if ( extrachill_events_get_perk_capacity( $event_id ) > 0 && ! extrachill_events_guest_list_holds_pass( $event_id, $user_id ) ) {
		return extrachill_events_guest_list_full_error();
	}

	return array( 'status' => 'rsvped' );
}

/**
 * Whether the user holds a pass that counts as their guest list spot.
 *
 * @param int $event_id Event post ID.
 * @param int $user_id  User ID.
 * @return bool
 */
function extrachill_events_guest_list_holds_pass( int $event_id, int $user_id ): bool {
	if ( ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		return false;
	}

	$pass = \ExtraChillEvents\Core\RsvpPassesTable::find_for_user_event( $event_id, $user_id );
	return $pass && \ExtraChillEvents\Core\RsvpPassesTable::STATUS_REVOKED !== $pass['status'];
}

/**
 * Whether a capped guest list has no spots left.
 *
 * @param int $event_id Event post ID.
 * @return bool
 */
function extrachill_events_guest_list_is_full( int $event_id ): bool {
	$capacity = extrachill_events_get_perk_capacity( $event_id );
	if ( $capacity <= 0 || ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		return false;
	}

	return \ExtraChillEvents\Core\RsvpPassesTable::count_for_event( $event_id )['claimed'] >= $capacity;
}

/**
 * The error returned when every spot is claimed.
 *
 * @return WP_Error
 */
function extrachill_events_guest_list_full_error(): WP_Error {
	return new WP_Error( 'guest_list_full', __( 'The guest list is full.', 'extrachill-events' ), array( 'status' => 409 ) );
}

/**
 * The error returned for a wrong or missing guest list password.
 *
 * Brute-force limiting is a transport concern: both public write boundaries
 * (extrachill-api guest-rsvp and guest-list-pass) apply the shared per-client
 * public-write rate limit before this runs.
 *
 * @return WP_Error
 */
function extrachill_events_guest_list_password_error(): WP_Error {
	return new WP_Error( 'invalid_password', __( "That password isn't right.", 'extrachill-events' ), array( 'status' => 403 ) );
}

/**
 * Claim a guest list pass as a logged-in user.
 *
 * The logged-in twin of the guest RSVP: checks the password and an open
 * spot, marks the user Going through extrachill-users, and returns the pass
 * so it can be shown on screen. Already-Going users (who marked before
 * they had the password) are issued their pass directly.
 *
 * @param int    $event_id Event post ID.
 * @param int    $user_id  Claiming user ID.
 * @param string $password Guest list password, when the event has one.
 * @return array|WP_Error `{ issued: true, code, perk_text, status }` or an error.
 */
function extrachill_events_claim_guest_list_pass( int $event_id, int $user_id, string $password = '' ) {
	$post = get_post( $event_id );
	if ( ! $post || 'data_machine_events' !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	if ( $user_id <= 0 ) {
		return new WP_Error( 'guest_list_login_required', __( 'Log in to claim a pass.', 'extrachill-events' ), array( 'status' => 401 ) );
	}

	if ( ! extrachill_events_perk_enabled( $event_id ) ) {
		return new WP_Error( 'guest_list_unavailable', __( 'This event has no guest list.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	if ( function_exists( 'ec_users_get_event_timing' ) && 'past' === ec_users_get_event_timing( $event_id, (int) get_current_blog_id() ) ) {
		return new WP_Error( 'guest_rsvp_closed', __( 'This event has already happened.', 'extrachill-events' ), array( 'status' => 410 ) );
	}

	$claim_error = extrachill_events_guest_list_claim_error( $event_id, $password );
	if ( $claim_error ) {
		return $claim_error;
	}

	if ( ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) || ! function_exists( 'ec_users_mark_event' ) ) {
		return new WP_Error( 'guest_list_unavailable', __( 'The guest list is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	if ( ! \ExtraChillEvents\Core\RsvpPassesTable::has_room_for( $event_id, $user_id, extrachill_events_get_perk_capacity( $event_id ) ) ) {
		return extrachill_events_guest_list_full_error();
	}

	extrachill_events_rsvp_guest_list_authorization( $event_id, $user_id, true );
	try {
		$marked = ec_users_mark_event( $user_id, $event_id, (int) get_current_blog_id() );
		if ( is_wp_error( $marked ) ) {
			return new WP_Error( 'guest_list_claim_failed', __( 'Your spot could not be saved. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		// Already Going: the listener didn't fire, so issue here.
		if ( false === $marked ) {
			$pass = extrachill_events_issue_rsvp_pass( $event_id, $user_id );
			if ( $pass ) {
				extrachill_events_send_rsvp_pass_email( $event_id, $user_id, $pass );
			}
		}
	} finally {
		extrachill_events_rsvp_guest_list_authorization( $event_id, $user_id, false );
	}

	$pass = \ExtraChillEvents\Core\RsvpPassesTable::find_for_user_event( $event_id, $user_id );
	if ( $pass && \ExtraChillEvents\Core\RsvpPassesTable::STATUS_REDEEMED === $pass['status'] ) {
		return new WP_Error( 'guest_list_already_checked_in', __( "You're already checked in.", 'extrachill-events' ), array( 'status' => 409 ) );
	}
	if ( ! $pass || \ExtraChillEvents\Core\RsvpPassesTable::STATUS_ACTIVE !== $pass['status'] ) {
		return extrachill_events_guest_list_full_error();
	}

	return array(
		'issued'    => true,
		'code'      => (string) $pass['code'],
		'perk_text' => extrachill_events_get_perk_text( $event_id ),
		'status'    => (string) $pass['status'],
	);
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

	if ( ! extrachill_events_rsvp_pass_may_issue( $event_id, $user_id ) ) {
		return;
	}

	$pass = extrachill_events_issue_rsvp_pass( $event_id, $user_id );
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

/**
 * Issue a guest list pass for the password alone (extrachill#433).
 *
 * The /guest-list page flow: no account, no required email. The password is
 * the gate and the capacity is the bound; the returned code is a bearer
 * token the guest keeps on their phone and shows at the door. An email is
 * optional and only used to send a copy of the pass.
 *
 * Only password-mode events take this self-serve claim. A host_list event
 * answers guest_list_host_managed; an open (going) perk keeps the RSVP
 * flow, where the pass is tied to the attendee.
 *
 * @param int    $event_id Event post ID.
 * @param string $password Guest list password.
 * @param string $name     Guest's name, shown to the door at check-in.
 * @param string $email    Optional address to send a copy to.
 * @return array|WP_Error `{ code, name, qr_url, perk_text, emailed, spots_left }` or an error.
 */
function extrachill_events_issue_guest_list_pass( int $event_id, string $password, string $name, string $email = '' ) {
	$post = get_post( $event_id );
	if ( ! $post || 'data_machine_events' !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	if ( extrachill_events_perk_enabled( $event_id ) && EXTRACHILL_EVENTS_PERK_MODE_HOST_LIST === extrachill_events_get_perk_mode( $event_id ) ) {
		return extrachill_events_guest_list_claim_error( $event_id, $password );
	}

	if ( ! extrachill_events_perk_enabled( $event_id ) || ! extrachill_events_guest_list_accepts_claims( $event_id ) ) {
		return new WP_Error( 'guest_list_unavailable', __( 'This event has no guest list.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	if ( function_exists( 'ec_users_get_event_timing' ) && 'past' === ec_users_get_event_timing( $event_id, (int) get_current_blog_id() ) ) {
		return new WP_Error( 'guest_rsvp_closed', __( 'This event has already happened.', 'extrachill-events' ), array( 'status' => 410 ) );
	}

	$name = trim( sanitize_text_field( $name ) );
	if ( '' === $name ) {
		return new WP_Error( 'guest_name_required', __( 'Enter your name.', 'extrachill-events' ), array( 'status' => 400 ) );
	}
	$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 );

	$email = '' === $email ? '' : sanitize_email( $email );
	if ( '' !== $email && ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Enter a valid email address, or leave it blank.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$claim_error = extrachill_events_guest_list_claim_error( $event_id, $password );
	if ( $claim_error ) {
		return $claim_error;
	}

	if ( ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		return new WP_Error( 'guest_list_unavailable', __( 'The guest list is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	$capacity = extrachill_events_get_perk_capacity( $event_id );
	$pass     = \ExtraChillEvents\Core\RsvpPassesTable::issue_anonymous( $event_id, $capacity, $name );
	if ( ! $pass ) {
		return extrachill_events_guest_list_full_error();
	}

	if ( $capacity > 0 && function_exists( 'extrachill_cache_purge_post' ) ) {
		extrachill_cache_purge_post( $event_id );
	}

	$code    = (string) $pass['code'];
	$emailed = '' !== $email && extrachill_events_send_guest_list_pass_email( $event_id, $email, $code, $name );
	$counts  = extrachill_events_get_guest_list_counts( $event_id );

	return array(
		'code'       => $code,
		'name'       => $name,
		'qr_url'     => function_exists( 'extrachill_events_rsvp_pass_qr_url' ) ? extrachill_events_rsvp_pass_qr_url( $code ) : '',
		'perk_text'  => extrachill_events_get_perk_text( $event_id ),
		'emailed'    => $emailed,
		'spots_left' => isset( $counts['spots_left'] ) ? (int) $counts['spots_left'] : null,
	);
}

/**
 * Link that opens a guest list pass on the guest's phone: the event's
 * /guest-list page with the code and name, which assets/js/rsvp-pass.js
 * stores and shows. Used by the pass email and by hosts texting a comp.
 *
 * @param int    $event_id Event post ID.
 * @param string $code     Pass code.
 * @param string $name     Guest name.
 * @return string
 */
function extrachill_events_guest_list_pass_link( int $event_id, string $code, string $name = '' ): string {
	return add_query_arg(
		array(
			'pass' => rawurlencode( $code ),
			'name' => rawurlencode( $name ),
		),
		extrachill_events_guest_list_url( $event_id )
	);
}

/**
 * A host puts a guest on the list by name (extrachill#1119): no password,
 * no claiming. The comp gets the same account-less pass as a password
 * claim, counts against the same spots, and shows on the door list with
 * the host who added it. Authorization (the event's host) is the caller's
 * job: the extrachill/add-guest-list-name ability checks it.
 *
 * Works in every access mode (extrachill#1246): host_list, password, and
 * going. Only the perk has to be on, which also gives the pass link a
 * /guest-list page to open on. No password is ever involved.
 *
 * Invited bands and management (extrachill#1128) add names through this
 * same path with their party ID, and their allotment when allotments are
 * on; see inc/core/guest-list-parties.php.
 *
 * @param int    $event_id     Event post ID.
 * @param string $name         Guest name, shown to the door at check-in.
 * @param string $email        Optional address to send the pass to.
 * @param int    $host_user_id Host adding the guest (0 when an invited party adds it).
 * @param int    $party_id     Invited party adding the guest; 0 for a host add.
 * @param int    $party_cap    The party's allotment, enforced under the capacity lock. 0 = no party cap.
 * @return array|WP_Error `{ code, name, pass_url, qr_url, emailed, spots_left }` or an error.
 */
function extrachill_events_add_guest_list_name( int $event_id, string $name, string $email = '', int $host_user_id = 0, int $party_id = 0, int $party_cap = 0 ) {
	$post = get_post( $event_id );
	if ( ! $post || 'data_machine_events' !== $post->post_type ) {
		return new WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	// Every mode takes host adds (extrachill#1246); only the perk must be on.
	if ( ! extrachill_events_perk_enabled( $event_id ) ) {
		return new WP_Error( 'guest_list_unavailable', __( 'Turn on the guest list and save before adding names.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	if ( function_exists( 'ec_users_get_event_timing' ) && 'past' === ec_users_get_event_timing( $event_id, (int) get_current_blog_id() ) ) {
		return new WP_Error( 'guest_rsvp_closed', __( 'This event has already happened.', 'extrachill-events' ), array( 'status' => 410 ) );
	}

	$name = trim( sanitize_text_field( $name ) );
	if ( '' === $name ) {
		return new WP_Error( 'guest_name_required', __( 'Enter the guest\'s name.', 'extrachill-events' ), array( 'status' => 400 ) );
	}
	$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 );

	$email = '' === $email ? '' : sanitize_email( $email );
	if ( '' !== $email && ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Enter a valid email address, or leave it blank.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	if ( ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		return new WP_Error( 'guest_list_unavailable', __( 'The guest list is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	$capacity = extrachill_events_get_perk_capacity( $event_id );
	$pass     = \ExtraChillEvents\Core\RsvpPassesTable::issue_anonymous( $event_id, $capacity, $name, $host_user_id, $party_id, $party_cap );
	if ( ! $pass ) {
		return extrachill_events_guest_list_full_error();
	}

	if ( function_exists( 'extrachill_cache_purge_post' ) ) {
		extrachill_cache_purge_post( $event_id );
	}

	$code    = (string) $pass['code'];
	$emailed = '' !== $email && extrachill_events_send_guest_list_pass_email( $event_id, $email, $code, $name );
	$counts  = extrachill_events_get_guest_list_counts( $event_id );

	return array(
		'code'       => $code,
		'name'       => $name,
		'pass_url'   => extrachill_events_guest_list_pass_link( $event_id, $code, $name ),
		'qr_url'     => function_exists( 'extrachill_events_rsvp_pass_qr_url' ) ? extrachill_events_rsvp_pass_qr_url( $code ) : '',
		'emailed'    => $emailed,
		'spots_left' => isset( $counts['spots_left'] ) ? (int) $counts['spots_left'] : null,
	);
}

/**
 * A host takes a guest off the list by pass code. Frees the spot. A guest
 * who already checked in stays checked in.
 *
 * @param int    $event_id Event post ID.
 * @param string $code     Pass code.
 * @return array|WP_Error `{ removed: true, spots_left }` or an error.
 */
function extrachill_events_remove_guest_list_name( int $event_id, string $code ) {
	if ( ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		return new WP_Error( 'guest_list_unavailable', __( 'The guest list is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	$code = strtoupper( trim( $code ) );
	if ( ! \ExtraChillEvents\Core\RsvpPassesTable::revoke_by_code( $event_id, $code ) ) {
		return new WP_Error( 'guest_not_removable', __( 'That guest is not on this list, or has already checked in.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	if ( function_exists( 'extrachill_cache_purge_post' ) ) {
		extrachill_cache_purge_post( $event_id );
	}

	$counts = extrachill_events_get_guest_list_counts( $event_id );
	return array(
		'removed'    => true,
		'spots_left' => isset( $counts['spots_left'] ) ? (int) $counts['spots_left'] : null,
	);
}

/**
 * Email a copy of an account-less guest list pass.
 *
 * Same branded template and body as the RSVP pass email, addressed to the
 * email the guest typed. Nothing is stored about the address.
 *
 * @param int    $event_id Event post ID.
 * @param string $email    Recipient.
 * @param string $code     Pass code.
 * @param string $name     Guest name.
 * @return bool Whether the email was queued.
 */
function extrachill_events_send_guest_list_pass_email( int $event_id, string $email, string $code, string $name = '' ): bool {
	$post = get_post( $event_id );
	if ( ! $post || ! function_exists( 'ec_send_email_queued' ) ) {
		return false;
	}

	$result = ec_send_email_queued(
		array(
			'to'        => $email,
			/* translators: %s: Event title. */
			'subject'   => sprintf( __( 'Your guest list pass for %s', 'extrachill-events' ), extrachill_events_rsvp_pass_event_title( $post ) ),
			'from_name' => 'Extra Chill Events',
			'template'  => 'extrachill/branded',
			'context'   => array(
				'body_html'      => extrachill_events_render_rsvp_pass_email_body( $post, extrachill_events_get_perk_text( $event_id ), $code ),
				'recipient_name' => $name,
				'cta_url'        => extrachill_events_guest_list_pass_link( $event_id, $code, $name ),
				'cta_label'      => __( 'Open my pass', 'extrachill-events' ),
			),
		)
	);

	$queued = extrachill_events_rsvp_pass_email_queued( $result );
	if ( ! $queued ) {
		do_action(
			'datamachine_log',
			'error',
			'Guest list pass email was not queued',
			array(
				'event_id'   => $event_id,
				'error_code' => (string) ( $result['error_code'] ?? '' ),
			)
		);
	}
	return $queued;
}
