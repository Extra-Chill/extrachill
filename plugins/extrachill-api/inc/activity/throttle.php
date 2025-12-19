<?php
/**
 * Activity throttling utilities.
 *
 * Prevents feed clutter by deduplicating repeated activity events
 * within configurable time windows using transient-based caching.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_ACTIVITY_THROTTLE_RULES', array(
	'post_updated' => HOUR_IN_SECONDS,
) );

/**
 * Check if an activity event should be throttled.
 *
 * @param array $event Normalized event data.
 * @return bool True if event should be skipped.
 */
function extrachill_api_activity_should_throttle( $event ) {
	$type = $event['type'] ?? '';

	if ( ! isset( EXTRACHILL_ACTIVITY_THROTTLE_RULES[ $type ] ) ) {
		return false;
	}

	$actor_id = $event['actor_id'] ?? null;
	if ( ! $actor_id ) {
		return false;
	}

	$cache_key = extrachill_api_activity_throttle_key( $event );
	return (bool) get_transient( $cache_key );
}

/**
 * Mark an activity event as emitted.
 *
 * @param array $event Normalized event data.
 * @return void
 */
function extrachill_api_activity_mark_emitted( $event ) {
	$type = $event['type'] ?? '';

	if ( ! isset( EXTRACHILL_ACTIVITY_THROTTLE_RULES[ $type ] ) ) {
		return;
	}

	$actor_id = $event['actor_id'] ?? null;
	if ( ! $actor_id ) {
		return;
	}

	$cache_key = extrachill_api_activity_throttle_key( $event );
	$window    = EXTRACHILL_ACTIVITY_THROTTLE_RULES[ $type ];

	set_transient( $cache_key, 1, $window );
}

/**
 * Build throttle cache key from event data.
 *
 * @param array $event Event data.
 * @return string Cache key.
 */
function extrachill_api_activity_throttle_key( $event ) {
	$actor_id  = $event['actor_id'] ?? 0;
	$type      = $event['type'] ?? '';
	$object_id = $event['primary']['id'] ?? '';
	$blog_id   = $event['primary']['blog_id'] ?? 0;

	return sprintf(
		'ec_activity_throttle_%d_%s_%d_%s',
		$actor_id,
		$type,
		$blog_id,
		$object_id
	);
}
