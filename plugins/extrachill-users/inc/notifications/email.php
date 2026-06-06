<?php
/**
 * Unread-notification email digest channel.
 *
 * A DELIVERY CHANNEL on the network notification substrate (inc/notifications/
 * db.php + service.php). Users who have UNREAD in-app notifications they have
 * not seen get a periodic email nudge ("You have 3 unread notifications on
 * Extra Chill") to pull them back — a retention loop.
 *
 * Design:
 *   - A recurring sweep (hourly) finds users whose OLDEST unread notification is
 *     older than EC_NOTIFICATIONS_EMAIL_DELAY (so we never email instantly) and
 *     who have not been emailed within EC_NOTIFICATIONS_EMAIL_COOLDOWN (anti-spam:
 *     at most one digest per user per cooldown window). The sweep is scheduled
 *     through Data Machine's RecurringScheduler (Action Scheduler-backed), with
 *     a wp_schedule_event() fallback when Data Machine is unavailable.
 *   - For each such user, ONE branded HTML digest is ENQUEUED via the platform
 *     queued mail path (ec_send_email_queued() — one Action-Scheduler-backed job
 *     per recipient; it resolves the SMTP-configured site and handles
 *     switch_to_blog() internally; callers must NOT wrap it). Queuing keeps cron
 *     non-blocking and isolates a single failed send from the rest of the batch.
 *   - Per-user opt-out via the ec_notification_emails_disabled user_meta flag
 *     (default OFF == opted-in). Written ONLY through the canonical setter
 *     ec_users_set_notification_emails_disabled(), which both the settings-UI
 *     toggle (update-notification-preferences ability) and the one-click
 *     unsubscribe endpoint (inc/notifications/unsubscribe.php) call.
 *
 * Reads the substrate via ec_users_get_notifications() / a local distinct-user
 * query; never modifies db.php / service.php / abilities.
 *
 * Issues: Extra-Chill/extrachill-community#6 (the email nudge),
 *         Extra-Chill/extrachill-community#82 (the notification substrate epic).
 *
 * @package ExtraChill\Users
 * @since 0.15.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/service.php';
require_once __DIR__ . '/unsubscribe.php';

// ─── Tunables ────────────────────────────────────────────────────────────────

/**
 * How long a notification must sit UNREAD before it can trigger a digest email.
 *
 * Prevents emailing the instant a notification lands — gives the user a chance
 * to see it in-app first.
 */
if ( ! defined( 'EC_NOTIFICATIONS_EMAIL_DELAY' ) ) {
	define( 'EC_NOTIFICATIONS_EMAIL_DELAY', HOUR_IN_SECONDS );
}

/**
 * Minimum time between digest emails to the same user (anti-spam cooldown).
 *
 * At most one digest per user per window, regardless of how many unread
 * notifications accumulate.
 */
if ( ! defined( 'EC_NOTIFICATIONS_EMAIL_COOLDOWN' ) ) {
	define( 'EC_NOTIFICATIONS_EMAIL_COOLDOWN', DAY_IN_SECONDS );
}

/**
 * Max users processed per cron tick (keeps a single run bounded).
 */
if ( ! defined( 'EC_NOTIFICATIONS_EMAIL_BATCH_SIZE' ) ) {
	define( 'EC_NOTIFICATIONS_EMAIL_BATCH_SIZE', 50 );
}

/**
 * Number of latest unread titles to preview in the digest body.
 */
if ( ! defined( 'EC_NOTIFICATIONS_EMAIL_PREVIEW_COUNT' ) ) {
	define( 'EC_NOTIFICATIONS_EMAIL_PREVIEW_COUNT', 3 );
}

/**
 * User_meta key tracking when a user was last sent a digest (Unix timestamp).
 */
const EC_NOTIFICATIONS_LAST_EMAILED_META = 'ec_notifications_last_emailed';

/**
 * User_meta opt-out flag. Truthy value == this user does NOT want digest emails.
 * Absent/falsy == opted-in (default ON).
 */
const EC_NOTIFICATIONS_EMAILS_DISABLED_META = 'ec_notification_emails_disabled';

/**
 * Cron hook for the recurring digest run.
 */
const EC_NOTIFICATIONS_EMAIL_CRON_HOOK = 'ec_notifications_email_digest';

/**
 * Recurrence interval for the digest sweep (passed to RecurringScheduler).
 */
const EC_NOTIFICATIONS_EMAIL_INTERVAL = 'hourly';

// ─── Schedule ──────────────────────────────────────────────────────────────

/**
 * Fully qualified Data Machine RecurringScheduler class name.
 *
 * The digest sweep is scheduled through Data Machine's shared recurring
 * scheduling primitive (Action Scheduler-backed) rather than a hand-rolled
 * wp_schedule_event(). Data Machine stays generic — extrachill-users consumes
 * the primitive; no notification-specific code lives in Data Machine.
 */
const EC_NOTIFICATIONS_RECURRING_SCHEDULER = '\\DataMachine\\Engine\\Tasks\\RecurringScheduler';

/**
 * Ensure the recurring digest sweep is scheduled (idempotent).
 *
 * Self-heals on every site that loads this Network:true plugin, mirroring the
 * welcome-email fallback pattern in inc/core/activation.php. Because the
 * substrate is one base_prefix table shared by the whole network, only ONE
 * site needs to run the digest — we pin it to the SMTP-configured mail site so
 * exactly one scheduler owns it and sends always originate from a
 * mail-capable context.
 *
 * Scheduling goes through Data Machine's RecurringScheduler::ensureSchedule()
 * (Action Scheduler-backed, idempotent, persistence-verified). When Data
 * Machine is unavailable, falls back to WP-Cron via wp_schedule_event() so the
 * digest keeps working in isolation.
 *
 * On upgrade from the pre-queue WP-Cron implementation, any lingering
 * wp_schedule_event() registration of the same hook is cleared first so the
 * sweep cannot double-fire (once via WP-Cron and once via Action Scheduler).
 */
function ec_notifications_email_maybe_schedule() {
	$scheduler = EC_NOTIFICATIONS_RECURRING_SCHEDULER;

	if ( ! ec_notifications_email_is_owner_site() ) {
		// Not the owner site — make sure no stray schedule lingers here, in
		// either the legacy WP-Cron slot or the Action Scheduler slot.
		ec_notifications_email_clear_legacy_wp_cron();

		if ( class_exists( $scheduler ) ) {
			$scheduler::unschedule( EC_NOTIFICATIONS_EMAIL_CRON_HOOK, array() );
		}
		return;
	}

	// Always clear any legacy wp_schedule_event() registration. When DM is
	// available this prevents the old WP-Cron event from double-firing
	// alongside the Action Scheduler recurrence (upgrade path).
	if ( class_exists( $scheduler ) ) {
		ec_notifications_email_clear_legacy_wp_cron();

		$scheduler::ensureSchedule(
			EC_NOTIFICATIONS_EMAIL_CRON_HOOK,
			array(),
			EC_NOTIFICATIONS_EMAIL_INTERVAL
		);
		return;
	}

	// Fallback: Data Machine not loaded — keep the WP-Cron schedule.
	if ( ! wp_next_scheduled( EC_NOTIFICATIONS_EMAIL_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', EC_NOTIFICATIONS_EMAIL_CRON_HOOK );
	}
}
add_action( 'init', 'ec_notifications_email_maybe_schedule' );

/**
 * Clear any legacy WP-Cron registration of the digest hook.
 *
 * The pre-queue implementation scheduled the sweep with wp_schedule_event().
 * Once the sweep is owned by Data Machine's RecurringScheduler (Action
 * Scheduler) we must remove the WP-Cron event so the digest does not fire
 * twice. Safe to call unconditionally — a no-op when no WP-Cron event exists.
 *
 * @return void
 */
function ec_notifications_email_clear_legacy_wp_cron() {
	$existing = wp_next_scheduled( EC_NOTIFICATIONS_EMAIL_CRON_HOOK );
	if ( $existing ) {
		wp_clear_scheduled_hook( EC_NOTIFICATIONS_EMAIL_CRON_HOOK );
	}
}

/**
 * Is the current site the one that should run the digest cron?
 *
 * Pins ownership to the SMTP-configured mail site (main/community) so the
 * network-wide digest runs exactly once per tick from a mail-capable context.
 * Falls back to the main blog when the resolver is unavailable.
 *
 * @return bool
 */
function ec_notifications_email_is_owner_site() {
	$current = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	if ( $current <= 0 ) {
		return false;
	}

	if ( function_exists( 'ec_mail_site_id' ) ) {
		return (int) ec_mail_site_id() === $current;
	}

	if ( function_exists( 'ec_get_blog_id' ) ) {
		return (int) ec_get_blog_id( 'main' ) === $current;
	}

	return false;
}

// ─── Digest Run ──────────────────────────────────────────────────────────────

/**
 * Recurring callback: find eligible users and enqueue each one a digest email.
 *
 * Fired by the scheduled recurrence (Data Machine RecurringScheduler, or the
 * WP-Cron fallback). Per recipient, ec_notifications_email_send_digest()
 * enqueues an async send rather than calling wp_mail() inline, so the sweep
 * itself stays cheap and non-blocking.
 */
function ec_notifications_email_run() {
	$user_ids = ec_notifications_email_find_eligible_users( EC_NOTIFICATIONS_EMAIL_BATCH_SIZE );

	foreach ( $user_ids as $user_id ) {
		ec_notifications_email_send_digest( (int) $user_id );
	}
}
add_action( EC_NOTIFICATIONS_EMAIL_CRON_HOOK, 'ec_notifications_email_run' );

/**
 * Find users eligible for a digest email.
 *
 * Eligible == has at least one unread, NEVER-EMAILED notification whose
 * created_at is older than EC_NOTIFICATIONS_EMAIL_DELAY. The emailed_at IS NULL
 * predicate is what makes the digest "nudge once per notification": once a
 * notification has been included in a digest its emailed_at is stamped, so it
 * can never make the user eligible again. Without it the sweep re-nudged the
 * same stale unread notification every cooldown window, forever, until read.
 *
 * Uses the idx_email_sweep index (is_read, emailed_at, created_at) — one
 * grouped scan, no per-row loading.
 *
 * Cooldown + opt-out are filtered in PHP after the cheap DB pass so the SQL
 * stays index-friendly and decoupled from user_meta storage.
 *
 * @param int $limit Max users to return.
 * @return int[] User IDs ready for a digest, capped at $limit.
 */
function ec_notifications_email_find_eligible_users( $limit = 50 ) {
	global $wpdb;

	$limit = max( 1, (int) $limit );
	$table = extrachill_users_notifications_table_name();

	// Notifications must have been unread for at least the delay window.
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - EC_NOTIFICATIONS_EMAIL_DELAY );

	// Over-fetch candidates so PHP-side cooldown/opt-out filtering can still
	// fill the batch. Candidates are ordered by their oldest unread first so
	// the longest-waiting users are nudged first.
	$candidate_limit = $limit * 4;

	// Only users with an unread notification that has NEVER been emailed
	// (emailed_at IS NULL) are candidates — this is the once-per-notification
	// guard. A user whose every unread notification has already been emailed is
	// not eligible no matter how long it stays unread.
	$rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT user_id FROM {$table} WHERE is_read = 0 AND emailed_at IS NULL AND created_at <= %s GROUP BY user_id ORDER BY MIN(created_at) ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$cutoff,
			$candidate_limit
		)
	);

	$eligible = array();
	$now      = time();

	foreach ( (array) $rows as $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			continue;
		}

		if ( ec_notifications_email_user_opted_out( $user_id ) ) {
			continue;
		}

		if ( ec_notifications_email_in_cooldown( $user_id, $now ) ) {
			continue;
		}

		$eligible[] = $user_id;

		if ( count( $eligible ) >= $limit ) {
			break;
		}
	}

	return $eligible;
}

/**
 * Has the user opted out of digest emails?
 *
 * Default is OFF (opted-in). Any truthy value on the opt-out meta == disabled.
 *
 * @param int $user_id User ID.
 * @return bool True if the user should NOT receive digest emails.
 */
function ec_notifications_email_user_opted_out( $user_id ) {
	return (bool) get_user_meta( (int) $user_id, EC_NOTIFICATIONS_EMAILS_DISABLED_META, true );
}

/**
 * Canonical setter for the digest-email opt-out flag.
 *
 * THE single source of truth for writing ec_notification_emails_disabled. Both
 * the settings save path (the update-notification-preferences ability) and the
 * one-click unsubscribe endpoint call through here so the persistence logic
 * lives in exactly one place.
 *
 * Enabling removes the flag entirely (so the default "opted-in" state is the
 * absence of the meta), keeping ec_notifications_email_user_opted_out() simple.
 *
 * @param int  $user_id  User ID.
 * @param bool $disabled True to DISABLE digest emails, false to enable.
 * @return bool True on success, false on an invalid user.
 */
function ec_users_set_notification_emails_disabled( $user_id, $disabled ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}

	if ( $disabled ) {
		update_user_meta( $user_id, EC_NOTIFICATIONS_EMAILS_DISABLED_META, 1 );
		return true;
	}

	// Enabling = remove the flag so the opted-out check is false (default ON).
	delete_user_meta( $user_id, EC_NOTIFICATIONS_EMAILS_DISABLED_META );
	return true;
}

/**
 * Whether unread-notification digest emails are ENABLED for a user.
 *
 * User-facing sense: true == the user receives digest emails. Derived from the
 * inverted internal DISABLED flag. The settings UI binds its toggle to this.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function ec_users_notification_emails_enabled( $user_id ) {
	return ! ec_notifications_email_user_opted_out( $user_id );
}

/**
 * Is the user inside the anti-spam cooldown window?
 *
 * @param int $user_id User ID.
 * @param int $now     Reference Unix timestamp.
 * @return bool True if a digest was sent within the cooldown window.
 */
function ec_notifications_email_in_cooldown( $user_id, $now ) {
	$last = (int) get_user_meta( (int) $user_id, EC_NOTIFICATIONS_LAST_EMAILED_META, true );
	if ( $last <= 0 ) {
		return false;
	}

	return ( $now - $last ) < EC_NOTIFICATIONS_EMAIL_COOLDOWN;
}

/**
 * Assemble + enqueue one digest email to a user.
 *
 * Re-checks unread state at dispatch time (state can change between the
 * candidate scan and dispatch), builds the branded digest, then ENQUEUES the
 * send via ec_send_email_queued() — one Action-Scheduler-backed job per
 * recipient. This avoids a blocking synchronous wp_mail() loop across the whole
 * batch: cron no longer waits on SMTP per user, sends are spread across AS
 * dispatch ticks, and a single failed send is isolated to its own job (the
 * queued worker retries with backoff and never stalls the rest of the batch).
 *
 * The queued send is fire-and-forget from the digest's perspective, so the
 * cooldown is stamped at ENQUEUE time (on a confirmed enqueue) rather than
 * after delivery — eligibility and the unread count have already been verified
 * here, and the worker re-builds nothing. ec_send_email_queued() resolves the
 * SMTP-configured site and handles switch_to_blog() internally — do NOT wrap.
 *
 * On a confirmed enqueue, every unread notification that had not yet been
 * emailed is stamped with emailed_at = now so it can never trigger another
 * digest (the once-per-notification guard). A user only receives a fresh digest
 * when NEW (never-emailed) notifications arrive.
 *
 * @param int $user_id Recipient user ID.
 * @return bool True when a digest send was enqueued.
 */
function ec_notifications_email_send_digest( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}

	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User || empty( $user->user_email ) || ! is_email( $user->user_email ) ) {
		return false;
	}

	// Re-check opt-out + cooldown at dispatch time (defensive against races).
	if ( ec_notifications_email_user_opted_out( $user_id ) ) {
		return false;
	}
	if ( ec_notifications_email_in_cooldown( $user_id, time() ) ) {
		return false;
	}

	// There must be at least one unread notification we have NOT already
	// emailed about — otherwise this is a stale candidate (all unread items were
	// nudged in a prior digest) and re-sending would be the exact daily-repeat
	// bug this guard exists to prevent.
	if ( ec_notifications_email_count_unmailed_unread( $user_id ) <= 0 ) {
		return false;
	}

	// Pull the unread count + a small preview using the substrate reader.
	$result = ec_users_get_notifications(
		$user_id,
		array(
			'unread'   => true,
			'page'     => 1,
			'per_page' => EC_NOTIFICATIONS_EMAIL_PREVIEW_COUNT,
		)
	);

	$unread_count = (int) $result['unread_count'];
	if ( $unread_count <= 0 ) {
		// Nothing unread anymore — skip without burning the cooldown.
		return false;
	}

	$preview = $result['notifications'];

	$digest = ec_notifications_email_build_digest( $user, $unread_count, $preview );

	// Append a tokenized one-click unsubscribe footer line. Built here (not in
	// the pure build_digest assembler) because it mints a per-user signed URL.
	$body_html        = $digest['body_html'];
	$unsubscribe_html = ec_notifications_email_unsubscribe_footer_html( $user_id );
	if ( '' !== $unsubscribe_html ) {
		$body_html .= $unsubscribe_html;
	}

	// Enqueue one async send per recipient. ec_send_email_queued() delegates to
	// the datamachine/send-email-queued ability, which returns
	// [ 'success' => bool, 'action_id' => int, ... ].
	$envelope = ec_send_email_queued(
		array(
			'to'       => $user->user_email,
			'subject'  => $digest['subject'],
			'template' => 'extrachill/branded',
			'context'  => array(
				'subject_html'   => esc_html( $digest['subject'] ),
				'recipient_name' => $user->display_name,
				'body_html'      => $body_html,
				'cta_url'        => $digest['cta_url'],
				'cta_label'      => __( 'View Notifications', 'extrachill-users' ),
				'preheader'      => $digest['preheader'],
			),
		)
	);

	$queued = ! empty( $envelope['success'] );

	if ( ! $queued ) {
		$error = isset( $envelope['error'] ) ? (string) $envelope['error'] : 'unknown error';
		error_log( sprintf( 'ec_notifications_email: digest enqueue failed for user %d: %s', $user_id, $error ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- canonical operational logging surface.
		return false;
	}

	// Mark every currently-unread, not-yet-emailed notification as emailed so it
	// can never re-trigger a digest (the once-per-notification guard). Done on a
	// confirmed enqueue: the send is async, but eligibility is already verified
	// and the worker re-builds nothing, so stamping here is correct.
	ec_notifications_email_mark_unread_as_emailed( $user_id );

	// Stamp the cooldown on a confirmed enqueue. The actual send happens
	// asynchronously via Action Scheduler (with its own retry/backoff), so we
	// cannot wait for delivery to record the cooldown.
	update_user_meta( $user_id, EC_NOTIFICATIONS_LAST_EMAILED_META, time() );

	return true;
}

/**
 * Count a user's unread notifications that have NOT yet been emailed.
 *
 * The once-per-notification eligibility signal: only notifications with
 * emailed_at IS NULL can justify a digest. Uses the idx_email_sweep index.
 *
 * @param int $user_id User ID.
 * @return int Number of unread, never-emailed notifications.
 */
function ec_notifications_email_count_unmailed_unread( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return 0;
	}

	$table = extrachill_users_notifications_table_name();

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0 AND emailed_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$user_id
		)
	);
}

/**
 * Stamp emailed_at = now on a user's unread, not-yet-emailed notifications.
 *
 * After a digest is enqueued, every unread notification that was eligible to be
 * nudged is marked so it never triggers another email. Only unread + NULL
 * rows are touched: already-read rows are irrelevant and already-emailed rows
 * keep their original (earlier) stamp.
 *
 * @param int $user_id User ID.
 * @return int Number of rows stamped.
 */
function ec_notifications_email_mark_unread_as_emailed( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return 0;
	}

	$table = extrachill_users_notifications_table_name();
	$now   = gmdate( 'Y-m-d H:i:s' );

	return (int) $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET emailed_at = %s WHERE user_id = %d AND is_read = 0 AND emailed_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$now,
			$user_id
		)
	);
}

/**
 * Build the digest subject + HTML body for a user.
 *
 * Pure assembly — no sending, no side effects (safe to dry-run / unit-test).
 *
 * @param WP_User $user         Recipient.
 * @param int     $unread_count Total unread count.
 * @param array   $preview      Latest enriched unread notifications (preview).
 * @return array{ subject:string, preheader:string, body_html:string, cta_url:string }
 */
function ec_notifications_email_build_digest( WP_User $user, $unread_count, array $preview ) {
	$unread_count = (int) $unread_count;

	$subject = sprintf(
		/* translators: %d: number of unread notifications. */
		_n( 'You have %d unread notification on Extra Chill', 'You have %d unread notifications on Extra Chill', $unread_count, 'extrachill-users' ),
		$unread_count
	);

	$notifications_url = ec_notifications_email_notifications_url();

	$body_html = '<p>' . sprintf(
		/* translators: %d: number of unread notifications. */
		esc_html( _n( 'You have %d unread notification waiting for you on Extra Chill.', 'You have %d unread notifications waiting for you on Extra Chill.', $unread_count, 'extrachill-users' ) ),
		$unread_count
	) . '</p>';

	$preview_items = array();
	foreach ( $preview as $note ) {
		$title = isset( $note['title'] ) ? (string) $note['title'] : '';
		if ( '' === $title ) {
			continue;
		}
		$link = isset( $note['link'] ) ? (string) $note['link'] : '';
		if ( '' !== $link ) {
			$preview_items[] = '<li><a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a></li>';
		} else {
			$preview_items[] = '<li>' . esc_html( $title ) . '</li>';
		}
	}

	if ( ! empty( $preview_items ) ) {
		$body_html .= '<ul>' . implode( '', $preview_items ) . '</ul>';

		$remaining = $unread_count - count( $preview_items );
		if ( $remaining > 0 ) {
			$body_html .= '<p>' . sprintf(
				/* translators: %d: number of additional unread notifications not shown. */
				esc_html( _n( '…and %d more.', '…and %d more.', $remaining, 'extrachill-users' ) ),
				$remaining
			) . '</p>';
		}
	}

	$preheader = wp_strip_all_tags(
		sprintf(
			/* translators: %d: number of unread notifications. */
			_n( '%d unread notification on Extra Chill.', '%d unread notifications on Extra Chill.', $unread_count, 'extrachill-users' ),
			$unread_count
		)
	);

	return array(
		'subject'   => $subject,
		'preheader' => $preheader,
		'body_html' => $body_html,
		'cta_url'   => $notifications_url,
	);
}

/**
 * Build the one-click unsubscribe footer HTML for a digest email.
 *
 * Mints a per-user signed unsubscribe URL (inc/notifications/unsubscribe.php)
 * and wraps it in a small footer paragraph. Returns '' if the URL can't be
 * built (e.g. invalid user), so the caller simply omits the line.
 *
 * @param int $user_id Recipient user ID.
 * @return string Footer HTML, or '' on failure.
 */
function ec_notifications_email_unsubscribe_footer_html( $user_id ) {
	if ( ! function_exists( 'ec_notifications_unsubscribe_url' ) ) {
		return '';
	}

	$url = ec_notifications_unsubscribe_url( (int) $user_id );
	if ( '' === $url ) {
		return '';
	}

	return '<p style="font-size:12px;color:#888;margin-top:24px;">'
		. esc_html__( 'Don\'t want these emails?', 'extrachill-users' ) . ' '
		. '<a href="' . esc_url( $url ) . '" style="color:#888;">'
		. esc_html__( 'Unsubscribe from notification emails', 'extrachill-users' )
		. '</a>.</p>';
}

/**
 * Resolve the URL of the community notifications page.
 *
 * The in-app notifications view lives at community.extrachill.com/notifications
 * (rendered by extrachill-community). Falls back to the community site root,
 * then the main site, so the CTA is never empty.
 *
 * @return string
 */
function ec_notifications_email_notifications_url() {
	if ( function_exists( 'ec_get_site_url' ) ) {
		$community = ec_get_site_url( 'community' );
		if ( $community ) {
			return trailingslashit( $community ) . 'notifications/';
		}

		$main = ec_get_site_url( 'main' );
		if ( $main ) {
			return trailingslashit( $main );
		}
	}

	return home_url( '/' );
}
