<?php
/**
 * Concert Tracking Integration
 *
 * Hooks into data_machine_events_action_buttons to render attendance buttons
 * on single event pages. This is the composition layer — it decides how the
 * event action row is arranged based on event timing, and delegates all
 * attendance/nudge markup to extrachill-users.
 *
 * @package ExtraChillEvents
 * @since 0.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render attendance button in event action buttons container.
 *
 * Fires on the data_machine_events_action_buttons hook at priority 5
 * (before the share button at 10) to place it as the primary CTA.
 *
 * Composition is tense-aware. data-machine-events passes the event timing
 * ('past' | 'ongoing' | 'upcoming') into the hook and de-emphasizes its own
 * ticket / add-to-calendar CTAs on past events. This layer arranges the
 * attendance CTA accordingly:
 *
 * - PAST: request the attendance-first 'past-hero' variant so "I Was There"
 *   becomes the primary action with archive-payoff framing. For logged-in
 *   visitors, also render the import-at-intent nudge immediately after the
 *   attendance button — moving the concert-history unlock from the buried
 *   /my-shows/ tab to the moment of intent. We do NOT re-promote the ticket /
 *   add-to-calendar CTAs here; the generic per-#406 de-emphasis stands.
 * - UPCOMING / ONGOING: unchanged — the standard inline attendance toggle,
 *   secondary to the ticket-first action row.
 *
 * Guards:
 * - Only on events.extrachill.com (blog_id check)
 * - Graceful no-op if extrachill-users isn't active
 *
 * @hook data_machine_events_action_buttons
 * @param int    $post_id    Event post ID.
 * @param string $ticket_url Ticket URL (may be empty).
 * @param string $timing     Event timing state: 'past' | 'ongoing' | 'upcoming'.
 */
function ec_events_render_attendance_button( $post_id, $ticket_url, $timing = '' ) {
	unset( $ticket_url );
	if ( ! ec_is_events_site() ) {
		return;
	}

	if ( ! function_exists( 'ec_users_render_attendance_button' ) ) {
		return;
	}

	$event_id = (int) $post_id;

	if ( 'past' === $timing ) {
		// Attendance-first hero: "I Was There" becomes the primary action with
		// archive-payoff framing (logged-out framing is handled by the provider
		// via its logged_out_* copy when the visitor isn't signed in).
		ec_users_render_attendance_button(
			$event_id,
			array(
				'variant'               => 'past-hero',
				'hero_heading'          => __( 'Were you there?', 'extrachill-events' ),
				'hero_subheading'       => __( 'Add this show to your concert archive.', 'extrachill-events' ),
				'logged_out_heading'    => __( 'Were you there?', 'extrachill-events' ),
				'logged_out_subheading' => __( "Sign up to build your concert archive — every show you've seen, in one place.", 'extrachill-events' ),
			)
		);

		// Import-at-intent: once a logged-in visitor is at the point of marking
		// "I Was There", surface the concert-history import path (setlist.fm /
		// phish.net) right here rather than the buried /my-shows/ tab.
		if ( is_user_logged_in() && function_exists( 'ec_users_render_import_nudge' ) ) {
			ec_users_render_import_nudge();
		}

		return;
	}

	// Upcoming / ongoing: unchanged — standard inline attendance toggle.
	ec_users_render_attendance_button( $event_id );
}
add_action( 'data_machine_events_action_buttons', 'ec_events_render_attendance_button', 5, 3 );

/**
 * Only people who were there can rate a show.
 *
 * data-machine-events owns the generic rating primitive (any logged-in user,
 * past events). Extra Chill narrows it to verified attendance: the user must
 * have an "I Was There" mark for the event, made by hand or by a concert
 * history import (setlist.fm, phish.net).
 *
 * @hook data_machine_events_can_rate_event
 * @param bool $can_rate Whether the primitive allows rating.
 * @param int  $user_id  User ID.
 * @param int  $post_id  Event post ID.
 * @return bool
 */
function ec_events_require_attendance_to_rate( $can_rate, $user_id, $post_id ) {
	if ( ! $can_rate || ! ec_is_events_site() ) {
		return $can_rate;
	}

	if ( ! function_exists( 'ec_users_is_event_marked' ) ) {
		return false;
	}

	return ec_users_is_event_marked( (int) $user_id, (int) $post_id, get_current_blog_id() );
}
add_filter( 'data_machine_events_can_rate_event', 'ec_events_require_attendance_to_rate', 10, 3 );

/**
 * Tell people how to unlock rating a show.
 *
 * @hook data_machine_events_rating_gate_message
 * @param string $message Default message.
 * @return string
 */
function ec_events_rating_gate_message( $message ) {
	if ( ! ec_is_events_site() ) {
		return $message;
	}

	return __( 'Only people who were there can rate this show. Tap “I Was There” above to leave your rating.', 'extrachill-events' );
}
add_filter( 'data_machine_events_rating_gate_message', 'ec_events_rating_gate_message' );

/**
 * Unlock the rating form in place when a user marks "I Was There".
 *
 * Bridges extrachill-users' `ec:attendance-changed` DOM event to
 * data-machine-events' `dme:rating-eligibility`, so the form appears
 * without a reload.
 */
function ec_events_enqueue_rating_unlock() {
	if ( ! is_user_logged_in() || ! is_singular( 'data_machine_events' ) ) {
		return;
	}

	wp_enqueue_script(
		'extrachill-events-rating-unlock',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/rating-unlock.js',
		array(),
		EXTRACHILL_EVENTS_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'ec_events_enqueue_rating_unlock' );

/**
 * Publish reviews from verified attendees without a moderation wait.
 *
 * Core holds a user's first comment on a blog when "previously approved"
 * moderation is on, and the events blog starts with no comments, so every
 * first review would sit in the queue. An attendee's review is already
 * logged-in and gated on attendance, so treat it as trusted. Spam and trash
 * verdicts and WP_Error results are left alone; staff can still unapprove
 * a review afterwards.
 *
 * @hook pre_comment_approved
 * @param int|string|\WP_Error $approved    Approval status from core.
 * @param array                $commentdata Comment data.
 * @return int|string|\WP_Error
 */
function ec_events_approve_attendee_reviews( $approved, $commentdata ) {
	if ( 0 !== $approved && '0' !== $approved ) {
		return $approved;
	}

	if ( 'event_review' !== ( $commentdata['comment_type'] ?? '' ) || ! ec_is_events_site() ) {
		return $approved;
	}

	$user_id  = (int) ( $commentdata['user_id'] ?? 0 );
	$event_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
	if ( $user_id <= 0 || $event_id <= 0 || ! function_exists( 'ec_users_is_event_marked' ) ) {
		return $approved;
	}

	return ec_users_is_event_marked( $user_id, $event_id, get_current_blog_id() ) ? 1 : $approved;
}
add_filter( 'pre_comment_approved', 'ec_events_approve_attendee_reviews', 10, 2 );
