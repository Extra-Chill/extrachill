<?php
/**
 * Event Management Authority
 *
 * Answers "is this user authorized to manage this event?" — the one shared
 * definition of "host" reused across every event-management surface in this
 * plugin: the RSVP perk configuration (EventPerkAbilities), the host door
 * list, and pass redemption (RsvpPassAbilities). One decision function, so
 * these surfaces cannot quietly drift into disagreeing about who a host is.
 *
 * "Manage" is:
 *  - a network admin, always; or
 *  - the standard WordPress edit_post capability on the event post — anyone
 *    who could already edit this event in wp-admin manages it, matching
 *    the perk meta box's own save-path check (inc/admin/event-perks.php);
 *    or
 *  - an active member of the event's promoter organization or venue team
 *    (PromoterAuthorityRepository, VenueMembershipRepository) — the
 *    organizing team, whether or not they hold edit_post on this specific
 *    post.
 *
 * No new role system.
 *
 * Originally named and scoped for the door list alone
 * (extrachill-events#877/#878, "RSVP Door List Authority"); generalized
 * here (#879) once RSVP perk configuration needed the identical check
 * (previously a separate, narrower `edit_post`-only check duplicated in
 * EventPerkAbilities) and slice 2's scan-to-redeem will need it too.
 * `extrachill_events_user_can_manage_event_door_list()` is kept as a thin
 * back-compat alias for any external caller pinned to the old name.
 *
 * This function is also wired into extrachill-users' own
 * `extrachill_users_can_manage_event_attendance` filter (see bottom of this
 * file), so extrachill-users' `extrachill/get-event-attendees-full` ability
 * is independently protected if it is ever called through a path other than
 * this plugin's own door-list ability — extrachill-users owns attendance
 * privacy and enforces its own permission check at its own boundary; it
 * does not trust callers to have already authorized. This file is the one
 * place that knows what "manage this event" means, so both boundaries call
 * the same decision function rather than duplicating the logic.
 *
 * @package ExtraChillEvents
 * @since 0.69.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a user may manage an event: perk configuration, the door list
 * (full attendee list including private attendees), and pass redemption.
 *
 * @param int $user_id  Candidate user ID.
 * @param int $event_id Event post ID.
 * @return bool
 */
function extrachill_events_user_can_manage_event( int $user_id, int $event_id ): bool {
	if ( $user_id < 1 ) {
		return false;
	}

	if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_network_options' ) ) {
		return true;
	}

	$post = get_post( $event_id );
	if ( ! $post || 'data_machine_events' !== $post->post_type ) {
		return false;
	}

	if ( user_can( $user_id, 'edit_post', $event_id ) ) {
		return true;
	}

	if ( class_exists( '\\ExtraChillEvents\\Core\\PromoterAuthoritySchema' ) && \ExtraChillEvents\Core\PromoterAuthoritySchema::is_ready() ) {
		$promoter_terms = wp_get_post_terms( $event_id, 'promoter' );
		if ( ! is_wp_error( $promoter_terms ) && ! empty( $promoter_terms ) ) {
			$repository = new \ExtraChillEvents\Core\PromoterAuthorityRepository();
			foreach ( $promoter_terms as $term ) {
				$membership = $repository->get_active_membership( (int) $term->term_id, $user_id );
				if ( is_array( $membership ) ) {
					return true;
				}
			}
		}
	}

	if ( class_exists( '\\ExtraChillEvents\\Core\\BookingSchema' ) && \ExtraChillEvents\Core\BookingSchema::is_ready() ) {
		$venue_terms = wp_get_post_terms( $event_id, 'venue' );
		if ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) {
			$repository = new \ExtraChillEvents\Core\VenueMembershipRepository();
			foreach ( $venue_terms as $term ) {
				$membership = $repository->get_active( (int) $term->term_id, $user_id );
				if ( is_array( $membership ) ) {
					return true;
				}
			}
		}
	}

	return false;
}

/**
 * Back-compat alias for the door-list-specific name this function used to
 * have. New call sites should use extrachill_events_user_can_manage_event()
 * directly.
 *
 * @deprecated 0.70.0 Use extrachill_events_user_can_manage_event().
 *
 * @param int $user_id  Candidate user ID.
 * @param int $event_id Event post ID.
 * @return bool
 */
function extrachill_events_user_can_manage_event_door_list( int $user_id, int $event_id ): bool {
	return extrachill_events_user_can_manage_event( $user_id, $event_id );
}

/**
 * Answer extrachill-users' generic attendance-management authorization
 * extension point using this plugin's event-management authority.
 *
 * Default is false (extrachill-users denies by default); this filter only
 * ever grants, never revokes an existing grant. Skips events on a different
 * blog — this plugin only knows promoter/venue terms on its own site.
 *
 * @param bool $allowed  Whether access is already granted.
 * @param int  $user_id  Candidate user ID.
 * @param int  $event_id Event post ID.
 * @param int  $blog_id  Blog ID the event lives on.
 * @return bool
 */
function extrachill_events_authorize_full_event_attendance( $allowed, $user_id, $event_id, $blog_id ) {
	if ( $allowed ) {
		return $allowed;
	}

	if ( $blog_id && get_current_blog_id() !== (int) $blog_id ) {
		return $allowed;
	}

	return extrachill_events_user_can_manage_event( (int) $user_id, (int) $event_id );
}
add_filter( 'extrachill_users_can_manage_event_attendance', 'extrachill_events_authorize_full_event_attendance', 10, 4 );
