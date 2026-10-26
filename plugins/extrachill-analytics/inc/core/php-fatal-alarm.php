<?php
/**
 * PHP Fatal-Rate Alarm
 *
 * Near-real-time detection + beacon for new or spiking PHP fatal signatures.
 *
 * Why this exists (issue #48): on 2026-06-20, v0.14.0 introduced an uncaught
 * ValueError in the ec_vid cookie mint path that 500'd the entire network's
 * front door for ~3h22m. A brand-new fatal signature went 0 -> ~3,400/hr and
 * NOTHING alerted — it was caught only because a human read debug.log hours
 * later. This module closes that gap: it reads the LIVE debug.log tail on a
 * tight (~5 min) cadence, computes per-signature fatal rates, and beacons a
 * terse alert the moment a new or spiking fatal appears.
 *
 * Architecture / layer rules:
 *   - ALL error detection lives here (this plugin owns the error-capture
 *     surface). It reuses the existing parser/normalizer and the existing
 *     persisted table — no second parser, no second table.
 *   - Delivery has exactly one contract: the canonical `agents/dispatch-message`
 *     ability. The primary dispatch channel spawns an investigating agent; an
 *     optional notify-only dispatch channel is the fallback. Both channel names
 *     and the recipient are configuration (site option -> filter), default
 *     empty. No transport name or chat channel id is hardcoded (issue #304).
 *   - An alarm is only recorded as alerted once it was actually delivered.
 *     Undelivered alarms are retried on the next tick and fail the run, so a
 *     silent delivery outage is impossible (issue #304).
 *
 * Scope: severity=fatal (and parse) ONLY for v1 — highest signal, lowest noise.
 *
 * @package ExtraChill\Analytics
 * @since 0.15.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron hook fired on the tight (~5 min) cadence to evaluate the alarm.
 */
const EXTRACHILL_ANALYTICS_FATAL_ALARM_HOOK = 'extrachill_analytics_fatal_alarm_check';

/**
 * Site-option key holding per-signature alarm state (last-alerted bookkeeping).
 */
const EXTRACHILL_ANALYTICS_FATAL_ALARM_STATE_OPTION = 'extrachill_analytics_fatal_alarm_state';

/**
 * Site-option key for the configured alarm recipient (overridable by filter).
 */
const EXTRACHILL_ANALYTICS_FATAL_ALARM_CHANNEL_OPTION = 'extrachill_analytics_fatal_alarm_channel';

/**
 * Site-option key for the Discord user id to @mention on an alarm.
 */
const EXTRACHILL_ANALYTICS_FATAL_ALARM_MENTION_OPTION = 'extrachill_analytics_fatal_alarm_mention';

/**
 * Register the alarm as a Data Machine system task.
 *
 * The alarm runs as the agent-less `fatal_rate_alarm` SystemTask
 * (FatalRateAlarmTask), scheduled through DM's RecurringScheduleRegistry on
 * the built-in `every_5_minutes` interval. DM owns scheduling, idempotent
 * reschedule, unschedule-on-disable, and per-tick Jobs-table run history —
 * so the alarm is visible to `wp datamachine system` / `wp datamachine jobs`
 * and no longer hand-rolls `cron_schedules` + `wp_schedule_event` (issue #98).
 *
 * Registration is deferred to `plugins_loaded` priority 25 (after DM bootstraps
 * at priority 20) and guarded on DM's `SystemTask` base class. This plugin has
 * no PSR-4 autoloader, so the task class — which `extends SystemTask` — can only
 * be safely included once the parent exists. When Data Machine is absent the
 * task is simply never declared or scheduled and the rest of the plugin boots
 * cleanly.
 */
function extrachill_analytics_register_fatal_alarm_task() {
	if ( ! class_exists( '\\DataMachine\\Engine\\AI\\System\\Tasks\\SystemTask' ) ) {
		return;
	}

	require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/class-fatal-rate-alarm-task.php';

	add_filter(
		'datamachine_tasks',
		function ( array $tasks ): array {
			$tasks[ \ExtraChill\Analytics\FatalRateAlarmTask::TASK_TYPE ] = \ExtraChill\Analytics\FatalRateAlarmTask::class;
			return $tasks;
		}
	);

	// Near-real-time fatal detection is the entire point (issue #48), so the
	// cadence is DM's built-in `every_5_minutes` (300s) interval. The network's
	// 5-minute system-cron heartbeat drains Action Scheduler reliably even on
	// low-traffic sites.
	//
	// The filter stays registered on EVERY site — including subsites — on
	// purpose (issue #308). DM's RecurringScheduleRegistry must be able to SEE
	// the `network_only` definition on a subsite to classify it as not owned
	// there: FlowRoutines::boot() then unschedules any existing per-site chain
	// (the idempotent cleanup for pre-0.38.5 installs where all 11 sites had
	// their own tick) and dispatch rejects stale subsite ticks. Removing the
	// filter on subsites instead would orphan those chains, because boot()
	// only unschedules schedules it can still see.
	add_filter(
		'datamachine_recurring_schedules',
		function ( array $schedules ): array {
			$schedules['fatal_rate_alarm'] = extrachill_analytics_fatal_alarm_schedule_definition();
			return $schedules;
		}
	);
}
add_action( 'plugins_loaded', 'extrachill_analytics_register_fatal_alarm_task', 25 );

/**
 * The recurring-schedule definition for the fatal-rate alarm.
 *
 * `network_only => true` is the issue #308 fix: the alarm's signal is
 * network-wide (one shared debug.log, one network-wide dedupe site option),
 * so exactly one site — the network main site — may own the 5-minute tick.
 * DM's RecurringScheduleRegistry::isOwnedByCurrentSite() enforces this: the
 * main site schedules and runs the tick, every other site unschedules any
 * existing chain on boot and rejects stale ticks. Before this flag, all 11
 * network sites evaluated the same signature each tick and the 10 sites
 * without a dispatch transport failed delivery every time a real alarm fired.
 *
 * The `task_type` / `enabled_setting` literals mirror
 * FatalRateAlarmTask::TASK_TYPE / ::SETTING_KEY; literals are used here so the
 * definition is readable (and testable) without Data Machine's SystemTask base
 * class being loadable.
 *
 * @return array<string, mixed> Schedule definition for `datamachine_recurring_schedules`.
 */
function extrachill_analytics_fatal_alarm_schedule_definition() {
	return array(
		'task_type'       => 'fatal_rate_alarm',
		'interval'        => 'every_5_minutes',
		'enabled_setting' => 'fatal_rate_alarm_enabled',
		'default_enabled' => true,
		'label'           => 'Every 5 minutes — near-real-time PHP fatal-rate detection',
		'task_params'     => array( 'source' => 'recurring_schedule' ),
		'network_only'    => true,
	);
}

/**
 * Whether the current site owns the fatal-rate alarm (issue #308).
 *
 * Mirrors DM's RecurringScheduleRegistry::isOwnedByCurrentSite() for a
 * `network_only` schedule: on multisite only the network main site owns the
 * alarm; on single-site installs the (only) site always does. Used by
 * FatalRateAlarmTask as a belt-and-braces execution guard so a stale subsite
 * tick (scheduled before 0.38.5, firing before DM's boot cleanup runs) records
 * a skip instead of evaluating the network-wide signal and failing delivery.
 *
 * @return bool True when this site may evaluate and deliver the alarm.
 */
function extrachill_analytics_fatal_alarm_owned_by_current_site() {
	if ( ! is_multisite() ) {
		return true;
	}

	return is_main_site();
}

/**
 * Retire the legacy hand-rolled WP-cron event from pre-0.24.0 installs.
 *
 * Before the SystemTask migration (issue #98) the alarm self-scheduled a
 * recurring `extrachill_analytics_fatal_alarm_check` WP-cron event. That event
 * is now owned by DM's RecurringScheduleRegistry under a different hook, so the
 * stale WP-cron event must be cleared or the evaluator would fire twice each
 * tick. Idempotent: a no-op once the legacy event is gone.
 */
function extrachill_analytics_clear_legacy_fatal_alarm_cron() {
	if ( wp_next_scheduled( EXTRACHILL_ANALYTICS_FATAL_ALARM_HOOK ) ) {
		wp_clear_scheduled_hook( EXTRACHILL_ANALYTICS_FATAL_ALARM_HOOK );
	}
}
add_action( 'init', 'extrachill_analytics_clear_legacy_fatal_alarm_cron', 99 );

/**
 * Marker source stamped on this plugin's dispatch so the scoped permission
 * grant below only ever authorizes OUR own fatal-alarm dispatch — never a
 * blanket grant on agents/dispatch-message.
 */
const EXTRACHILL_ANALYTICS_FATAL_ALARM_DISPATCH_SOURCE = 'extrachill-analytics-fatal-alarm';

/**
 * Grant agents/dispatch-message permission for the alarm's own dispatch only.
 *
 * The alarm runs in cron context (no logged-in user), but the dispatch ability
 * gates on manage_options. Rather than weaken the ability globally, we narrowly
 * authorize ONLY dispatches this plugin originated (identified by our metadata
 * source marker) AND only in a trusted server context (cron or WP-CLI). All
 * other callers are unaffected and still hit the default gate.
 *
 * @param bool  $allowed Current decision from the ability's default gate.
 * @param array $input   Canonical dispatch-message input.
 * @return bool Whether to allow the dispatch.
 */
function extrachill_analytics_grant_fatal_alarm_dispatch_permission( $allowed, $input ) {
	if ( $allowed ) {
		return true;
	}

	$source = '';
	if ( isset( $input['metadata']['source'] ) ) {
		$source = (string) $input['metadata']['source'];
	}

	if ( EXTRACHILL_ANALYTICS_FATAL_ALARM_DISPATCH_SOURCE !== $source ) {
		return $allowed;
	}

	$trusted_context = wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );

	return $trusted_context ? true : $allowed;
}
add_filter( 'agents_dispatch_message_permission', 'extrachill_analytics_grant_fatal_alarm_dispatch_permission', 10, 2 );

/**
 * The fatal-rate threshold (fatals per hour) above which a signature alarms.
 *
 * A 0->nonzero NEW fatal always alarms regardless of this threshold; the
 * threshold governs re-alerting on a *known* signature that escalates.
 *
 * @return int Threshold in fatals/hour. Default 50.
 */
function extrachill_analytics_fatal_alarm_threshold() {
	/**
	 * Filter the fatal-rate alarm threshold (fatals per hour).
	 *
	 * @param int $threshold Default 50 fatals/hour.
	 */
	return (int) apply_filters( 'extrachill_analytics_fatal_alarm_threshold', 50 );
}

/**
 * Resolve the recipient the alarm is delivered to.
 *
 * The recipient is opaque to this plugin: it is handed to the dispatch channel
 * as `recipient` (for a chat bridge, the chat channel that hosts the alarm
 * thread). Resolution: site option -> filter. There is no default; an
 * unconfigured install reports `unconfigured` instead of guessing (issue #304).
 *
 * @return string Recipient identifier, or '' when unconfigured.
 */
function extrachill_analytics_fatal_alarm_channel() {
	$channel = (string) get_site_option( EXTRACHILL_ANALYTICS_FATAL_ALARM_CHANNEL_OPTION, '' );

	/**
	 * Filter the recipient the fatal-rate alarm is delivered to.
	 *
	 * @param string $channel Recipient identifier, or '' when unconfigured.
	 */
	return (string) apply_filters( 'extrachill_analytics_fatal_alarm_channel', $channel );
}

/**
 * Resolve the Discord user id to @mention when an alarm fires.
 *
 * The mention is injected into the dispatched prompt/message body so a human is
 * actually paged (a channel post with no mention notifies nobody). Resolution:
 * site option -> filter -> '' (no mention). No user id is hardcoded in source;
 * the default is empty so an unconfigured install simply doesn't mention anyone.
 *
 * @return string Discord user id, or '' for no mention.
 */
function extrachill_analytics_fatal_alarm_mention() {
	$mention = (string) get_site_option( EXTRACHILL_ANALYTICS_FATAL_ALARM_MENTION_OPTION, '' );

	/**
	 * Filter the Discord user id the fatal-rate alarm @mentions.
	 *
	 * @param string $mention Discord user id, or '' for no mention.
	 */
	return (string) apply_filters( 'extrachill_analytics_fatal_alarm_mention', $mention );
}

/**
 * Resolve the dispatch channel id used to spawn a coding-agent session.
 *
 * This is the *transport channel* name registered with the canonical
 * `agents/dispatch-message` ability (a CLI runtime that opens an agent thread),
 * NOT a Discord channel id. The value is config-resolved so no transport/vendor
 * name is hardcoded in this layer (layer purity): option -> filter -> default.
 *
 * @return string Dispatch channel id, or '' to disable agent dispatch.
 */
function extrachill_analytics_fatal_alarm_dispatch_channel() {
	$channel = (string) get_site_option( 'extrachill_analytics_fatal_alarm_dispatch_channel', '' );

	/**
	 * Filter the agents/dispatch-message channel the alarm spawns a session on.
	 *
	 * Set to '' to disable agent dispatch; the notify-only channel (if any)
	 * then carries the alarm.
	 *
	 * @param string $channel Dispatch transport channel id.
	 */
	return (string) apply_filters( 'extrachill_analytics_fatal_alarm_dispatch_channel', $channel );
}

/**
 * Resolve the notify-only dispatch channel used as the delivery fallback.
 *
 * Also an `agents/dispatch-message` channel name, but one whose transport only
 * posts the message (no agent session). It carries the alarm when the primary
 * agent dispatch is unconfigured or fails, so a human is still paged.
 * Resolution: site option -> filter -> '' (no fallback).
 *
 * @return string Notify-only dispatch channel id, or '' when unconfigured.
 */
function extrachill_analytics_fatal_alarm_notify_channel() {
	$channel = (string) get_site_option( 'extrachill_analytics_fatal_alarm_notify_channel', '' );

	/**
	 * Filter the notify-only agents/dispatch-message channel for the alarm.
	 *
	 * @param string $channel Notify-only dispatch channel id, or ''.
	 */
	return (string) apply_filters( 'extrachill_analytics_fatal_alarm_notify_channel', $channel );
}

/**
 * Whether the alarm has a recipient and at least one delivery channel.
 *
 * @return bool True when an alarm could be delivered.
 */
function extrachill_analytics_fatal_alarm_delivery_configured() {
	return '' !== extrachill_analytics_fatal_alarm_channel()
		&& ( '' !== extrachill_analytics_fatal_alarm_dispatch_channel() || '' !== extrachill_analytics_fatal_alarm_notify_channel() );
}

/**
 * The lookback window (seconds) used to compute the current fatal rate.
 *
 * @return int Window in seconds. Default 1 hour.
 */
function extrachill_analytics_fatal_alarm_window_seconds() {
	/**
	 * Filter the rate-evaluation window for the fatal alarm.
	 *
	 * @param int $seconds Default HOUR_IN_SECONDS.
	 */
	return (int) apply_filters( 'extrachill_analytics_fatal_alarm_window', HOUR_IN_SECONDS );
}

/**
 * The recency horizon (seconds) within which a signature's NEWEST occurrence
 * must fall for it to be considered "still firing".
 *
 * This is the #48 fix. A fatal can be inside the (1-hour) rate window yet have
 * stopped firing minutes ago (e.g. the cookie fatal resolved at 03:56 still
 * shows in a 04:50 one-hour window). Only signatures whose last_seen is within
 * this horizon are live; anything older is RESOLVED and never alarms — so a
 * just-fixed signature does not re-alarm off persisted/window residue.
 *
 * Default 2× the 5-minute cron cadence (10 min) so one quiet cron cycle is
 * tolerated but a genuinely stopped fatal goes silent fast.
 *
 * @return int Recency horizon in seconds. Default 10 minutes.
 */
function extrachill_analytics_fatal_alarm_recency_seconds() {
	/**
	 * Filter the recency horizon that defines "still firing" for the alarm.
	 *
	 * @param int $seconds Default 10 minutes (2 * the 5-min cadence).
	 */
	return (int) apply_filters( 'extrachill_analytics_fatal_alarm_recency', 10 * MINUTE_IN_SECONDS );
}

/**
 * Severities that are eligible to alarm. v1: fatal-class only.
 *
 * @return string[] Lowercased severities.
 */
function extrachill_analytics_fatal_alarm_severities() {
	/**
	 * Filter which severities the alarm evaluates. Default fatal + parse.
	 *
	 * @param string[] $severities Lowercased canonical severities.
	 */
	return (array) apply_filters( 'extrachill_analytics_fatal_alarm_severities', array( 'fatal', 'parse' ) );
}

/**
 * Evaluate the fatal-rate alarm against the LIVE debug.log tail.
 *
 * Computes, per fatal-class signature, the number of fatals whose last_seen is
 * within the evaluation window. A signature alarms when it is NEW (never alerted
 * and freshly firing) OR when its in-window rate crosses the threshold. Already
 * alerted signatures are deduped until they go quiet and return, or escalate
 * past their last-alerted rate. Signatures whose newest occurrence is older than
 * the window are treated as RESOLVED and never alarm (this is the #48 fix: a
 * stopped fatal must not re-alarm off persisted residue).
 *
 * Delivery outcome (`beacon.outcome`, issue #304):
 *   - `none`            no alarms to deliver.
 *   - `delivered`       every alarm was delivered by some channel.
 *   - `partial`         some alarms delivered, others not.
 *   - `delivery_failed` alarms existed, delivery is configured, none delivered.
 *   - `unconfigured`    alarms existed but no recipient/channel is configured.
 * Only delivered alarms are written to dedupe state; the rest re-alarm on the
 * next tick.
 *
 * @param bool        $dry_run       When true, compute and return what WOULD
 *                                   alarm without delivering or mutating
 *                                   dedupe state.
 * @param bool        $test_delivery When true, skip evaluation and deliver one
 *                                   clearly labelled synthetic alarm through
 *                                   the configured channels. Overrides dry_run;
 *                                   never writes dedupe state.
 * @param object|null $dispatcher    Object with `execute( array $input )`
 *                                   compatible with `agents/dispatch-message`.
 *                                   Defaults to the registered ability.
 * @return array{
 *     evaluated_at:int,
 *     window_seconds:int,
 *     threshold:int,
 *     candidates:array<int, array<string, mixed>>,
 *     alarms:array<int, array<string, mixed>>,
 *     dry_run:bool,
 *     test_delivery:bool,
 *     beacon:array<string, mixed>
 * }
 */
function extrachill_analytics_evaluate_fatal_alarm( $dry_run = false, $test_delivery = false, $dispatcher = null ) {
	if ( null === $dispatcher && function_exists( 'wp_get_ability' ) ) {
		$dispatcher = wp_get_ability( 'agents/dispatch-message' );
	}

	$now            = time();
	$window         = extrachill_analytics_fatal_alarm_window_seconds();
	$since_ts       = $now - $window;
	$recency        = extrachill_analytics_fatal_alarm_recency_seconds();
	$fresh_after_ts = $now - $recency;
	$threshold      = extrachill_analytics_fatal_alarm_threshold();
	$alarm_sevs     = array_map( 'strtolower', extrachill_analytics_fatal_alarm_severities() );
	$log_path       = extrachill_analytics_php_error_log_path();

	$result = array(
		'evaluated_at'   => $now,
		'window_seconds' => $window,
		'threshold'      => $threshold,
		'candidates'     => array(),
		'alarms'         => array(),
		'dry_run'        => (bool) $dry_run && ! $test_delivery,
		'test_delivery'  => (bool) $test_delivery,
		'beacon'         => array(
			'attempted'    => false,
			'sent'         => 0,
			'failed'       => 0,
			'channel'      => extrachill_analytics_fatal_alarm_channel(),
			'configured'   => extrachill_analytics_fatal_alarm_delivery_configured(),
			'can_dispatch' => null !== $dispatcher,
			'outcome'      => 'none',
			'deliveries'   => array(),
		),
	);

	if ( $test_delivery ) {
		$result['alarms'] = array( extrachill_analytics_fatal_alarm_synthetic_test_alarm( $now ) );
		$result['beacon'] = extrachill_analytics_deliver_fatal_alarms( $result['alarms'], $result['beacon'], $dispatcher );
		return $result;
	}

	// Read the live tail only — the alarm is about what is firing NOW, not the
	// persisted blend (which retains resolved history and would re-alarm #48).
	// Cap the read so an enormous unrotated log can't blow memory.
	$parsed = extrachill_analytics_parse_php_error_log( $log_path, $since_ts, 32 * MB_IN_BYTES );

	// Aggregate in-window fatal-class entries per signature.
	$agg = array();
	foreach ( $parsed['entries'] as $entry ) {
		if ( ! in_array( $entry['severity'], $alarm_sevs, true ) ) {
			continue;
		}
		if ( null === $entry['ts'] || $entry['ts'] < $since_ts ) {
			continue;
		}
		// Ignore fatals raised outside the live install — a coding-agent
		// worktree copy under /var/lib/datamachine/workspace, a `wp eval`
		// phar, /tmp scratch, etc. Those are dev/agent artifacts (e.g. a
		// "Cannot redeclare" thrown when a worktree re-includes an
		// already-loaded production plugin), not live-site faults, and must
		// never page the alarm. The classifier fails open, so an
		// unresolvable origin still alarms. The alarm only ever reads freshly
		// parsed live-tail entries, so from_production is always present.
		if ( ! $entry['from_production'] ) {
			continue;
		}

		$sig = $entry['signature'];
		if ( ! isset( $agg[ $sig ] ) ) {
			$agg[ $sig ] = array(
				'signature' => $sig,
				'severity'  => $entry['severity'],
				'file'      => $entry['file'],
				'sample'    => $entry['sample'],
				'count'     => 0,
				'first_ts'  => $entry['ts'],
				'last_ts'   => $entry['ts'],
			);
		}

		++$agg[ $sig ]['count'];
		$agg[ $sig ]['first_ts'] = min( $agg[ $sig ]['first_ts'], $entry['ts'] );
		$agg[ $sig ]['last_ts']  = max( $agg[ $sig ]['last_ts'], $entry['ts'] );
	}

	$state = get_site_option( EXTRACHILL_ANALYTICS_FATAL_ALARM_STATE_OPTION, array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}

	$alarms     = array();
	$candidates = array();

	foreach ( $agg as $sig => $data ) {
		// Extrapolate an hourly rate from the in-window count. Using the window
		// length normalizes "12 in 5 min" to a per-hour rate so a short cadence
		// doesn't understate a spike.
		$span    = max( 1, $data['last_ts'] - $data['first_ts'] );
		$rate_hr = (int) round( $data['count'] * ( HOUR_IN_SECONDS / max( $span, 60 ) ) );

		// RESOLVED guard (#48 fix): a signature whose newest occurrence predates
		// the recency horizon has stopped firing. It may still sit inside the
		// 1-hour rate window (and in the persisted blend forever), but it is NOT
		// live and must never alarm. This is what keeps a just-fixed fatal from
		// re-alarming off residue.
		$is_live = $data['last_ts'] >= $fresh_after_ts;

		$prior      = isset( $state[ $sig ] ) && is_array( $state[ $sig ] ) ? $state[ $sig ] : null;
		$is_new     = null === $prior;
		$last_alert = $prior['last_alerted_at'] ?? 0;
		$last_rate  = $prior['last_alerted_rate'] ?? 0;

		// Re-arm: if the signature went quiet (last alert older than 2 windows
		// and the prior last_seen predates this window's data resuming), treat a
		// fresh occurrence as new again.
		$quiet_for   = $now - (int) $last_alert;
		$rearmed     = ! $is_new && $quiet_for > ( 2 * $window );
		$escalated   = ! $is_new && $rate_hr >= max( 1, $threshold ) && $rate_hr > ( (int) $last_rate * 2 );
		$over_thresh = $rate_hr >= max( 1, $threshold );

		$reasons = array();
		if ( $is_new ) {
			// Any brand-new fatal signature firing in-window alarms immediately
			// (0->nonzero), regardless of threshold — this is the #48 trigger.
			$reasons[] = 'new_signature';
		}
		if ( $rearmed ) {
			$reasons[] = 'returned_after_quiet';
		}
		if ( $over_thresh && ( $is_new || $rearmed || $escalated ) ) {
			$reasons[] = 'over_threshold';
		}
		if ( $escalated ) {
			$reasons[] = 'escalated';
		}

		// Only a LIVE signature can alarm; resolved residue is reported as a
		// candidate (would_alarm=false) but never beacons.
		$should_alarm = $is_live && ( $is_new || $rearmed || $escalated );

		if ( ! $is_live ) {
			$reasons = array( 'resolved_not_firing' );
		}

		$row = array(
			'signature'   => $data['signature'],
			'severity'    => $data['severity'],
			'file'        => $data['file'],
			'sample'      => $data['sample'],
			'count'       => $data['count'],
			'rate_per_hr' => $rate_hr,
			'first_seen'  => gmdate( 'Y-m-d H:i:s', $data['first_ts'] ),
			'last_seen'   => gmdate( 'Y-m-d H:i:s', $data['last_ts'] ),
			'is_new'      => $is_new,
			'is_live'     => $is_live,
			'reasons'     => $reasons,
			'would_alarm' => $should_alarm,
		);

		$candidates[] = $row;

		if ( $should_alarm ) {
			$alarms[] = $row;
		}
	}

	$result['candidates'] = $candidates;
	$result['alarms']     = $alarms;

	if ( $dry_run || empty( $alarms ) ) {
		return $result;
	}

	$result['beacon'] = extrachill_analytics_deliver_fatal_alarms( $alarms, $result['beacon'], $dispatcher );

	// Persist dedupe state ONLY for alarms that were actually delivered. An
	// undelivered alarm keeps no state, so the next tick alarms on it again
	// (issue #304: state used to be written regardless, silencing failures).
	$state_changed = false;
	foreach ( $result['beacon']['deliveries'] as $index => $delivery ) {
		if ( empty( $delivery['delivered'] ) ) {
			continue;
		}
		$alarm                        = $alarms[ $index ];
		$state[ $alarm['signature'] ] = array(
			'last_alerted_at'   => $now,
			'last_alerted_rate' => $alarm['rate_per_hr'],
			'last_seen'         => $alarm['last_seen'],
		);
		$state_changed                = true;
	}

	// Prune state entries that are stale (not seen in 7 days) to keep the option
	// from growing unbounded across the network's whole signature history.
	$cutoff = $now - ( 7 * DAY_IN_SECONDS );
	foreach ( $state as $sig => $entry ) {
		$ts = isset( $entry['last_alerted_at'] ) ? (int) $entry['last_alerted_at'] : 0;
		if ( $ts > 0 && $ts < $cutoff ) {
			unset( $state[ $sig ] );
			$state_changed = true;
		}
	}

	if ( $state_changed ) {
		update_site_option( EXTRACHILL_ANALYTICS_FATAL_ALARM_STATE_OPTION, $state );
	}

	return $result;
}

/**
 * Map a delivery outcome to a run failure reason.
 *
 * A tick fails when alarms existed but were not all delivered, so a delivery
 * outage surfaces in job history instead of completing silently (issue #304).
 *
 * @param string $outcome Beacon outcome from the evaluator.
 * @return string|null Failure reason, or null when the tick succeeded.
 */
function extrachill_analytics_fatal_alarm_failure_reason( $outcome ) {
	if ( in_array( $outcome, array( 'unconfigured', 'delivery_failed', 'partial' ), true ) ) {
		return $outcome;
	}
	return null;
}

/**
 * Deliver a list of alarms and fold per-alarm outcomes into the beacon result.
 *
 * Each entry in `beacon.deliveries` is keyed by the alarm's index and records
 * which channel delivered it. `beacon.outcome` summarizes the batch (see
 * extrachill_analytics_evaluate_fatal_alarm()).
 *
 * @param array       $alarms     Alarm rows to deliver.
 * @param array       $beacon     Beacon result to update.
 * @param object|null $dispatcher `agents/dispatch-message` compatible object.
 * @return array Updated beacon result.
 */
function extrachill_analytics_deliver_fatal_alarms( $alarms, $beacon, $dispatcher ) {
	if ( empty( $alarms ) ) {
		return $beacon;
	}

	$beacon['attempted'] = true;

	if ( ! $beacon['configured'] || null === $dispatcher ) {
		$beacon['failed']  = count( $alarms );
		$beacon['outcome'] = $beacon['configured'] ? 'delivery_failed' : 'unconfigured';
		$error             = $beacon['configured']
			? array(
				'code'    => 'dispatcher_unavailable',
				'message' => 'The agents/dispatch-message ability is not available on this site.',
			)
			: array(
				'code'    => 'delivery_unconfigured',
				'message' => 'No alarm recipient or delivery channel is configured.',
			);
		foreach ( $alarms as $index => $alarm ) {
			$beacon['deliveries'][ $index ] = array(
				'signature'      => $alarm['signature'],
				'dispatch'       => false,
				'notify'         => false,
				'delivered'      => false,
				'dispatch_error' => $error,
				'notify_error'   => $error,
			);
		}
		return $beacon;
	}

	foreach ( $alarms as $index => $alarm ) {
		$delivery                       = extrachill_analytics_send_fatal_alarm_beacon( $alarm, $beacon['channel'], $dispatcher );
		$beacon['deliveries'][ $index ] = $delivery;
		if ( $delivery['delivered'] ) {
			++$beacon['sent'];
		} else {
			++$beacon['failed'];
		}
	}

	if ( 0 === $beacon['failed'] ) {
		$beacon['outcome'] = 'delivered';
	} elseif ( 0 === $beacon['sent'] ) {
		$beacon['outcome'] = 'delivery_failed';
	} else {
		$beacon['outcome'] = 'partial';
	}

	return $beacon;
}

/**
 * Deliver a single fatal-rate alarm.
 *
 * Primary path: the configured agent dispatch channel spawns a coding-agent
 * session that investigates and fixes the fatal, with the configured human
 * @mentioned. Fallback path: the configured notify-only dispatch channel posts
 * a plain alarm message so a human is still paged. Both go through the single
 * `agents/dispatch-message` contract; channel names are configuration.
 *
 * When a path fails, the per-delivery record carries the failure detail
 * (`dispatch_error` / `notify_error`, each `{code, message}`), so a
 * `delivery_failed` job explains itself instead of recording only
 * `dispatch:false, notify:false` (issue #308).
 *
 * @param array  $alarm      One alarm row from the evaluator.
 * @param string $recipient  Recipient handed to the dispatch channel.
 * @param object $dispatcher `agents/dispatch-message` compatible object.
 * @return array Per-path outcome: signature, dispatch, notify, delivered,
 *               plus dispatch_error / notify_error on the failed paths.
 */
function extrachill_analytics_send_fatal_alarm_beacon( $alarm, $recipient, $dispatcher ) {
	$dispatch = extrachill_analytics_dispatch_fatal_alarm_message(
		$dispatcher,
		extrachill_analytics_fatal_alarm_dispatch_channel(),
		$recipient,
		extrachill_analytics_fatal_alarm_prompt( $alarm, false ),
		$alarm
	);

	$delivery = array(
		'signature' => $alarm['signature'],
		'dispatch'  => $dispatch['sent'],
		'notify'    => false,
		'delivered' => $dispatch['sent'],
	);

	if ( $dispatch['sent'] ) {
		return $delivery;
	}

	$delivery['dispatch_error'] = $dispatch['error'];

	$notify = extrachill_analytics_dispatch_fatal_alarm_message(
		$dispatcher,
		extrachill_analytics_fatal_alarm_notify_channel(),
		$recipient,
		extrachill_analytics_fatal_alarm_prompt( $alarm, true ),
		$alarm
	);

	$delivery['notify']    = $notify['sent'];
	$delivery['delivered'] = $notify['sent'];
	if ( ! $notify['sent'] ) {
		$delivery['notify_error'] = $notify['error'];
	}

	return $delivery;
}

/**
 * Send one alarm message through an `agents/dispatch-message` channel.
 *
 * Never swallows the failure cause (issue #308): every unsent outcome carries
 * a `{code, message}` error — the WP_Error from the dispatch ability, a
 * `not_sent` reason when the response lacked `sent`, or why the path was
 * never attempted (unconfigured channel/recipient, unusable dispatcher).
 *
 * @param object $dispatcher       `agents/dispatch-message` compatible object.
 * @param string $dispatch_channel Dispatch channel name ('' = skip).
 * @param string $recipient        Recipient handed to the channel.
 * @param string $message          Message body.
 * @param array  $alarm            Alarm row (for metadata).
 * @return array{sent:bool, error:?array{code:string, message:string}} Path outcome.
 */
function extrachill_analytics_dispatch_fatal_alarm_message( $dispatcher, $dispatch_channel, $recipient, $message, $alarm ) {
	if ( '' === $dispatch_channel ) {
		return array(
			'sent'  => false,
			'error' => array(
				'code'    => 'channel_unconfigured',
				'message' => 'No dispatch channel is configured for this path.',
			),
		);
	}

	if ( '' === $recipient ) {
		return array(
			'sent'  => false,
			'error' => array(
				'code'    => 'recipient_unconfigured',
				'message' => 'No alarm recipient is configured.',
			),
		);
	}

	if ( ! method_exists( $dispatcher, 'execute' ) ) {
		return array(
			'sent'  => false,
			'error' => array(
				'code'    => 'dispatcher_unavailable',
				'message' => 'The dispatcher does not expose an execute() method.',
			),
		);
	}

	$response = $dispatcher->execute(
		array(
			'channel'   => $dispatch_channel,
			'recipient' => $recipient,
			'message'   => $message,
			'metadata'  => array(
				'source'    => EXTRACHILL_ANALYTICS_FATAL_ALARM_DISPATCH_SOURCE,
				'signature' => $alarm['signature'],
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'sent'  => false,
			'error' => array(
				'code'    => (string) $response->get_error_code(),
				'message' => $response->get_error_message(),
			),
		);
	}

	if ( is_array( $response ) && ! empty( $response['sent'] ) ) {
		return array(
			'sent'  => true,
			'error' => null,
		);
	}

	$detail = '';
	if ( is_array( $response ) && isset( $response['error'] ) && is_scalar( $response['error'] ) ) {
		$detail = ' Channel error: ' . (string) $response['error'];
	}

	return array(
		'sent'  => false,
		'error' => array(
			'code'    => 'not_sent',
			'message' => 'The dispatch channel response did not report the message as sent.' . $detail,
		),
	);
}

/**
 * Choose the message body for an alarm and delivery path.
 *
 * @param array $alarm       Alarm row.
 * @param bool  $notify_only True for the notify-only fallback (plain alert),
 *                           false for the agent dispatch (fix prompt).
 * @return string Message body.
 */
function extrachill_analytics_fatal_alarm_prompt( $alarm, $notify_only ) {
	if ( ! empty( $alarm['test_delivery'] ) ) {
		return extrachill_analytics_format_fatal_alarm_test_message();
	}

	return $notify_only
		? extrachill_analytics_format_fatal_alarm_message( $alarm, true )
		: extrachill_analytics_format_fatal_alarm_fix_prompt( $alarm );
}

/**
 * Build the synthetic alarm row used by `test_delivery`.
 *
 * @param int $now Current timestamp.
 * @return array Alarm row flagged with `test_delivery`.
 */
function extrachill_analytics_fatal_alarm_synthetic_test_alarm( $now ) {
	$stamp = gmdate( 'Y-m-d H:i:s', $now );

	return array(
		'signature'     => 'test-delivery',
		'severity'      => 'fatal',
		'file'          => 'test_delivery',
		'sample'        => 'Synthetic test alarm. No PHP fatal occurred.',
		'count'         => 0,
		'rate_per_hr'   => 0,
		'first_seen'    => $stamp,
		'last_seen'     => $stamp,
		'is_new'        => false,
		'is_live'       => false,
		'reasons'       => array( 'test_delivery' ),
		'would_alarm'   => false,
		'test_delivery' => true,
	);
}

/**
 * Message body for a test delivery. Tells a spawned agent not to act.
 *
 * @return string Message body.
 */
function extrachill_analytics_format_fatal_alarm_test_message() {
	return extrachill_analytics_fatal_alarm_mention_prefix()
		. '🧪 **PHP fatal alarm delivery test.** No real fatal occurred; this verifies the alarm reaches this channel. '
		. 'If you are an agent, reply with one line confirming receipt and stop. Do not run tools.';
}

/**
 * Build the Discord mention prefix for the configured human, or '' if none.
 *
 * @return string e.g. "<@123456789012345678> " or ''.
 */
function extrachill_analytics_fatal_alarm_mention_prefix() {
	$mention = extrachill_analytics_fatal_alarm_mention();
	if ( '' === $mention ) {
		return '';
	}

	// Accept a bare id or an already-formatted mention; normalize to <@id>.
	if ( ctype_digit( $mention ) ) {
		return '<@' . $mention . '> ';
	}

	return $mention . ' ';
}

/**
 * Map alarm reasons to human labels.
 *
 * @param array $alarm One alarm row from the evaluator.
 * @return string Comma-joined reason labels.
 */
function extrachill_analytics_fatal_alarm_reason_text( $alarm ) {
	$reason_labels = array(
		'new_signature'        => 'NEW fatal signature',
		'returned_after_quiet' => 'returned after going quiet',
		'over_threshold'       => 'over rate threshold',
		'escalated'            => 'escalating',
	);

	$reasons = array();
	foreach ( (array) $alarm['reasons'] as $reason ) {
		$reasons[] = $reason_labels[ $reason ] ?? $reason;
	}

	return $reasons ? implode( ', ', $reasons ) : 'fatal detected';
}

/**
 * Format the terse, actionable Discord notification body for a fatal alarm.
 *
 * Used on the FALLBACK path (plain post). When $with_mention is true the
 * configured human is @mentioned so the post actually pages someone.
 *
 * @param array $alarm        One alarm row from the evaluator.
 * @param bool  $with_mention Prefix the configured human mention.
 * @return string Message content.
 */
function extrachill_analytics_format_fatal_alarm_message( $alarm, $with_mention = false ) {
	$prefix = $with_mention ? extrachill_analytics_fatal_alarm_mention_prefix() : '';
	$header = $prefix . '🚨 **PHP fatal alarm** — ' . extrachill_analytics_fatal_alarm_reason_text( $alarm );

	$lines = array(
		$header,
		'',
		'**Severity:** ' . strtoupper( (string) $alarm['severity'] ),
		'**Where:** `' . $alarm['file'] . '`',
		'**Signature:** `' . $alarm['signature'] . '`',
		'**In window:** ' . (int) $alarm['count'] . ' (~' . (int) $alarm['rate_per_hr'] . '/hr)',
		'**First seen:** ' . $alarm['first_seen'] . ' UTC',
		'**Last seen:** ' . $alarm['last_seen'] . ' UTC',
		'',
		'```' . substr( (string) $alarm['sample'], 0, 300 ) . '```',
		'Triage: `wp extrachill analytics errors --since=1h --severity=fatal`',
	);

	$message = implode( "\n", $lines );

	// Discord hard-caps a message at 2000 chars.
	if ( strlen( $message ) > 1900 ) {
		$message = substr( $message, 0, 1897 ) . '...';
	}

	return $message;
}

/**
 * Build the agent-dispatch prompt instructing the bot to fix the fatal.
 *
 * This is the spawned coding-agent session's entire context, so it must be
 * self-contained: it @mentions the configured human, states the incident, and
 * guides root-cause investigation, issue filing, and Homeboy cooking through
 * a fix PR. Merging, releasing, and deploying remain operator-owned.
 *
 * @param array $alarm One alarm row from the evaluator.
 * @return string Prompt body.
 */
function extrachill_analytics_format_fatal_alarm_fix_prompt( $alarm ) {
	$mention = extrachill_analytics_fatal_alarm_mention_prefix();

	$lines = array(
		$mention . '🚨 **PHP fatal alarm — investigate and fix.**',
		'',
		'A PHP fatal is firing on the live network RIGHT NOW (' . extrachill_analytics_fatal_alarm_reason_text( $alarm ) . ').',
		'',
		'**Goal:** Investigate the root cause and get a fix up as a PR.',
		'',
		'**Fatal details (from the live debug.log tail):**',
		'- Severity: ' . strtoupper( (string) $alarm['severity'] ),
		'- Location: `' . $alarm['file'] . '`',
		'- Signature: `' . $alarm['signature'] . '`',
		'- Rate: ' . (int) $alarm['count'] . ' in the last window (~' . (int) $alarm['rate_per_hr'] . '/hr)',
		'- First seen: ' . $alarm['first_seen'] . ' UTC · Last seen: ' . $alarm['last_seen'] . ' UTC',
		'- Sample: `' . substr( (string) $alarm['sample'], 0, 240 ) . '`',
		'',
		'**Workflow:** Confirm with `wp extrachill analytics errors --since=1h --severity=fatal`; `grep` the location in the read-only plugin/theme tree to find the owning repo. File a GitHub issue there with the fatal evidence pasted in, then cook it with `homeboy agent-task cook` (one cook per issue). Report the issue URL and PR URL back. The run ends at the PR; merging, releasing, and deploying are operator-owned.',
		'If it has stopped firing but evidence shows a real defect, still file the issue and cook it, then report back. Stop without an issue only when nothing points at a real defect. Base any fix on evidence.',
	);

	$prompt = implode( "\n", $lines );

	// Keep the spawned prompt well under transport limits.
	if ( strlen( $prompt ) > 1800 ) {
		$prompt = substr( $prompt, 0, 1797 ) . '...';
	}

	return $prompt;
}
