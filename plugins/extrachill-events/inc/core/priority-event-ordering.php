<?php
/**
 * Priority Event Ordering
 *
 * Helper function to check if an event is marked as priority.
 * Integrates with priority-venue-ordering.php for 3-tier sorting.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if event is marked as priority
 *
 * @param array $event             Event item with post object.
 * @param array $priority_event_ids Array of priority event post IDs.
 * @return bool True if event is priority.
 */
function extrachill_is_priority_event( $event, $priority_event_ids ) {
	$post = $event['post'] ?? null;
	if ( ! $post ) {
		return false;
	}

	return in_array( $post->ID, $priority_event_ids, true );
}
