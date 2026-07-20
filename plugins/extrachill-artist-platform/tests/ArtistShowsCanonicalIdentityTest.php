<?php

use PHPUnit\Framework\TestCase;

final class ArtistShowsCanonicalIdentityTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id'     => 4,
			'blog_stack'          => array(),
			'cross_site_requests' => array(),
			'blogs'               => array(
				1 => array(
					'terms'     => array(),
					'term_meta' => array(),
				),
				4 => array(),
			),
		);
	}

	private function assertPublicShowsAreEmpty( int $artist_term_id ): void {
		$this->assertFalse( ec_artist_profile_has_shows( 50, $artist_term_id ) );

		ob_start();
		ec_render_artist_profile_shows_section( 50, $artist_term_id );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_renamed_main_and_events_slugs_use_canonical_identity(): void {
		$GLOBALS['ec_test']['blogs'][1]['terms'][301] = (object) array(
			'term_id'  => 301,
			'taxonomy' => 'artist',
			'slug'     => 'renamed-main-band',
		);
		$GLOBALS['ec_test']['cross_site_result'] = array(
			'taxonomy'  => 'artist',
			'term_id'   => 901,
			'term_slug' => 'renamed-events-band',
			'found'     => true,
			'upcoming'  => array( array( 'event_id' => 9 ) ),
			'past'      => array( array( 'event_id' => 8 ) ),
		);

		$result  = ec_artist_shows_gather( 301 );
		$request = $GLOBALS['ec_test']['cross_site_requests'][0][3]['query']['input'];

		$this->assertSame( 301, $request['artist_term_id'] );
		$this->assertArrayNotHasKey( 'term_slug', $request );
		$this->assertSame( 901, $result['term_id'] );
		$this->assertSame( 'renamed-events-band', $result['term_slug'] );
		$this->assertSame( 9, $result['upcoming'][0]['event_id'] );
		$this->assertSame( 8, $result['past'][0]['event_id'] );
		$this->assertSame( 'https://site-7.example/artist/renamed-events-band', ec_artist_shows_archive_url( $result['term_slug'] ) );
	}

	public function test_successful_canonical_lookup_calls_events_adapter(): void {
		$GLOBALS['ec_test']['cross_site_result'] = array(
			'taxonomy'  => 'artist',
			'term_id'   => 902,
			'term_slug' => 'canonical-band',
			'found'     => true,
			'upcoming'  => array( array( 'event_id' => 10 ) ),
			'past'      => array(),
		);

		$result  = ec_artist_shows_gather( 303 );
		$request = $GLOBALS['ec_test']['cross_site_requests'][0];

		$this->assertSame( 'events', $request[0] );
		$this->assertSame( '/wp-abilities/v1/abilities/extrachill-events/events-by-artist/run', $request[2] );
		$this->assertSame(
			array(
				'artist_term_id' => 303,
				'scope'          => 'all',
				'limit'          => 12,
			),
			$request[3]['query']['input']
		);
		$this->assertSame( 10, $result['upcoming'][0]['event_id'] );
	}

	public function test_missing_mapping_error_preserves_empty_public_rendering(): void {
		$GLOBALS['ec_test']['cross_site_result'] = new WP_Error( 'artist_mapping_missing', 'No mapping.' );

		$this->assertPublicShowsAreEmpty( 304 );
		$this->assertCount( 1, $GLOBALS['ec_test']['cross_site_requests'] );
	}

	public function test_stale_mapping_error_preserves_empty_public_rendering(): void {
		$GLOBALS['ec_test']['cross_site_result'] = new WP_Error( 'stale_artist_mapping', 'Stale mapping.' );

		$this->assertPublicShowsAreEmpty( 305 );
		$this->assertCount( 1, $GLOBALS['ec_test']['cross_site_requests'] );
	}

	public function test_adapter_unavailability_preserves_empty_public_rendering(): void {
		$GLOBALS['ec_test']['cross_site_result'] = new WP_Error( 'ability_not_found', 'Adapter unavailable.' );

		$this->assertPublicShowsAreEmpty( 306 );
		$this->assertCount( 1, $GLOBALS['ec_test']['cross_site_requests'] );
	}

	public function test_malformed_adapter_response_preserves_empty_public_rendering(): void {
		$GLOBALS['ec_test']['cross_site_result'] = array(
			'taxonomy'  => 'artist',
			'term_id'   => 0,
			'term_slug' => 'invalid-identity',
			'found'     => true,
			'upcoming'  => array( array( 'event_id' => 11 ) ),
			'past'      => array(),
		);

		$this->assertPublicShowsAreEmpty( 307 );
	}

	public function test_invalid_canonical_id_returns_no_shows_without_an_events_request(): void {
		$this->assertSame(
			array(
				'term_id'   => 0,
				'term_slug' => '',
				'upcoming'  => array(),
				'past'      => array(),
			),
			ec_artist_shows_gather( 0 )
		);
		$this->assertSame( array(), $GLOBALS['ec_test']['cross_site_requests'] );
	}
}
