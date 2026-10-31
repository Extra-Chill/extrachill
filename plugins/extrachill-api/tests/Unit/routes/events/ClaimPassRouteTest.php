<?php
/**
 * Tests for the guest list claim REST adapter (extrachill#433).
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the logged-in, rate-limited guest list claim transport. */
class ClaimPassRouteTest extends WP_UnitTestCase {

	/** @var array */
	private $ability_inputs = array();

	/** @var array|WP_Error */
	private $ability_result = array();

	/** Register a controlled claim-event-pass ability. */
	public function set_up() {
		parent::set_up();

		$this->ability_inputs = array();
		$this->ability_result = array(
			'issued'    => true,
			'code'      => 'ABCDE-FGHJK-LMNPQ',
			'perk_text' => 'Extra Chill guest list',
			'status'    => 'active',
		);

		if ( ! wp_has_ability_category( 'extrachill-api-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'extrachill-api-tests',
				array(
					'label'       => 'Extra Chill API Tests',
					'description' => 'Controlled abilities for API transport tests.',
				)
			);
		}

		if ( wp_has_ability( 'extrachill/claim-event-pass' ) ) {
			wp_unregister_ability( 'extrachill/claim-event-pass' );
		}

		WP_Abilities_Registry::get_instance()->register(
			'extrachill/claim-event-pass',
			array(
				'label'               => 'Test claim pass',
				'description'         => 'Controlled claim ability.',
				'category'            => 'extrachill-api-tests',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'execute_claim' ),
				'permission_callback' => '__return_true',
			)
		);

		do_action( 'rest_api_init' );
	}

	/** Remove the controlled ability. */
	public function tear_down() {
		wp_unregister_ability( 'extrachill/claim-event-pass' );
		remove_all_filters( 'extrachill_api_claim_pass_rate_limit' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** The route is registered on the events site. */
	public function test_route_is_registered_with_events_affinity() {
		$this->assertArrayHasKey( '/extrachill/v1/events/(?P<id>\d+)/claim-pass', rest_get_server()->get_routes() );
		$this->assertSame( 'events', extrachill_api_rest_ability_route_site( '/extrachill/v1/events/(?P<id>\d+)/claim-pass' ) );
	}

	/** Logged-out callers are refused before the ability runs. */
	public function test_permission_requires_login() {
		wp_set_current_user( 0 );

		$result = extrachill_api_claim_pass_permission( new WP_REST_Request( 'POST', '/extrachill/v1/events/1/claim-pass' ) );

		$this->assertWPError( $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/** Handler maps the path ID and forwards the password verbatim. */
	public function test_handler_forwards_input_and_returns_pass() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/events/640348/claim-pass' );
		$request->set_param( 'id', '640348' );
		$request->set_param( 'password', ' Spooky <31> ' );

		$response = extrachill_api_handle_claim_pass( $request );

		$this->assertSame(
			array(
				'event_id' => 640348,
				'password' => ' Spooky <31> ',
			),
			$this->ability_inputs[0]
		);
		$this->assertSame( 'ABCDE-FGHJK-LMNPQ', $response->get_data()['code'] );
	}

	/** Ability errors (wrong password, full list) pass through. */
	public function test_ability_error_passes_through() {
		$this->ability_result = new WP_Error( 'guest_list_full', 'The guest list is full.', array( 'status' => 409 ) );

		$request = new WP_REST_Request( 'POST', '/extrachill/v1/events/1/claim-pass' );
		$request->set_param( 'id', 1 );

		$result = extrachill_api_handle_claim_pass( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'guest_list_full', $result->get_error_code() );
	}

	/** Controlled ability callback. */
	public function execute_claim( $input ) {
		$this->ability_inputs[] = $input;
		return $this->ability_result;
	}
}
