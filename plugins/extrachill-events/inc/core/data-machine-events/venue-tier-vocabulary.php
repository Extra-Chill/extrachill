<?php
/**
 * Extra Chill venue tier vocabulary for Data Machine Events.
 *
 * Extends the generic `_venue_tier` vocabulary from data-machine-events
 * (#786) through the `data_machine_events_venue_tier_vocabulary` filter.
 * Site-specific tiers are a consumer config change, never a code change
 * in the generic layer.
 *
 * Adds `restaurant`: a dining room where live music is background to the
 * meal. The generic `bar_gig` tier covers both bars and restaurants, but
 * for Extra Chill the local bar and brewery circuit is a core part of the
 * scene while restaurant sets are not. Splitting them lets a roundup keep
 * bar shows and drop dinner sets with one venue_tier filter.
 *
 * Tiers are human-assigned only (the AI import surface has no tier field),
 * so this tier is set through the venue admin screen or
 * `Venue_Taxonomy::update_venue_meta()`.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize the Extra Chill venue tier vocabulary hooks.
 */
function extrachill_events_init_venue_tier_vocabulary() {
	if ( ! defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ) {
		return;
	}

	add_filter( 'data_machine_events_venue_tier_vocabulary', 'extrachill_events_venue_tier_vocabulary' );
}

/**
 * Append the Extra Chill tiers to the generic vocabulary.
 *
 * @param mixed $vocabulary Generic vocabulary, slug => label.
 * @return array
 */
function extrachill_events_venue_tier_vocabulary( $vocabulary ) {
	$vocabulary = is_array( $vocabulary ) ? $vocabulary : array();

	$vocabulary['bar_gig']    = 'Bar / brewery gig — music incidental to the bar';
	$vocabulary['restaurant'] = 'Restaurant — music as background to dining';

	return $vocabulary;
}
