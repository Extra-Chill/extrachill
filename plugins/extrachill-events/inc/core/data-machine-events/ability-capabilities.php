<?php
/**
 * Data Machine Events ability capability bridge.
 *
 * Data Machine Events gates its write and diagnostic read abilities
 * (update-event, upsert-event, get-venue-events, event-health-check, ...)
 * on filterable capabilities that default to `edit_others_posts` and
 * `edit_posts`. Extra Chill team members hold the events admin capability
 * instead, so both gates resolve to it here. DME stays platform-agnostic.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__, 2 ) . '/Core/TeamAuthorization.php';

/**
 * Resolve DME ability capability gates to the Extra Chill events team capability.
 *
 * @param string $capability Data Machine Events default capability.
 * @return string Events team capability.
 */
function extrachill_events_dme_ability_capability( $capability ): string {
	unset( $capability );

	return \ExtraChillEvents\Core\TeamAuthorization::ACCESS_CAPABILITY;
}
add_filter( 'datamachine_events_write_capability', 'extrachill_events_dme_ability_capability' );
add_filter( 'datamachine_events_read_capability', 'extrachill_events_dme_ability_capability' );
