<?php
/**
 * Tests for the photo contest REST adapters (extrachill-api#197).
 *
 * The owning abilities live in extrachill-content-blocks, which is not
 * loaded here, so controlled stub abilities are registered per test run.
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the photo contest submit/entries/vote transport adapters. */
class PhotoContestRoutesTest extends WP_UnitTestCase {

	/** @var string[] */
	private $ability_names = array(
		'extrachill/photo-contest-submit',
		'extrachill/photo-contest-list',
		'extrachill/photo-contest-vote',
	);

	/** @var array<string, array<int, array>> Inputs captured per ability. */
	private $ability_inputs = array();

	/** @var array<string, mixed> Controlled result per ability. */
	private $ability_results = array();

	/** @var string|null */
	private $original_remote_addr;

	/** Register controlled photo contest abilities. */
	public function set_up() {
		parent::set_up();

		$this->ability_inputs       = array();
		$this->ability_results      = array(
			'extrachill/photo-contest-submit' => array(
				'message' => 'Entry received.',
				'entry'   => array( 'id' => 7 ),
			),
			'extrachill/photo-contest-list'   => array( 'entries' => array() ),
			'extrachill/photo-contest-vote'   => array(
				'message'    => 'Vote counted.',
				'vote_count' => 3,
			),
		);
		$this->original_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;

		if ( ! wp_has_ability_category( 'extrachill-api-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'extrachill-api-tests',
				array(
					'label'       => 'Extra Chill API Tests',
					'description' => 'Controlled abilities for API transport tests.',
				)
			);
		}

		foreach ( $this->ability_names as $ability_name ) {
			$this->register_stub_ability( $ability_name );
		}

		do_action( 'rest_api_init' );
	}

	/** Remove the controlled abilities. */
	public function tear_down() {
		foreach ( $this->ability_names as $ability_name ) {
			if ( wp_has_ability( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
		}

		if ( null === $this->original_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
		}

		parent::tear_down();
	}

	/** All three public routes are registered. */
	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/extrachill/v1/content-blocks/photo-contest/submit', $routes );
		$this->assertArrayHasKey( '/extrachill/v1/content-blocks/photo-contest/entries', $routes );
		$this->assertArrayHasKey( '/extrachill/v1/content-blocks/photo-contest/vote', $routes );
	}

	/** Missing required submit fields are rejected before the ability runs. */
	public function test_submit_requires_args() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/content-blocks/photo-contest/submit' );
		$request->set_body_params( array( 'post_id' => 5 ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );
		$this->assertSame( array(), $this->ability_inputs );
	}

	/** A malformed submit email fails REST arg validation. */
	public function test_submit_rejects_invalid_email() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/content-blocks/photo-contest/submit' );
		$request->set_body_params(
			array(
				'post_id'      => 5,
				'contest_id'   => 'skinful-2025',
				'email'        => 'not-an-email',
				'display_name' => 'Ghoul',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( array(), $this->ability_inputs );
	}

	/** A non-positive vote post_id fails validation. */
	public function test_vote_rejects_invalid_post_id() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/content-blocks/photo-contest/vote' );
		$request->set_body_params(
			array(
				'post_id'       => 0,
				'contest_id'    => 'skinful-2025',
				'entry_id'      => 7,
				'email_address' => 'voter@example.com',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/** Entries requires both post_id and contest_id. */
	public function test_entries_requires_args() {
		$request = new WP_REST_Request( 'GET', '/extrachill/v1/content-blocks/photo-contest/entries' );
		$request->set_query_params( array( 'post_id' => 5 ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );
	}

	/** The multipart photo and client IP pass through to the ability. */
	public function test_submit_passes_photo_and_ip_through() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$photo                  = array(
			'name'     => 'costume.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => '/tmp/phpABC123',
			'error'    => 0,
			'size'     => 123456,
		);

		$request = new WP_REST_Request( 'POST', '/extrachill/v1/content-blocks/photo-contest/submit' );
		$request->set_body_params(
			array(
				'post_id'      => 5,
				'contest_id'   => 'skinful-2025',
				'email'        => 'entrant@example.com',
				'display_name' => 'Ghoul',
				'instagram'    => '@ghoul',
				'website'      => '',
			)
		);
		$request->set_file_params( array( 'photo' => $photo ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'message' => 'Entry received.',
				'entry'   => array( 'id' => 7 ),
			),
			$response->get_data()
		);

		$input = $this->ability_inputs['extrachill/photo-contest-submit'][0];
		$this->assertSame( $photo, $input['photo'] );
		$this->assertSame( '203.0.113.9', $input['ip'] );
		$this->assertSame( 5, $input['post_id'] );
		$this->assertSame( 'skinful-2025', $input['contest_id'] );
		$this->assertSame( 'entrant@example.com', $input['email'] );
		$this->assertSame( 'Ghoul', $input['display_name'] );
		$this->assertSame( '@ghoul', $input['instagram'] );
		$this->assertSame( '', $input['website'] );
	}

	/** A non-empty honeypot value is forwarded untouched for the ability to judge. */
	public function test_submit_forwards_honeypot_value() {
		$request = $this->submit_request( array( 'website' => 'https://spam.example' ) );

		rest_get_server()->dispatch( $request );

		$this->assertSame( 'https://spam.example', $this->ability_inputs['extrachill/photo-contest-submit'][0]['website'] );
	}

	/**
	 * Submit ability error codes map to the contracted HTTP statuses.
	 *
	 * @dataProvider submit_error_status_provider
	 */
	public function test_submit_error_code_status_mapping( $code, $status ) {
		$this->ability_results['extrachill/photo-contest-submit'] = new WP_Error( $code, 'Nope.' );

		$response = rest_get_server()->dispatch( $this->submit_request() );

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	/** @return array<string, array{string, int}> */
	public function submit_error_status_provider() {
		return array(
			'invalid_submission' => array( 'invalid_submission', 400 ),
			'invalid_email'      => array( 'invalid_email', 400 ),
			'missing_name'       => array( 'missing_name', 400 ),
			'missing_photo'      => array( 'missing_photo', 400 ),
			'upload_failed'      => array( 'upload_failed', 400 ),
			'invalid_photo_type' => array( 'invalid_photo_type', 400 ),
			'photo_too_large'    => array( 'photo_too_large', 413 ),
			'rate_limited'       => array( 'rate_limited', 429 ),
			'contest_not_found'  => array( 'contest_not_found', 404 ),
			'entries_closed'     => array( 'entries_closed', 403 ),
			'already_entered'    => array( 'already_entered', 409 ),
		);
	}

	/** already_entered keeps the error data.entry so entrants get their share link back. */
	public function test_already_entered_preserves_entry_data() {
		$entry = array(
			'id'        => 7,
			'share_url' => 'https://extrachill.com/contest?entry=7',
		);
		$this->ability_results['extrachill/photo-contest-submit'] = new WP_Error(
			'already_entered',
			'You already entered.',
			array( 'entry' => $entry )
		);

		$response = rest_get_server()->dispatch( $this->submit_request() );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'already_entered', $response->get_data()['code'] );
		$this->assertSame( $entry, $response->get_data()['data']['entry'] );
	}

	/**
	 * Vote ability error codes map to the contracted HTTP statuses.
	 *
	 * @dataProvider vote_error_status_provider
	 */
	public function test_vote_error_code_status_mapping( $code, $status ) {
		$this->ability_results['extrachill/photo-contest-vote'] = new WP_Error( $code, 'Nope.' );

		$response = rest_get_server()->dispatch( $this->vote_request() );

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	/** @return array<string, array{string, int}> */
	public function vote_error_status_provider() {
		return array(
			'entry_not_found'   => array( 'entry_not_found', 404 ),
			'contest_not_found' => array( 'contest_not_found', 404 ),
			'voting_closed'     => array( 'voting_closed', 403 ),
			'already_voted'     => array( 'already_voted', 409 ),
			'invalid_email'     => array( 'invalid_email', 400 ),
			'rate_limited'      => array( 'rate_limited', 429 ),
		);
	}

	/** Unmapped error codes keep the ability-provided status. */
	public function test_unmapped_error_keeps_ability_status() {
		$this->ability_results['extrachill/photo-contest-vote'] = new WP_Error(
			'vote_storage_unavailable',
			'Try again shortly.',
			array( 'status' => 503 )
		);

		$response = rest_get_server()->dispatch( $this->vote_request() );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'vote_storage_unavailable', $response->get_data()['code'] );
	}

	/** Unmapped error codes without a status fall back to 500. */
	public function test_unmapped_error_without_status_becomes_500() {
		$this->ability_results['extrachill/photo-contest-vote'] = new WP_Error( 'mystery_failure', 'Unknown.' );

		$response = rest_get_server()->dispatch( $this->vote_request() );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'mystery_failure', $response->get_data()['code'] );
	}

	/** The client IP passes through to the vote ability for per-IP rate limiting. */
	public function test_vote_passes_ip_through() {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.4';

		$response = rest_get_server()->dispatch( $this->vote_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '198.51.100.4', $this->ability_inputs['extrachill/photo-contest-vote'][0]['ip'] );
	}

	/** Vote success returns the public {message, vote_count} envelope. */
	public function test_vote_success_envelope() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->ability_results['extrachill/photo-contest-vote'] = array(
			'message'    => 'Vote counted.',
			'vote_count' => 12,
			'internal'   => 'not exposed',
		);

		$response = rest_get_server()->dispatch( $this->vote_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'message'    => 'Vote counted.',
				'vote_count' => 12,
			),
			$response->get_data()
		);
		$this->assertSame(
			array(
				'post_id'       => 5,
				'contest_id'    => 'skinful-2025',
				'entry_id'      => 7,
				'email_address' => 'voter@example.com',
				'ip'            => '203.0.113.9',
			),
			$this->ability_inputs['extrachill/photo-contest-vote'][0]
		);
	}

	/** Entries success returns the list with a short cache window. */
	public function test_entries_success_envelope_and_cache_header() {
		$entries = array(
			array(
				'id'            => 7,
				'display_name'  => 'Ghoul',
				'instagram'     => '@ghoul',
				'image_url'     => 'https://extrachill.com/photo.jpg',
				'thumbnail_url' => 'https://extrachill.com/photo-150.jpg',
				'vote_count'    => 3,
				'share_url'     => 'https://extrachill.com/contest?entry=7',
			),
		);
		$this->ability_results['extrachill/photo-contest-list'] = array( 'entries' => $entries );

		$request = new WP_REST_Request( 'GET', '/extrachill/v1/content-blocks/photo-contest/entries' );
		$request->set_query_params(
			array(
				'post_id'    => 5,
				'contest_id' => 'skinful-2025',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'entries' => $entries ), $response->get_data() );
		$this->assertSame( 'public, max-age=15', $response->get_headers()['Cache-Control'] );
		$this->assertSame(
			array(
				'post_id'    => 5,
				'contest_id' => 'skinful-2025',
			),
			$this->ability_inputs['extrachill/photo-contest-list'][0]
		);
	}

	/** Entries contest_not_found maps to 404. */
	public function test_entries_contest_not_found_maps_to_404() {
		$this->ability_results['extrachill/photo-contest-list'] = new WP_Error( 'contest_not_found', 'No contest.' );

		$request = new WP_REST_Request( 'GET', '/extrachill/v1/content-blocks/photo-contest/entries' );
		$request->set_query_params(
			array(
				'post_id'    => 5,
				'contest_id' => 'missing',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'contest_not_found', $response->get_data()['code'] );
	}

	/**
	 * Each handler returns a 500 ability_missing error when its ability is absent.
	 *
	 * @dataProvider missing_ability_provider
	 */
	public function test_missing_ability_returns_500( $ability_name, $handler ) {
		// wp_get_ability() fires a doing_it_wrong notice for unregistered abilities.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		wp_unregister_ability( $ability_name );

		$result = call_user_func( $handler, new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_missing', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	/** @return array<string, array{string, string}> */
	public function missing_ability_provider() {
		return array(
			'submit'  => array( 'extrachill/photo-contest-submit', 'extrachill_api_content_blocks_photo_contest_submit_handler' ),
			'entries' => array( 'extrachill/photo-contest-list', 'extrachill_api_content_blocks_photo_contest_entries_handler' ),
			'vote'    => array( 'extrachill/photo-contest-vote', 'extrachill_api_content_blocks_photo_contest_vote_handler' ),
		);
	}

	/** Build a valid submit request. */
	private function submit_request( array $overrides = array() ) {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/content-blocks/photo-contest/submit' );
		$request->set_body_params(
			array_merge(
				array(
					'post_id'      => 5,
					'contest_id'   => 'skinful-2025',
					'email'        => 'entrant@example.com',
					'display_name' => 'Ghoul',
				),
				$overrides
			)
		);

		return $request;
	}

	/** Build a valid vote request. */
	private function vote_request() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/content-blocks/photo-contest/vote' );
		$request->set_body_params(
			array(
				'post_id'       => 5,
				'contest_id'    => 'skinful-2025',
				'entry_id'      => 7,
				'email_address' => 'voter@example.com',
			)
		);

		return $request;
	}

	/** Register a controlled ability that records inputs and returns the stubbed result. */
	private function register_stub_ability( $ability_name ) {
		if ( wp_has_ability( $ability_name ) ) {
			wp_unregister_ability( $ability_name );
		}

		$test = $this;
		WP_Abilities_Registry::get_instance()->register(
			$ability_name,
			array(
				'label'               => 'Test ' . $ability_name,
				'description'         => 'Controlled photo contest ability.',
				'category'            => 'extrachill-api-tests',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) use ( $test, $ability_name ) {
					$test->ability_inputs[ $ability_name ][] = $input;

					return $test->ability_results[ $ability_name ];
				},
			)
		);
	}
}
