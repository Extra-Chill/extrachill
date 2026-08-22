<?php
/**
 * Tests for artist booking inquiry follow-through transport.
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the thin route-affine adapters around Events-owned abilities. */
class Booking_Inquiry_Follow_Through_Test extends WP_UnitTestCase {

	/** @var array<string, array> */
	private $inputs = array();

	/** @var array<string, mixed> */
	private $results = array();

	/** @var array<string, int> */
	private $rate_counts = array();

	/** @var array<string, int> */
	private $actor_ids = array();

	/** @var array<string, int> */
	private $side_effects = array();

	/** @var array<string, string> */
	private $abilities = array(
		'status'           => EXTRACHILL_API_BOOKING_STATUS_ABILITY,
		'correction'       => EXTRACHILL_API_BOOKING_CORRECTION_ABILITY,
		'withdrawal'       => EXTRACHILL_API_BOOKING_WITHDRAWAL_ABILITY,
		'receipt-recovery' => EXTRACHILL_API_BOOKING_RECOVERY_ABILITY,
	);

	public function set_up() {
		parent::set_up();
		$this->inputs      = array();
		$this->actor_ids   = array();
		$this->rate_counts = array();
		$this->side_effects = array_fill_keys( array_keys( $this->abilities ), 0 );
		$this->results     = array(
			'status'           => $this->status_result(),
			'correction'       => array(
				'venue_term_id' => 42,
				'public_id'     => $this->public_id(),
				'operation'     => 'correction_requested',
				'version'       => 2,
			),
			'withdrawal'       => array(
				'venue_term_id' => 42,
				'public_id'     => $this->public_id(),
				'operation'     => 'withdrawn',
				'status'        => 'withdrawn',
				'status_label'  => 'Withdrawn',
				'version'       => 3,
			),
			'receipt-recovery' => array(
				'venue_term_id' => 42,
				'public_id'     => $this->public_id(),
				'operation'     => 'receipt_resend_requested',
			),
		);
		remove_filter( 'rest_pre_dispatch', 'extrachill_api_route_affinity_dispatch', 10 );
		add_filter( 'extrachill_api_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );
		foreach ( $this->abilities as $operation => $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
			$this->register_ability( $operation, $name );
		}
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int( 1, 250 );
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		remove_all_filters( 'extrachill_bypass_turnstile_verification' );
		remove_all_filters( 'extrachill_api_booking_follow_through_read_rate_limit' );
		remove_all_filters( 'extrachill_api_booking_follow_through_write_rate_limit' );
		remove_filter( 'extrachill_api_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );
		foreach ( $this->abilities as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		if ( isset( wp_get_ability_categories()['booking-follow-through-test'] ) ) {
			wp_unregister_ability_category( 'booking-follow-through-test' );
		}
		add_filter( 'rest_pre_dispatch', 'extrachill_api_route_affinity_dispatch', 10, 3 );
		parent::tear_down();
	}

	public function test_routes_register_under_venue_affinity_with_exact_hidden_abilities() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();

		foreach ( $this->abilities as $operation => $ability_name ) {
			$route = '/extrachill/v1/venues/(?P<venue>\d+)/booking-inquiries/follow-through/' . $operation;
			$this->assertArrayHasKey( $route, $routes );
			$this->assertSame( array( $ability_name ), $routes[ $route ][0]['_extrachill_abilities'] );
			$this->assertFalse( wp_get_ability( $ability_name )->get_meta_item( 'show_in_rest' ) );
		}
		$this->assertSame( 'events', extrachill_api_add_booking_route_affinity( array() )['/extrachill/v1/venues/'] );
	}

	public function test_status_accepts_lowercase_body_capability_and_uses_canonical_identity() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$response = $this->dispatch( $this->request( 'status' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'public_id' => $this->public_id(), 'venue_term_id' => 42, 'capability' => str_repeat( 'a', 64 ) ), $this->inputs['status'] );
		$this->assertSame( $user_id, $this->actor_ids['status'] );
		$this->assertArrayNotHasKey( 'user_id', $this->inputs['status'] );
	}

	public function test_authenticated_status_does_not_require_capability() {
		wp_set_current_user( self::factory()->user->create() );
		$request = $this->request( 'status' );
		$body    = $request->get_body_params();
		unset( $body['capability'] );
		$request->set_body_params( $body );

		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'capability', $this->inputs['status'] );
		$this->assertSame( 42, $this->inputs['status']['venue_term_id'] );
	}

	public function test_uppercase_capability_is_rejected_by_rest_schema() {
		$response = $this->dispatch( $this->request( 'status', array( 'capability' => strtoupper( str_repeat( 'a', 64 ) ) ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertEmpty( $this->inputs );
	}

	public function test_capability_is_body_only_and_submitted_identity_is_rejected() {
		$query = $this->request( 'status' );
		$query->set_query_params( array( 'capability' => str_repeat( 'a', 64 ) ) );
		$invalid_location = $this->dispatch( $query );
		$this->assertSame( 400, $invalid_location->get_status() );
		$this->assertSame( 'booking_capability_location_invalid', $invalid_location->get_data()['code'] );

		$identity = $this->request( 'status', array( 'user_id' => 99 ) );
		$invalid_identity = $this->dispatch( $identity );
		$this->assertSame( 400, $invalid_identity->get_status() );
		$this->assertSame( 'booking_identity_not_allowed', $invalid_identity->get_data()['code'] );
		$this->assertEmpty( $this->inputs );
	}

	public function test_recovery_rejects_client_attestations_and_forwards_only_canonical_fields() {
		$request = $this->request( 'receipt-recovery', array( 'contact_verified' => true ) );
		$rejected = $this->dispatch( $request );
		$this->assertSame( 400, $rejected->get_status() );
		$this->assertSame( 'booking_attestation_not_allowed', $rejected->get_data()['code'] );

		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		$response = $this->dispatch( $this->request( 'receipt-recovery' ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( array( 'accepted' => true ), $response->get_data() );
		$this->assertSame(
			array(
				'public_id'       => $this->public_id(),
				'venue_term_id'   => 42,
				'idempotency_key' => 'retry-123',
				'contact_email'   => 'artist@example.com',
			),
			$this->inputs['receipt-recovery']
		);
	}

	public function test_trusted_path_venue_prevents_mutation_and_recovery_side_effects() {
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		foreach ( array( 'correction', 'withdrawal', 'receipt-recovery' ) as $operation ) {
			$response = $this->dispatch( $this->request( $operation, array(), 99 ) );

			$this->assertSame( 'receipt-recovery' === $operation ? 202 : 404, $response->get_status(), $operation );
			$this->assertSame( 99, $this->inputs[ $operation ]['venue_term_id'], $operation );
			$this->assertSame( 0, $this->side_effects[ $operation ], $operation );
		}
	}

	public function test_client_venue_override_never_replaces_trusted_path_venue() {
		$response = $this->dispatch( $this->request( 'status', array( 'venue' => 999 ), 42 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 42, $this->inputs['status']['venue_term_id'] );
	}

	public function test_write_turnstile_and_rate_limit_run_before_execution() {
		add_filter( 'extrachill_api_booking_follow_through_write_rate_limit', static fn() => 1 );
		$turnstile = $this->dispatch( $this->request( 'correction', array( 'turnstile_response' => '' ) ) );
		$this->assertSame( 403, $turnstile->get_status() );
		$this->assertEmpty( $this->inputs );

		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		$accepted = $this->dispatch( $this->request( 'correction' ) );
		$this->assertSame( 200, $accepted->get_status() );
		$this->assertArrayNotHasKey( 'turnstile_response', $this->inputs['correction'] );
		$blocked = $this->dispatch( $this->request( 'correction' ) );
		$this->assertSame( 429, $blocked->get_status() );
		$this->assertSame( 'public_write_rate_limited', $blocked->get_data()['code'] );
		$this->assertNotEmpty( $blocked->get_headers()['Retry-After'] ?? $blocked->get_headers()['retry-after'] ?? null );
	}

	public function test_status_has_a_bounded_public_read_rate_limit() {
		add_filter( 'extrachill_api_booking_follow_through_read_rate_limit', static fn() => 1 );
		$this->assertSame( 200, $this->dispatch( $this->request( 'status' ) )->get_status() );
		$blocked = $this->dispatch( $this->request( 'status' ) );

		$this->assertSame( 429, $blocked->get_status() );
		$this->assertSame( 'public_read_rate_limited', $blocked->get_data()['code'] );
		$this->assertNotEmpty( $blocked->get_headers()['Retry-After'] ?? $blocked->get_headers()['retry-after'] ?? null );
	}

	public function test_unknown_wrong_token_and_wrong_user_share_one_non_enumerating_error() {
		foreach ( array( 'unknown', 'wrong-token', 'wrong-user' ) as $case ) {
			$this->results['status'] = new WP_Error( 'booking_inquiry_forbidden', 'Private ' . $case, array( 'status' => 403, 'booking_id' => 123 ) );
			$error = $this->dispatch( $this->request( 'status' ) );
			$this->assertSame( 404, $error->get_status() );
			$this->assertSame( 'booking_inquiry_unavailable', $error->get_data()['code'] );
			$this->assertStringNotContainsString( 'Private', $error->get_data()['message'] );
			$this->assertArrayNotHasKey( 'booking_id', $error->get_data()['data'] );
		}
	}

	public function test_stale_version_preserves_only_current_version() {
		$this->results['correction'] = new WP_Error(
			'booking_version_conflict',
			'Database row changed.',
			array(
				'status'          => 409,
				'current_version' => 7,
				'private_path'    => '/srv/private',
			)
		);
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		$error = $this->dispatch( $this->request( 'correction' ) );

		$this->assertSame( 409, $error->get_status() );
		$this->assertSame( 'booking_version_conflict', $error->get_data()['code'] );
		$this->assertSame( array( 'status' => 409, 'current_version' => 7 ), $error->get_data()['data'] );
		$this->assertStringNotContainsString( 'Database', $error->get_data()['message'] );
	}

	public function test_recovery_is_neutral_for_matched_unmatched_and_inapplicable_inquiries() {
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		foreach ( array( $this->results['receipt-recovery'], new WP_Error( 'booking_receipt_recovery_forbidden', 'No match.', array( 'status' => 403 ) ), new WP_Error( 'booking_receipt_recovery_status_forbidden', 'Wrong status.', array( 'status' => 409 ) ) ) as $result ) {
			$this->results['receipt-recovery'] = $result;
			$response = $this->dispatch( $this->request( 'receipt-recovery' ) );
			$this->assertSame( 202, $response->get_status() );
			$this->assertSame( array( 'accepted' => true ), $response->get_data() );
		}
	}

	public function test_recovery_infrastructure_failure_remains_actionable_and_private() {
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		$this->results['receipt-recovery'] = new WP_Error( 'booking_receipt_recovery_unavailable', 'Queue /srv/private failed.', array( 'status' => 503, 'queue_id' => 9 ) );
		$response = $this->dispatch( $this->request( 'receipt-recovery' ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'booking_follow_through_unavailable', $response->get_data()['code'] );
		$this->assertStringNotContainsString( '/srv/private', wp_json_encode( $response->get_data() ) );
		$this->assertArrayNotHasKey( 'queue_id', $response->get_data()['data'] );
	}

	public function test_venue_affinity_is_required_for_every_operation_and_recovery_stays_neutral() {
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		foreach ( array_keys( $this->abilities ) as $operation ) {
			$valid = $this->results[ $operation ];
			foreach ( array( 'missing', 'malformed', 'mismatch' ) as $case ) {
				$this->results[ $operation ] = $valid;
				if ( 'missing' === $case ) {
					unset( $this->results[ $operation ]['venue_term_id'] );
				} elseif ( 'malformed' === $case ) {
					$this->results[ $operation ]['venue_term_id'] = '42';
				} else {
					$this->results[ $operation ]['venue_term_id'] = 99;
				}
				$response = $this->dispatch( $this->request( $operation ) );
				$this->assertSame( 'receipt-recovery' === $operation ? 202 : 404, $response->get_status(), $operation . ':' . $case );
				$this->assertArrayNotHasKey( 'venue_term_id', $response->get_data(), $operation . ':' . $case );
			}
		}
	}

	public function test_responses_strip_internal_venue_and_private_fields() {
		$this->results['status']['private_notes'] = 'secret';
		$this->results['status']['venue']['contact_email'] = 'private@example.com';
		$response = $this->dispatch( $this->request( 'status' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'venue_term_id', $response->get_data() );
		$this->assertArrayNotHasKey( 'private_notes', $response->get_data() );
		$this->assertSame( array( 'name' => 'The Royal American' ), $response->get_data()['venue'] );
	}

	public function test_capabilities_and_private_error_data_never_enter_responses_or_headers() {
		$capability              = str_repeat( 'b', 64 );
		$this->results['status'] = new WP_Error( 'booking_read_failed', 'Token ' . $capability, array( 'status' => 500, 'capability' => $capability ) );
		$response                = $this->dispatch( $this->request( 'status', array( 'capability' => $capability ) ) );
		$serialized              = wp_json_encode( array( $response->get_data(), $response->get_headers() ) );

		$this->assertStringNotContainsString( $capability, $serialized );
		$this->assertStringNotContainsString( 'Token', $serialized );
		$this->assertSame( 503, $response->get_status() );
	}

	public function test_all_endpoints_dispatch_and_never_forward_turnstile_or_internal_venue() {
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		$statuses = array(
			'status'           => 200,
			'correction'       => 200,
			'withdrawal'       => 200,
			'receipt-recovery' => 202,
		);
		foreach ( $statuses as $operation => $status ) {
			$response = $this->dispatch( $this->request( $operation ) );
			$this->assertSame( $status, $response->get_status(), $operation );
			$this->assertArrayNotHasKey( 'venue_term_id', $response->get_data(), $operation );
			$this->assertArrayNotHasKey( 'turnstile_response', $this->inputs[ $operation ], $operation );
		}
	}

	public function test_hidden_ability_metadata_must_be_exact_false() {
		wp_unregister_ability( EXTRACHILL_API_BOOKING_STATUS_ABILITY );
		$this->register_ability( 'status', EXTRACHILL_API_BOOKING_STATUS_ABILITY, true );
		$response = $this->dispatch( $this->request( 'status' ) );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'booking_follow_through_unavailable', $response->get_data()['code'] );
		$this->assertEmpty( $this->inputs );

		foreach ( array( null, true, array( 'invalid' ) ) as $meta ) {
			$ability = new class( $meta ) {
				private $meta;

				public function __construct( $meta ) {
					$this->meta = $meta;
				}

				public function get_meta_item() {
					return $this->meta;
				}
			};
			$this->assertFalse( extrachill_api_booking_ability_is_hidden( $ability ) );
		}
	}

	public function test_registered_routes_match_exact_canonical_ability_schemas() {
		do_action( 'rest_api_init' );
		$manifest = extrachill_api_rest_ability_adapter_manifest( rest_get_server()->get_routes( 'extrachill/v1' ) );
		$checked  = array();

		foreach ( $manifest as $adapter ) {
			$name = $adapter['contract']['ability'];
			if ( ! in_array( $name, $this->abilities, true ) ) {
				continue;
			}
			$this->assertSame( array(), extrachill_api_rest_ability_schema_findings( $adapter['endpoint'], wp_get_ability( $name ), $adapter['contract'] ), $name );
			$this->assertArrayHasKey( 'venue_term_id', $adapter['contract']['exceptions']['ability_only'], $name );
			$this->assertArrayHasKey( 'venue', $adapter['contract']['exceptions']['transport_only'], $name );
			$checked[] = $name;
		}

		$this->assertEqualsCanonicalizing( array_values( $this->abilities ), array_values( array_unique( $checked ) ) );
	}

	/** Register one controlled hidden Events contract. */
	private function register_ability( $operation, $name, $show_in_rest = false ) {
		if ( ! isset( wp_get_ability_categories()['booking-follow-through-test'] ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'booking-follow-through-test',
				array(
					'label'       => 'Booking follow-through test',
					'description' => 'Test-only hidden ability contracts.',
				)
			);
		}
		$test       = $this;
		$definition = array(
			'label'               => 'Booking follow-through fixture',
			'description'         => 'Controlled hidden Events contract.',
			'category'            => 'booking-follow-through-test',
			'input_schema'        => $this->ability_input_schema( $operation ),
			'output_schema'       => array( 'type' => 'object', 'additionalProperties' => true ),
			'permission_callback' => '__return_true',
			'execute_callback'    => static function ( $input ) use ( $operation, $test ) {
				$test->inputs[ $operation ] = $input;
				$test->actor_ids[ $operation ] = get_current_user_id();
				if ( 42 !== (int) ( $input['venue_term_id'] ?? 0 ) ) {
					return new WP_Error( 'receipt-recovery' === $operation ? 'booking_receipt_recovery_forbidden' : 'booking_inquiry_forbidden', 'Wrong venue.', array( 'status' => 403 ) );
				}
				++$test->side_effects[ $operation ];
				return $test->results[ $operation ];
			},
		);
		if ( null !== $show_in_rest ) {
			$definition['meta'] = array( 'show_in_rest' => $show_in_rest );
		}
		WP_Abilities_Registry::get_instance()->register(
			$name,
			$definition
		);
	}

	/** Return the exact final Events input schema for one controlled ability. */
	private function ability_input_schema( $operation ) {
		$properties = array(
			'public_id'     => array(
				'type'   => 'string',
				'format' => 'uuid',
			),
			'venue_term_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$required   = array( 'public_id', 'venue_term_id' );
		if ( 'receipt-recovery' !== $operation ) {
			$properties['capability'] = array(
				'type'      => 'string',
				'minLength' => 64,
				'maxLength' => 64,
			);
		}
		if ( in_array( $operation, array( 'correction', 'withdrawal' ), true ) ) {
			$properties['expected_version'] = array(
				'type'    => 'integer',
				'minimum' => 1,
			);
			$properties['idempotency_key']  = array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 120,
			);
			$required                            = array_merge( $required, array( 'expected_version', 'idempotency_key' ) );
		}
		if ( 'correction' === $operation ) {
			$properties['correction'] = array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 2000,
			);
			$required[]               = 'correction';
		}
		if ( 'receipt-recovery' === $operation ) {
			$properties['contact_email']   = array(
				'type'      => 'string',
				'format'    => 'email',
				'maxLength' => 255,
			);
			$properties['idempotency_key'] = array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 120,
			);
			$required = array_merge( $required, array( 'contact_email', 'idempotency_key' ) );
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/** Return a body-only request for one fixed route. */
	private function request( $operation, array $overrides = array(), $venue = 42 ) {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/venues/' . absint( $venue ) . '/booking-inquiries/follow-through/' . $operation );
		$bodies  = array(
			'status'           => array(
				'public_id'  => $this->public_id(),
				'capability' => str_repeat( 'a', 64 ),
			),
			'correction'       => array(
				'public_id'          => $this->public_id(),
				'capability'         => str_repeat( 'a', 64 ),
				'expected_version'   => 2,
				'idempotency_key'    => 'retry-123',
				'correction'         => 'Please correct the requested date.',
				'turnstile_response' => 'test-token',
			),
			'withdrawal'       => array(
				'public_id'          => $this->public_id(),
				'capability'         => str_repeat( 'a', 64 ),
				'expected_version'   => 2,
				'idempotency_key'    => 'retry-123',
				'turnstile_response' => 'test-token',
			),
			'receipt-recovery' => array(
				'public_id'          => $this->public_id(),
				'idempotency_key'    => 'retry-123',
				'contact_email'      => 'artist@example.com',
				'turnstile_response' => 'test-token',
			),
		);
		$request->set_body_params( array_merge( $bodies[ $operation ], $overrides ) );
		return $request;
	}

	/** Dispatch through WordPress route matching, validation, and permissions. */
	private function dispatch( WP_REST_Request $request ) {
		$route    = $request->get_route();
		$response = rest_do_request( $request );
		$request->set_route( $route );
		return extrachill_api_booking_transport_error_headers( $response, rest_get_server(), $request );
	}

	private function public_id() {
		return '11111111-1111-4111-8111-111111111111';
	}

	private function status_result() {
		return array(
			'venue_term_id'     => 42,
			'public_id'          => $this->public_id(),
			'venue'              => array( 'name' => 'The Royal American' ),
			'submitted_at'       => '2026-08-21 20:00:00',
			'updated_at'         => '2026-08-21 20:01:00',
			'status'             => 'submitted',
			'status_label'       => 'Pending review',
			'version'            => 2,
			'requested_interval' => array( 'start_at' => null, 'end_at' => null ),
			'requested_space'    => array( 'key' => null, 'label' => 'Not specified' ),
			'permitted_actions'  => array( 'request_correction', 'withdraw' ),
		);
	}

	public function use_test_rate_limit_store() {
		return array( $this, 'increment_test_rate_limit' );
	}

	public function increment_test_rate_limit( $key ) {
		$this->rate_counts[ $key ] = ( $this->rate_counts[ $key ] ?? 0 ) + 1;
		return $this->rate_counts[ $key ];
	}
}
