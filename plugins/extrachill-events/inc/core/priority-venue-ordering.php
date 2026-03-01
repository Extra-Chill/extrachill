<?php
/**
 * Priority Venue Ordering
 *
 * Reorders events within day groups based on venue priority.
 * Only applies when viewing a single location (all events share same location).
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reorder events by priority (single-location contexts only)
 *
 * 3-tier priority hierarchy:
 * 1. Priority Events (highest) - individual events marked as priority
 * 2. Priority Venue Events - events at venues marked as priority
 * 3. Regular Events (lowest) - all other events
 *
 * Priority sorting only applies when all events in the day group
 * share the same location. Multi-location views remain in datetime order.
 *
 * @param array  $events   Events for this day.
 * @param string $date_key Date string (Y-m-d).
 * @param array  $context  Context with date_obj and show_past.
 * @return array Reordered events if single-location, unchanged otherwise.
 */
function ec_events_reorder_by_priority( $events, $date_key, $context ) {
	if ( count( $events ) < 2 ) {
		return $events;
	}

	$priority_venue_ids = ec_get_priority_venue_ids();
	$priority_event_ids = function_exists( 'extrachill_get_priority_event_ids' )
		? extrachill_get_priority_event_ids()
		: array();

	if ( empty( $priority_venue_ids ) && empty( $priority_event_ids ) ) {
		return $events;
	}

	// Check if all events share the same location.
	$locations = array();
	foreach ( $events as $event ) {
		$post = $event['post'] ?? null;
		if ( ! $post ) {
			$locations['_none'] = true;
			continue;
		}

		$location_terms = get_the_terms( $post->ID, 'location' );
		$location_id    = ( $location_terms && ! is_wp_error( $location_terms ) )
			? $location_terms[0]->term_id
			: '_none';

		$locations[ $location_id ] = true;

		// Multiple locations detected - skip priority sorting.
		if ( count( $locations ) > 1 ) {
			return $events;
		}
	}

	// Single location - apply priority sorting.
	usort(
		$events,
		function ( $a, $b ) use ( $priority_venue_ids, $priority_event_ids ) {
			$a_event_priority = extrachill_is_priority_event( $a, $priority_event_ids );
			$b_event_priority = extrachill_is_priority_event( $b, $priority_event_ids );
			$a_venue_priority = ec_is_priority_venue_event( $a, $priority_venue_ids );
			$b_venue_priority = ec_is_priority_venue_event( $b, $priority_venue_ids );

			// Tier 1: Priority events first.
			if ( $a_event_priority && ! $b_event_priority ) {
				return -1;
			}
			if ( ! $a_event_priority && $b_event_priority ) {
				return 1;
			}

			// Tier 2: Priority venue events next (only if neither is priority event).
			if ( ! $a_event_priority && ! $b_event_priority ) {
				if ( $a_venue_priority && ! $b_venue_priority ) {
					return -1;
				}
				if ( ! $a_venue_priority && $b_venue_priority ) {
					return 1;
				}
			}

			// Same priority tier: maintain datetime order.
			$a_time = $a['datetime'] ?? null;
			$b_time = $b['datetime'] ?? null;

			if ( $a_time && $b_time ) {
				return $a_time <=> $b_time;
			}

			return 0;
		}
	);

	return $events;
}
add_filter( 'data_machine_events_day_group_events', 'ec_events_reorder_by_priority', 10, 3 );

/**
 * Check if event is at a priority venue
 *
 * @param array $event             Event item with post object.
 * @param array $priority_venue_ids Array of priority venue term IDs.
 * @return bool True if event's venue is priority.
 */
function ec_is_priority_venue_event( $event, $priority_venue_ids ) {
	$post = $event['post'] ?? null;
	if ( ! $post ) {
		return false;
	}

	$venue_terms = get_the_terms( $post->ID, 'venue' );
	if ( ! $venue_terms || is_wp_error( $venue_terms ) ) {
		return false;
	}

	return in_array( $venue_terms[0]->term_id, $priority_venue_ids, true );
}
