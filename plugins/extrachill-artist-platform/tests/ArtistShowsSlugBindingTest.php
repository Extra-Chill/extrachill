<?php

use PHPUnit\Framework\TestCase;

final class ArtistShowsSlugBindingTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id'    => 4,
			'blog_stack'         => array(),
			'cross_site_requests' => array(),
			'blogs'              => array(
				1 => array(
					'terms'     => array(),
					'term_meta' => array(),
				),
				4 => array(),
			),
		);
	}

	public function test_existing_slug_based_show_listing_remains_available(): void {
		$GLOBALS['ec_test']['blogs'][1]['terms'][301] = (object) array(
			'term_id'  => 301,
			'taxonomy' => 'artist',
			'slug'     => 'working-band',
		);
		$GLOBALS['ec_test']['cross_site_result']       = array(
			'taxonomy'  => 'artist',
			'term_slug' => 'working-band',
			'found'     => true,
			'upcoming'  => array( array( 'event_id' => 9 ) ),
			'past'      => array( array( 'event_id' => 8 ) ),
		);

		$result  = ec_artist_shows_gather( 301 );
		$request = $GLOBALS['ec_test']['cross_site_requests'][0][3]['query']['input'];

		$this->assertSame( 'working-band', $request['term_slug'] );
		$this->assertArrayNotHasKey( 'term_id', $request );
		$this->assertSame( 9, $result['upcoming'][0]['event_id'] );
		$this->assertSame( 8, $result['past'][0]['event_id'] );
	}

	public function test_missing_main_term_returns_no_shows_without_an_events_request(): void {
		$this->assertSame(
			array( 'upcoming' => array(), 'past' => array() ),
			ec_artist_shows_gather( 302 )
		);
		$this->assertSame( array(), $GLOBALS['ec_test']['cross_site_requests'] );
	}
}
