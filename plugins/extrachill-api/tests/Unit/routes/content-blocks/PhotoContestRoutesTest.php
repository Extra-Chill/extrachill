<?php
/**
 * Tests for the photo contest REST adapters (extrachill-api#197, #199).
 *
 * The owning abilities live in extrachill-content-blocks, which is not
 * loaded here, so controlled stub abilities are registered per test run.
 * Stubs mirror the content-blocks#34 contract: hidden from the core REST
 * runner unless a test flips the flag to exercise the refusal path.
 *
 * Error-mapping and envelope tests call the handlers directly; dispatch is
 * reserved for transport-boundary behavior (arg validation, admission) to
 * keep the suite's memory footprint bounded.
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

	/** @var array<string, int> Deterministic per-key admission counters. */
	private $rate_counts = array();

	/** Register controlled photo contest abilities. */
	public function set_up() {
		parent::set_up();

		$this->rate_counts          = array();
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
		$_SERVER['REMOTE_ADDR']     = '198.51.100.' . random_int( 1, 250 );
		add_filter( 'extrachill_api_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );

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

		remove_filter( 'extrachill_api_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );
		remove_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		remove_all_filters( 'extrachill_api_photo_contest_submit_rate_limit' );
		remove_all_filters( 'extrachill_api_photo_contest_vote_rate_limit' );

		parent::tear_down();
	}

	/** All three public routes are registered with the write admission wired in. */
	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/extrachill/v1/content-blocks/photo-contest/submit', $routes );
		$this->assertArrayHasKey( '/extrachill/v1/content-blocks/photo-contest/entries', $routes );
		$this->assertArrayHasKey( '/extrachill/v1/content-blocks/photo-contest/vote', $routes );
		$this->assertTrue( is_callable( $routes['/extrachill/v1/content-blocks/photo-contest/submit'][0]['permission_callback'] ) );
		$this->assertSame( 'string', $routes['/extrachill/v1/content-blocks/photo-contest/submit'][0]['args']['turnstile_response']['type'] );
		$this->assertArrayNotHasKey( 'turnstile_response', $routes['/extrachill/v1/content-blocks/photo-contest/vote'][0]['args'] );
		$this->assertSame( '__return_true', $routes['/extrachill/v1/content-blocks/photo-contest/entries'][0]['permission_callback'] );
		$this->assertSame( 'extrachill_api_content_blocks_photo_contest_vote_permission', $routes['/extrachill/v1/content-blocks/photo-contest/vote'][0]['permission_callback'] );
	}

	/** Missing tokens are rejected before metering; token-free votes still work. */
	public function test_submit_requires_turnstile_but_votes_do_not() {
		if ( ! function_exists( 'ec_turnstile_permission_callback' ) ) {
			$this->markTestSkipped( 'The network Turnstile primitive is not loaded.' );
		}

		wp_set_current_user( 0 );
		remove_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		$request = $this->submit_request();
		$params  = $request->get_body_params();
		unset( $params['turnstile_response'] );
		$request->set_body_params( $params );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'turnstile_missing_token', $response->get_data()['code'] );
		$this->assertSame( array(), $this->ability_inputs );
		$this->assertSame( array(), $this->rate_counts );

		$vote = rest_get_server()->dispatch( $this->vote_request() );
		$this->assertSame( 200, $vote->get_status() );
		$this->assertCount( 1, $this->ability_inputs['extrachill/photo-contest-vote'] );
		$this->assertCount( 1, $this->rate_counts );
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
		$response = rest_get_server()->dispatch( $this->submit_request( array( 'email' => 'not-an-email' ) ) );

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
				'contest_id'    => 'contest-1',
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

	/** The multipart photo passes through; the client IP never does. */
	public function test_submit_passes_photo_through_without_ip() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$photo                  = array(
			'name'     => 'entry.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => '/tmp/phpABC123',
			'error'    => 0,
			'size'     => 123456,
		);

		$request = $this->submit_request(
			array(
				'instagram' => '@ghoul',
				'website'   => '',
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
		$this->assertArrayNotHasKey( 'ip', $input );
		$this->assertArrayNotHasKey( 'turnstile_response', $input );
		$this->assertSame( 5, $input['post_id'] );
		$this->assertSame( 'contest-1', $input['contest_id'] );
		$this->assertSame( 'entrant@example.com', $input['email'] );
		$this->assertSame( 'Ghoul', $input['display_name'] );
		$this->assertSame( '@ghoul', $input['instagram'] );
		$this->assertSame( '', $input['website'] );
	}

	/** A non-empty honeypot value is forwarded untouched for the ability to judge. */
	public function test_submit_forwards_honeypot_value() {
		extrachill_api_content_blocks_photo_contest_submit_handler( $this->submit_request( array( 'website' => 'https://spam.example' ) ) );

		$this->assertSame( 'https://spam.example', $this->ability_inputs['extrachill/photo-contest-submit'][0]['website'] );
	}

	/**
	 * Submit ability error codes map to the contracted HTTP statuses.
	 *
	 * @dataProvider submit_error_status_provider
	 */
	public function test_submit_error_code_status_mapping( $code, $status ) {
		$this->ability_results['extrachill/photo-contest-submit'] = new WP_Error( $code, 'Nope.' );

		$result = extrachill_api_content_blocks_photo_contest_submit_handler( $this->submit_request() );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
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

		$result = extrachill_api_content_blocks_photo_contest_submit_handler( $this->submit_request() );

		$this->assertWPError( $result );
		$this->assertSame( 'already_entered', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( $entry, $result->get_error_data()['entry'] );
	}

	/**
	 * Vote ability error codes map to the contracted HTTP statuses.
	 *
	 * @dataProvider vote_error_status_provider
	 */
	public function test_vote_error_code_status_mapping( $code, $status ) {
		$this->ability_results['extrachill/photo-contest-vote'] = new WP_Error( $code, 'Nope.' );

		$result = extrachill_api_content_blocks_photo_contest_vote_handler( $this->vote_request() );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
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

		$result = extrachill_api_content_blocks_photo_contest_vote_handler( $this->vote_request() );

		$this->assertWPError( $result );
		$this->assertSame( 'vote_storage_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	/** Unmapped error codes without a status fall back to 500. */
	public function test_unmapped_error_without_status_becomes_500() {
		$this->ability_results['extrachill/photo-contest-vote'] = new WP_Error( 'mystery_failure', 'Unknown.' );

		$result = extrachill_api_content_blocks_photo_contest_vote_handler( $this->vote_request() );

		$this->assertWPError( $result );
		$this->assertSame( 'mystery_failure', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	/** Admission owns the client IP; it is never forwarded as an ability input. */
	public function test_vote_does_not_forward_ip() {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.4';

		$response = rest_get_server()->dispatch( $this->vote_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'ip', $this->ability_inputs['extrachill/photo-contest-vote'][0] );
	}

	/** Submit and vote meter independent public-write budgets: 429 with Retry-After once over the cap. */
	public function test_write_routes_rate_limited_once_over_cap() {
		$defaults = array();
		add_filter(
			'extrachill_api_photo_contest_submit_rate_limit',
			function ( $limit ) use ( &$defaults ) {
				$defaults['submit'] = $limit;
				return 1;
			}
		);
		add_filter(
			'extrachill_api_photo_contest_vote_rate_limit',
			function ( $limit ) use ( &$defaults ) {
				$defaults['vote'] = $limit;
				return 1;
			}
		);

		$first = rest_get_server()->dispatch( $this->submit_request() );
		$this->assertSame( 200, $first->get_status() );

		$blocked = rest_get_server()->dispatch( $this->submit_request() );
		$this->assertSame( 429, $blocked->get_status() );
		$this->assertSame( 'public_write_rate_limited', $blocked->get_data()['code'] );
		$this->assertArrayHasKey( 'Retry-After', $blocked->get_data()['data']['headers'] );
		$this->assertCount( 1, $this->ability_inputs['extrachill/photo-contest-submit'] );

		// The vote scope is unaffected by the exhausted submit budget.
		$this->assertTrue( extrachill_api_content_blocks_photo_contest_vote_permission( $this->vote_request() ) );
		$vote_blocked = extrachill_api_content_blocks_photo_contest_vote_permission( $this->vote_request() );
		$this->assertWPError( $vote_blocked );
		$this->assertSame( 'public_write_rate_limited', $vote_blocked->get_error_code() );
		$this->assertSame( 429, $vote_blocked->get_error_data()['status'] );
		$this->assertArrayHasKey( 'Retry-After', $vote_blocked->get_error_data()['headers'] );

		// The filters receive the documented default caps.
		$this->assertSame(
			array(
				'submit' => 5,
				'vote'   => 30,
			),
			$defaults
		);
	}

	/** REST-visible write abilities are refused with 503 before execution. */
	public function test_rest_visible_write_abilities_are_refused() {
		$this->register_stub_ability( 'extrachill/photo-contest-vote', true );
		$this->register_stub_ability( 'extrachill/photo-contest-submit', true );

		$vote_refused   = extrachill_api_content_blocks_photo_contest_vote_handler( $this->vote_request() );
		$submit_refused = extrachill_api_content_blocks_photo_contest_submit_handler( $this->submit_request() );

		foreach ( array( $vote_refused, $submit_refused ) as $refused ) {
			$this->assertWPError( $refused );
			$this->assertSame( 'photo_contest_unavailable', $refused->get_error_code() );
			$this->assertSame( 503, $refused->get_error_data()['status'] );
		}
		$this->assertSame( array(), $this->ability_inputs );
	}

	/** Vote success returns the public {message, vote_count} envelope. */
	public function test_vote_success_envelope() {
		$this->ability_results['extrachill/photo-contest-vote'] = array(
			'message'    => 'Vote counted.',
			'vote_count' => 12,
			'internal'   => 'not exposed',
		);

		$response = extrachill_api_content_blocks_photo_contest_vote_handler( $this->vote_request() );

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
				'contest_id'    => 'contest-1',
				'entry_id'      => 7,
				'email_address' => 'voter@example.com',
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

		$response = extrachill_api_content_blocks_photo_contest_entries_handler( $this->entries_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'entries' => $entries ), $response->get_data() );
		$this->assertSame( 'public, max-age=15', $response->get_headers()['Cache-Control'] );
		$this->assertSame(
			array(
				'post_id'    => 5,
				'contest_id' => 'contest-1',
			),
			$this->ability_inputs['extrachill/photo-contest-list'][0]
		);
	}

	/** Entries contest_not_found maps to 404. */
	public function test_entries_contest_not_found_maps_to_404() {
		$this->ability_results['extrachill/photo-contest-list'] = new WP_Error( 'contest_not_found', 'No contest.' );

		$result = extrachill_api_content_blocks_photo_contest_entries_handler( $this->entries_request( 'missing' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'contest_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
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
					'contest_id'   => 'contest-1',
					'email'        => 'entrant@example.com',
					'display_name' => 'Ghoul',
					'turnstile_response' => 'test-token',
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
				'contest_id'    => 'contest-1',
				'entry_id'      => 7,
				'email_address' => 'voter@example.com',
			)
		);

		return $request;
	}

	/** Build a valid entries request. */
	private function entries_request( $contest_id = 'contest-1' ) {
		$request = new WP_REST_Request( 'GET', '/extrachill/v1/content-blocks/photo-contest/entries' );
		$request->set_query_params(
			array(
				'post_id'    => 5,
				'contest_id' => $contest_id,
			)
		);

		return $request;
	}

	/**
	 * Register a controlled ability that records inputs and returns the stubbed result.
	 *
	 * @param string $ability_name Ability name.
	 * @param bool   $show_in_rest Whether the stub is REST-visible (content-blocks#34 ships false).
	 */
	private function register_stub_ability( $ability_name, $show_in_rest = false ) {
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
				'meta'                => array( 'show_in_rest' => (bool) $show_in_rest ),
			)
		);
	}

	/** Return the deterministic test counter. */
	public function use_test_rate_limit_store() {
		return array( $this, 'increment_test_rate_limit' );
	}

	/** Increment one deterministic counter. */
	public function increment_test_rate_limit( $key ) {
		$this->rate_counts[ $key ] = ( $this->rate_counts[ $key ] ?? 0 ) + 1;
		return $this->rate_counts[ $key ];
	}
}
