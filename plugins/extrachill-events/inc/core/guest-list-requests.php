<?php
/**
 * Guest List Requests (extrachill#1129)
 *
 * Venue-side anti-scam layer on top of invited parties
 * (inc/core/guest-list-parties.php). Nothing a band submits on its own
 * works at the door until the venue's team accepts it:
 *
 * - **More spots.** With allotments on, an invited band asks for N more
 *   spots from its private page. The ask sits on the party row until a host
 *   approves (the allotment grows by the asked, or host-edited, amount) or
 *   denies it.
 * - **Submitted lists.** A band sends a whole list of names at once. Each
 *   name is an account-less pass in `pending`: it holds no spot and never
 *   redeems (scanning shows "Not on the list"). Approving activates the
 *   whole list under the capacity lock and, with allotments on, grows the
 *   allotment to cover it; denying marks every name `denied` for good.
 *   Names a band adds one at a time within its spots stay instant.
 * - **Uninvited requests.** A band or manager asks for spots on a show it
 *   wasn't invited to, from the public guest list page. That creates a
 *   `pending` party with no working link. Accepting turns it into a normal
 *   party and mails a fresh invite link; denying emails the requester.
 *
 * Hosts are told by email (and the network notification bell when
 * extrachill-users is active) and decide from the "Pending requests"
 * section of the host panel, the abilities, or the CLI. Every decision
 * re-checks extrachill_events_user_can_manage_event() here too, so no
 * caller can skip the venue's authority.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\GuestListPartiesTable;
use ExtraChillEvents\Core\RsvpPassesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Most names one submission (or one uninvited request) may carry. */
const EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_NAMES = 50;

/** Most spots one ask may request. */
const EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_SPOTS = 50;

/** Most names a party may have waiting for approval at once. */
const EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_PENDING = 100;

/** Public requests allowed per IP address per hour. */
const EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_IP_LIMIT = 5;

/** Public requests allowed per email address per hour. */
const EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_EMAIL_LIMIT = 3;

/** Request kinds a host decides. */
const EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_KINDS = array( 'party', 'spots', 'list' );

/**
 * Turn a pasted list (one name per line) or an array into clean names.
 *
 * @param string|array $names Raw names.
 * @return string[] Cleaned, de-duplicated, non-empty names, in order.
 */
function extrachill_events_guest_list_parse_names( $names ): array {
	$lines = is_array( $names ) ? $names : preg_split( '/\r\n|\r|\n/', (string) $names );
	$clean = array();
	foreach ( (array) $lines as $line ) {
		$name = extrachill_events_guest_list_clean_name( (string) $line );
		if ( '' !== $name && ! in_array( $name, $clean, true ) ) {
			$clean[] = $name;
		}
	}
	return $clean;
}

/**
 * Normalize a note to the venue: plain text, 500 chars.
 *
 * @param string $note Raw note.
 * @return string
 */
function extrachill_events_guest_list_clean_note( string $note ): string {
	$note = trim( sanitize_textarea_field( $note ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 500 ) : substr( $note, 0, 500 );
}

/**
 * The error for a decision on something that is not waiting.
 *
 * @return WP_Error
 */
function extrachill_events_guest_list_request_not_found_error(): WP_Error {
	return new WP_Error( 'guest_list_request_not_found', __( 'That request is not waiting on this event, or was already decided.', 'extrachill-events' ), array( 'status' => 404 ) );
}

/**
 * An invited party asks the venue for more spots (allotments on only).
 *
 * @param string $token Raw invite token.
 * @param int    $spots Extra spots asked for.
 * @param string $note  Optional note to the venue.
 * @return array|WP_Error `{ requested_spots }` or an error.
 */
function extrachill_events_party_request_more_spots( string $token, int $spots, string $note = '' ) {
	$party = extrachill_events_resolve_guest_list_party( $token );
	if ( is_wp_error( $party ) ) {
		return $party;
	}

	$event_id = (int) $party['event_id'];
	if ( ! extrachill_events_guest_list_allotments_enabled( $event_id ) ) {
		return new WP_Error( 'allotments_off', __( 'This guest list has no per-band limit: add names until the list is full.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	if ( $spots < 1 || $spots > EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_SPOTS ) {
		/* translators: %d: most spots one ask may request. */
		return new WP_Error( 'invalid_spots', sprintf( __( 'Ask for between 1 and %d spots.', 'extrachill-events' ), EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_SPOTS ), array( 'status' => 400 ) );
	}

	if ( ! GuestListPartiesTable::set_spot_request( $event_id, (int) $party['id'], $spots, extrachill_events_guest_list_clean_note( $note ) ) ) {
		return new WP_Error( 'guest_list_request_failed', __( 'Your request could not be saved. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	extrachill_events_notify_guest_list_request(
		$event_id,
		(array) GuestListPartiesTable::find( (int) $party['id'] ),
		'spots',
		/* translators: 1: band name, 2: extra spots asked for. */
		sprintf( _n( '%1$s asked for %2$d more guest list spot.', '%1$s asked for %2$d more guest list spots.', $spots, 'extrachill-events' ), (string) $party['name'], $spots )
	);

	return array( 'requested_spots' => $spots );
}

/**
 * An invited party submits a list of names for the venue's approval.
 *
 * The names are held as pending passes: no spot taken, nothing scans,
 * until a host approves the list.
 *
 * @param string       $token Raw invite token.
 * @param string|array $names One name per line, or an array.
 * @return array|WP_Error `{ submitted, pending }` or an error.
 */
function extrachill_events_party_submit_guest_list( string $token, $names ) {
	$party = extrachill_events_resolve_guest_list_party( $token );
	if ( is_wp_error( $party ) ) {
		return $party;
	}

	$event_id = (int) $party['event_id'];
	if ( function_exists( 'ec_users_get_event_timing' ) && 'past' === ec_users_get_event_timing( $event_id, (int) get_current_blog_id() ) ) {
		return new WP_Error( 'guest_rsvp_closed', __( 'This event has already happened.', 'extrachill-events' ), array( 'status' => 410 ) );
	}

	$names = extrachill_events_guest_list_parse_names( $names );
	if ( empty( $names ) ) {
		return new WP_Error( 'guest_names_required', __( 'Enter at least one name, one per line.', 'extrachill-events' ), array( 'status' => 400 ) );
	}
	if ( count( $names ) > EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_NAMES ) {
		/* translators: %d: most names per submission. */
		return new WP_Error( 'too_many_names', sprintf( __( 'Send at most %d names at a time.', 'extrachill-events' ), EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_NAMES ), array( 'status' => 400 ) );
	}

	$party_id = (int) $party['id'];
	if ( RsvpPassesTable::count_pending_for_party( $event_id, $party_id ) + count( $names ) > EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_PENDING ) {
		return new WP_Error( 'too_many_pending', __( 'You already have a lot of names waiting for the venue. Wait for them to decide first.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	$submitted = 0;
	foreach ( $names as $name ) {
		$submitted += RsvpPassesTable::issue_pending( $event_id, $name, $party_id ) ? 1 : 0;
	}
	if ( 0 === $submitted ) {
		return new WP_Error( 'guest_list_request_failed', __( 'Your list could not be saved. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	extrachill_events_notify_guest_list_request(
		$event_id,
		$party,
		'list',
		/* translators: 1: band name, 2: number of names. */
		sprintf( _n( '%1$s sent %2$d name for the guest list.', '%1$s sent %2$d names for the guest list.', $submitted, 'extrachill-events' ), (string) $party['name'], $submitted )
	);

	return array(
		'submitted' => $submitted,
		'pending'   => RsvpPassesTable::count_pending_for_party( $event_id, $party_id ),
	);
}

/**
 * Count one public request against a rate-limit bucket.
 *
 * @param string $key   Limiter key.
 * @param int    $limit Requests allowed per hour.
 * @return bool Whether the bucket was already full.
 */
function extrachill_events_guest_list_request_bucket_full( string $key, int $limit ): bool {
	return true !== extrachill_rate_limit_admit( $key, $limit, HOUR_IN_SECONDS );
}

/**
 * A band or manager asks for spots on a show it was not invited to.
 *
 * Creates a pending party with no working link and, optionally, pending
 * names. Nothing works at the door until a host accepts it.
 *
 * @param int          $event_id  Event post ID.
 * @param string       $name      Band or management name.
 * @param string       $email     Where to reply (required).
 * @param int          $spots     Spots asked for.
 * @param string|array $names     Optional guest names, one per line.
 * @param string       $note      Optional note to the venue.
 * @param string       $client_ip Requester IP, for the rate limit.
 * @return array|WP_Error `{ requested: true, party_id, requested_spots, names }` or an error.
 */
function extrachill_events_request_guest_list_party( int $event_id, string $name, string $email, int $spots, $names = '', string $note = '', string $client_ip = '' ) {
	$post = get_post( $event_id );
	if ( ! $post || 'data_machine_events' !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	if ( ! extrachill_events_perk_enabled( $event_id ) || ! extrachill_events_guest_list_is_gated( $event_id ) ) {
		return new WP_Error( 'guest_list_unavailable', __( 'This event has no guest list.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	if ( function_exists( 'ec_users_get_event_timing' ) && 'past' === ec_users_get_event_timing( $event_id, (int) get_current_blog_id() ) ) {
		return new WP_Error( 'guest_rsvp_closed', __( 'This event has already happened.', 'extrachill-events' ), array( 'status' => 410 ) );
	}

	$name = extrachill_events_guest_list_clean_name( $name );
	if ( '' === $name ) {
		return new WP_Error( 'party_name_required', __( 'Enter the band or management name.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$email = sanitize_email( $email );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Enter a valid email address so the venue can reply.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$names = extrachill_events_guest_list_parse_names( $names );
	if ( count( $names ) > EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_NAMES ) {
		/* translators: %d: most names per request. */
		return new WP_Error( 'too_many_names', sprintf( __( 'Send at most %d names at a time.', 'extrachill-events' ), EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_NAMES ), array( 'status' => 400 ) );
	}

	$spots = max( $spots, count( $names ) );
	if ( $spots < 1 || $spots > EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_SPOTS ) {
		/* translators: %d: most spots one request may ask for. */
		return new WP_Error( 'invalid_spots', sprintf( __( 'Ask for between 1 and %d spots.', 'extrachill-events' ), EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_SPOTS ), array( 'status' => 400 ) );
	}

	if ( ! extrachill_events_guest_list_parties_available() ) {
		return extrachill_events_guest_list_parties_unavailable_error();
	}

	if ( GuestListPartiesTable::has_pending_request( $event_id, $email ) ) {
		return new WP_Error( 'guest_list_request_exists', __( 'You already asked for spots on this show. The venue will email you when they decide.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	// Rate limits: per address and per email, both counted before writing.
	$ip_full    = '' !== $client_ip && extrachill_events_guest_list_request_bucket_full( 'ec_gl_req_ip_' . md5( $client_ip ), EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_IP_LIMIT );
	$email_full = extrachill_events_guest_list_request_bucket_full( 'ec_gl_req_em_' . md5( strtolower( $email ) ), EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_EMAIL_LIMIT );
	if ( $ip_full || $email_full ) {
		return new WP_Error( 'guest_list_request_rate_limited', __( 'Too many requests. Try again in an hour.', 'extrachill-events' ), array( 'status' => 429 ) );
	}

	// The token minted here is discarded: a pending party has no working
	// link. Accepting mints and mails a fresh one.
	$created = GuestListPartiesTable::create(
		$event_id,
		$name,
		$email,
		null,
		0,
		array(
			'requested_spots' => $spots,
			'request_note'    => extrachill_events_guest_list_clean_note( $note ),
		)
	);
	if ( ! $created ) {
		return new WP_Error( 'guest_list_request_failed', __( 'Your request could not be saved. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	$party    = $created['party'];
	$party_id = (int) $party['id'];
	foreach ( $names as $guest ) {
		RsvpPassesTable::issue_pending( $event_id, $guest, $party_id );
	}

	extrachill_events_notify_guest_list_request(
		$event_id,
		$party,
		'party',
		/* translators: 1: band or management name, 2: spots asked for. */
		sprintf( _n( '%1$s asked to be on the guest list with %2$d spot.', '%1$s asked to be on the guest list with %2$d spots.', $spots, 'extrachill-events' ), $name, $spots )
	);

	return array(
		'requested'       => true,
		'party_id'        => $party_id,
		'requested_spots' => $spots,
		'names'           => count( $names ),
	);
}

/**
 * The open request kinds on one party.
 *
 * @param int   $event_id Event post ID.
 * @param array $party    Party row.
 * @return string[] Subset of EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_KINDS.
 */
function extrachill_events_guest_list_party_open_kinds( int $event_id, array $party ): array {
	if ( GuestListPartiesTable::STATUS_PENDING === $party['status'] ) {
		return array( 'party' );
	}
	if ( GuestListPartiesTable::STATUS_ACTIVE !== $party['status'] ) {
		return array();
	}

	$kinds = array();
	if ( isset( $party['requested_spots'] ) && null !== $party['requested_spots'] ) {
		$kinds[] = 'spots';
	}
	if ( RsvpPassesTable::count_pending_for_party( $event_id, (int) $party['id'] ) > 0 ) {
		$kinds[] = 'list';
	}
	return $kinds;
}

/**
 * Host lists everything waiting for the venue on an event.
 *
 * @param int $event_id Event post ID.
 * @return array|WP_Error `{ allotments_enabled, requests: [] }`; each request: party_id, kind, name, email, origin, requested_spots, note, names[], allotment, used, requested_at.
 */
function extrachill_events_list_guest_list_requests( int $event_id ) {
	if ( ! extrachill_events_guest_list_parties_available() ) {
		return extrachill_events_guest_list_parties_unavailable_error();
	}

	$requests = array();
	foreach ( GuestListPartiesTable::list_for_event( $event_id ) as $party ) {
		$kinds = extrachill_events_guest_list_party_open_kinds( $event_id, $party );
		if ( empty( $kinds ) ) {
			continue;
		}

		$pending_names = array();
		foreach ( extrachill_events_guest_list_party_passes( $party ) as $pass ) {
			if ( RsvpPassesTable::STATUS_PENDING === $pass['status'] ) {
				$pending_names[] = (string) $pass['guest_name'];
			}
		}

		foreach ( $kinds as $kind ) {
			$requests[] = array(
				'party_id'        => (int) $party['id'],
				'kind'            => $kind,
				'name'            => (string) $party['name'],
				'email'           => (string) $party['email'],
				'origin'          => (string) ( $party['origin'] ?? GuestListPartiesTable::ORIGIN_INVITE ),
				'requested_spots' => 'list' === $kind || null === ( $party['requested_spots'] ?? null ) ? null : (int) $party['requested_spots'],
				'note'            => 'list' === $kind ? '' : (string) ( $party['request_note'] ?? '' ),
				'names'           => 'spots' === $kind ? array() : $pending_names,
				'allotment'       => isset( $party['allotment'] ) && null !== $party['allotment'] ? (int) $party['allotment'] : null,
				'used'            => RsvpPassesTable::count_for_party( $event_id, (int) $party['id'] ),
				'requested_at'    => (string) ( $party['requested_at'] ?? $party['created_at'] ?? '' ),
			);
		}
	}

	return array(
		'allotments_enabled' => extrachill_events_guest_list_allotments_enabled( $event_id ),
		'requests'           => $requests,
	);
}

/**
 * Load a party for a host decision and settle which kind is being decided.
 *
 * @param int    $event_id      Event post ID.
 * @param int    $party_id      Party ID.
 * @param string $kind          party|spots|list, or '' to infer when only one is open.
 * @param int    $actor_user_id Deciding host.
 * @return array|WP_Error `{ party, kind }` or an error.
 */
function extrachill_events_guest_list_request_for_decision( int $event_id, int $party_id, string $kind, int $actor_user_id ) {
	if ( ! function_exists( 'extrachill_events_user_can_manage_event' ) || ! extrachill_events_user_can_manage_event( $actor_user_id, $event_id ) ) {
		return new WP_Error( 'event_door_list_forbidden', __( 'You are not authorized to manage this event\'s door list.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	if ( ! extrachill_events_guest_list_parties_available() ) {
		return extrachill_events_guest_list_parties_unavailable_error();
	}

	$party = GuestListPartiesTable::find( $party_id );
	if ( ! $party || (int) $party['event_id'] !== $event_id ) {
		return extrachill_events_guest_list_request_not_found_error();
	}

	$open = extrachill_events_guest_list_party_open_kinds( $event_id, $party );
	if ( '' === $kind ) {
		if ( 1 !== count( $open ) ) {
			return empty( $open )
				? extrachill_events_guest_list_request_not_found_error()
				: new WP_Error( 'guest_list_request_kind_required', __( 'This band has more than one request waiting: say which (spots or list).', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$kind = $open[0];
	}

	if ( ! in_array( $kind, EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_KINDS, true ) ) {
		return new WP_Error( 'invalid_request_kind', __( 'Request kind must be party, spots, or list.', 'extrachill-events' ), array( 'status' => 400 ) );
	}
	if ( ! in_array( $kind, $open, true ) ) {
		return extrachill_events_guest_list_request_not_found_error();
	}

	return array(
		'party' => $party,
		'kind'  => $kind,
	);
}

/**
 * Activate a party's pending names; with allotments on, grow the allotment
 * so the approved names fit.
 *
 * @param int $event_id Event post ID.
 * @param int $party_id Party ID.
 * @return int|WP_Error Names activated, or guest_list_full when they don't fit the event.
 */
function extrachill_events_activate_guest_list_party_names( int $event_id, int $party_id ) {
	$activated = RsvpPassesTable::activate_pending_for_party( $event_id, $party_id, extrachill_events_get_perk_capacity( $event_id ) );
	if ( null === $activated ) {
		return new WP_Error( 'guest_list_full', __( 'Not enough spots left on the guest list for these names. Raise the guest list spots first, or take names off.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	if ( $activated > 0 && extrachill_events_guest_list_allotments_enabled( $event_id ) ) {
		$party = GuestListPartiesTable::find( $party_id );
		$used  = RsvpPassesTable::count_for_party( $event_id, $party_id );
		if ( $party && $used > (int) ( $party['allotment'] ?? 0 ) ) {
			GuestListPartiesTable::set_allotment( $event_id, $party_id, $used );
		}
	}

	if ( $activated > 0 && function_exists( 'extrachill_cache_purge_post' ) ) {
		extrachill_cache_purge_post( $event_id );
	}

	return $activated;
}

/**
 * A host approves a request.
 *
 * - `spots`: the allotment grows by $spots (default: what was asked).
 * - `list`: the pending names go live together (allotment grows to fit).
 * - `party`: the uninvited party becomes a normal one with $spots
 *   (default: what was asked), its names go live, and the band gets its
 *   private invite link by email.
 *
 * @param int      $event_id      Event post ID.
 * @param int      $party_id      Party ID.
 * @param string   $kind          party|spots|list, or '' to infer.
 * @param int|null $spots         Host-edited spots for `spots`/`party`.
 * @param int      $actor_user_id Deciding host.
 * @return array|WP_Error `{ approved: true, kind, party, activated, invite_url?, emailed?, names_waiting? }` or an error.
 */
function extrachill_events_approve_guest_list_request( int $event_id, int $party_id, string $kind = '', ?int $spots = null, int $actor_user_id = 0 ) {
	$request = extrachill_events_guest_list_request_for_decision( $event_id, $party_id, $kind, $actor_user_id );
	if ( is_wp_error( $request ) ) {
		return $request;
	}

	$party = $request['party'];
	$kind  = $request['kind'];
	if ( null !== $spots && ( $spots < 0 || $spots > 100000 ) ) {
		return new WP_Error( 'invalid_spots', __( 'Spots must be 0 or more.', 'extrachill-events' ), array( 'status' => 400 ) );
	}
	$asked  = isset( $party['requested_spots'] ) && null !== $party['requested_spots'] ? (int) $party['requested_spots'] : 0;
	$spots  = null === $spots ? $asked : $spots;
	$result = array(
		'approved'  => true,
		'kind'      => $kind,
		'activated' => 0,
	);

	if ( 'spots' === $kind ) {
		if ( $spots < 1 ) {
			return new WP_Error( 'invalid_spots', __( 'Approve at least 1 spot, or deny the request.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! GuestListPartiesTable::close_spot_request( $event_id, $party_id, $spots, $actor_user_id ) ) {
			return extrachill_events_guest_list_request_not_found_error();
		}
		/* translators: 1: spots added, 2: event title. */
		$message = sprintf( _n( 'The venue gave you %1$d more guest list spot for %2$s. Open your guest list link to add the name.', 'The venue gave you %1$d more guest list spots for %2$s. Open your guest list link to add the names.', $spots, 'extrachill-events' ), $spots, extrachill_events_guest_list_request_event_title( $event_id ) );
		extrachill_events_send_guest_list_request_decision_email( $event_id, $party, __( 'More guest list spots approved', 'extrachill-events' ), $message );
	} elseif ( 'list' === $kind ) {
		$activated = extrachill_events_activate_guest_list_party_names( $event_id, $party_id );
		if ( is_wp_error( $activated ) ) {
			return $activated;
		}
		$result['activated'] = $activated;
		/* translators: 1: number of names, 2: event title. */
		$message = sprintf( _n( 'The venue approved %1$d name on your guest list for %2$s. Open your guest list link for the pass link.', 'The venue approved %1$d names on your guest list for %2$s. Open your guest list link for their pass links.', $activated, 'extrachill-events' ), $activated, extrachill_events_guest_list_request_event_title( $event_id ) );
		extrachill_events_send_guest_list_request_decision_email( $event_id, $party, __( 'Your guest list was approved', 'extrachill-events' ), $message );
	} else {
		$token = GuestListPartiesTable::accept( $event_id, $party_id, $spots, $actor_user_id );
		if ( null === $token ) {
			return extrachill_events_guest_list_request_not_found_error();
		}

		$activated = extrachill_events_activate_guest_list_party_names( $event_id, $party_id );
		if ( is_wp_error( $activated ) ) {
			// Accepted, but the event is too full for the whole list: the
			// names stay pending as a list request the host can decide later.
			$result['names_waiting'] = RsvpPassesTable::count_pending_for_party( $event_id, $party_id );
			$activated               = 0;
		}
		$result['activated']  = $activated;
		$result['invite_url'] = extrachill_events_guest_list_party_invite_url( $event_id, $token );
		$result['emailed']    = extrachill_events_send_guest_list_party_invite_email( $event_id, (array) GuestListPartiesTable::find( $party_id ), $result['invite_url'] );
	}

	$result['party'] = extrachill_events_guest_list_party_summary( $event_id, (array) GuestListPartiesTable::find( $party_id ) );
	return $result;
}

/**
 * A host denies a request.
 *
 * - `spots`: the ask is closed; the allotment stays as it was.
 * - `list`: every pending name is denied and never redeems.
 * - `party`: the uninvited party and its names are denied for good.
 *
 * The band (or requester) is told by email.
 *
 * @param int    $event_id      Event post ID.
 * @param int    $party_id      Party ID.
 * @param string $kind          party|spots|list, or '' to infer.
 * @param int    $actor_user_id Deciding host.
 * @return array|WP_Error `{ denied: true, kind, denied_names, emailed }` or an error.
 */
function extrachill_events_deny_guest_list_request( int $event_id, int $party_id, string $kind = '', int $actor_user_id = 0 ) {
	$request = extrachill_events_guest_list_request_for_decision( $event_id, $party_id, $kind, $actor_user_id );
	if ( is_wp_error( $request ) ) {
		return $request;
	}

	$party  = $request['party'];
	$kind   = $request['kind'];
	$title  = extrachill_events_guest_list_request_event_title( $event_id );
	$denied = 0;

	if ( 'spots' === $kind ) {
		if ( ! GuestListPartiesTable::close_spot_request( $event_id, $party_id, 0, $actor_user_id ) ) {
			return extrachill_events_guest_list_request_not_found_error();
		}
		$subject = __( 'Guest list spots request declined', 'extrachill-events' );
		/* translators: %s: event title. */
		$message = sprintf( __( 'The venue declined your request for more guest list spots for %s. Your current spots are unchanged.', 'extrachill-events' ), $title );
	} elseif ( 'list' === $kind ) {
		$denied  = RsvpPassesTable::deny_pending_for_party( $event_id, $party_id );
		$subject = __( 'Guest list names declined', 'extrachill-events' );
		/* translators: %s: event title. */
		$message = sprintf( __( 'The venue declined the list of names you sent for %s. Those names are not on the guest list.', 'extrachill-events' ), $title );
	} else {
		if ( ! GuestListPartiesTable::deny( $event_id, $party_id, $actor_user_id ) ) {
			return extrachill_events_guest_list_request_not_found_error();
		}
		$denied  = RsvpPassesTable::deny_pending_for_party( $event_id, $party_id );
		$subject = __( 'Guest list request declined', 'extrachill-events' );
		/* translators: %s: event title. */
		$message = sprintf( __( 'The venue declined your guest list request for %s. No one from your request is on the guest list.', 'extrachill-events' ), $title );
	}

	return array(
		'denied'       => true,
		'kind'         => $kind,
		'denied_names' => $denied,
		'emailed'      => extrachill_events_send_guest_list_request_decision_email( $event_id, $party, $subject, $message ),
	);
}

/**
 * Event title for request emails.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function extrachill_events_guest_list_request_event_title( int $event_id ): string {
	$post = get_post( $event_id );
	return $post ? extrachill_events_rsvp_pass_event_title( $post ) : '';
}

/**
 * Users who manage an event and should hear about its requests: the event
 * author plus the active venue and promoter teams. Filterable.
 *
 * @param int $event_id Event post ID.
 * @return int[]
 */
function extrachill_events_event_manager_user_ids( int $event_id ): array {
	$ids  = array();
	$post = get_post( $event_id );
	if ( $post && ! empty( $post->post_author ) ) {
		$ids[] = (int) $post->post_author;
	}

	if ( function_exists( 'wp_get_post_terms' ) && class_exists( '\\ExtraChill\\Users\\Teams\\TeamMembershipRepository' ) ) {
		$venue_terms = wp_get_post_terms( $event_id, 'venue' );
		if ( ! is_wp_error( $venue_terms ) ) {
			$repository = new \ExtraChillEvents\Core\VenueMembershipRepository();
			foreach ( (array) $venue_terms as $term ) {
				$members = $repository->list_for_venue(
					(int) $term->term_id,
					array(
						'status' => 'active',
						'limit'  => 100,
					)
				);
				foreach ( is_wp_error( $members ) ? array() : (array) $members as $member ) {
					$ids[] = (int) $member['user_id'];
				}
			}
		}
	}

	if ( function_exists( 'wp_get_post_terms' ) && class_exists( '\\ExtraChillEvents\\Core\\PromoterAuthoritySchema' ) && \ExtraChillEvents\Core\PromoterAuthoritySchema::is_ready() ) {
		$promoter_terms = wp_get_post_terms( $event_id, 'promoter' );
		if ( ! is_wp_error( $promoter_terms ) ) {
			$repository = new \ExtraChillEvents\Core\PromoterAuthorityRepository();
			foreach ( (array) $promoter_terms as $term ) {
				$members = $repository->list_memberships( (int) $term->term_id );
				foreach ( is_wp_error( $members ) ? array() : (array) $members as $member ) {
					if ( 'active' === ( $member['status'] ?? '' ) ) {
						$ids[] = (int) $member['user_id'];
					}
				}
			}
		}
	}

	/**
	 * Filter who hears about an event's guest list requests.
	 *
	 * @param int[] $ids      User IDs.
	 * @param int   $event_id Event post ID.
	 */
	$ids = (array) apply_filters( 'extrachill_events_guest_list_request_recipients', $ids, $event_id );

	return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
}

/**
 * Tell the event's managers something is waiting for them: email, plus the
 * network notification bell when extrachill-users is active. Never blocks
 * the request.
 *
 * @param int    $event_id Event post ID.
 * @param array  $party    Party row.
 * @param string $kind     party|spots|list.
 * @param string $summary  One line, e.g. "Slim S.O.U.L. asked for 4 more spots."
 * @return int Emails queued.
 */
function extrachill_events_notify_guest_list_request( int $event_id, array $party, string $kind, string $summary ): int {
	$post = get_post( $event_id );
	if ( ! $post ) {
		return 0;
	}

	$title    = extrachill_events_rsvp_pass_event_title( $post );
	$link     = trailingslashit( (string) get_permalink( $post ) ) . '#ec-guest-list-requests';
	$user_ids = extrachill_events_event_manager_user_ids( $event_id );

	$emails = array();
	foreach ( $user_ids as $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user && ! empty( $user->user_email ) && is_email( $user->user_email ) ) {
			$emails[] = (string) $user->user_email;
		}
	}
	if ( empty( $emails ) ) {
		$admin = (string) get_option( 'admin_email', '' );
		if ( '' !== $admin ) {
			$emails[] = $admin;
		}
	}

	$queued = 0;
	if ( function_exists( 'ec_send_email_queued' ) ) {
		$body = '<p>' . esc_html( $summary ) . '</p><p>' . esc_html(
			sprintf(
				/* translators: %s: event title. */
				__( 'Event: %s. Nothing they send works at the door until you accept it. Accept or deny it under Pending requests on the event page.', 'extrachill-events' ),
				$title
			)
		) . '</p>';
		foreach ( array_unique( $emails ) as $email ) {
			$result  = ec_send_email_queued(
				array(
					'to'        => $email,
					/* translators: %s: event title. */
					'subject'   => sprintf( __( 'Guest list request for %s', 'extrachill-events' ), $title ),
					'from_name' => 'Extra Chill Events',
					'template'  => 'extrachill/branded',
					'context'   => array(
						'body_html' => $body,
						'cta_url'   => $link,
						'cta_label' => __( 'Review request', 'extrachill-events' ),
					),
				)
			);
			$queued += extrachill_events_rsvp_pass_email_queued( $result ) ? 1 : 0;
		}
	}

	// The bell, best effort. This producer sends its own email above.
	if ( $user_ids && function_exists( 'ec_users_notify_with_receipts' ) && function_exists( 'ec_get_network_bot_user_id' ) ) {
		$actor_id = absint( ec_get_network_bot_user_id() );
		if ( $actor_id ) {
			try {
				ec_users_notify_with_receipts(
					$user_ids,
					array(
						'actor_id'            => $actor_id,
						'type'                => 'guest_list_request',
						'title'               => $summary,
						'link'                => $link,
						'item_id'             => $event_id,
						'producer'            => 'extrachill-events/guest-list-requests',
						'idempotency_key'     => 'party:' . (int) $party['id'] . ':' . $kind . ':' . md5( $summary . '|' . (string) ( $party['requested_at'] ?? '' ) . '|' . microtime() ),
						'producer_owns_email' => true,
					)
				);
			} catch ( \Throwable $e ) {
				do_action( 'datamachine_log', 'warning', 'Guest list request bell notification failed', array( 'error' => $e->getMessage() ) );
			}
		}
	}

	return $queued;
}

/**
 * Email a band (or requester) the venue's decision.
 *
 * @param int    $event_id Event post ID.
 * @param array  $party    Party row.
 * @param string $subject  Subject.
 * @param string $message  One-paragraph body.
 * @return bool Whether the email was queued.
 */
function extrachill_events_send_guest_list_request_decision_email( int $event_id, array $party, string $subject, string $message ): bool {
	$email = (string) ( $party['email'] ?? '' );
	if ( '' === $email || ! function_exists( 'ec_send_email_queued' ) || ! get_post( $event_id ) ) {
		return false;
	}

	$result = ec_send_email_queued(
		array(
			'to'        => $email,
			'subject'   => $subject,
			'from_name' => 'Extra Chill Events',
			'template'  => 'extrachill/branded',
			'context'   => array(
				'body_html'      => '<p>' . esc_html( $message ) . '</p>',
				'recipient_name' => (string) ( $party['name'] ?? '' ),
			),
		)
	);

	return extrachill_events_rsvp_pass_email_queued( $result );
}
