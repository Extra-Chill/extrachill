<?php
/**
 * Minimal Data Machine Events event_type vocabulary stub for standalone tests.
 *
 * Mirrors DME's contract: get_vocabulary() returns name/slug/schema_type/default
 * entries and the upsert ability's eventType enum is the entry names. Defaults
 * to DME's shipped Schema.org vocabulary; tests may replace it through
 * $GLOBALS['ec_test_event_type_vocabulary'] as a site filter would.
 *
 * @package ExtraChillEvents\Tests
 */

namespace DataMachineEvents\Core;

if ( ! class_exists( Event_Type_Taxonomy::class, false ) ) {
	final class Event_Type_Taxonomy {
		public static function get_vocabulary(): array {
			if ( ! empty( $GLOBALS['ec_test_event_type_vocabulary'] ) ) {
				return $GLOBALS['ec_test_event_type_vocabulary'];
			}
			$vocabulary = array();
			foreach ( array( 'Event', 'MusicEvent', 'Festival', 'ComedyEvent', 'DanceEvent', 'TheaterEvent', 'SportsEvent', 'ExhibitionEvent' ) as $type ) {
				$vocabulary[] = array(
					'name'        => $type,
					'slug'        => strtolower( $type ),
					'schema_type' => $type,
					'default'     => 'Event' === $type,
				);
			}
			return $vocabulary;
		}

		public static function get_vocabulary_names(): array {
			return array_map(
				static fn( array $entry ): string => $entry['name'],
				self::get_vocabulary()
			);
		}
	}
}
