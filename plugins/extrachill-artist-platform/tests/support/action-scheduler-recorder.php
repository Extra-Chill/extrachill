<?php
/**
 * Action Scheduler enqueue recorder for tests.
 *
 * Network team invitations queue delivery through Action Scheduler, which
 * this harness does not load. Queued actions are recorded in
 * `$GLOBALS['ec_artist_platform_test_queued_actions']` instead of running.
 *
 * @package ExtraChillArtistPlatform
 */

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Record an async action instead of scheduling it.
	 *
	 * @param string $hook   Hook name.
	 * @param array  $args   Action args.
	 * @param string $group  Action group.
	 * @param bool   $unique Whether the action is unique.
	 * @return int Recorded action ID.
	 */
	function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false ) {
		$GLOBALS['ec_artist_platform_test_queued_actions'][] = array(
			'hook'   => $hook,
			'args'   => $args,
			'group'  => $group,
			'unique' => $unique,
		);
		return count( $GLOBALS['ec_artist_platform_test_queued_actions'] );
	}
}
