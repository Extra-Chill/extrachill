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
		// Always (re-)register hierarchically rather than only registering
		// `if ( ! taxonomy_exists( 'location' ) )`. Taxonomy registrations
		// live in $GLOBALS['wp_taxonomies'], a plain PHP array that survives
		// WP_UnitTestCase's per-test database transaction rollback — an
		// earlier test in the same managed PHPUnit process that registers
		// 'location' without `hierarchical => true` (production's real
		// shape) silently poisons every test that runs after it in that
		// process, this one included, because taxonomy_exists() alone can't
		// tell a wrongly-shaped registration from a correct one (#890).
		// Registering unconditionally means this test always exercises the
		// real product configuration regardless of what ran before it.
		register_taxonomy( 'location', 'data_machine_events', array( 'hierarchical' => true ) );
		$this->assertTrue( is_taxonomy_hierarchical( 'location' ), 'This suite requires the real, hierarchical location taxonomy shape; a wrongly-shaped registration would silently produce false results below.' );
		extrachill_events_get_location_terms_by_name( true );
	}

	public function test_create_false_never_inserts_terms(): void {
		$before = wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) );

		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'Vadstena', '', '', 'Sweden' ) );
		$this->assertSame( $before, wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) ) );
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

	/**
	 * Regression for #890: a stateless international city lives at
	 * Continent > Country > City, so its country is its PARENT. The hierarchy
	 * filter only checked the grandparent (the continent), rejected the city
	 * that was just created, and created a duplicate on every re-resolve.
	 * Self-contained: creates its own continent root, so it does not depend
	 * on test order or on which terms other tests left behind.
	 */
	public function test_reresolving_a_stateless_international_city_never_duplicates_it(): void {
		$europe    = get_term_by( 'name', 'Europe', 'location' );
		$europe_id = $europe instanceof \WP_Term ? (int) $europe->term_id : (int) wp_insert_term( 'Europe', 'location' )['term_id'];

		$first = extrachill_events_resolve_location_term_for_venue_city( 'Aarhus', '', '', 'Denmark', true );
		$this->assertInstanceOf( \WP_Term::class, $first );
		$country = get_term( $first->parent, 'location' );
		$this->assertSame( 'Denmark', $country->name );
		$this->assertSame( $europe_id, (int) $country->parent, 'Country sits under its continent, so the city has no state tier.' );

		$terms_after_first = wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) );

		foreach ( array( 'Denmark', 'DK', 'DNK' ) as $country_input ) {
			$again = extrachill_events_resolve_location_term_for_venue_city( 'Aarhus', '', '', $country_input, true );
			$this->assertInstanceOf( \WP_Term::class, $again );
			$this->assertSame( (int) $first->term_id, (int) $again->term_id, "Re-resolving with country '{$country_input}' must reuse the city." );
		}

		$this->assertSame( $terms_after_first, wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) ), 'No duplicate terms may be created.' );
	}

	/**
	 * Regression for #890 (third cause): extrachill_events_create_location_term_from_venue()
	 * looked up the continent with get_term_by( 'name', $continent, 'location' ),
	 * which queries with orderby=none, number=1. Term names are not unique
	 * in WordPress (only slugs are, via auto-suffixing), so if more than one
	 * term is named "Europe", which one that call returns is undefined. A
	 * non-root duplicate picked over the real root continent fails the
	 * `0 === $continent_term->parent` check, silently leaves $parent_id at
	 * 0, and creates the country (and every city under it) as a duplicate
	 * root instead of reusing the existing continent branch — exactly the
	 * "sibling city creates a second country term" failure #890 tracked,
	 * which only surfaced when a scoped CI run happened to leave a
	 * non-root "Europe" in the same process. Self-contained: creates its
	 * own decoy non-root "Europe" (inserted first, so an unordered query
	 * is most likely to surface it) alongside the real root "Europe"
	 * (inserted second), so it fails deterministically on old code
	 * regardless of what other tests ran first in this process.
	 */
	public function test_country_resolves_under_root_continent_when_a_duplicate_named_continent_exists(): void {
		$decoy_parent = wp_insert_term( 'Old World Archive', 'location' );
		$this->assertNotWPError( $decoy_parent );
		$decoy_europe = wp_insert_term( 'Europe', 'location', array( 'parent' => (int) $decoy_parent['term_id'] ) );
		$this->assertNotWPError( $decoy_europe );

		$real_europe = wp_insert_term( 'Europe', 'location' );
		$this->assertNotWPError( $real_europe );
		$this->assertNotSame( (int) $decoy_europe['term_id'], (int) $real_europe['term_id'] );

		$city = extrachill_events_resolve_location_term_for_venue_city( 'Malmo', '', '', 'Sweden', true );
		$this->assertInstanceOf( \WP_Term::class, $city );

		$country = get_term( $city->parent, 'location' );
		$this->assertSame( 'Sweden', $country->name );
		$this->assertSame(
			(int) $real_europe['term_id'],
			(int) $country->parent,
			'The country must resolve under the real root continent, never the non-root duplicate.'
		);

		// A sibling city resolved with the same country must reuse that
		// exact country term, not create a second one under whichever
		// "Europe" an ambiguous lookup happens to surface this time.
		$sibling = extrachill_events_resolve_location_term_for_venue_city( 'Lund', '', '', 'Sweden', true );
		$this->assertInstanceOf( \WP_Term::class, $sibling );
		$this->assertSame(
			(int) $country->term_id,
			(int) $sibling->parent,
			'Sibling city must reuse the same country term, not create a duplicate.'
		);
	}

	public function test_reresolving_a_us_city_with_state_still_reuses_it(): void {
		$first = extrachill_events_resolve_location_term_for_venue_city( 'Walterboro', 'SC', '', 'United States', true );
		$this->assertInstanceOf( \WP_Term::class, $first );
		$terms_after_first = wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) );

		$again = extrachill_events_resolve_location_term_for_venue_city( 'Walterboro', 'South Carolina', '', 'US', true );
		$this->assertSame( (int) $first->term_id, (int) $again->term_id );
		$this->assertSame( $terms_after_first, wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) ) );
	}

	/**
	 * Regression for #890 (second cause): the hierarchy filter compared the
	 * venue country with normalize() alone, which only knows US/CA/MX/GB codes,
	 * while create mode names country terms via the full display-name map.
	 * "DK" stayed "dk", never matched the "Denmark" term, and every re-resolve
	 * by code inserted a duplicate. Exercises the filter directly on a known
	 * tree, so it is independent of the name cache and test order.
	 */
	public function test_hierarchy_filter_matches_country_codes_against_country_terms(): void {
		$europe  = wp_insert_term( 'Hierarchy Test Continent', 'location' );
		$denmark = wp_insert_term( 'Denmark', 'location', array( 'parent' => (int) $europe['term_id'] ) );
		$this->assertNotWPError( $denmark );
		$city = wp_insert_term( 'Hierarchy Test City', 'location', array( 'parent' => (int) $denmark['term_id'] ) );
		$term = get_term( (int) $city['term_id'], 'location' );

		foreach ( array( 'Denmark', 'DK', 'DNK', 'dk' ) as $country_input ) {
			$this->assertCount(
				1,
				extrachill_events_filter_locations_by_hierarchy( array( $term ), '', $country_input ),
				"Country input '{$country_input}' must match the Denmark term."
			);
		}
		$this->assertCount( 0, extrachill_events_filter_locations_by_hierarchy( array( $term ), '', 'Germany' ) );
	}

	public function test_unknown_country_and_venue_like_city_are_refused(): void {
		$before = wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) );

		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'Somewhere', '', '', 'Atlantis', true ) );
		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'Skydiver Records, 358 Smith Street', '', '', 'Australia', true ) );
		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'The Baby G - Toronto', '', '', 'Canada', true ) );

		$this->assertSame( $before, wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) ) );
	}
}
