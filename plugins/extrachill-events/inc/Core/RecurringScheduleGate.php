<?php
/**
 * Recurring Schedule Gate
 *
 * Several Events services make sure an Action Scheduler recurring action
 * exists by checking for it on every `init`. Each check is a database query
 * on every request. This gate remembers a confirmed schedule in a per-site
 * transient (served from the object cache), so a steady-state request does
 * no Action Scheduler lookups. A schedule deleted out from under the gate is
 * recreated within one TTL.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Ensures a recurring action exists, checking Action Scheduler at most once per TTL.
 */
final class RecurringScheduleGate {

	/** How long a confirmed schedule is trusted before Action Scheduler is asked again. */
	public const TTL = HOUR_IN_SECONDS;

	/**
	 * Ensure the recurring action exists.
	 *
	 * @param string   $hook     Action hook.
	 * @param string   $group    Action Scheduler group.
	 * @param callable $schedule Creates the recurring action when it is missing and
	 *                           returns the action ID (0 on failure).
	 * @param int      $ttl      Seconds to trust a confirmation. Callers whose hook
	 *                           also runs as single actions pass their recurring
	 *                           interval, so a pending single action cannot hide a
	 *                           missing recurring one for longer than one interval.
	 */
	public static function ensure( string $hook, string $group, callable $schedule, int $ttl = self::TTL ): void {
		$key = self::transient_key( $hook, $group );
		if ( false !== get_transient( $key ) ) {
			return;
		}

		// A failed schedule is not remembered, so the next request retries.
		if ( ! self::is_scheduled( $hook, $group ) && ! call_user_func( $schedule ) ) {
			return;
		}

		set_transient( $key, 1, max( 1, $ttl ) );
	}

	/**
	 * Whether a pending or running action exists for the hook.
	 *
	 * @param string $hook  Action hook.
	 * @param string $group Action Scheduler group.
	 */
	private static function is_scheduled( string $hook, string $group ): bool {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( $hook, array(), $group );
		}

		return false !== as_next_scheduled_action( $hook, array(), $group );
	}

	/**
	 * Transient key for one hook and group.
	 *
	 * @param string $hook  Action hook.
	 * @param string $group Action Scheduler group.
	 */
	private static function transient_key( string $hook, string $group ): string {
		return 'ece_schedule_' . md5( $hook . '|' . $group );
	}
}
