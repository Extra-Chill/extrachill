<?php
/**
 * Artist archive SEO: "Tour Dates" title framing and meta description.
 *
 * @package ExtraChillEvents\Tests
 */

class ArtistSeoTest extends WP_UnitTestCase {

	public function test_description_lists_cities_without_and_more_when_all_shown(): void {
		$this->assertSame(
			'Sublime tour dates: 3 upcoming shows in Lincoln, Phoenix, and Denver. Find tickets and concert details on Extra Chill.',
			extrachill_events_build_artist_description( 'Sublime', 3, array( 'Lincoln', 'Phoenix', 'Denver' ) )
		);
	}

	public function test_description_says_and_more_when_shows_exceed_listed_cities(): void {
		$this->assertStringContainsString(
			'7 upcoming shows including Atlantic City, Richmond, Jacksonville, and more.',
			extrachill_events_build_artist_description( 'Phish', 7, array( 'Atlantic City', 'Richmond', 'Jacksonville', 'Miami' ) )
		);
	}

	public function test_single_show_uses_singular(): void {
		$this->assertSame(
			'Jordan Cobb tour dates: 1 upcoming show in Atlanta. Find tickets and concert details on Extra Chill.',
			extrachill_events_build_artist_description( 'Jordan Cobb', 1, array( 'Atlanta' ) )
		);
	}

	public function test_no_upcoming_shows_has_fallback(): void {
		$this->assertStringStartsWith(
			'Nitty Gritty Dirt Band tour dates and concert history',
			extrachill_events_build_artist_description( 'Nitty Gritty Dirt Band', 0, array() )
		);
	}

	public function test_description_never_exceeds_160_chars(): void {
		$long = str_repeat( 'Very Long Artist Name ', 6 );
		$this->assertLessThanOrEqual( 160, strlen( extrachill_events_build_artist_description( $long, 12, array( 'A', 'B', 'C', 'D' ) ) ) );
	}

	public function test_title_untouched_off_artist_archive(): void {
		$parts = array( 'title' => 'Something' );
		$this->assertSame( $parts, extrachill_events_artist_title( $parts ) );
	}
}
