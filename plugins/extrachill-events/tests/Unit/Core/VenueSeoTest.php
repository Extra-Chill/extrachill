<?php
/**
 * Venue archive SEO: "Upcoming Events" title framing and meta description.
 *
 * @package ExtraChillEvents\Tests
 */

class VenueSeoTest extends WP_UnitTestCase {

	public function test_location_label_uses_us_state_code(): void {
		$this->assertSame( 'Nashville, TN', extrachill_events_venue_location_label( 'The Truth', 'Nashville', 'TN' ) );
	}

	public function test_location_label_is_city_only_outside_the_us(): void {
		$this->assertSame( 'Amsterdam', extrachill_events_venue_location_label( 'Gashouder', 'Amsterdam', 'Noord-Holland' ) );
	}

	public function test_location_label_empty_when_name_contains_city(): void {
		$this->assertSame( '', extrachill_events_venue_location_label( 'Pacha New York', 'New York', 'NY' ) );
		$this->assertSame( '', extrachill_events_venue_location_label( 'Pacha New York', 'New York City', 'New York' ) );
		$this->assertSame( '', extrachill_events_venue_location_label( 'Somewhere', '', 'NY' ) );
	}

	public function test_title_matches_upcoming_events_query(): void {
		$this->assertSame( 'The Truth Upcoming Events in Nashville, TN', extrachill_events_build_venue_title( 'The Truth', 'Nashville, TN' ) );
		$this->assertSame( 'Pacha New York Upcoming Events', extrachill_events_build_venue_title( 'Pacha New York', '' ) );
	}

	public function test_description_with_upcoming_shows(): void {
		$this->assertSame(
			'43 upcoming shows at The Truth in Nashville, TN. Next up: Trap Karaoke on Oct 23. Showtimes and tickets on Extra Chill.',
			extrachill_events_build_venue_description( 'The Truth', 'Nashville, TN', 43, 'Trap Karaoke', 'Oct 23' )
		);
	}

	public function test_single_show_uses_singular(): void {
		$this->assertStringStartsWith(
			'1 upcoming show at Chico Feo in Charleston, SC.',
			extrachill_events_build_venue_description( 'Chico Feo', 'Charleston, SC', 1, 'Songwriter Soap Box', 'Oct 26' )
		);
	}

	public function test_no_upcoming_shows_has_fallback(): void {
		$this->assertSame(
			'Concerts and events at Allianz Amphitheater at Riverfront in Richmond, VA. Past shows and new dates on the Extra Chill live music calendar.',
			extrachill_events_build_venue_description( 'Allianz Amphitheater at Riverfront', 'Richmond, VA', 0 )
		);
	}

	public function test_long_description_drops_whole_sentences(): void {
		$description = extrachill_events_build_venue_description( 'The Truth', 'Nashville, TN', 43, str_repeat( 'Very Long Show Title ', 4 ), 'Oct 23' );
		$this->assertLessThanOrEqual( 160, strlen( $description ) );
		$this->assertStringEndsWith( '.', $description );
		$this->assertStringContainsString( 'Next up:', $description );
	}

	public function test_description_never_exceeds_160_chars(): void {
		$long = str_repeat( 'Very Long Venue Name ', 10 );
		$this->assertLessThanOrEqual( 160, strlen( extrachill_events_build_venue_description( $long, 'Nashville, TN', 12, 'Show', 'Oct 1' ) ) );
	}

	public function test_title_untouched_off_venue_archive(): void {
		$parts = array( 'title' => 'Something' );
		$this->assertSame( $parts, extrachill_events_venue_title( $parts ) );
	}

	public function test_description_untouched_off_venue_archive(): void {
		$this->assertSame( 'default', extrachill_events_venue_description( 'default' ) );
	}
}
