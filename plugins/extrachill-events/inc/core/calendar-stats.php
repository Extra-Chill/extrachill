<?php
/**
 * Calendar Stats
 *
 * Renders a live stats line showing upcoming event/venue/location counts.
 * Stats are cached in a transient (1 hour) to avoid heavy queries on every page load.
 *
 * @package ExtraChillEvents
 * @since 0.12.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get calendar stats (cached).
 *
 * @return array{events: int, venues: int, locations: int}
 */
function extrachill_events_get_calendar_stats(): array {
	$cache_key = 'extrachill_calendar_stats';
	$cached    = get_transient( $cache_key );

	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	// Requires data-machine-events to be active.
	// Uses the public integration API. See data-machine-events docs/integration-api.md.
	if ( ! function_exists( 'data_machine_events_query_events' ) ) {
		return array(
			'events'    => 0,
			'venues'    => 0,
			'locations' => 0,
		);
	}

	// Count upcoming events via query-events public function.
	$result = data_machine_events_query_events(
		array(
			'scope'  => 'upcoming',
			'fields' => 'count',
		)
	);
	$events = (int) ( $result['total'] ?? 0 );

	// Count upcoming venues and locations via get-upcoming-counts ability. The
	// counts ability has no public wrapper yet; falling back to direct
	// instantiation guarded by class_exists() is acceptable for this internal
	// stat panel until a public function is added.
	if ( ! class_exists( '\DataMachineEvents\Abilities\UpcomingCountAbilities' ) ) {
		return array(
			'events'    => $events,
			'venues'    => 0,
			'locations' => 0,
		);
	}
	$counts_ability = new \DataMachineEvents\Abilities\UpcomingCountAbilities();

	$venue_result = $counts_ability->executeGetUpcomingCounts(
		array(
			'taxonomy'      => 'venue',
			'exclude_roots' => false,
		)
	);
	$venues       = count( $venue_result['terms'] ?? array() );

	$location_result = $counts_ability->executeGetUpcomingCounts(
		array(
			'taxonomy'      => 'location',
			'exclude_roots' => false,
		)
	);
	$locations       = count( $location_result['terms'] ?? array() );

	$stats = array(
		'events'    => $events,
		'venues'    => $venues,
		'locations' => $locations,
	);

	set_transient( $cache_key, $stats, 6 * HOUR_IN_SECONDS );

	return $stats;
}

/**
 * Render the calendar stats line.
 *
 * Output: "25,318 upcoming events at 596 venues in 56 locations"
 */
function extrachill_events_render_calendar_stats(): void {
	$stats = extrachill_events_get_calendar_stats();

	if ( $stats['events'] < 1 ) {
		return;
	}

	printf(
		'<p class="calendar-stats">%s upcoming events at %s venues in %s locations</p>',
		esc_html( number_format( $stats['events'] ) ),
		esc_html( number_format( $stats['venues'] ) ),
		esc_html( number_format( $stats['locations'] ) )
	);
}

/**
 * Get upcoming stats scoped to a single taxonomy term.
 *
 * Returns counts of upcoming events for the term, plus distinct venues
 * (for artist/location archives) and distinct locations (for artist
 * archives) of those upcoming events.
 *
 * Implementation:
 *   - Events count uses `data_machine_events_query_events()` with
 *     `tax_filters` + `fields=count`. Verified in
 *     `inc/Abilities/EventDateQueryAbilities.php::executeQueryEvents()`
 *     which accepts `tax_filters` keyed by taxonomy slug.
 *   - Venue / location counts use the new co-occurrence filter in
 *     `UpcomingCountAbilities::executeGetUpcomingCounts()`
 *     (`filter_taxonomy` + `filter_term_id`), shipped in
 *     data-machine-events PR #307.
 *
 * Partial failures degrade gracefully: if the venues/locations counts
 * return WP_Error, the function still returns a valid events count and
 * zeros for the missing axes. The render function will then drop the
 * affected clause from the sentence.
 *
 * Cached for 6h in a per-term transient
 * (`extrachill_calendar_stats_{taxonomy}_{term_id}`). Lazy on first
 * request — NOT pre-warmed (the cross-product of artist+location+venue
 * terms is too large for blanket warming).
 *
 * @param string $taxonomy 'artist'|'location'|'venue'.
 * @param int    $term_id  Term ID.
 * @return array{events:int, venues:int, locations:int}
 *   - artist: all three populated
 *   - location: events + venues populated, locations = 0
 *   - venue: events populated, venues = 0, locations = 0
 */
function extrachill_events_get_term_calendar_stats( string $taxonomy, int $term_id ): array {
	$zero = array(
		'events'    => 0,
		'venues'    => 0,
		'locations' => 0,
	);

	if ( ! in_array( $taxonomy, array( 'artist', 'location', 'venue' ), true ) ) {
		return $zero;
	}
	if ( $term_id < 1 ) {
		return $zero;
	}

	$cache_key = "extrachill_calendar_stats_{$taxonomy}_{$term_id}";
	$cached    = get_transient( $cache_key );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	if ( ! function_exists( 'data_machine_events_query_events' ) ) {
		return $zero;
	}

	// Events count: events tagged with this term that are upcoming.
	$events_result = data_machine_events_query_events(
		array(
			'scope'       => 'upcoming',
			'fields'      => 'count',
			'tax_filters' => array( $taxonomy => array( $term_id ) ),
		)
	);
	$events        = (int) ( $events_result['total'] ?? 0 );

	$venues    = 0;
	$locations = 0;

	// Co-occurrence counts require the new UpcomingCountAbilities signature
	// (filter_taxonomy + filter_term_id), shipped in data-machine-events #307.
	if ( $events > 0 && class_exists( '\DataMachineEvents\Abilities\UpcomingCountAbilities' ) ) {
		$counts_ability = new \DataMachineEvents\Abilities\UpcomingCountAbilities();

		// Venues count for artist + location archives.
		if ( 'artist' === $taxonomy || 'location' === $taxonomy ) {
			$venue_result = $counts_ability->executeGetUpcomingCounts(
				array(
					'taxonomy'        => 'venue',
					'exclude_roots'   => false,
					'filter_taxonomy' => $taxonomy,
					'filter_term_id'  => $term_id,
				)
			);

			if ( is_wp_error( $venue_result ) ) {
				error_log(
					sprintf(
						'[extrachill-events] term calendar stats: venue count failed for %s=%d: %s',
						$taxonomy,
						$term_id,
						$venue_result->get_error_message()
					)
				);
			} else {
				$venues = count( $venue_result['terms'] ?? array() );
			}
		}

		// Locations count for artist archives only.
		if ( 'artist' === $taxonomy ) {
			$location_result = $counts_ability->executeGetUpcomingCounts(
				array(
					'taxonomy'        => 'location',
					'exclude_roots'   => false,
					'filter_taxonomy' => $taxonomy,
					'filter_term_id'  => $term_id,
				)
			);

			if ( is_wp_error( $location_result ) ) {
				error_log(
					sprintf(
						'[extrachill-events] term calendar stats: location count failed for %s=%d: %s',
						$taxonomy,
						$term_id,
						$location_result->get_error_message()
					)
				);
			} else {
				$locations = count( $location_result['terms'] ?? array() );
			}
		}
	}

	$stats = array(
		'events'    => $events,
		'venues'    => $venues,
		'locations' => $locations,
	);

	set_transient( $cache_key, $stats, 6 * HOUR_IN_SECONDS );

	return $stats;
}

/**
 * Render the term-scoped stats line. Hides itself when events == 0.
 *
 * Output examples:
 *   - artist:   "123 upcoming events at 45 venues in 12 locations"
 *   - location: "123 upcoming events at 45 venues"
 *   - venue:    "123 upcoming events"
 *
 * If venues/locations counts failed in the helper (returned 0), the
 * affected clause is dropped from the sentence so the line still
 * provides useful information rather than reading "N events at 0
 * venues."
 *
 * @param string $taxonomy 'artist'|'location'|'venue'.
 * @param int    $term_id  Term ID.
 */
function extrachill_events_render_term_calendar_stats( string $taxonomy, int $term_id ): void {
	if ( ! in_array( $taxonomy, array( 'artist', 'location', 'venue' ), true ) ) {
		return;
	}

	$stats = extrachill_events_get_term_calendar_stats( $taxonomy, $term_id );

	if ( $stats['events'] < 1 ) {
		return;
	}

	$events_fmt    = esc_html( number_format( $stats['events'] ) );
	$venues_fmt    = esc_html( number_format( $stats['venues'] ) );
	$locations_fmt = esc_html( number_format( $stats['locations'] ) );

	if ( 'artist' === $taxonomy ) {
		if ( $stats['venues'] > 0 && $stats['locations'] > 0 ) {
			$sentence = "{$events_fmt} upcoming events at {$venues_fmt} venues in {$locations_fmt} locations";
		} elseif ( $stats['venues'] > 0 ) {
			$sentence = "{$events_fmt} upcoming events at {$venues_fmt} venues";
		} else {
			$sentence = "{$events_fmt} upcoming events";
		}
	} elseif ( 'location' === $taxonomy ) {
		if ( $stats['venues'] > 0 ) {
			$sentence = "{$events_fmt} upcoming events at {$venues_fmt} venues";
		} else {
			$sentence = "{$events_fmt} upcoming events";
		}
	} else { // venue
		$sentence = "{$events_fmt} upcoming events";
	}

	echo '<p class="calendar-stats">' . $sentence . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- All interpolated values are esc_html()'d above.
}
