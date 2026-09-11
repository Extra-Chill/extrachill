<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistShowsCanonicalIdentityTest extends EC_Artist_Platform_TestCase {
	private $http_requests;

	protected function setUp(): void {
		parent::setUp();

		$this->http_requests           = new stdClass();
		$this->http_requests->requests = array();
		$this->http_requests->response = null;
		$state                         = $this->http_requests;
		$this->ec_inject_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $state ) {
				$state->requests[] = array( $url, $args );
				return $state->response ?? array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'taxonomy'  => 'artist',
							'term_id'   => 0,
							'term_slug' => '',
							'found'     => false,
							'upcoming'  => array(),
							'past'      => array(),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			100
		);
	}

	private function set_response( $response ): void {
		$this->http_requests->response = array(
			'headers'  => array(),
			'body'     => wp_json_encode( $response ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	private function events_url(): string {
		return untrailingslashit( get_home_url( ec_get_blog_id( 'events' ) ) );
	}

	public function test_renamed_main_and_events_slugs_use_canonical_identity(): void {
		$this->set_response(
			array(
				'taxonomy'  => 'artist',
				'term_id'   => 901,
				'term_slug' => 'renamed-events-band',
				'found'     => true,
				'upcoming'  => array( array( 'event_id' => 9 ) ),
				'past'      => array( array( 'event_id' => 8 ) ),
			)
		);

		$result  = ec_artist_shows_gather( 301 );
		$request = $this->http_requests->requests[0] ?? null;

		$this->assertNotNull( $request, 'The canonical lookup must call the events adapter.' );
		$this->assertStringContainsString( '/wp-abilities/v1/abilities/', (string) $request[0] );
		$this->assertSame( 901, $result['term_id'] );
		$this->assertSame( 'renamed-events-band', $result['term_slug'] );
		$this->assertSame( 9, $result['upcoming'][0]['event_id'] );
		$this->assertSame( 8, $result['past'][0]['event_id'] );
		$this->assertSame(
			$this->events_url() . '/artist/renamed-events-band',
			ec_artist_shows_archive_url( $result['term_slug'] )
		);
	}

	public function test_successful_canonical_lookup_calls_events_adapter(): void {
		$this->set_response(
			array(
				'taxonomy'  => 'artist',
				'term_id'   => 902,
				'term_slug' => 'canonical-band',
				'found'     => true,
				'upcoming'  => array( array( 'event_id' => 10 ) ),
				'past'      => array(),
			)
		);

		$result  = ec_artist_shows_gather( 303 );
		$request = $this->http_requests->requests[0];

		$this->assertStringContainsString( '/wp-abilities/v1/abilities/extrachill-events/events-by-artist/run', $request[0] );
		$this->assertSame( 10, $result['upcoming'][0]['event_id'] );
	}

	private function assertPublicShowsAreEmpty( int $artist_term_id ): void {
		$this->assertFalse( ec_artist_profile_has_shows( 50, $artist_term_id ) );

		ob_start();
		ec_render_artist_profile_shows_section( 50, $artist_term_id );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_missing_mapping_error_preserves_empty_public_rendering(): void {
		$this->set_response(
			array(
				'taxonomy'  => 'artist',
				'term_id'   => 0,
				'term_slug' => '',
				'found'     => false,
				'upcoming'  => array(),
				'past'      => array(),
			)
		);

		$this->assertPublicShowsAreEmpty( 304 );
		$this->assertCount( 1, $this->http_requests->requests );
	}

	public function test_stale_mapping_error_preserves_empty_public_rendering(): void {
		$this->set_response(
			array(
				'taxonomy'  => 'artist',
				'term_id'   => 0,
				'term_slug' => 'stale',
				'found'     => false,
				'upcoming'  => array(),
				'past'      => array(),
			)
		);

		$this->assertPublicShowsAreEmpty( 305 );
		$this->assertCount( 1, $this->http_requests->requests );
	}

	public function test_adapter_unavailability_preserves_empty_public_rendering(): void {
		// A 404 REST response surfaces as an HTTP error result.
		$this->http_requests->response = array(
			'headers'  => array(),
			'body'     => '{"code":"ability_not_found","message":"Adapter unavailable."}',
			'response' => array(
				'code'    => 404,
				'message' => 'Not Found',
			),
			'cookies'  => array(),
		);

		$this->assertPublicShowsAreEmpty( 306 );
		$this->assertCount( 1, $this->http_requests->requests );
	}

	public function test_malformed_adapter_response_preserves_empty_public_rendering(): void {
		$this->set_response(
			array(
				'taxonomy'  => 'artist',
				'term_id'   => 0,
				'term_slug' => 'invalid-identity',
				'found'     => true,
				'upcoming'  => array( array( 'event_id' => 11 ) ),
				'past'      => array(),
			)
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
		$this->assertSame( array(), $this->http_requests->requests );
	}
}
