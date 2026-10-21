<?php
/**
 * Tests for the event submission REST adapter.
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the current ability-backed event submission contract. */
class Event_SubmissionsTest extends WP_UnitTestCase {

	/** @var array */
	private $ability_inputs = array();

	/** Register a controlled submit-event ability. */
	public function set_up() {
		parent::set_up();

		if ( ! wp_has_ability_category( 'extrachill-api-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'extrachill-api-tests',
				array(
					'label'       => 'Extra Chill API Tests',
					'description' => 'Controlled abilities for API transport tests.',
				)
			);
		}

		WP_Abilities_Registry::get_instance()->register(
			'extrachill/submit-event',
			array(
				'label'               => 'Test event submission',
				'description'         => 'Controlled event submission ability.',
				'category'            => 'extrachill-api-tests',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'execute_submission' ),
				'permission_callback' => '__return_true',
			)
		);

		do_action( 'rest_api_init' );
	}

	/** Remove the controlled ability. */
	public function tear_down() {
		wp_unregister_ability( 'extrachill/submit-event' );
		parent::tear_down();
	}

	/** The public route remains registered at the compatibility boundary. */
	public function test_route_is_registered() {
		$this->assertArrayHasKey( '/extrachill/v1/event-submissions', rest_get_server()->get_routes() );
	}

	/** Sanitized request fields are forwarded to the canonical ability. */
	public function test_handler_forwards_sanitized_input() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-submissions' );
		$request->set_param( 'event_title', '<b>Live</b> at The Royal American' );
		$request->set_param( 'event_date', '2026-08-01' );
		$request->set_param( 'event_link', 'javascript:alert(1)' );
		$request->set_param( 'notes', "Doors <script>alert(1)</script> at 8" );

		$response = extrachill_api_handle_event_submission( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'accepted' => true ), $response->get_data() );
		$this->assertSame( 'Live at The Royal American', $this->ability_inputs[0]['event_title'] );
		$this->assertSame( '', $this->ability_inputs[0]['event_link'] );
		$this->assertSame( 'Doors  at 8', $this->ability_inputs[0]['notes'] );
	}

	/** A venue picked from suggestions is forwarded as an integer term ID (extrachill-events#965). */
	public function test_handler_forwards_chosen_venue_term_id() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-submissions' );
		$request->set_param( 'event_title', 'A Nightmare on King Street' );
		$request->set_param( 'event_date', '2026-10-18' );
		$request->set_param( 'venue_name', 'Recovery Room Tavern' );
		$request->set_param( 'venue_term_id', '104506' );

		extrachill_api_handle_event_submission( $request );

		$this->assertSame( 104506, $this->ability_inputs[0]['venue_term_id'] );
		$this->assertSame( 'Recovery Room Tavern', $this->ability_inputs[0]['venue_name'] );
	}

	/** A free-text venue with no pick forwards a zero term ID. */
	public function test_handler_forwards_zero_venue_term_id_for_free_text_venue() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-submissions' );
		$request->set_param( 'event_title', 'Basement Show' );
		$request->set_param( 'event_date', '2026-10-18' );
		$request->set_param( 'venue_name', 'Brand New Room' );

		extrachill_api_handle_event_submission( $request );

		$this->assertSame( 0, $this->ability_inputs[0]['venue_term_id'] );
		$this->assertSame( 'Brand New Room', $this->ability_inputs[0]['venue_name'] );
	}

	/**
	 * Run a request through the route's own argument validation and sanitization.
	 *
	 * @param array $params Request params.
	 * @return WP_REST_Request|WP_Error Request on success, error on invalid params.
	 */
	private function validated_request( array $params ) {
		$routes  = rest_get_server()->get_routes();
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-submissions' );
		$request->set_attributes( array( 'args' => $routes['/extrachill/v1/event-submissions'][0]['args'] ) );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$valid = $request->has_valid_params();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$sanitized = $request->sanitize_params();
		return is_wp_error( $sanitized ) ? $sanitized : $request;
	}

	/** The form posts venue_term_id="" for a typed venue; it must not 400 (#201). */
	public function test_route_accepts_empty_venue_term_id() {
		$request = $this->validated_request(
			array(
				'event_title'   => 'Basement Show',
				'event_date'    => '2026-10-18',
				'venue_name'    => 'Brand New Room',
				'venue_term_id' => '',
			)
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		extrachill_api_handle_event_submission( $request );
		$this->assertSame( 0, $this->ability_inputs[0]['venue_term_id'] );
	}

	/** A picked venue still validates and forwards as an integer. */
	public function test_route_accepts_numeric_venue_term_id() {
		$request = $this->validated_request(
			array(
				'event_title'   => 'A Nightmare on King Street',
				'event_date'    => '2026-10-18',
				'venue_name'    => 'Recovery Room Tavern',
				'venue_term_id' => '104506',
			)
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		extrachill_api_handle_event_submission( $request );
		$this->assertSame( 104506, $this->ability_inputs[0]['venue_term_id'] );
	}

	/** Garbage term IDs are still rejected. */
	public function test_route_rejects_non_numeric_venue_term_id() {
		$result = $this->validated_request(
			array(
				'event_title'   => 'Basement Show',
				'event_date'    => '2026-10-18',
				'venue_name'    => 'Brand New Room',
				'venue_term_id' => 'abc',
			)
		);

		$this->assertWPError( $result );
	}

	/** Controlled ability callback. */
	public function execute_submission( array $input ) {
		$this->ability_inputs[] = $input;
		return array( 'accepted' => true );
	}
}
