<?php
/**
 * Tests for the privacy-safe booking availability facade.
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises transport behavior through a controlled Events ability double. */
class Booking_AvailabilityTest extends WP_UnitTestCase {

	/** @var array<int, array<string, mixed>> */
	private $ability_inputs = array();

	/** @var int[] */
	private $ability_actor_ids = array();

	/** @var mixed */
	private $ability_result = array( 'available' => true );

	/** @var array<string, int> */
	private $rate_counts = array();

	/** Install the controlled hidden ability and atomic rate-limit store. */
	public function set_up() {
		parent::set_up();
		$this->ability_inputs    = array();
		$this->ability_actor_ids = array();
		$this->ability_result    = array( 'available' => true );
		$this->rate_counts       = array();
		$_SERVER['REMOTE_ADDR']  = '198.51.100.' . random_int( 1, 250 );
		add_filter( 'extrachill_api_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );
	}

	/** Remove test-owned global state. */
	public function tear_down() {
		wp_set_current_user( 0 );
		wp_clear_auth_cookie();
		remove_all_filters( 'extrachill_api_booking_availability_rate_limit' );
		remove_filter( 'extrachill_api_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );
		if ( isset( wp_get_abilities()[ EXTRACHILL_API_BOOKING_AVAILABILITY_ABILITY ] ) ) {
			wp_unregister_ability( EXTRACHILL_API_BOOKING_AVAILABILITY_ABILITY );
		}
		if ( wp_has_ability_category( 'booking-availability-tests' ) ) {
			wp_unregister_ability_category( 'booking-availability-tests' );
		}
		parent::tear_down();
	}

	/** The public POST route uses the existing Events venue-segment affinity. */
	public function test_route_registration_and_events_affinity() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$route  = $routes['/extrachill/v1/venues/(?P<venue>\d+)/booking-availability'][0];

		$this->assertSame( 'extrachill_api_booking_availability_permission', $route['permission_callback'] );
		$this->assertSame( 'events', extrachill_api_add_booking_route_affinity( array() )['/extrachill/v1/venues/'] );
	}

	/** The controlled Events dependency is resolvable but remains REST-hidden. */
	public function test_hidden_ability_fixture_is_registered() {
		$this->register_ability( false );
		$ability = wp_get_ability( EXTRACHILL_API_BOOKING_AVAILABILITY_ABILITY );

		$this->assertNotNull( $ability );
		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
	}

	/** The adapter sends only the Events schema and returns only its boolean. */
	public function test_strict_input_and_exact_success_projection() {
		$this->register_ability( false );
		$this->ability_result = array(
			'available'     => false,
			'booking_id'    => 99,
			'conflict_type' => 'private',
		);
		$response             = extrachill_api_handle_booking_availability( $this->valid_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'available' => false ), $response->get_data() );
		$this->assertSame(
			array(
				'venue_term_id'       => 42,
				'requested_space_key' => 'main-room',
				'requested_start_at'  => '2026-08-10 20:00:00',
				'requested_end_at'    => '2026-08-10 22:00:00',
			),
			$this->ability_inputs[0]
		);
	}

	/** Caller identity remains ambient and can never be supplied in the body. */
	public function test_anonymous_and_authenticated_callers_have_identical_projection() {
		$this->register_ability( false );
		$anonymous = extrachill_api_handle_booking_availability( $this->valid_request() );
		$user_id   = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$authenticated = extrachill_api_handle_booking_availability( $this->valid_request() );

		$this->assertSame( $anonymous->get_data(), $authenticated->get_data() );
		$this->assertSame( array( 0, $user_id ), $this->ability_actor_ids );
		$this->assertArrayNotHasKey( 'user_id', $this->ability_inputs[1] );

		$request = $this->valid_request( array( 'user_id' => $user_id ) );
		$error   = extrachill_api_handle_booking_availability( $request );
		$this->assert_fixed_validation_error( $error );
	}

	/** Every malformed public request receives the same fixed 400. */
	public function test_invalid_and_mismatched_requests_use_one_fixed_error() {
		$requests = array(
			$this->valid_request( array( 'venue' => 43 ) ),
			$this->valid_request( array( 'requested_space_key' => 'Main Room' ) ),
			$this->valid_request( array( 'requested_start_at' => '2026-02-30 20:00:00' ) ),
			$this->valid_request( array( 'requested_end_at' => '2026-08-10 20:00:00' ) ),
			$this->valid_request( array( 'contact_email' => 'private@example.com' ) ),
		);
		$query = $this->valid_request();
		$query->set_query_params( array( 'debug' => '1' ) );
		$requests[] = $query;

		foreach ( $requests as $request ) {
			$this->assert_fixed_validation_error( extrachill_api_handle_booking_availability( $request ) );
		}
		$this->assertEmpty( $this->ability_inputs );
	}

	/** Missing, REST-visible, failed, and malformed dependencies converge on one 503. */
	public function test_dependency_failures_use_one_generic_retryable_error() {
		$this->assert_service_error( extrachill_api_handle_booking_availability( $this->valid_request() ) );

		$this->register_ability( true );
		$this->assert_service_error( extrachill_api_handle_booking_availability( $this->valid_request() ) );

		wp_unregister_ability( EXTRACHILL_API_BOOKING_AVAILABILITY_ABILITY );
		$this->ability_result = new WP_Error( 'booking_conflict_check_failed', 'Database path /private/root.', array( 'database_error' => 'secret' ) );
		$this->register_ability( false );
		$this->assert_service_error( extrachill_api_handle_booking_availability( $this->valid_request() ) );

		$this->ability_result = array( 'available' => 'yes' );
		$this->assert_service_error( extrachill_api_handle_booking_availability( $this->valid_request() ) );
	}

	/** Known owner validation is fixed at 400 while service errors remain generic. */
	public function test_owner_error_projection_never_exposes_internal_data() {
		$invalid = extrachill_api_booking_availability_public_error( new WP_Error( 'booking_availability_interval_invalid', 'Owner details.', array( 'status' => 400, 'field' => 'space' ) ) );
		$service = extrachill_api_booking_availability_public_error( new WP_Error( 'booking_hold_lock_not_acquired', 'Lock owner 123.', array( 'status' => 503, 'lock' => 'secret' ) ) );

		$this->assert_fixed_validation_error( $invalid );
		$this->assert_service_error( $service );
		$this->assertStringNotContainsString( 'Owner', wp_json_encode( $invalid ) );
		$this->assertStringNotContainsString( 'Lock', wp_json_encode( $service ) );
	}

	/** The advisory endpoint consumes the public-read budget, not Turnstile. */
	public function test_public_read_rate_limit_returns_stable_429() {
		add_filter( 'extrachill_api_booking_availability_rate_limit', static function () { return 1; } );
		$request = $this->valid_request();

		$this->assertTrue( extrachill_api_booking_availability_permission( $request ) );
		$blocked = extrachill_api_booking_availability_permission( $request );

		$this->assertWPError( $blocked );
		$this->assertSame( 'public_read_rate_limited', $blocked->get_error_code() );
		$this->assertSame( 429, $blocked->get_error_data()['status'] );
		$this->assertArrayHasKey( 'Retry-After', $blocked->get_error_data()['headers'] );
		$this->assertNull( $request->get_param( 'turnstile_response' ) );
	}

	/**
	 * Transport fixtures cover every owner-policy outcome without duplicating policy.
	 *
	 * @dataProvider availability_fixture_provider
	 */
	public function test_policy_outcomes_pass_through_controlled_ability( $label, $start_at, $end_at, $available ) {
		unset( $label );
		$this->register_ability( false );
		$this->ability_result = array( 'available' => $available );
		$response             = extrachill_api_handle_booking_availability(
			$this->valid_request(
				array(
					'requested_start_at' => $start_at,
					'requested_end_at'   => $end_at,
				)
			)
		);

		$this->assertSame( array( 'available' => $available ), $response->get_data() );
	}

	/** Return controlled open/conflict and boundary fixtures. */
	public function availability_fixture_provider() {
		return array(
			'open interval'          => array( 'open', '2026-08-10 18:00:00', '2026-08-10 20:00:00', true ),
			'pending inquiry'        => array( 'pending', '2026-08-10 18:00:00', '2026-08-10 20:00:00', true ),
			'active hold'            => array( 'held', '2026-08-10 18:00:00', '2026-08-10 20:00:00', false ),
			'confirmed booking'      => array( 'confirmed', '2026-08-10 18:00:00', '2026-08-10 20:00:00', false ),
			'canonical event'        => array( 'canonical', '2026-08-10 18:00:00', '2026-08-10 20:00:00', false ),
			'half-open boundary'     => array( 'half-open', '2026-08-10 20:00:00', '2026-08-10 22:00:00', true ),
			'same-day non-overlap'   => array( 'same-day', '2026-08-10 14:00:00', '2026-08-10 16:00:00', true ),
		);
	}

	/** Register the exact hidden ability contract used by Events PR #530. */
	private function register_ability( $show_in_rest ) {
		if ( isset( wp_get_abilities()[ EXTRACHILL_API_BOOKING_AVAILABILITY_ABILITY ] ) ) {
			wp_unregister_ability( EXTRACHILL_API_BOOKING_AVAILABILITY_ABILITY );
		}
		if ( ! wp_has_ability_category( 'booking-availability-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'booking-availability-tests',
				array(
					'label'       => 'Booking availability tests',
					'description' => 'Controlled hidden availability contract.',
				)
			);
		}
		$test = $this;
		WP_Abilities_Registry::get_instance()->register(
			EXTRACHILL_API_BOOKING_AVAILABILITY_ABILITY,
			array(
				'label'               => 'Check booking availability',
				'description'         => 'Controlled booking availability.',
				'category'            => 'booking-availability-tests',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'venue_term_id'       => array( 'type' => 'integer' ),
						'requested_space_key' => array( 'type' => 'string' ),
						'requested_start_at'  => array( 'type' => 'string' ),
						'requested_end_at'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'venue_term_id', 'requested_space_key', 'requested_start_at', 'requested_end_at' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) use ( $test ) {
					$test->ability_inputs[]    = $input;
					$test->ability_actor_ids[] = get_current_user_id();
					return $test->ability_result;
				},
				'meta'                => array( 'show_in_rest' => (bool) $show_in_rest ),
			)
		);
	}

	/** Build a canonical JSON request, optionally replacing or adding fields. */
	private function valid_request( array $changes = array() ) {
		$body = array_merge(
			array(
				'venue'               => 42,
				'requested_space_key' => 'main-room',
				'requested_start_at'  => '2026-08-10 20:00:00',
				'requested_end_at'    => '2026-08-10 22:00:00',
			),
			$changes
		);
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/venues/42/booking-availability' );
		$request->set_url_params( array( 'venue' => '42' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	/** Assert the exact fixed validation contract. */
	private function assert_fixed_validation_error( $error ) {
		$this->assertWPError( $error );
		$this->assertSame( 'booking_availability_invalid_request', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame( array( 'status' => 400 ), $error->get_error_data() );
	}

	/** Assert the exact generic retryable dependency contract. */
	private function assert_service_error( $error ) {
		$this->assertWPError( $error );
		$this->assertSame( 'booking_availability_unavailable', $error->get_error_code() );
		$this->assertSame( array( 'status' => 503, 'retryable' => true ), $error->get_error_data() );
		$this->assertStringNotContainsString( 'private', strtolower( $error->get_error_message() ) );
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
