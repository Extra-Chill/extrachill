<?php
/**
 * Guest List Parties (extrachill#1128)
 *
 * A venue or promoter host invites a band or artist management to fill
 * guest list spots for one event. The party gets an emailed private link
 * (inc/core/guest-list-invite-page.php) and adds guest names through it
 * with no password and no account. Every name is an ordinary account-less
 * guest list pass (see extrachill_events_add_guest_list_name()) tagged with
 * the party, so it counts against the event's spots, scans at the door, and
 * shows on the door list as "Jake (Slim S.O.U.L.)".
 *
 * Allotments are off by default: parties are limited only by the event's
 * overall capacity. With allotments on, each party is also capped at its
 * own allotment; a party with no allotment set can add no one until the
 * host sets one (fail closed, so turning allotments on always means control).
 *
 * Revoking a party kills its link. Names it already added stay on the list;
 * the host takes them off one by one from the door list.
 *
 * Authorization for host actions is the caller's job (the abilities check
 * extrachill_events_user_can_manage_event()); party actions are gated by
 * the invite token alone.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\GuestListPartiesTable;
use ExtraChillEvents\Core\RsvpPassesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Endpoint slug for the party's private page, appended to the event permalink. */
const EXTRACHILL_EVENTS_GUEST_INVITE_ENDPOINT = 'guest-invite';

/**
 * Private link that opens a party's add-names page.
 *
 * @param int    $event_id Event post ID.
 * @param string $token    Raw invite token.
 * @return string
 */
function extrachill_events_guest_list_party_invite_url( int $event_id, string $token ): string {
	return trailingslashit( (string) get_permalink( get_post( $event_id ) ) ) . EXTRACHILL_EVENTS_GUEST_INVITE_ENDPOINT . '/' . rawurlencode( $token ) . '/';
}

/**
 * The storage layer is loaded and installed.
 *
 * @return bool
 */
function extrachill_events_guest_list_parties_available(): bool {
	return class_exists( GuestListPartiesTable::class ) && class_exists( RsvpPassesTable::class );
}

/**
 * The error for a temporarily missing storage layer.
 *
 * @return WP_Error
 */
function extrachill_events_guest_list_parties_unavailable_error(): WP_Error {
	return new WP_Error( 'guest_list_unavailable', __( 'The guest list is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
}

/**
 * Normalize a party name or guest name: trimmed, plain text, 100 chars.
 *
 * @param string $name Raw name.
 * @return string
 */
function extrachill_events_guest_list_clean_name( string $name ): string {
	$name = trim( sanitize_text_field( $name ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 );
}

/**
 * A party's spot cap right now.
 *
 * @param int   $event_id Event post ID.
 * @param array $party    Party row.
 * @return int|null Null when allotments are off (only overall capacity applies).
 */
function extrachill_events_guest_list_party_cap( int $event_id, array $party ): ?int {
	if ( ! extrachill_events_guest_list_allotments_enabled( $event_id ) ) {
		return null;
	}

	return isset( $party['allotment'] ) && null !== $party['allotment'] ? max( 0, (int) $party['allotment'] ) : 0;
}

/**
 * Public shape of a party for hosts: never the token or its hash.
 *
 * @param int   $event_id Event post ID.
 * @param array $party    Party row.
 * @return array
 */
function extrachill_events_guest_list_party_summary( int $event_id, array $party ): array {
	$cap  = extrachill_events_guest_list_party_cap( $event_id, $party );
	$used = RsvpPassesTable::count_for_party( $event_id, (int) $party['id'] );

	return array(
		'party_id'   => (int) $party['id'],
		'name'       => (string) $party['name'],
		'email'      => (string) $party['email'],
		'status'     => (string) $party['status'],
		'allotment'  => isset( $party['allotment'] ) && null !== $party['allotment'] ? (int) $party['allotment'] : null,
		'used'       => $used,
		'spots_left' => null === $cap ? null : max( 0, $cap - $used ),
		'created_at' => (string) ( $party['created_at'] ?? '' ),
	);
}

/**
 * Host invites a band or management to fill guest list spots.
 *
 * @param int      $event_id     Event post ID.
 * @param string   $name         Party name, e.g. "Slim S.O.U.L.".
 * @param string   $email        Where to send the private link.
 * @param int|null $allotment    Spots for this party. Required when allotments are on.
 * @param int      $host_user_id Inviting host.
 * @return array|WP_Error `{ party_id, name, email, allotment, invite_url, emailed }` or an error.
 */
function extrachill_events_create_guest_list_party( int $event_id, string $name, string $email, ?int $allotment = null, int $host_user_id = 0 ) {
	$post = get_post( $event_id );
	if ( ! $post || 'data_machine_events' !== $post->post_type ) {
		return new WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	if ( ! extrachill_events_perk_enabled( $event_id ) || ! extrachill_events_perk_password_required( $event_id ) ) {
		return new WP_Error( 'guest_list_unavailable', __( 'Turn on the guest list (set a password and save) before inviting bands.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	$name = extrachill_events_guest_list_clean_name( $name );
	if ( '' === $name ) {
		return new WP_Error( 'party_name_required', __( 'Enter the band or management name.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$email = sanitize_email( $email );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Enter a valid email address to send the invite to.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	if ( null !== $allotment && $allotment < 0 ) {
		return new WP_Error( 'invalid_allotment', __( 'Spots must be 0 or more.', 'extrachill-events' ), array( 'status' => 400 ) );
	}
	if ( null === $allotment && extrachill_events_guest_list_allotments_enabled( $event_id ) ) {
		return new WP_Error( 'allotment_required', __( 'Allotments are on: give this band a number of spots.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	if ( ! extrachill_events_guest_list_parties_available() ) {
		return extrachill_events_guest_list_parties_unavailable_error();
	}

	$created = GuestListPartiesTable::create( $event_id, $name, $email, $allotment, $host_user_id );
	if ( ! $created ) {
		return new WP_Error( 'party_invite_failed', __( 'The invite could not be saved. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	$party      = $created['party'];
	$invite_url = extrachill_events_guest_list_party_invite_url( $event_id, $created['token'] );
	$emailed    = extrachill_events_send_guest_list_party_invite_email( $event_id, $party, $invite_url );

	return array(
		'party_id'   => (int) $party['id'],
		'name'       => $name,
		'email'      => $email,
		'allotment'  => $allotment,
		'invite_url' => $invite_url,
		'emailed'    => $emailed,
	);
}

/**
 * Host revokes an invite. The link stops working; names already added stay.
 *
 * @param int $event_id Event post ID.
 * @param int $party_id Party ID.
 * @return array|WP_Error `{ revoked: true, kept_guests }` or an error.
 */
function extrachill_events_revoke_guest_list_party( int $event_id, int $party_id ) {
	if ( ! extrachill_events_guest_list_parties_available() ) {
		return extrachill_events_guest_list_parties_unavailable_error();
	}

	if ( ! GuestListPartiesTable::revoke( $event_id, $party_id ) ) {
		return new WP_Error( 'party_not_revocable', __( 'That invite is not on this event, or was already revoked.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	return array(
		'revoked'     => true,
		'kept_guests' => RsvpPassesTable::count_for_party( $event_id, $party_id ),
	);
}

/**
 * Host sets a party's allotment (null clears it).
 *
 * @param int      $event_id  Event post ID.
 * @param int      $party_id  Party ID.
 * @param int|null $allotment Spots.
 * @return array|WP_Error Party summary or an error.
 */
function extrachill_events_set_guest_list_party_allotment( int $event_id, int $party_id, ?int $allotment ) {
	if ( null !== $allotment && $allotment < 0 ) {
		return new WP_Error( 'invalid_allotment', __( 'Spots must be 0 or more.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	if ( ! extrachill_events_guest_list_parties_available() ) {
		return extrachill_events_guest_list_parties_unavailable_error();
	}

	if ( ! GuestListPartiesTable::set_allotment( $event_id, $party_id, $allotment ) ) {
		return new WP_Error( 'party_not_found', __( 'That invite is not on this event.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	return extrachill_events_guest_list_party_summary( $event_id, (array) GuestListPartiesTable::find( $party_id ) );
}

/**
 * Host lists the parties invited to an event, with spots used.
 *
 * @param int $event_id Event post ID.
 * @return array|WP_Error `{ allotments_enabled, parties: [] }` or an error.
 */
function extrachill_events_list_guest_list_parties( int $event_id ) {
	if ( ! extrachill_events_guest_list_parties_available() ) {
		return extrachill_events_guest_list_parties_unavailable_error();
	}

	$parties = array();
	foreach ( GuestListPartiesTable::list_for_event( $event_id ) as $party ) {
		$parties[] = extrachill_events_guest_list_party_summary( $event_id, $party );
	}

	return array(
		'allotments_enabled' => extrachill_events_guest_list_allotments_enabled( $event_id ),
		'parties'            => $parties,
	);
}

/**
 * Resolve an invite token to its active party.
 *
 * @param string $token Raw invite token.
 * @return array|WP_Error Party row, or invalid_invite / invite_revoked.
 */
function extrachill_events_resolve_guest_list_party( string $token ) {
	if ( ! extrachill_events_guest_list_parties_available() ) {
		return extrachill_events_guest_list_parties_unavailable_error();
	}

	$party = GuestListPartiesTable::find_by_token( strtolower( trim( $token ) ) );
	if ( ! $party ) {
		return new WP_Error( 'invalid_invite', __( 'This invite link is not valid.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	if ( GuestListPartiesTable::STATUS_ACTIVE !== $party['status'] ) {
		return new WP_Error( 'invite_revoked', __( 'This invite was cancelled by the venue. Contact them if you think that is a mistake.', 'extrachill-events' ), array( 'status' => 410 ) );
	}

	return $party;
}

/**
 * The error when a party has used every spot it was given.
 *
 * @return WP_Error
 */
function extrachill_events_guest_list_party_full_error(): WP_Error {
	return new WP_Error( 'party_allotment_full', __( "You've used all your guest list spots. Ask the venue for more.", 'extrachill-events' ), array( 'status' => 409 ) );
}

/**
 * An invited party adds a guest by name, through its invite token.
 *
 * @param string $token Raw invite token.
 * @param string $name  Guest name.
 * @param string $email Optional address to send the pass to.
 * @return array|WP_Error Same as extrachill_events_add_guest_list_name() plus `party_spots_left`.
 */
function extrachill_events_party_add_guest_list_name( string $token, string $name, string $email = '' ) {
	$party = extrachill_events_resolve_guest_list_party( $token );
	if ( is_wp_error( $party ) ) {
		return $party;
	}

	$event_id = (int) $party['event_id'];
	$party_id = (int) $party['id'];
	$cap      = extrachill_events_guest_list_party_cap( $event_id, $party );

	// Allotments on: no allotment means no spots, and a full allotment is
	// rejected before anything is written. The table re-checks under the lock.
	if ( null !== $cap && ( $cap <= 0 || RsvpPassesTable::count_for_party( $event_id, $party_id ) >= $cap ) ) {
		return extrachill_events_guest_list_party_full_error();
	}

	$result = extrachill_events_add_guest_list_name( $event_id, $name, $email, 0, $party_id, (int) $cap );
	if ( is_wp_error( $result ) ) {
		if ( 'guest_list_full' === $result->get_error_code() && null !== $cap && RsvpPassesTable::count_for_party( $event_id, $party_id ) >= $cap ) {
			return extrachill_events_guest_list_party_full_error();
		}
		return $result;
	}

	$result['party_spots_left'] = null === $cap ? null : max( 0, $cap - RsvpPassesTable::count_for_party( $event_id, $party_id ) );
	return $result;
}

/**
 * An invited party takes one of its own guests off the list.
 *
 * @param string $token Raw invite token.
 * @param string $code  Pass code of a guest this party added.
 * @return array|WP_Error `{ removed: true }` or an error.
 */
function extrachill_events_party_remove_guest_list_name( string $token, string $code ) {
	$party = extrachill_events_resolve_guest_list_party( $token );
	if ( is_wp_error( $party ) ) {
		return $party;
	}

	$event_id = (int) $party['event_id'];
	$code     = strtoupper( trim( $code ) );
	if ( '' === $code || ! RsvpPassesTable::revoke_by_code( $event_id, $code, (int) $party['id'] ) ) {
		return new WP_Error( 'guest_not_removable', __( 'That guest is not on your list, or has already checked in.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	if ( function_exists( 'extrachill_cache_purge_post' ) ) {
		extrachill_cache_purge_post( $event_id );
	}

	return array( 'removed' => true );
}

/**
 * The names a party has on the list (active and checked in), oldest first.
 *
 * @param array $party Party row.
 * @return array[] Pass rows.
 */
function extrachill_events_guest_list_party_passes( array $party ): array {
	$passes = array();
	foreach ( RsvpPassesTable::list_guest_passes( (int) $party['event_id'] ) as $pass ) {
		if ( (int) ( $pass['party_id'] ?? 0 ) === (int) $party['id'] && RsvpPassesTable::STATUS_REVOKED !== $pass['status'] ) {
			$passes[] = $pass;
		}
	}
	return $passes;
}

/**
 * Door-facing name for a guest list pass: "Jake (Slim S.O.U.L.)" when an
 * invited party added it, the bare name otherwise.
 *
 * @param string $guest_name Guest name ('' falls back to a generic label).
 * @param string $party_name Party name, or '' for none.
 * @return string
 */
function extrachill_events_guest_list_pass_label( string $guest_name, string $party_name = '' ): string {
	$guest_name = '' !== $guest_name ? $guest_name : __( 'Guest list pass', 'extrachill-events' );
	if ( '' === $party_name ) {
		return $guest_name;
	}

	/* translators: 1: guest name, 2: band or management that added them. */
	return sprintf( __( '%1$s (%2$s)', 'extrachill-events' ), $guest_name, $party_name );
}

/**
 * Party name for one pass row, for the verify page.
 *
 * @param array $pass Pass row.
 * @return string '' when the pass came from no party.
 */
function extrachill_events_guest_list_pass_party_name( array $pass ): string {
	$party_id = (int) ( $pass['party_id'] ?? 0 );
	if ( $party_id <= 0 || ! class_exists( GuestListPartiesTable::class ) ) {
		return '';
	}

	$party = GuestListPartiesTable::find( $party_id );
	return $party ? (string) $party['name'] : '';
}

/**
 * The account-less guest rows for the door list, with their source.
 *
 * @param int $event_id Event post ID.
 * @return array[] Each: label, guest_name, source (party name or ''), added_by (host display name or ''), code, status, redeemed_at (formatted).
 */
function extrachill_events_door_list_guest_rows( int $event_id ): array {
	$passes = array_values(
		array_filter(
			RsvpPassesTable::list_guest_passes( $event_id ),
			static function ( $pass ) {
				return RsvpPassesTable::STATUS_REVOKED !== $pass['status'];
			}
		)
	);

	$party_names = array();
	foreach ( $passes as $pass ) {
		if ( ! empty( $pass['party_id'] ) && class_exists( GuestListPartiesTable::class ) ) {
			foreach ( GuestListPartiesTable::list_for_event( $event_id ) as $party ) {
				$party_names[ (int) $party['id'] ] = (string) $party['name'];
			}
			break;
		}
	}

	$rows = array();
	foreach ( $passes as $pass ) {
		$added_by_id = (int) ( $pass['issued_by_user_id'] ?? 0 );
		$added_by    = $added_by_id ? get_userdata( $added_by_id ) : false;
		$guest_name  = (string) ( $pass['guest_name'] ?? '' );
		$source      = $party_names[ (int) ( $pass['party_id'] ?? 0 ) ] ?? '';
		$rows[]      = array(
			'label'       => extrachill_events_guest_list_pass_label( $guest_name, $source ),
			'guest_name'  => $guest_name,
			'source'      => $source,
			'added_by'    => $added_by ? (string) $added_by->display_name : '',
			'code'        => (string) $pass['code'],
			'status'      => (string) $pass['status'],
			'redeemed_at' => ! empty( $pass['redeemed_at'] )
				? mysql2date( (string) get_option( 'time_format' ), get_date_from_gmt( (string) $pass['redeemed_at'] ) )
				: '',
		);
	}

	return $rows;
}

/**
 * Email a party its private invite link.
 *
 * @param int    $event_id   Event post ID.
 * @param array  $party      Party row.
 * @param string $invite_url Private link.
 * @return bool Whether the email was queued.
 */
function extrachill_events_send_guest_list_party_invite_email( int $event_id, array $party, string $invite_url ): bool {
	$post = get_post( $event_id );
	if ( ! $post || ! function_exists( 'ec_send_email_queued' ) ) {
		return false;
	}

	$title = extrachill_events_rsvp_pass_event_title( $post );
	$cap   = extrachill_events_guest_list_party_cap( $event_id, $party );
	$spots = null === $cap
		? __( 'Add as many names as you need; the list closes when the event is full.', 'extrachill-events' )
		/* translators: %d: number of guest list spots. */
		: sprintf( _n( 'You have %d spot.', 'You have %d spots.', $cap, 'extrachill-events' ), $cap );

	$body = '<p>' . esc_html(
		sprintf(
			/* translators: %s: event title. */
			__( "You're invited to put names on the guest list for %s.", 'extrachill-events' ),
			$title
		)
	) . '</p><p>' . esc_html( $spots ) . '</p><p>' . esc_html__( 'Open the link, type each guest\'s name, and they get a pass that scans at the door. No account or password needed. Keep this link private: anyone with it can add names.', 'extrachill-events' ) . '</p>';

	$result = ec_send_email_queued(
		array(
			'to'        => (string) $party['email'],
			/* translators: %s: event title. */
			'subject'   => sprintf( __( 'Add your guest list for %s', 'extrachill-events' ), $title ),
			'from_name' => 'Extra Chill Events',
			'template'  => 'extrachill/branded',
			'context'   => array(
				'body_html'      => $body,
				'recipient_name' => (string) $party['name'],
				'cta_url'        => $invite_url,
				'cta_label'      => __( 'Add guest names', 'extrachill-events' ),
			),
		)
	);

	$queued = extrachill_events_rsvp_pass_email_queued( $result );
	if ( ! $queued ) {
		do_action(
			'datamachine_log',
			'error',
			'Guest list party invite email was not queued',
			array(
				'event_id'   => $event_id,
				'party_id'   => (int) $party['id'],
				'error_code' => (string) ( $result['error_code'] ?? '' ),
			)
		);
	}
	return $queued;
}
