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
 * Issue passes to everyone already marked Going when a perk is turned on.
 *
 * Passes were only issued on the unmarked -> marked transition, so turning
 * a perk on after people had RSVP'd left every existing attendee without a
 * pass, email, or door-list entry (#920). This fills that gap using the same
 * issue + email path as a fresh mark.
 *
 * Idempotent: attendees who already hold an active or redeemed pass are
 * skipped, so they get no second email and their code is never touched.
 * A revoked pass for someone who is Going again is reactivated with a fresh
 * code, the same as re-marking.
 *
 * @param int $event_id Event post ID on the current blog.
 * @return int Number of passes issued or reactivated.
 */
function extrachill_events_backfill_rsvp_passes( int $event_id ): int {
	if ( ! function_exists( 'extrachill_events_perk_enabled' ) || ! extrachill_events_perk_enabled( $event_id ) ) {
		return 0;
	}

	if ( ! function_exists( 'ec_users_get_event_attendees_full' ) || ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		return 0;
	}

	$limit     = defined( 'EC_USERS_EVENT_ATTENDEE_FULL_LIMIT_MAX' ) ? (int) EC_USERS_EVENT_ATTENDEE_FULL_LIMIT_MAX : 1000;
	$attendees = ec_users_get_event_attendees_full( $event_id, (int) get_current_blog_id(), $limit );

	$issued = 0;
	foreach ( (array) $attendees as $attendee ) {
		$user_id = (int) $attendee['user_id'];
		if ( $user_id <= 0 ) {
			continue;
		}

		$existing = \ExtraChillEvents\Core\RsvpPassesTable::find_for_user_event( $event_id, $user_id );
		if ( $existing && \ExtraChillEvents\Core\RsvpPassesTable::STATUS_REVOKED !== $existing['status'] ) {
			continue;
		}

		$pass = \ExtraChillEvents\Core\RsvpPassesTable::issue_or_reactivate( $event_id, $user_id );
		if ( ! $pass ) {
			continue;
		}

		extrachill_events_send_rsvp_pass_email( $event_id, $user_id, $pass );
		++$issued;
	}

	return $issued;
}

/**
 * Backfill passes when the perk-enabled meta is written with a truthy value.
 *
 * Both write paths (the extrachill/set-event-perk ability and the admin meta
 * box) go through update_post_meta(), so this one meta hook covers both. A
 * disabled perk has no meta row, so enabling it fires `added_post_meta`.
 * Re-saving an enabled perk writes the same value, which core treats as a
 * no-op that fires no hook. `updated_post_meta` is handled too for any other
 * truthy write, and the backfill is idempotent either way.
 *
 * @param int    $meta_id    Meta ID.
 * @param int    $object_id  Post ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 */
function extrachill_events_rsvp_perk_meta_written( $meta_id, $object_id, $meta_key, $meta_value ): void {
	unset( $meta_id );

	if ( ! defined( 'EXTRACHILL_EVENTS_PERK_ENABLED_META' ) || EXTRACHILL_EVENTS_PERK_ENABLED_META !== $meta_key || ! $meta_value ) {
		return;
	}

	extrachill_events_backfill_rsvp_passes( (int) $object_id );
}
add_action( 'added_post_meta', 'extrachill_events_rsvp_perk_meta_written', 10, 4 );
add_action( 'updated_post_meta', 'extrachill_events_rsvp_perk_meta_written', 10, 4 );

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

	// ec_send_email_queued() already sends as the system (extrachill-network#318);
	// callers must not wrap it in their own PermissionHelper context.
	$result = ec_send_email_queued(
		array(
			'to'        => $user->user_email,
			/* translators: %s: Event title. */
			'subject'   => sprintf( __( 'Your RSVP pass for %s', 'extrachill-events' ), extrachill_events_rsvp_pass_event_title( $post ) ),
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

	if ( ! extrachill_events_rsvp_pass_email_queued( $result ) ) {
		// A refused send used to vanish silently (#897). The pass itself is
		// still issued and shown on screen and at the door, so this stays
		// non-fatal, but it must be visible. No recipient address in the log.
		do_action(
			'datamachine_log',
			'error',
			'RSVP pass email was not queued',
			array(
				'event_id'   => $event_id,
				'user_id'    => $user_id,
				'error_code' => (string) ( $result['error_code'] ?? '' ),
				'error'      => (string) ( $result['error'] ?? '' ),
			)
		);
	}
}

/**
 * Whether a queued-send result reports success.
 *
 * `ec_send_email_queued()` normalizes to `[ 'success' => bool, ... ]`; anything
 * else (a WP_Error from an older wrapper, null, a non-array) is a failure.
 *
 * @param mixed $result Result from ec_send_email_queued().
 * @return bool
 */
function extrachill_events_rsvp_pass_email_queued( $result ): bool {
	return is_array( $result ) && true === ( $result['success'] ?? false );
}

/**
 * Event title as plain text for the pass email.
 *
 * `post_title` is stored with HTML entities (e.g. `Extra Chill &amp; WordPress`).
 * Used raw, the subject line showed a literal `&amp;` and the body, which
 * escapes again, showed `&amp;amp;`. Decode once here; callers escape for
 * their own output context.
 *
 * @param WP_Post $post Event post.
 * @return string
 */
function extrachill_events_rsvp_pass_event_title( $post ): string {
	return html_entity_decode( (string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Build the pass email body HTML.
 *
 * The QR (slice 2) is a normal remote `<img>` pointing at this plugin's own
 * QR endpoint (inc/core/rsvp-pass-qr.php), not a base64 data URI — Gmail's
 * webmail strips `data:` image sources, so a normal hosted URL is the
 * reliable choice for a transactional email. Rendering degrades cleanly if
 * the endpoint is unavailable (see extrachill_events_rsvp_pass_qr_url()'s
 * own guards): the image simply fails to load and the plaintext code below
 * it remains fully usable on its own.
 *
 * @param WP_Post $post      Event post.
 * @param string  $perk_text Attendee-facing perk description.
 * @param string  $code      Pass code, as issued.
 * @return string
 */
function extrachill_events_render_rsvp_pass_email_body( $post, string $perk_text, string $code ): string {
	/* translators: %s: Event title. */
	$html = '<p>' . sprintf( esc_html__( "You're going to %s.", 'extrachill-events' ), esc_html( extrachill_events_rsvp_pass_event_title( $post ) ) ) . '</p>';

	if ( '' !== $perk_text ) {
		$html .= '<p><strong>' . esc_html( $perk_text ) . '</strong></p>';
	}

	$html .= '<p>' . esc_html__( 'Show this pass at check-in:', 'extrachill-events' ) . '</p>';
	$html .= '<p style="font-size:20px;font-weight:bold;letter-spacing:2px;">' . esc_html( $code ) . '</p>';

	if ( function_exists( 'extrachill_events_rsvp_pass_qr_url' ) ) {
		$qr_url = extrachill_events_rsvp_pass_qr_url( $code );
		$html  .= '<p><img src="' . esc_url( $qr_url ) . '" width="200" height="200" alt="' . esc_attr__( 'QR code for this pass', 'extrachill-events' ) . '"></p>';
	}

	return $html;
}
