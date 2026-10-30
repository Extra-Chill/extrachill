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
