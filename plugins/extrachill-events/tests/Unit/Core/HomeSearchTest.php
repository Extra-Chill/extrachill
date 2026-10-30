<?php
/**
 * Events homepage "Find shows" routing tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/wp-stubs.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/home-search.php';

/**
 * Verifies where one homepage search box sends a visitor.
 */
final class HomeSearchTest extends TestCase {

	private const URLS = array(
		'home'      => 'https://events.example/',
		'directory' => 'https://events.example/location/',
		'all'       => 'https://events.example/all',
	);

	public static function normalize( string $text ): string {
		return (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( $text ) );
	}

	private static function cities(): array {
		return array(
			array( 'name' => 'Charleston', 'label' => 'Charleston, South Carolina', 'url' => 'https://events.example/location/usa/south-carolina/charleston' ),
			array( 'name' => 'Charleston', 'label' => 'Charleston, West Virginia', 'url' => 'https://events.example/location/usa/west-virginia/charleston' ),
			array( 'name' => 'Austin', 'label' => 'Austin, Texas', 'url' => 'https://events.example/location/usa/texas/austin' ),
		);
	}

	private static function terms(): array {
		return array(
			'lofi venue'  => array( 'name' => 'Lo-Fi Brewing', 'taxonomy' => 'venue', 'count' => 81, 'url' => 'https://events.example/venue/lo-fi-brewing' ),
			'lofi artist' => array( 'name' => 'LO-Fi Brewing', 'taxonomy' => 'artist', 'count' => 7, 'url' => 'https://events.example/artist/lo-fi-brewing' ),
			'lofidels'    => array( 'name' => 'Lofidels', 'taxonomy' => 'artist', 'count' => 2, 'url' => 'https://events.example/artist/lofidels' ),
			'stranger'    => array( 'name' => 'Little Stranger', 'taxonomy' => 'artist', 'count' => 20, 'url' => 'https://events.example/artist/little-stranger' ),
			'royal'       => array( 'name' => 'The Royal American', 'taxonomy' => 'venue', 'count' => 29, 'url' => 'https://events.example/venue/the-royal-american' ),
			'cph venue'   => array( 'name' => 'Charleston Pour House', 'taxonomy' => 'venue', 'count' => 96, 'url' => 'https://events.example/venue/charleston-pour-house' ),
			'ph venue'    => array( 'name' => 'The Pour House Music Hall', 'taxonomy' => 'venue', 'count' => 12, 'url' => 'https://events.example/venue/the-pour-house' ),
			'tiger pub'   => array( 'name' => 'Blind Tiger Pub 36-38 Broad St', 'taxonomy' => 'venue', 'count' => 35, 'url' => 'https://events.example/venue/blind-tiger-pub' ),
			'tiger band'  => array( 'name' => 'Blind Tiger', 'taxonomy' => 'artist', 'count' => 4, 'url' => 'https://events.example/artist/blind-tiger' ),
			'location'    => array( 'name' => 'Austin', 'taxonomy' => 'location', 'count' => 900, 'url' => 'https://events.example/location/usa/texas/austin' ),
		);
	}

	private function resolve( string $query, array $term_keys = array(), array $urls = self::URLS ): array {
		$terms = array_values( array_intersect_key( self::terms(), array_flip( $term_keys ) ) );
		return extrachill_events_home_search_resolve( $query, self::cities(), $terms, $urls, array( self::class, 'normalize' ) );
	}

	public function test_empty_search_goes_home(): void {
		$this->assertSame( 'home', $this->resolve( '  - ' )['type'] );
	}

	public function test_unique_city_goes_to_its_calendar(): void {
		$this->assertSame( 'https://events.example/location/usa/texas/austin', $this->resolve( 'austin', array( 'location' ) )['url'] );
		$this->assertSame( 'https://events.example/location/usa/south-carolina/charleston', $this->resolve( 'Charleston, South Carolina' )['url'] );
	}

	public function test_city_shared_by_several_states_goes_to_the_directory(): void {
		$result = $this->resolve( 'charleston', array( 'cph venue' ) );

		$this->assertSame( 'cities', $result['type'] );
		$this->assertStringStartsWith( 'https://events.example/location/?search=', $result['url'] );
	}

	public function test_exact_venue_wins_over_same_named_artist(): void {
		$result = $this->resolve( 'lofi brewing', array( 'lofi artist', 'lofi venue', 'lofidels' ) );

		$this->assertSame( array( 'type' => 'venue', 'url' => 'https://events.example/venue/lo-fi-brewing' ), $result );
	}

	public function test_only_matching_venue_or_artist_is_the_destination(): void {
		$this->assertSame( 'https://events.example/artist/little-stranger', $this->resolve( 'little stranger', array( 'stranger' ) )['url'] );
		$this->assertSame( 'https://events.example/venue/the-royal-american', $this->resolve( 'royal american', array( 'royal' ) )['url'] );
	}

	public function test_ambiguous_names_search_the_calendar(): void {
		$result = $this->resolve( 'pour house', array( 'cph venue', 'ph venue' ) );
		$this->assertSame( 'events', $result['type'] );
		$this->assertStringStartsWith( 'https://events.example/all?event_search=', $result['url'] );

		$this->assertSame( 'events', $this->resolve( 'pour house', array( 'cph venue', 'ph venue', 'stranger' ) )['type'] );
	}

	public function test_only_matching_venue_beats_an_exactly_named_act(): void {
		$this->assertSame( 'https://events.example/venue/blind-tiger-pub', $this->resolve( 'blind tiger', array( 'tiger band', 'tiger pub' ) )['url'] );
		$this->assertSame( 'https://events.example/venue/lo-fi-brewing', $this->resolve( 'lofi', array( 'lofi venue', 'lofidels' ) )['url'] );
	}

	public function test_partial_city_goes_to_the_directory(): void {
		$this->assertSame( 'cities', $this->resolve( 'aus' )['type'] );
	}

	public function test_partial_city_wins_over_a_same_named_act(): void {
		$terms = array( 'name' => 'Aus', 'taxonomy' => 'artist', 'count' => 3, 'url' => 'https://events.example/artist/aus' );
		$result = extrachill_events_home_search_resolve( 'aus', self::cities(), array( $terms ), self::URLS, array( self::class, 'normalize' ) );

		$this->assertSame( 'cities', $result['type'] );
	}

	public function test_anything_else_searches_the_calendar(): void {
		$result = $this->resolve( 'halloween' );

		$this->assertSame( 'events', $result['type'] );
		$this->assertSame( add_query_arg( 'event_search', rawurlencode( 'halloween' ), 'https://events.example/all' ), $result['url'] );
	}

	public function test_without_a_city_directory_cities_fall_back_to_calendar_search(): void {
		$urls              = self::URLS;
		$urls['directory'] = '';

		$this->assertSame( 'events', $this->resolve( 'aus', array(), $urls )['type'] );
		$this->assertSame( 'events', $this->resolve( 'charleston', array(), $urls )['type'] );
	}

	public function test_homepage_form_submits_to_the_unified_search(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/core/account-market.php' );

		$this->assertStringContainsString( 'name="<?php echo esc_attr( EXTRACHILL_EVENTS_HOME_SEARCH_PARAM ); ?>"', $source );
		$this->assertStringContainsString( "'City, venue, artist, or show'", $source );
		$this->assertStringNotContainsString( "'City or state'", $source );
	}
}
