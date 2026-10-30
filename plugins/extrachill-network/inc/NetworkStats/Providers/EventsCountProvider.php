<?php
/**
 * Events-count metric provider.
 *
 * Counts published events on the events site (blog 7,
 * `data_machine_events` post type).
 *
 * data-machine-events is a PER-SITE plugin, so its public integration function
 * data_machine_events_query_events() and its UpcomingCountAbilities are NOT
 * loaded in the PHP process of any other site, and switch_to_blog() does not
 * load them. The event rows, however, live in the shared database. So:
 *  - When the events integration API IS available (events-site origin / a
 *    warmer running on blog 7), prefer the richer "upcoming" count via
 *    data_machine_events_query_events() — the number a calendar landing page
 *    actually wants.
 *  - Otherwise fall back to a plugin-independent UPCOMING count read directly
 *    from blog 7's `datamachine_event_dates` table (start today or later) —
 *    the count a calendar landing page wants, not the all-time published total.
 *
 * Returns null only when the events site itself is unavailable.
 *
 * @package ExtraChillNetwork\NetworkStats\Providers
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats\Providers;

use ExtraChillNetwork\NetworkStats\AbstractMetricProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Count of events on the network.
 */
class EventsCountProvider extends AbstractMetricProvider {

	/**
	 * Construct the events-count provider.
	 */
	public function __construct() {
		parent::__construct( 'events_count', __( 'Events', 'extrachill-network' ), HOUR_IN_SECONDS );
	}

	/**
	 * {@inheritDoc}
	 */
	public function value() {
		// Prefer the events integration API when loaded (events-site origin).
		if ( function_exists( 'data_machine_events_query_events' ) ) {
			$result = data_machine_events_query_events(
				array(
					'scope'  => 'upcoming',
					'fields' => 'count',
				)
			);
			if ( is_array( $result ) && isset( $result['total'] ) ) {
				return (int) $result['total'];
			}
		}

		// Plugin-independent fallback: UPCOMING count from blog 7's event-dates
		// table (the calendar's own boundary), not the all-time published total.
		return $this->count_upcoming_events( 'events' );
	}
}
