<?php
/**
 * Extra Chill event_type vocabulary for Data Machine Events.
 *
 * Supplies the editorial event-format vocabulary through the
 * `data_machine_events_event_type_vocabulary` filter shipped by
 * data-machine-events#761. The filter REPLACES the Schema.org default
 * vocabulary: every Extra Chill term still maps to a valid Schema.org
 * `@type` (stamped as `_schema_type` term meta by data-machine-events),
 * so JSON-LD output keeps working while the human-facing terms become
 * filterable formats.
 *
 * There is deliberately no `Festival` term: the `festival` taxonomy already
 * carries that fact, and a second encoding of the same data recreates the
 * dual-source-of-truth problem (Extra-Chill/extrachill-events#802). A
 * festival set is still a Concert.
 *
 * data-machine-events owns the taxonomy registration, closed-vocabulary
 * enforcement, and term seeding; this file only declares the vocabulary.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize the Extra Chill event_type vocabulary hooks.
 */
function extrachill_events_init_event_type_vocabulary() {
	if ( ! defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ) {
		return;
	}

	add_filter( 'data_machine_events_event_type_vocabulary', 'extrachill_events_event_type_vocabulary' );
}

/**
 * Supply the Extra Chill editorial event-format vocabulary.
 *
 * Uses the accepted array-of-entries shape. The single entry carrying
 * `default` is the vocabulary fallback: it is assigned whenever an
 * AI-supplied or backfilled value cannot be resolved to another term.
 *
 * @param array $vocabulary Default Schema.org vocabulary from data-machine-events.
 * @return array Replacement vocabulary entries.
 */
function extrachill_events_event_type_vocabulary( $vocabulary ) {
	unset( $vocabulary );

	return array(
		array(
			'name'        => 'Concert',
			'slug'        => 'concert',
			'schema_type' => 'MusicEvent',
			'default'     => true,
		),
		array(
			'name'        => 'DJ Set',
			'slug'        => 'dj-set',
			'schema_type' => 'MusicEvent',
			'default'     => false,
		),
		array(
			'name'        => 'Dance Party',
			'slug'        => 'dance-party',
			'schema_type' => 'MusicEvent',
			'default'     => false,
		),
		array(
			'name'        => 'Open Mic',
			'slug'        => 'open-mic',
			'schema_type' => 'MusicEvent',
			'default'     => false,
		),
		array(
			'name'        => 'Karaoke',
			'slug'        => 'karaoke',
			'schema_type' => 'Event',
			'default'     => false,
		),
		array(
			'name'        => 'Trivia & Games',
			'slug'        => 'trivia-games',
			'schema_type' => 'Event',
			'default'     => false,
		),
		array(
			'name'        => 'Comedy',
			'slug'        => 'comedy',
			'schema_type' => 'ComedyEvent',
			'default'     => false,
		),
		array(
			'name'        => 'Other',
			'slug'        => 'other',
			'schema_type' => 'Event',
			'default'     => false,
		),
	);
}
