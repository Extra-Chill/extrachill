<?php
/**
 * Events-cities metric provider.
 *
 * Counts the distinct cities the event calendar covers — modelled as non-empty
 * terms of the `location` taxonomy on the events site (blog 7). A "city" on the
 * calendar is a populated location term, mirroring the calendar-stats line
 * ("…in N locations").
 *
 * Uses the plugin-independent term-table count (see AbstractMetricProvider),
 * since the `location` taxonomy is registered by the per-site events plugin and
 * is not present in another site's process after switch_to_blog().
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
 * Count of cities (populated location terms) on the events calendar.
 */
class EventsCitiesProvider extends AbstractMetricProvider {

	/**
	 * Construct the events-cities provider.
	 */
	public function __construct() {
		parent::__construct( 'events_cities', __( 'Cities', 'extrachill-network' ), HOUR_IN_SECONDS );
	}

	/**
	 * {@inheritDoc}
	 */
	public function value() {
		return $this->count_nonempty_terms( 'events', 'location' );
	}
}
