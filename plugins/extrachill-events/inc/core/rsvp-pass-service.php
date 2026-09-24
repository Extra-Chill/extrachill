<?php
/**
 * RSVP Pass Service
 *
 * Reacts to extrachill-users' attendance hooks to issue, reactivate, and
 * revoke RSVP perk passes. Attendance itself (the mark/unmark) stays owned
 * by extrachill-users (ec_users_mark_event() / ec_users_unmark_event(),
 * inc/concert-tracking/service.php); this file only listens.
 *
 * @package ExtraChillEvents
 * @since 0.69.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issue (or idempotently return, or reactivate) a pass when a user newly
 * marks Going on a perk-enabled event.
 *
 * Fires only on the genuine unmarked -> marked transition — see
 * ec_users_mark_event()'s own docblock ("not on no-op re-marks") — so a
 * double-click that the attendance table already treats as a no-op never
 * reaches this listener at all. What remains idempotent here is the reverse
 * direction: unmark -> mark -> unmark -> mark, which reactivates the same
 * row with a fresh code rather than creating a duplicate.
 *
 * @param int $user_id  User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id  Blog ID the event lives on.
 */
function extrachill_events_rsvp_pass_on_marked( int $user_id, int $event_id, int $blog_id ): void {
	// extrachill-users fires this network-wide for any Events-blog target;
	// the pass table only exists on this site, so only act when the event
	// actually lives here.
	if ( (int) get_current_blog_id() !== $blog_id ) {
		return;
	}

	if ( ! function_exists( 'extrachill_events_perk_enabled' ) || ! extrachill_events_perk_enabled( $event_id ) ) {
		return;
	}

	if ( ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		return;
	}

	$pass = \ExtraChillEvents\Core\RsvpPassesTable::issue_or_reactivate( $event_id, $user_id );
	if ( ! $pass ) {
		return;
	}

	extrachill_events_send_rsvp_pass_email( $event_id, $user_id, $pass );
}
add_action( 'ec_users_event_marked', 'extrachill_events_rsvp_pass_on_marked', 10, 3 );

/**
 * Revoke a pass when a user unmarks Going.
 *
 * @param int $user_id  User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id  Blog ID the event lives on.
 */
function extrachill_events_rsvp_pass_on_unmarked( int $user_id, int $event_id, int $blog_id ): void {
	if ( (int) get_current_blog_id() !== $blog_id ) {
		return;
	}

	if ( ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		return;
	}

	\ExtraChillEvents\Core\RsvpPassesTable::revoke( $event_id, $user_id );
}
add_action( 'ec_users_event_unmarked', 'extrachill_events_rsvp_pass_on_unmarked', 10, 3 );

/**
 * Email the attendee their pass.
 *
 * Queued (`ec_send_email_queued()`), not synchronous: this listener runs
 * inline inside the `extrachill/toggle-event-mark` REST request, and a
 * blocking mail send would slow down the attendee's click. Failures are
 * non-fatal — the on-screen pass (inc/core/rsvp-pass-integration.php) and
 * the door list are always the authoritative displays; email is a
 * convenience delivery channel.
 *
 * @param int   $event_id Event post ID.
 * @param int   $user_id  Attendee user ID.
 * @param array $pass     Pass row from RsvpPassesTable.
 */
function extrachill_events_send_rsvp_pass_email( int $event_id, int $user_id, array $pass ): void {
	if ( ! function_exists( 'ec_send_email_queued' ) ) {
		return;
	}

	$user = get_userdata( $user_id );
	if ( ! $user || ! is_email( $user->user_email ) ) {
		return;
	}

	$post = get_post( $event_id );
	if ( ! $post ) {
		return;
	}

	$perk_text = function_exists( 'extrachill_events_get_perk_text' ) ? extrachill_events_get_perk_text( $event_id ) : '';
	$body      = extrachill_events_render_rsvp_pass_email_body( $post, $perk_text, (string) $pass['code'] );

	ec_send_email_queued(
		array(
			'to'        => $user->user_email,
			/* translators: %s: Event title. */
			'subject'   => sprintf( __( 'Your RSVP pass for %s', 'extrachill-events' ), $post->post_title ),
			'from_name' => 'Extra Chill Events',
			'template'  => 'extrachill/branded',
			'context'   => array(
				'recipient_name' => $user->display_name,
				'body_html'      => $body,
				'cta_url'        => (string) get_permalink( $post ),
				'cta_label'      => __( 'View Event', 'extrachill-events' ),
			),
		)
	);
}

/**
 * Build the pass email body HTML.
 *
 * @param WP_Post $post      Event post.
 * @param string  $perk_text Attendee-facing perk description.
 * @param string  $code      Pass code, as issued.
 * @return string
 */
function extrachill_events_render_rsvp_pass_email_body( $post, string $perk_text, string $code ): string {
	/* translators: %s: Event title. */
	$html = '<p>' . sprintf( esc_html__( "You're going to %s.", 'extrachill-events' ), esc_html( $post->post_title ) ) . '</p>';

	if ( '' !== $perk_text ) {
		$html .= '<p><strong>' . esc_html( $perk_text ) . '</strong></p>';
	}

	$html .= '<p>' . esc_html__( 'Show this pass at the door:', 'extrachill-events' ) . '</p>';
	$html .= '<p style="font-size:20px;font-weight:bold;letter-spacing:2px;">' . esc_html( $code ) . '</p>';

	return $html;
}
