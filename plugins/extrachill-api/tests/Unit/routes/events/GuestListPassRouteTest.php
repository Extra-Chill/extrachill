<?php
/**
 * Tests for the guest list pass REST adapter (extrachill#433).
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the public, Turnstile-gated guest list pass transport. */
class GuestListPassRouteTest extends WP_UnitTestCase {

	/**
	 * Inputs the controlled ability received.
	 *
	 * @var array
	 */
	private $ability_inputs = array();

	/**
	 * What the controlled ability returns.
	 *
	 * @var array|WP_Error
	 */
	private $ability_result = array();

	/** Register a controlled issue-guest-list-pass ability. */
	public function set_up() {
		parent::set_up();

		$this->ability_inputs = array();
		$this->ability_result = array(
			'code'       => 'ABCDE-FGHJK-LMNPQ',
			'name'       => 'Sarah',
			'qr_url'     => 'https://events.example/qr',
			'perk_text'  => 'Extra Chill guest list',
			'emailed'    => false,
			'spots_left' => 59,
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

		if ( wp_has_ability( 'extrachill/issue-guest-list-pass' ) ) {
			wp_unregister_ability( 'extrachill/issue-guest-list-pass' );
		}

		WP_Abilities_Registry::get_instance()->register(
			'extrachill/issue-guest-list-pass',
			array(
				'label'               => 'Test guest list pass',
				'description'         => 'Controlled guest list ability.',
				'category'            => 'extrachill-api-tests',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'execute_issue' ),
				'permission_callback' => '__return_true',
			)
		);

		do_action( 'rest_api_init' );
	}

	/** Remove the controlled ability. */
	public function tear_down() {
		wp_unregister_ability( 'extrachill/issue-guest-list-pass' );
		remove_all_filters( 'extrachill_api_guest_list_pass_rate_limit' );
		remove_all_filters( 'extrachill_bypass_turnstile_verification' );
		parent::tear_down();
	}

	/** The route is registered on the events site. */
	public function test_route_is_registered_with_events_affinity() {
		$this->assertArrayHasKey( '/extrachill/v1/events/(?P<id>\d+)/guest-list-pass', rest_get_server()->get_routes() );
		$this->assertSame( 'events', extrachill_api_rest_ability_route_site( '/extrachill/v1/events/(?P<id>\d+)/guest-list-pass' ) );
	}

	/** A request without a Turnstile token is refused before the ability runs. */
	public function test_permission_requires_turnstile() {
		if ( ! function_exists( 'ec_turnstile_check_request' ) ) {
			$this->markTestSkipped( 'extrachill-network Turnstile helpers are not loaded.' );
		}
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'local' === WP_ENVIRONMENT_TYPE ) {
			$this->markTestSkipped( 'Turnstile is bypassed in local environments.' );
		}
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_false', 999 );

		$result = extrachill_api_guest_list_pass_permission( new WP_REST_Request( 'POST', '/extrachill/v1/events/1/guest-list-pass' ) );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->ability_inputs );
	}

	/** Handler maps the path ID and forwards the password verbatim. */
	public function test_handler_forwards_input_and_returns_pass() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/events/640348/guest-list-pass' );
		$request->set_param( 'id', '640348' );
		$request->set_param( 'password', ' Spooky <31> ' );
		$request->set_param( 'name', ' Sarah ' );
		$request->set_param( 'email', 'sarah@example.com' );

		$response = extrachill_api_handle_guest_list_pass( $request );

		$this->assertSame(
			array(
				'event_id' => 640348,
				'password' => ' Spooky <31> ',
				'name'     => 'Sarah',
				'email'    => 'sarah@example.com',
			),
			$this->ability_inputs[0]
		);
		$this->assertSame( 'ABCDE-FGHJK-LMNPQ', $response->get_data()['code'] );
	}

	/** Ability errors (wrong password, full list) pass through. */
	public function test_ability_error_passes_through() {
		$this->ability_result = new WP_Error( 'guest_list_full', 'The guest list is full.', array( 'status' => 409 ) );

		$request = new WP_REST_Request( 'POST', '/extrachill/v1/events/1/guest-list-pass' );
		$request->set_param( 'id', 1 );
		$request->set_param( 'password', 'x' );
		$request->set_param( 'name', 'Sam' );

		$result = extrachill_api_handle_guest_list_pass( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'guest_list_full', $result->get_error_code() );
	}

	/**
	 * Controlled ability callback.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_issue( $input ) {
		$this->ability_inputs[] = $input;
		return $this->ability_result;
	}
}
