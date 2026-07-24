<?php
/**
 * Concert tracking engagement notifications.
 *
 * The first engagement consumers of the network notification substrate
 * (ec_users_notify(), inc/notifications/service.php). Two features, both
 * reacting to the concert-tracking domain hooks fired from service.php:
 *
 *   1. SHOW REMINDERS (scheduled) — when a user marks an UPCOMING show, an
 *      Action Scheduler single action is queued for ~2 days before the event
 *      start. The callback fires ec_users_notify() with a "show_reminder"
 *      in-app notification. Unmarking cancels the pending action. Past/ongoing
 *      marks (including the bulk historical importer) get no reminder, so a
 *      large past-event import never schedules a flood of actions.
 *
 *   2. MILESTONES (in-app, at mark time) — when a mark causes the user's total
 *      tracked-show count to cross a milestone (1, 10, 25, 50, 100, then every
 *      100), fire a "milestone" in-app notification linking to My Shows.
 *
 * This file is the consumer only: it never writes notification storage
 * directly, it always calls ec_users_notify(). It also never touches the
 * substrate in inc/notifications/* nor the email channel.
 *
 * Parent epic: Extra-Chill/extrachill-community#82.
 * Substrate:   Extra-Chill/extrachill-users#87.
 *
 * @package ExtraChill\Users
 * @since 0.16.0
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ───────────────────────────────────────────────────────────────

/**
 * Action Scheduler hook for a queued show reminder.
 */
const EC_USERS_SHOW_REMINDER_ACTION = 'ec_users_send_show_reminder';

/**
 * Notification `type` value for a show reminder.
 *
 * Canonical home for the time-sensitive reminder type. Other consumers (e.g.
 * the email digest channel) reference this constant rather than re-declaring
 * the literal, so the staleness logic and the producer can never drift.
 */
const EC_USERS_SHOW_REMINDER_TYPE = 'show_reminder';

/**
 * Action Scheduler group for concert reminders (eases bulk inspection/cancel).
 */
const EC_USERS_SHOW_REMINDER_GROUP = 'ec-users-show-reminders';

/**
 * How far ahead of the event start the reminder should fire, in seconds.
 * Default: 2 days before the show.
 */
const EC_USERS_SHOW_REMINDER_LEAD = 2 * DAY_IN_SECONDS;

/**
 * Minimum lead time. If the show is closer than this, fire the reminder soon
 * (next ~hour) rather than skipping it entirely — a same-week mark still
 * deserves a heads-up. Marks for shows already started are skipped (the
 * upcoming-only timing guard handles that).
 */
const EC_USERS_SHOW_REMINDER_MIN_LEAD = HOUR_IN_SECONDS;

/**
 * Recurring hook that resolves stale (past-event) unread show reminders.
 *
 * A `show_reminder` notification is created while a show is upcoming. Once the
 * show passes it is meaningless — its title ("X is tomorrow") is no longer true.
 * If the user never read it, the unread row would otherwise linger forever in
 * the bell/count and could be picked up by the unread-notification email
 * digest. This sweep marks such rows read so a passed show's reminder becomes a
 * true no-op everywhere (bell, in-app list, count, and email).
 */
const EC_USERS_STALE_REMINDER_SWEEP_HOOK = 'ec_users_resolve_stale_show_reminders';

/**
 * Recurrence interval for the stale-reminder sweep (RecurringScheduler).
 */
const EC_USERS_STALE_REMINDER_SWEEP_INTERVAL = 'hourly';

/**
 * Fully qualified Data Machine RecurringScheduler class name.
 *
 * The sweep is scheduled through Data Machine's shared recurring-scheduling
 * primitive (Action Scheduler-backed) rather than a hand-rolled
 * wp_schedule_event(). Mirrors the digest sweep in inc/notifications/email.php.
 */
const EC_USERS_STALE_REMINDER_RECURRING_SCHEDULER = '\\DataMachine\\Engine\\Tasks\\RecurringScheduler';

// ─── Hook Wiring ───────────────────────────────────────────────────────────────

add_action( 'ec_users_event_marked', 'ec_users_on_event_marked', 10, 3 );
add_action( 'ec_users_event_unmarked', 'ec_users_on_event_unmarked', 10, 3 );
add_action( EC_USERS_SHOW_REMINDER_ACTION, 'ec_users_deliver_show_reminder', 10, 3 );
add_action( 'init', 'ec_users_stale_reminder_sweep_maybe_schedule' );
add_action(
	EC_USERS_STALE_REMINDER_SWEEP_HOOK,
	static function (): void {
		ec_users_resolve_stale_show_reminders();
	}
);

/**
 * React to a newly-marked event: schedule a reminder + check milestones.
 *
 * @param int $user_id  User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id  Blog ID the event lives on.
 */
function ec_users_on_event_marked( int $user_id, int $event_id, int $blog_id ) {
	ec_users_maybe_schedule_show_reminder( $user_id, $event_id, $blog_id );
	ec_users_maybe_notify_milestone( $user_id, $event_id );
}

/**
 * React to an unmarked event: cancel any pending reminder.
 *
 * @param int $user_id  User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id  Blog ID the event lives on.
 */
function ec_users_on_event_unmarked( int $user_id, int $event_id, int $blog_id ) {
	ec_users_cancel_show_reminder( $user_id, $event_id, $blog_id );
}

// ─── Show Reminders ──────────────────────────────────────────────────────────

/**
 * Schedule a single show-reminder action for an upcoming event.
 *
 * Only schedules for UPCOMING events — past/ongoing marks (including bulk
 * historical imports) get nothing, so the importer never floods the queue.
 * Idempotent: existing pending reminders for this user+event are cleared
 * before scheduling so a re-mark cannot double-schedule.
 *
 * Requires Action Scheduler (as_schedule_single_action). When unavailable the
 * function no-ops rather than falling back, because cancel-on-unmark relies on
 * the Action Scheduler group/args API; a partial wp-cron fallback could not be
 * reliably cancelled. Action Scheduler ships on this network (data-machine,
 * woocommerce/imagify), so this is a defensive guard, not an expected path.
 *
 * @param int $user_id  User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id  Blog ID the event lives on.
 * @return int|null Scheduled action ID, or null when not scheduled.
 */
function ec_users_maybe_schedule_show_reminder( int $user_id, int $event_id, int $blog_id ) {
	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		return null;
	}

	if ( $user_id <= 0 || $event_id <= 0 ) {
		return null;
	}

	// Upcoming-only guard. ec_users_get_event_timing() reads the events-site
	// dates table; ensure we evaluate timing in the event's own blog context.
	$timing = ec_users_reminder_event_timing( $event_id, $blog_id );
	if ( 'upcoming' !== $timing ) {
		return null;
	}

	$start_ts = ec_users_reminder_event_start_timestamp( $event_id, $blog_id );
	if ( null === $start_ts ) {
		return null;
	}

	$now      = time();
	$fire_at  = $start_ts - EC_USERS_SHOW_REMINDER_LEAD;
	$min_fire = $now + EC_USERS_SHOW_REMINDER_MIN_LEAD;

	// If the ideal 2-days-before slot is already in the past (show is < 2 days
	// out), fire soon instead of skipping. If the show start itself is already
	// behind the minimum lead, there is no useful reminder window — skip.
	if ( $fire_at < $min_fire ) {
		if ( $start_ts <= $min_fire ) {
			return null;
		}
		$fire_at = $min_fire;
	}

	// Idempotency: clear any existing pending reminder for this user+event
	// before scheduling, so a re-mark never stacks duplicates.
	ec_users_cancel_show_reminder( $user_id, $event_id, $blog_id );

	$args = array(
		'user_id'  => $user_id,
		'event_id' => $event_id,
		'blog_id'  => $blog_id,
	);

	$action_id = as_schedule_single_action(
		$fire_at,
		EC_USERS_SHOW_REMINDER_ACTION,
		$args,
		EC_USERS_SHOW_REMINDER_GROUP
	);

	return $action_id ? (int) $action_id : null;
}

/**
 * Cancel any pending show-reminder action for a user+event.
 *
 * @param int $user_id  User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id  Blog ID the event lives on.
 */
function ec_users_cancel_show_reminder( int $user_id, int $event_id, int $blog_id ) {
	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		return;
	}

	as_unschedule_all_actions(
		EC_USERS_SHOW_REMINDER_ACTION,
		array(
			'user_id'  => $user_id,
			'event_id' => $event_id,
			'blog_id'  => $blog_id,
		),
		EC_USERS_SHOW_REMINDER_GROUP
	);
}

/**
 * Deliver a scheduled show reminder via the notification substrate.
 *
 * Action Scheduler callback. Re-validates that the user still has the event
 * marked (they may have unmarked after the action was queued but before a
 * cancellation propagated) and that the event is still upcoming, then enqueues
 * an in-app "show_reminder" notification through ec_users_notify().
 *
 * @param int $user_id  User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id  Blog ID the event lives on.
 */
function ec_users_deliver_show_reminder( $user_id, $event_id, $blog_id ) {
	$user_id  = (int) $user_id;
	$event_id = (int) $event_id;
	$blog_id  = (int) $blog_id;

	if ( $user_id <= 0 || $event_id <= 0 ) {
		return;
	}

	if ( ! function_exists( 'ec_users_notify' ) ) {
		return;
	}

	// Still marked? A late cancel race or a manual unmark should suppress it.
	if ( function_exists( 'ec_users_is_event_marked' ) && ! ec_users_is_event_marked( $user_id, $event_id, $blog_id ) ) {
		return;
	}

	// Still upcoming? Don't fire a "coming up" reminder for a show that has
	// already started/passed by the time the action ran.
	if ( 'upcoming' !== ec_users_reminder_event_timing( $event_id, $blog_id ) ) {
		return;
	}

	$details = ec_users_reminder_event_details( $event_id, $blog_id );
	if ( null === $details ) {
		return;
	}

	$title = ec_users_build_reminder_title( $details );

	ec_users_notify(
		$user_id,
		array(
			'actor_id' => $user_id, // System reminder: attribute to the recipient (substrate requires a valid actor).
			'type'     => EC_USERS_SHOW_REMINDER_TYPE,
			'title'    => $title,
			'link'     => $details['permalink'],
			'item_id'  => $event_id,
		)
	);
}

/**
 * Build a human-friendly reminder title, e.g. "Phish at MSG is in 2 days".
 *
 * Uses the event post title as the show name (already encodes artist + venue
 * on this platform) and a relative countdown derived from the event start.
 *
 * @param array $details Event details from ec_users_reminder_event_details().
 * @return string
 */
function ec_users_build_reminder_title( array $details ): string {
	$name     = isset( $details['title'] ) ? (string) $details['title'] : '';
	$start_ts = isset( $details['start_ts'] ) ? (int) $details['start_ts'] : 0;

	if ( '' === $name ) {
		$name = __( 'A show you are tracking', 'extrachill-users' );
	}

	$when = '';
	if ( $start_ts > 0 ) {
		$diff = $start_ts - time();
		if ( $diff <= HOUR_IN_SECONDS ) {
			$when = __( 'is starting soon', 'extrachill-users' );
		} elseif ( $diff < DAY_IN_SECONDS ) {
			$when = __( 'is today', 'extrachill-users' );
		} elseif ( $diff < 2 * DAY_IN_SECONDS ) {
			$when = __( 'is tomorrow', 'extrachill-users' );
		} else {
			$days = (int) floor( $diff / DAY_IN_SECONDS );
			/* translators: %d: number of days until the show */
			$when = sprintf( _n( 'is in %d day', 'is in %d days', $days, 'extrachill-users' ), $days );
		}
	}

	if ( '' === $when ) {
		/* translators: %s: show name */
		return sprintf( __( '%s is coming up', 'extrachill-users' ), $name );
	}

	/* translators: 1: show name, 2: relative time phrase (e.g. "is in 2 days") */
	return sprintf( __( '%1$s %2$s', 'extrachill-users' ), $name, $when );
}

// ─── Milestones ──────────────────────────────────────────────────────────────

/**
 * Fire a milestone notification when a mark crosses a milestone threshold.
 *
 * The count is read AFTER the mark inserted (the hook fires post-insert), so
 * the user's current total IS the milestone value when one is crossed. We fire
 * only on the exact crossing — once per milestone — never on every mark.
 *
 * Milestones: 1, 10, 25, 50, 100, then every additional 100 (200, 300, ...).
 *
 * The tracked-show count is network-wide (all blogs), so the originating
 * blog_id is irrelevant here — only the recipient and the linked event matter.
 *
 * @param int $user_id  User ID.
 * @param int $event_id Event post ID (linked as item_id for context).
 */
function ec_users_maybe_notify_milestone( int $user_id, int $event_id ) {
	if ( $user_id <= 0 ) {
		return;
	}

	if ( ! function_exists( 'ec_users_notify' ) || ! function_exists( 'ec_users_get_user_event_count' ) ) {
		return;
	}

	// Total tracked shows for this user across the network (all blogs).
	$count = ec_users_get_user_event_count( $user_id );

	if ( ! ec_users_is_milestone_count( $count ) ) {
		return;
	}

	$link = ec_users_my_shows_url( $user_id );

	ec_users_notify(
		$user_id,
		array(
			'actor_id' => $user_id, // Self-milestone: attribute to the recipient.
			'type'     => 'milestone',
			'title'    => ec_users_build_milestone_title( $count ),
			'link'     => $link,
			'item_id'  => $event_id,
		)
	);
}

/**
 * Whether a tracked-show count is exactly a milestone value.
 *
 * @param int $count Total tracked shows.
 * @return bool
 */
function ec_users_is_milestone_count( int $count ): bool {
	if ( $count <= 0 ) {
		return false;
	}

	$fixed = array( 1, 10, 25, 50, 100 );
	if ( in_array( $count, $fixed, true ) ) {
		return true;
	}

	// Every additional 100 after the first hundred (200, 300, 400, ...).
	return $count > 100 && 0 === $count % 100;
}

/**
 * Build a celebratory milestone title, e.g. "Your 50th show! 🎉".
 *
 * @param int $count Milestone value.
 * @return string
 */
function ec_users_build_milestone_title( int $count ): string {
	if ( 1 === $count ) {
		return __( 'Your first tracked show! 🎉', 'extrachill-users' );
	}

	/* translators: %s: ordinal number (e.g. 10th, 50th) */
	return sprintf( __( 'Your %s show! 🎉', 'extrachill-users' ), ec_users_ordinal( $count ) );
}

/**
 * Format an integer as an English ordinal (1st, 2nd, 3rd, 10th, 50th).
 *
 * @param int $number Number.
 * @return string
 */
function ec_users_ordinal( int $number ): string {
	$abs    = abs( $number );
	$tens   = $abs % 100;
	$suffix = 'th';

	if ( $tens < 11 || $tens > 13 ) {
		switch ( $abs % 10 ) {
			case 1:
				$suffix = 'st';
				break;
			case 2:
				$suffix = 'nd';
				break;
			case 3:
				$suffix = 'rd';
				break;
		}
	}

	return $number . $suffix;
}

// ─── Stale Reminder Sweep ─────────────────────────────────────────────────────

/**
 * Ensure the recurring stale-reminder sweep is scheduled (idempotent).
 *
 * The notification substrate is one base_prefix table shared by the whole
 * network, so only ONE site needs to run the sweep. We pin it to the
 * SMTP-configured mail site (reusing the digest's owner-site resolver) so
 * exactly one scheduler owns it. Non-owner sites clear any stray schedule.
 *
 * Scheduling goes through Data Machine's RecurringScheduler when available
 * (Action Scheduler-backed, idempotent), falling back to WP-Cron otherwise so
 * the sweep keeps working in isolation. Mirrors
 * ec_notifications_email_maybe_schedule().
 */
function ec_users_stale_reminder_sweep_maybe_schedule() {
	$scheduler = EC_USERS_STALE_REMINDER_RECURRING_SCHEDULER;
	$is_owner  = function_exists( 'ec_notifications_email_is_owner_site' )
		? ec_notifications_email_is_owner_site()
		: ( function_exists( 'is_main_site' ) && is_main_site() );

	if ( ! $is_owner ) {
		// Not the owner site — make sure no stray schedule lingers here.
		if ( wp_next_scheduled( EC_USERS_STALE_REMINDER_SWEEP_HOOK ) ) {
			wp_clear_scheduled_hook( EC_USERS_STALE_REMINDER_SWEEP_HOOK );
		}
		if ( class_exists( $scheduler ) ) {
			$scheduler::unschedule( EC_USERS_STALE_REMINDER_SWEEP_HOOK, array() );
		}
		return;
	}

	if ( class_exists( $scheduler ) ) {
		// Clear any legacy WP-Cron registration so the sweep cannot double-fire
		// (once via WP-Cron and once via Action Scheduler) on the upgrade path.
		if ( wp_next_scheduled( EC_USERS_STALE_REMINDER_SWEEP_HOOK ) ) {
			wp_clear_scheduled_hook( EC_USERS_STALE_REMINDER_SWEEP_HOOK );
		}

		$scheduler::ensureSchedule(
			EC_USERS_STALE_REMINDER_SWEEP_HOOK,
			array(),
			EC_USERS_STALE_REMINDER_SWEEP_INTERVAL
		);
		return;
	}

	// Fallback: Data Machine not loaded — keep a WP-Cron schedule.
	if ( ! wp_next_scheduled( EC_USERS_STALE_REMINDER_SWEEP_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', EC_USERS_STALE_REMINDER_SWEEP_HOOK );
	}
}

/**
 * Mark every unread show reminder whose event has passed as read.
 *
 * The recurring sweep callback. Loads all unread `show_reminder` rows, resolves
 * each distinct event's timing ONCE (many users can track the same show), then
 * marks the rows for past/ongoing events read in a single bulk UPDATE per stale
 * event and flushes the affected users' unread-count caches so the bell badge
 * updates immediately. Upcoming shows are left untouched.
 *
 * Network-wide: the table is keyed by base_prefix, so this single owner-site run
 * covers reminders created from any site. Timing is resolved in the events-blog
 * context via ec_users_reminder_event_timing().
 *
 * @return int Number of notification rows marked read.
 */
function ec_users_resolve_stale_show_reminders() {
	global $wpdb;

	$table = extrachill_users_notifications_table_name();

	// All distinct events with at least one unread reminder. One timing lookup
	// per event rather than per row.
	$event_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT item_id FROM {$table} WHERE is_read = 0 AND type = %s AND item_id IS NOT NULL AND item_id > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			EC_USERS_SHOW_REMINDER_TYPE
		)
	);

	if ( empty( $event_ids ) ) {
		return 0;
	}

	$events_blog = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
	if ( $events_blog <= 0 ) {
		// Cannot resolve timing without the events blog — do nothing rather than
		// risk marking upcoming reminders read.
		return 0;
	}

	$total_read = 0;

	foreach ( $event_ids as $event_id ) {
		$event_id = (int) $event_id;
		if ( $event_id <= 0 ) {
			continue;
		}

		// Only past/ongoing events are stale; an upcoming reminder is still live.
		if ( 'upcoming' === ec_users_reminder_event_timing( $event_id, $events_blog ) ) {
			continue;
		}

		// Capture the affected users BEFORE the update so their bell caches can
		// be flushed (the substrate's mark-read helper flushes per-user, but we
		// update in bulk here for efficiency).
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$table} WHERE is_read = 0 AND type = %s AND item_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				EC_USERS_SHOW_REMINDER_TYPE,
				$event_id
			)
		);

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_read = 1 WHERE is_read = 0 AND type = %s AND item_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				EC_USERS_SHOW_REMINDER_TYPE,
				$event_id
			)
		);

		if ( $updated && function_exists( 'ec_users_flush_unread_count_cache' ) ) {
			ec_users_flush_unread_count_cache( array_map( 'intval', (array) $user_ids ) );
		}

		$total_read += (int) $updated;
	}

	return $total_read;
}

// ─── Event Lookup Helpers ──────────────────────────────────────────────────────

/**
 * Resolve event timing in the event's own blog context.
 *
 * @param int $event_id Event post ID.
 * @param int $blog_id  Blog ID the event lives on.
 * @return string 'upcoming' | 'ongoing' | 'past'
 */
function ec_users_reminder_event_timing( int $event_id, int $blog_id ): string {
	$switched     = false;
	$current_blog = get_current_blog_id();
	if ( $blog_id && $current_blog !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	try {
		// Prefer the events plugin's primitive when it is actually loaded in
		// this context. It is NOT network-activated, so on the cron/owner site
		// (main/community) the function is undefined even after switch_to_blog()
		// — switching tables/timezone does not load another site's plugins. In
		// that case fall back to a direct dates-table read (equivalent logic),
		// rather than the ec_users_get_event_timing() 'past' fallback which
		// would wrongly mark every upcoming reminder stale.
		if ( function_exists( 'datamachine_get_event_timing' ) ) {
			return datamachine_get_event_timing( $event_id );
		}

		return ec_users_reminder_event_timing_direct( $event_id );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Compute event timing directly from the event_dates table (no plugin dep).
 *
 * Mirrors datamachine_get_event_timing() exactly for use in contexts where the
 * data-machine-events plugin is not loaded (e.g. the digest/sweep crons running
 * on the mail/owner site). MUST be called with the events blog already current
 * (the caller switch_to_blog()s) so both the table prefix and current_time()
 * resolve in the event's own timezone — start_datetime is stored in that local
 * timezone and compared against the local "now".
 *
 *   upcoming = start >= now
 *   ongoing  = start < now AND end >= now
 *   past     = start < now AND (end < now OR end IS NULL)
 *
 * Returns 'upcoming' when the row is missing/unparseable so an unknown event is
 * never wrongly treated as stale (fail-safe: never suppress a live reminder).
 *
 * @param int $event_id Event post ID.
 * @return string 'upcoming' | 'ongoing' | 'past'
 */
function ec_users_reminder_event_timing_direct( int $event_id ): string {
	global $wpdb;

	$dates_table = $wpdb->prefix . 'datamachine_event_dates';

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT start_datetime, end_datetime FROM {$dates_table} WHERE post_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$event_id
		)
	);

	if ( ! $row || empty( $row->start_datetime ) || '0000-00-00 00:00:00' === $row->start_datetime ) {
		// Unknown timing — fail safe toward 'upcoming' so a live reminder is
		// never suppressed by a missing/bad dates row.
		return 'upcoming';
	}

	$now   = current_time( 'mysql' );
	$start = (string) $row->start_datetime;
	$end   = ! empty( $row->end_datetime ) ? (string) $row->end_datetime : null;

	if ( $start >= $now ) {
		return 'upcoming';
	}

	if ( $end && $end >= $now ) {
		return 'ongoing';
	}

	return 'past';
}

/**
 * Get the event start as a Unix timestamp.
 *
 * The datamachine_event_dates.start_datetime column is stored in the site's
 * local timezone (it is compared against current_time('mysql') by the events
 * timing primitive). We therefore parse it in the WP timezone and convert to a
 * UTC-based Unix timestamp so Action Scheduler fires at the right wall-clock
 * moment.
 *
 * @param int $event_id Event post ID.
 * @param int $blog_id  Blog ID the event lives on.
 * @return int|null Unix timestamp, or null when unresolvable.
 */
function ec_users_reminder_event_start_timestamp( int $event_id, int $blog_id ) {
	global $wpdb;

	$prefix      = $wpdb->get_blog_prefix( $blog_id ? $blog_id : get_current_blog_id() );
	$dates_table = $prefix . 'datamachine_event_dates';

	$start = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT start_datetime FROM {$dates_table} WHERE post_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->get_blog_prefix.
			$event_id
		)
	);

	if ( empty( $start ) || '0000-00-00 00:00:00' === $start ) {
		return null;
	}

	try {
		$tz = wp_timezone();
		$dt = new DateTimeImmutable( (string) $start, $tz );
		return $dt->getTimestamp();
	} catch ( Exception $e ) {
		return null;
	}
}

/**
 * Fetch event details needed to render a reminder.
 *
 * Switches to the event's blog so get_post()/get_permalink() resolve correctly.
 *
 * @param int $event_id Event post ID.
 * @param int $blog_id  Blog ID the event lives on.
 * @return array{ title:string, permalink:string, start_ts:int }|null
 */
function ec_users_reminder_event_details( int $event_id, int $blog_id ) {
	$start_ts = ec_users_reminder_event_start_timestamp( $event_id, $blog_id );

	$switched     = false;
	$current_blog = get_current_blog_id();
	if ( $blog_id && $current_blog !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	try {
		$post = get_post( $event_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$permalink = get_permalink( $event_id );

		return array(
			'title'     => (string) $post->post_title,
			'permalink' => $permalink ? (string) $permalink : '',
			'start_ts'  => $start_ts ? (int) $start_ts : 0,
		);
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Resolve the user's My Shows URL for milestone notifications.
 *
 * Prefers the community profile URL (where My Shows lives) and falls back to
 * the events site home when the profile linker is unavailable.
 *
 * @param int $user_id User ID.
 * @return string
 */
function ec_users_my_shows_url( int $user_id ): string {
	if ( function_exists( 'extrachill_get_user_community_profile_url' ) ) {
		$url = (string) extrachill_get_user_community_profile_url( $user_id );
		if ( '' !== $url ) {
			return $url;
		}
	}

	if ( function_exists( 'ec_get_blog_id' ) && function_exists( 'get_home_url' ) ) {
		$events_blog = ec_get_blog_id( 'events' );
		if ( $events_blog ) {
			return (string) get_home_url( $events_blog, '/' );
		}
	}

	return home_url( '/' );
}
