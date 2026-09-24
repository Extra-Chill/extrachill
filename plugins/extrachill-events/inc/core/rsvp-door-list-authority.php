<?php
/**
 * RSVP Door List Authority
 *
 * Answers "is this user authorized to see this event's full attendee list
 * (including private attendees) and redeem its RSVP passes?" — the resolution
 * to the extrachill-users#414/#415 privacy tension: attendance stays private
 * to the public, but the host of an event they run may see who is coming.
 *
 * "Host" reuses the existing promoter/venue authority services (PromoterAuthorityRepository,
 * VenueMembershipRepository) — no new role system. Network admins always pass.
 *
 * This function is also wired into extrachill-users' own
 * `extrachill_users_can_manage_event_attendance` filter (see bottom of this
 * file), so extrachill-users' `extrachill/get-event-attendees-full` ability
 * is independently protected if it is ever called through a path other than
 * this plugin's own door-list ability — extrachill-users owns attendance
 * privacy and enforces its own permission check at its own boundary; it
 * does not trust callers to have already authorized. This file is the one
 * place that knows what "host" means for an event, so both boundaries call
 * the same decision function rather than duplicating the logic.
 *
 * @package ExtraChillEvents
 * @since 0.69.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a user may manage the door list (full attendee list + redemption)
 * for an event.
 *
 * @param int $user_id  Candidate user ID.
 * @param int $event_id Event post ID.
 * @return bool
 */
function extrachill_events_user_can_manage_event_door_list( int $user_id, int $event_id ): bool {
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
 * Answer extrachill-users' generic attendance-management authorization
 * extension point using this plugin's event-host authority.
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

	return extrachill_events_user_can_manage_event_door_list( (int) $user_id, (int) $event_id );
}
add_filter( 'extrachill_users_can_manage_event_attendance', 'extrachill_events_authorize_full_event_attendance', 10, 4 );
