<?php
/**
 * Location term creation from venue metadata (#820).
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use WP_UnitTestCase;

/**
 * @covers ::extrachill_events_resolve_location_term_for_venue_city
 * @covers ::extrachill_events_create_location_term_from_venue
 */
final class LocationTermCreationTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! taxonomy_exists( 'location' ) ) {
			register_taxonomy( 'location', 'data_machine_events', array( 'hierarchical' => true ) );
		}
		extrachill_events_get_location_terms_by_name( true );
	}

	public function test_create_false_never_inserts_terms(): void {
		$before = wp_count_terms( array( 'taxonomy' => 'location', 'hide_empty' => false ) );

		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'Vadstena', '', '', 'Sweden' ) );
		$this->assertSame( $before, wp_count_terms( array( 'taxonomy' => 'location', 'hide_empty' => false ) ) );
	}

	public function test_creates_continent_country_city_chain_and_is_idempotent(): void {
		$europe = wp_insert_term( 'Europe', 'location' );
		$this->assertNotWPError( $europe );

		$city = extrachill_events_resolve_location_term_for_venue_city( 'Vadstena', '', '', 'Sweden', true );
		$this->assertInstanceOf( \WP_Term::class, $city );
		$this->assertSame( 'Vadstena', $city->name );

		$country = get_term( $city->parent, 'location' );
		$this->assertSame( 'Sweden', $country->name );
		$this->assertSame( (int) $europe['term_id'], (int) $country->parent );

		$again = extrachill_events_resolve_location_term_for_venue_city( 'Vadstena', '', '', 'SWE', true );
		$this->assertSame( (int) $city->term_id, (int) $again->term_id, 'Second resolve must reuse the created term.' );

		$second_city = extrachill_events_resolve_location_term_for_venue_city( 'Uppsala', '', '', 'se', true );
		$this->assertSame( (int) $country->term_id, (int) $second_city->parent, 'Sibling city must reuse the country term.' );
	}

	public function test_country_without_continent_mapping_is_created_at_root(): void {
		$city = extrachill_events_resolve_location_term_for_venue_city( 'Tokyo', '', '', 'Japan', true );
		$this->assertInstanceOf( \WP_Term::class, $city );
		$country = get_term( $city->parent, 'location' );
		$this->assertSame( 'Japan', $country->name );
		$this->assertSame( 0, (int) $country->parent );
	}

	public function test_us_city_keeps_state_tier(): void {
		$city = extrachill_events_resolve_location_term_for_venue_city( 'Charleston', 'SC', '29403', 'US', true );
		$this->assertInstanceOf( \WP_Term::class, $city );
		$state = get_term( $city->parent, 'location' );
		$this->assertSame( 'South Carolina', $state->name );
		$country = get_term( $state->parent, 'location' );
		$this->assertSame( 'United States', $country->name );
	}

	public function test_unknown_country_and_venue_like_city_are_refused(): void {
		$before = wp_count_terms( array( 'taxonomy' => 'location', 'hide_empty' => false ) );

		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'Somewhere', '', '', 'Atlantis', true ) );
		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'Skydiver Records, 358 Smith Street', '', '', 'Australia', true ) );
		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'The Baby G - Toronto', '', '', 'Canada', true ) );

		$this->assertSame( $before, wp_count_terms( array( 'taxonomy' => 'location', 'hide_empty' => false ) ) );
	}
}
