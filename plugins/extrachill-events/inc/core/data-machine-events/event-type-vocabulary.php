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
 * `default` is the vocabulary fallback: `Other` (#859).
 *
 * Fallback semantics, precisely:
 *
 * - The fallback means "unresolvable", never "confirmed". An event whose
 *   type cannot be resolved lands in `Other` (bare schema.org `Event`) and
 *   stays visible for audit — it is NOT asserted to be a concert. Asserting
 *   `MusicEvent` in JSON-LD on non-evidence is a structured-data accuracy
 *   problem independent of any archive filtering (#851).
 * - On the ingest (upsert) path the default is never stamped by code: a
 *   declared-but-unresolvable `eventType` value rejects the whole upsert
 *   (data-machine-events EventUpsertValidator gate), and a missing value
 *   leaves any existing term untouched (EventTaxonomyAssigner::processEventType).
 *   The default reaches the model as prompt text instead — data-machine-events
 *   tells the AI "if none of them clearly fit, choose <default>" — so moving
 *   the declaration to `Other` retargets that coercion automatically.
 * - The default IS stamped by code on the direct-update path
 *   (data-machine-events EventUpdateAbilities resolves any declared-but-
 *   unresolvable value to the default term) and by this plugin's
 *   backfill-event-type CLI, which resolves its final fallback from the
 *   declared default rather than a hardcoded name.
 * - Matching is case-insensitive against term name, slug, AND schema_type,
 *   in declaration order. Because of the schema_type arm, a bare "Event"
 *   value resolves to the FIRST `Event`-mapped entry (Karaoke) — declaration
 *   order is load-bearing; do not reorder casually.
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
			'default'     => false,
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
			'default'     => true,
		),
	);
}
