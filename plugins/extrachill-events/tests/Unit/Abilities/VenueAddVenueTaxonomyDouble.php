<?php
/**
 * Venue_Taxonomy double for the venue-add unit suite.
 *
 * Records find_or_create_venue() calls and models DME's canonical dedup just
 * far enough to prove the contract: a same-name venue in the same city is
 * reused, a same-name venue elsewhere becomes a distinct term.
 */

namespace DataMachineEvents\Core;

if ( ! class_exists( Venue_Taxonomy::class ) ) {
	class Venue_Taxonomy {
		public static function find_or_create_venue( $venue_name, $venue_data = array(), array $log_context = array() ) {
			$GLOBALS['venue_add_test']['find_or_create_calls'][] = array( $venue_name, $venue_data );

			$terms  = $GLOBALS['venue_add_test']['terms']['venue'] ?? array();
			$cities = $GLOBALS['venue_add_test']['venue_cities'] ?? array();
			foreach ( $terms as $term ) {
				if ( 0 === strcasecmp( $term->name, (string) $venue_name ) && 0 === strcasecmp( (string) ( $cities[ $term->term_id ] ?? '' ), (string) ( $venue_data['city'] ?? '' ) ) ) {
					return array(
						'term_id'      => $term->term_id,
						'was_created'  => false,
						'match_status' => 'matched',
					);
				}
			}

			$term_id = 500 + count( $terms );
			$GLOBALS['venue_add_test']['terms']['venue'][ $term_id ] = new \WP_Term( $term_id, (string) $venue_name, sanitize_title( (string) $venue_name ), 'venue' );
			$GLOBALS['venue_add_test']['venue_cities'][ $term_id ]   = (string) ( $venue_data['city'] ?? '' );

			return array(
				'term_id'      => $term_id,
				'was_created'  => true,
				'match_status' => 'created',
			);
		}
	}
}
