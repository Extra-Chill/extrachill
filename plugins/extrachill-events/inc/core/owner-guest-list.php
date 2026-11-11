<?php
/**
 * Owner's Guest List (extrachill#1130)
 *
 * A venue's or promoter's permanent list: the people the owners always let
 * in (partners, friends, the regular sound engineer). Each person gets one
 * durable pass that works at every published show tagged with that venue
 * or promoter, for as long as they stay on the list.
 *
 * Rules, kept deliberately simple:
 *  - Who manages it: the venue's verified team (active venue membership)
 *    or the promoter's verified members (active promoter membership), plus
 *    site/network admins. The same memberships that already let them run a
 *    show's door (extrachill_events_user_can_manage_event()).
 *  - Where it works: any published event tagged with the owning venue or
 *    promoter term. It does not need the event's own guest list (the RSVP
 *    perk) turned on: the owner's list is the owner's standing door policy,
 *    not a per-show perk. It never uses one of the event's guest list spots.
 *  - Check-in: once per person per event, recorded in its own table, so the
 *    same pass checks in tonight and again next week.
 *  - Removal: immediate. The code is dead at the next scan.
 *
 * Storage: ExtraChillEvents\Core\OwnerGuestListTable. Abilities:
 * ExtraChillEvents\Abilities\OwnerGuestListAbilities. Door check-in rides
 * the existing extrachill/redeem-event-pass ability (a code that is not a
 * per-event pass falls through to this list).
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\OwnerGuestListTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Door label for an owner type: "Venue list" or "Promoter list".
 *
 * @param string $owner_type venue|promoter.
 * @return string
 */
function extrachill_events_owner_list_label( string $owner_type ): string {
	return OwnerGuestListTable::OWNER_PROMOTER === $owner_type
		? __( 'Promoter list', 'extrachill-events' )
		: __( 'Venue list', 'extrachill-events' );
}

/**
 * Resolve and validate a list owner.
 *
 * @param string $owner_type venue|promoter.
 * @param int    $term_id    Venue or promoter term ID.
 * @return array{owner_type: string, owner_id: int, owner_name: string, label: string}|WP_Error
 */
function extrachill_events_owner_list_owner( string $owner_type, int $term_id ) {
	if ( ! in_array( $owner_type, OwnerGuestListTable::owner_types(), true ) ) {
		return new WP_Error( 'owner_list_invalid_owner', __( 'An owner\'s list belongs to a venue or a promoter.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$term = $term_id > 0 ? get_term( $term_id, $owner_type ) : null;
	if ( ! $term || is_wp_error( $term ) ) {
		return new WP_Error( 'owner_list_owner_not_found', __( 'That venue or promoter could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	return array(
		'owner_type' => $owner_type,
		'owner_id'   => (int) $term->term_id,
		'owner_name' => html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		'label'      => extrachill_events_owner_list_label( $owner_type ),
	);
}

/**
 * Whether a user may add to or remove from an owner's list: the venue's
 * active team, the promoter's active members, or an admin.
 *
 * @param int    $user_id    Candidate user ID.
 * @param string $owner_type venue|promoter.
 * @param int    $term_id    Owner term ID.
 * @return bool
 */
function extrachill_events_user_can_manage_owner_list( int $user_id, string $owner_type, int $term_id ): bool {
	if ( $user_id < 1 || $term_id < 1 ) {
		return false;
	}

	if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_network_options' ) ) {
		return true;
	}

	if ( OwnerGuestListTable::OWNER_VENUE === $owner_type ) {
		if ( ! class_exists( '\\ExtraChill\\Users\\Teams\\TeamMembershipRepository' ) ) {
			return false;
		}
		$membership = ( new \ExtraChillEvents\Core\VenueMembershipRepository() )->get_active( $term_id, $user_id );
		return is_array( $membership );
	}

	if ( OwnerGuestListTable::OWNER_PROMOTER === $owner_type ) {
		if ( ! class_exists( '\\ExtraChillEvents\\Core\\PromoterAuthoritySchema' ) || ! \ExtraChillEvents\Core\PromoterAuthoritySchema::is_ready() ) {
			return false;
		}
		$membership = ( new \ExtraChillEvents\Core\PromoterAuthorityRepository() )->get_active_membership( $term_id, $user_id );
		return is_array( $membership );
	}

	return false;
}

/**
 * The venues and promoters an event is tagged with: every owner whose list
 * works at this event.
 *
 * @param int $event_id Event post ID.
 * @return array[] Each `{ owner_type, owner_id, owner_name, label }`.
 */
function extrachill_events_owner_list_owners_for_event( int $event_id ): array {
	$owners = array();
	foreach ( OwnerGuestListTable::owner_types() as $owner_type ) {
		$terms = wp_get_post_terms( $event_id, $owner_type );
		if ( is_wp_error( $terms ) ) {
			continue;
		}
		foreach ( (array) $terms as $term ) {
			$owner = extrachill_events_owner_list_owner( $owner_type, (int) $term->term_id );
			if ( is_array( $owner ) ) {
				$owners[] = $owner;
			}
		}
	}
	return $owners;
}

/**
 * Whether an owner's-list member may check in at an event: the person is
 * still on the list, and the event is a published show tagged with their
 * list's venue or promoter.
 *
 * @param array $member   Member row.
 * @param int   $event_id Event post ID.
 * @return bool
 */
function extrachill_events_owner_list_event_is_eligible( array $member, int $event_id ): bool {
	if ( OwnerGuestListTable::STATUS_ACTIVE !== (string) $member['status'] ) {
		return false;
	}

	$post = get_post( $event_id );
	if ( ! $post || 'data_machine_events' !== $post->post_type || 'publish' !== ( $post->post_status ?? '' ) ) {
		return false;
	}

	$terms = wp_get_post_terms( $event_id, (string) $member['owner_type'] );
	if ( is_wp_error( $terms ) ) {
		return false;
	}
	foreach ( (array) $terms as $term ) {
		if ( (int) $term->term_id === (int) $member['owner_term_id'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Pass page link for an owner's-list pass: the holder's QR and code.
 *
 * @param string $code Pass code.
 * @return string
 */
function extrachill_events_owner_list_pass_url( string $code ): string {
	return add_query_arg( 'code', rawurlencode( $code ), home_url( '/owner-pass/' ) );
}

/**
 * Shape a member row for ability output.
 *
 * @param array $member Member row.
 * @return array
 */
function extrachill_events_owner_list_member_summary( array $member ): array {
	$added_by_id = (int) ( $member['added_by_user_id'] ?? 0 );
	$added_by    = $added_by_id ? get_userdata( $added_by_id ) : false;
	$code        = (string) $member['code'];

	return array(
		'member_id'  => (int) $member['id'],
		'name'       => (string) $member['name'],
		'email'      => (string) $member['email'],
		'code'       => $code,
		'status'     => (string) $member['status'],
		'pass_url'   => extrachill_events_owner_list_pass_url( $code ),
		'qr_url'     => function_exists( 'extrachill_events_rsvp_pass_qr_url' ) ? extrachill_events_rsvp_pass_qr_url( $code ) : '',
		'added_by'   => $added_by ? (string) $added_by->display_name : '',
		'created_at' => (string) $member['created_at'],
	);
}

/**
 * Everyone on an owner's list. Authorization is the caller's job.
 *
 * @param string $owner_type venue|promoter.
 * @param int    $term_id    Owner term ID.
 * @return array|WP_Error `{ owner_type, owner_id, owner_name, label, members[] }`.
 */
function extrachill_events_list_owner_list( string $owner_type, int $term_id ) {
	$owner = extrachill_events_owner_list_owner( $owner_type, $term_id );
	if ( is_wp_error( $owner ) ) {
		return $owner;
	}

	$owner['members'] = array_map( 'extrachill_events_owner_list_member_summary', OwnerGuestListTable::list_for_owner( $owner_type, $term_id ) );
	return $owner;
}

/**
 * Put someone on an owner's list. Authorization is the caller's job (the
 * extrachill/add-owner-guest-list-name ability checks it).
 *
 * @param string $owner_type venue|promoter.
 * @param int    $term_id    Owner term ID.
 * @param string $name       Name shown at the door.
 * @param string $email      Optional address to send the pass to.
 * @param int    $actor_id   Team member adding them.
 * @return array|WP_Error Member summary plus `emailed`, or an error.
 */
function extrachill_events_add_owner_list_name( string $owner_type, int $term_id, string $name, string $email = '', int $actor_id = 0 ) {
	$owner = extrachill_events_owner_list_owner( $owner_type, $term_id );
	if ( is_wp_error( $owner ) ) {
		return $owner;
	}

	$name = trim( sanitize_text_field( $name ) );
	if ( '' === $name ) {
		return new WP_Error( 'guest_name_required', __( 'Enter the guest\'s name.', 'extrachill-events' ), array( 'status' => 400 ) );
	}
	$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 );

	$email = '' === trim( $email ) ? '' : sanitize_email( $email );
	if ( '' !== $email && ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Enter a valid email address, or leave it blank.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$member = OwnerGuestListTable::create( $owner_type, $term_id, $name, $email, $actor_id );
	if ( ! $member ) {
		return new WP_Error( 'owner_list_write_failed', __( 'The guest could not be added. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	$summary            = extrachill_events_owner_list_member_summary( $member );
	$summary['emailed'] = '' !== $email && extrachill_events_send_owner_list_pass_email( $owner, $member );
	return $summary + $owner;
}

/**
 * Take someone off an owner's list. Their pass stops working at once.
 * Authorization is the caller's job.
 *
 * @param string $owner_type venue|promoter.
 * @param int    $term_id    Owner term ID.
 * @param int    $member_id  Member ID.
 * @param int    $actor_id   Team member removing them.
 * @return array|WP_Error `{ removed: true, member_id }` or an error.
 */
function extrachill_events_remove_owner_list_name( string $owner_type, int $term_id, int $member_id, int $actor_id = 0 ) {
	if ( ! OwnerGuestListTable::remove( $owner_type, $term_id, $member_id, $actor_id ) ) {
		return new WP_Error( 'owner_list_member_not_found', __( 'That person is not on this list.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	return array(
		'removed'   => true,
		'member_id' => $member_id,
	);
}

/**
 * Check an owner's-list pass in at one event. Authorization (the caller
 * can run this event's door) is the caller's job: the redeem-event-pass
 * ability checks it.
 *
 * Errors read "Not on the list" so the door gets one plain answer for an
 * unknown code, a removed person, or a show that isn't theirs.
 *
 * @param int    $event_id Event post ID.
 * @param string $code     Pass code.
 * @param int    $actor_id Door staff checking them in.
 * @return array|WP_Error `{ already_redeemed, redeemed_at, redeemed_by_user_id, name, label }`.
 */
function extrachill_events_owner_list_check_in( int $event_id, string $code, int $actor_id ) {
	$code   = strtoupper( trim( $code ) );
	$member = '' === $code ? null : OwnerGuestListTable::find_by_code( $code );
	if ( ! $member ) {
		return new WP_Error( 'rsvp_pass_not_found', __( 'No pass found for this code.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	if ( OwnerGuestListTable::STATUS_ACTIVE !== (string) $member['status'] ) {
		return new WP_Error( 'owner_list_removed', __( 'Not on the list. This person was taken off the list.', 'extrachill-events' ), array( 'status' => 410 ) );
	}

	if ( ! extrachill_events_owner_list_event_is_eligible( $member, $event_id ) ) {
		return new WP_Error( 'owner_list_not_eligible', __( 'Not on the list. This pass is not for this show.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	$result = OwnerGuestListTable::check_in( (int) $member['id'], $event_id, $actor_id );
	if ( function_exists( 'extrachill_cache_purge_post' ) ) {
		extrachill_cache_purge_post( $event_id );
	}

	return $result + array(
		'name'  => (string) $member['name'],
		'label' => extrachill_events_owner_list_label( (string) $member['owner_type'] ),
	);
}

/**
 * Door list rows for an event's owner's lists: everyone on the list of any
 * venue or promoter the event is tagged with, labelled, with tonight's
 * check-in state. Same row shape as extrachill_events_door_list_guest_rows()
 * plus `owner_list: true`, so the door list renders them alongside the
 * per-event guests (and never offers to remove them from the standing list).
 *
 * @param int $event_id Event post ID.
 * @return array[]
 */
function extrachill_events_owner_list_door_rows( int $event_id ): array {
	$post = get_post( $event_id );
	if ( ! $post || 'publish' !== ( $post->post_status ?? '' ) ) {
		return array();
	}

	$checkins = null;
	$rows     = array();
	foreach ( extrachill_events_owner_list_owners_for_event( $event_id ) as $owner ) {
		$members = OwnerGuestListTable::list_for_owner( $owner['owner_type'], $owner['owner_id'] );
		if ( ! $members ) {
			continue;
		}
		if ( null === $checkins ) {
			$checkins = OwnerGuestListTable::list_checkins_for_event( $event_id );
		}
		foreach ( $members as $member ) {
			$checkin = $checkins[ (int) $member['id'] ] ?? null;
			$rows[]  = array(
				'pass_id'     => (int) ( $member['id'] ?? 0 ),
				'label'       => sprintf(
					/* translators: 1: guest name, 2: "Venue list" or "Promoter list". */
					__( '%1$s (%2$s)', 'extrachill-events' ),
					(string) $member['name'],
					$owner['label']
				),
				'guest_name'  => (string) $member['name'],
				'source'      => $owner['label'],
				'owner_name'  => $owner['owner_name'],
				'added_by'    => '',
				'code'        => (string) $member['code'],
				'status'      => $checkin ? 'redeemed' : 'active',
				'redeemed_at' => $checkin
					? mysql2date( (string) get_option( 'time_format' ), get_date_from_gmt( (string) $checkin['checked_in_at'] ) )
					: '',
				'owner_list'  => true,
			);
		}
	}

	return $rows;
}

/**
 * Upcoming-tonight shows for one owner: published events tagged with the
 * venue or promoter that start within the door window around now (from
 * 14 hours ago to 6 hours ahead, or still running). Used to work out which
 * show a scanned owner's-list pass is checking in to.
 *
 * Event dates are wall-clock times in the site timezone (data-machine-events
 * stores them that way), so "now" is the site's local time.
 *
 * @param string $owner_type venue|promoter.
 * @param int    $term_id    Owner term ID.
 * @return int[] Event post IDs, soonest first.
 */
function extrachill_events_owner_list_door_event_ids( string $owner_type, int $term_id ): array {
	$pre = apply_filters( 'extrachill_events_owner_list_door_event_ids', null, $owner_type, $term_id );
	if ( is_array( $pre ) ) {
		return array_values( array_map( 'intval', $pre ) );
	}

	if ( ! class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' ) ) {
		return array();
	}

	global $wpdb;
	$dates = \DataMachineEvents\Core\EventDatesTable::table_name();
	$now   = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Event dates are site-local wall clock.
	$from  = gmdate( 'Y-m-d H:i:s', $now - 14 * HOUR_IN_SECONDS );
	$until = gmdate( 'Y-m-d H:i:s', $now + 6 * HOUR_IN_SECONDS );
	$right = gmdate( 'Y-m-d H:i:s', $now );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded door lookup; live data.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table names.
			"SELECT d.post_id FROM {$dates} d INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = d.post_id INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s AND tt.term_id = %d AND d.post_status = 'publish' AND d.start_datetime <= %s AND ( d.start_datetime >= %s OR ( d.end_datetime IS NOT NULL AND d.end_datetime >= %s ) ) ORDER BY d.start_datetime ASC LIMIT %d",
			$owner_type,
			$term_id,
			$until,
			$from,
			$right,
			10
		)
	);

	return array_values( array_map( 'intval', (array) $ids ) );
}

/**
 * Tonight's shows a door person runs through their own venue or promoter
 * memberships. Lets a scan of someone else's owner's list answer "Not on
 * the list" instead of a bare "could not be verified".
 *
 * @param int $user_id Door staff user ID.
 * @return int[]
 */
function extrachill_events_owner_list_staff_door_event_ids( int $user_id ): array {
	$owners = array();
	if ( class_exists( '\\ExtraChill\\Users\\Teams\\TeamMembershipRepository' ) ) {
		$venue_ids = ( new \ExtraChillEvents\Core\VenueMembershipRepository() )->list_active_venue_ids_for_user( $user_id );
		foreach ( is_wp_error( $venue_ids ) ? array() : (array) $venue_ids as $venue_id ) {
			$owners[] = array( OwnerGuestListTable::OWNER_VENUE, (int) $venue_id );
		}
	}
	if ( class_exists( '\\ExtraChillEvents\\Core\\PromoterAuthoritySchema' ) && \ExtraChillEvents\Core\PromoterAuthoritySchema::is_ready() ) {
		$memberships = ( new \ExtraChillEvents\Core\PromoterAuthorityRepository() )->list_active_memberships_for_user( $user_id );
		foreach ( is_wp_error( $memberships ) ? array() : (array) $memberships as $membership ) {
			$owners[] = array( OwnerGuestListTable::OWNER_PROMOTER, (int) $membership['promoter_term_id'] );
		}
	}

	$ids = array();
	foreach ( $owners as $owner ) {
		$ids = array_merge( $ids, extrachill_events_owner_list_door_event_ids( $owner[0], $owner[1] ) );
	}
	return array_values( array_unique( $ids ) );
}

/**
 * What the /rsvp-verify/ scan page shows for an owner's-list code.
 *
 * Non-enumerating like the per-event verify page: null (the generic "could
 * not be verified") unless the viewer runs the door for a show this pass
 * could be scanned at. A removed or ineligible pass reveals no name.
 *
 * @param string $code     Scanned code.
 * @param int    $user_id  Viewer.
 * @param int    $event_id Show the viewer is scanning for, when known (0 = work it out from tonight's shows).
 * @return array|null `{ state: eligible|not_eligible|removed, owner, name?, events?[] }`, or null.
 */
function extrachill_events_owner_list_verify_view( string $code, int $user_id, int $event_id = 0 ): ?array {
	$code   = strtoupper( trim( $code ) );
	$member = ( $user_id > 0 && '' !== $code ) ? OwnerGuestListTable::find_by_code( $code ) : null;
	if ( ! $member ) {
		return null;
	}

	$owner = extrachill_events_owner_list_owner( (string) $member['owner_type'], (int) $member['owner_term_id'] );
	if ( is_wp_error( $owner ) ) {
		return null;
	}

	$owner_team = extrachill_events_user_can_manage_owner_list( $user_id, $owner['owner_type'], $owner['owner_id'] );

	if ( OwnerGuestListTable::STATUS_ACTIVE !== (string) $member['status'] ) {
		$scanned_at_door = $event_id > 0 && extrachill_events_user_can_manage_event( $user_id, $event_id );
		return ( $owner_team || $scanned_at_door )
			? array(
				'state' => 'removed',
				'owner' => $owner,
			)
			: null;
	}

	$candidates = $event_id > 0 ? array( $event_id ) : extrachill_events_owner_list_door_event_ids( $owner['owner_type'], $owner['owner_id'] );
	$events     = array();
	foreach ( $candidates as $candidate ) {
		if ( extrachill_events_owner_list_event_is_eligible( $member, (int) $candidate ) && extrachill_events_user_can_manage_event( $user_id, (int) $candidate ) ) {
			$checkin  = OwnerGuestListTable::find_checkin( (int) $member['id'], (int) $candidate );
			$post     = get_post( (int) $candidate );
			$events[] = array(
				'event_id'      => (int) $candidate,
				'title'         => $post ? html_entity_decode( (string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '',
				'checked_in_at' => $checkin ? (string) $checkin['checked_in_at'] : '',
			);
		}
	}

	if ( $events ) {
		return array(
			'state'  => 'eligible',
			'owner'  => $owner,
			'name'   => (string) $member['name'],
			'code'   => (string) $member['code'],
			'events' => $events,
		);
	}

	// Not for this show. Say so only to someone who runs a door tonight
	// (or the list's own team), never to a random viewer.
	$runs_a_door = $event_id > 0
		? extrachill_events_user_can_manage_event( $user_id, $event_id )
		: (bool) array_filter(
			extrachill_events_owner_list_staff_door_event_ids( $user_id ),
			static function ( $door_event_id ) use ( $user_id ) {
				return extrachill_events_user_can_manage_event( $user_id, (int) $door_event_id );
			}
		);

	return ( $runs_a_door || $owner_team )
		? array(
			'state' => 'not_eligible',
			'owner' => $owner,
		)
		: null;
}

/**
 * Email an owner's-list pass. Same branded template and pass body as the
 * per-event pass email: the code, the QR, and a button to the pass page.
 *
 * @param array $owner  Owner summary from extrachill_events_owner_list_owner().
 * @param array $member Member row.
 * @return bool Whether the email was queued.
 */
function extrachill_events_send_owner_list_pass_email( array $owner, array $member ): bool {
	if ( ! function_exists( 'ec_send_email_queued' ) || '' === (string) $member['email'] ) {
		return false;
	}

	$code = (string) $member['code'];
	/* translators: %s: venue or promoter name. */
	$html  = '<p>' . sprintf( esc_html__( "You're on the %s guest list.", 'extrachill-events' ), esc_html( $owner['owner_name'] ) ) . '</p>';
	$html .= '<p><strong>' . esc_html(
		sprintf(
			/* translators: %s: venue or promoter name. */
			__( 'This pass works at every %s show until the list changes.', 'extrachill-events' ),
			$owner['owner_name']
		)
	) . '</strong></p>';
	$html .= '<p>' . esc_html__( 'Show this pass at check-in:', 'extrachill-events' ) . '</p>';
	$html .= '<p style="font-size:20px;font-weight:bold;letter-spacing:2px;">' . esc_html( $code ) . '</p>';
	if ( function_exists( 'extrachill_events_rsvp_pass_qr_url' ) ) {
		$html .= '<p><img src="' . esc_url( extrachill_events_rsvp_pass_qr_url( $code ) ) . '" width="200" height="200" alt="' . esc_attr__( 'QR code for this pass', 'extrachill-events' ) . '"></p>';
	}

	$result = ec_send_email_queued(
		array(
			'to'        => (string) $member['email'],
			/* translators: %s: venue or promoter name. */
			'subject'   => sprintf( __( 'Your guest list pass for %s', 'extrachill-events' ), $owner['owner_name'] ),
			'from_name' => 'Extra Chill Events',
			'template'  => 'extrachill/branded',
			'context'   => array(
				'body_html'      => $html,
				'recipient_name' => (string) $member['name'],
				'cta_url'        => extrachill_events_owner_list_pass_url( $code ),
				'cta_label'      => __( 'Open my pass', 'extrachill-events' ),
			),
		)
	);

	$queued = function_exists( 'extrachill_events_rsvp_pass_email_queued' ) ? extrachill_events_rsvp_pass_email_queued( $result ) : ! empty( $result['success'] );
	if ( ! $queued ) {
		do_action(
			'datamachine_log',
			'error',
			'Owner guest list pass email was not queued',
			array(
				'owner_type' => $owner['owner_type'],
				'owner_id'   => $owner['owner_id'],
				'error_code' => (string) ( $result['error_code'] ?? '' ),
			)
		);
	}
	return $queued;
}

/**
 * The owners' lists the current user may manage for one event: each venue
 * or promoter on the show whose team they're on.
 *
 * @param int $event_id Event post ID.
 * @param int $user_id  User ID.
 * @return array[] Owner summaries.
 */
function extrachill_events_owner_lists_for_manager( int $event_id, int $user_id ): array {
	return array_values(
		array_filter(
			extrachill_events_owner_list_owners_for_event( $event_id ),
			static function ( $owner ) use ( $user_id ) {
				return extrachill_events_user_can_manage_owner_list( $user_id, $owner['owner_type'], $owner['owner_id'] );
			}
		)
	);
}
