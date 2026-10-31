<?php
/**
 * Tests for the guest RSVP REST adapter (extrachill-api#194).
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the Turnstile-gated guest RSVP transport. */
class GuestRsvpRouteTest extends WP_UnitTestCase {

	/** @var array */
	private $ability_inputs = array();

	/** @var array|WP_Error */
	private $ability_result = array( 'status' => 'rsvped' );

	/** Register a controlled guest-rsvp-event ability. */
	public function set_up() {
		parent::set_up();

		$this->ability_inputs = array();
		$this->ability_result = array( 'status' => 'rsvped' );

		if ( ! wp_has_ability_category( 'extrachill-api-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'extrachill-api-tests',
				array(
					'label'       => 'Extra Chill API Tests',
					'description' => 'Controlled abilities for API transport tests.',
				)
			);
		}

		if ( wp_has_ability( 'extrachill/guest-rsvp-event' ) ) {
			wp_unregister_ability( 'extrachill/guest-rsvp-event' );
		}

		WP_Abilities_Registry::get_instance()->register(
			'extrachill/guest-rsvp-event',
			array(
				'label'               => 'Test guest RSVP',
				'description'         => 'Controlled guest RSVP ability.',
				'category'            => 'extrachill-api-tests',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'execute_guest_rsvp' ),
				'permission_callback' => '__return_true',
			)
		);

		do_action( 'rest_api_init' );
	}

	/** Remove the controlled ability. */
	public function tear_down() {
		wp_unregister_ability( 'extrachill/guest-rsvp-event' );
		remove_all_filters( 'extrachill_bypass_turnstile_verification' );
		remove_all_filters( 'extrachill_api_guest_rsvp_rate_limit' );
		parent::tear_down();
	}

	/** The public route is registered. */
	public function test_route_is_registered() {
		$this->assertArrayHasKey( '/extrachill/v1/events/(?P<id>\d+)/guest-rsvp', rest_get_server()->get_routes() );
	}

	/** The route belongs to the events site. */
	public function test_route_has_events_affinity() {
		$this->assertSame( 'events', extrachill_api_rest_ability_route_site( '/extrachill/v1/events/(?P<id>\d+)/guest-rsvp' ) );
	}

	/** Handler maps the path ID and forwards only sanitized attribution. */
	public function test_handler_forwards_sanitized_input() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/events/486727/guest-rsvp' );
		$request->set_param( 'id', '486727' );
		$request->set_param( 'email', ' guest@example.com ' );
		$request->set_param( 'referrer', 'javascript:alert(1)' );
		$request->set_param( 'turnstile_response', 'token' );
		$request->set_param(
			'utm',
			array(
				'utm_source' => '<b>instagram</b>',
				'evil'       => 'nope',
				'utm_medium' => array( 'nested' ),
			)
		);

		$response = extrachill_api_handle_guest_rsvp( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'status' => 'rsvped' ), $response->get_data() );
		$this->assertSame( 486727, $this->ability_inputs[0]['event_id'] );
		$this->assertSame( 'guest@example.com', $this->ability_inputs[0]['email'] );
		$this->assertArrayNotHasKey( 'referrer', $this->ability_inputs[0] );
		$this->assertArrayNotHasKey( 'turnstile_response', $this->ability_inputs[0] );
		$this->assertSame( array( 'utm_source' => 'instagram' ), $this->ability_inputs[0]['utm'] );
	}

	/** Success response is opaque even if the ability returns more. */
	public function test_success_response_is_opaque() {
		$this->ability_result = array(
			'status'  => 'rsvped',
			'user_id' => 99,
			'created' => false,
		);

		$request = new WP_REST_Request( 'POST', '/extrachill/v1/events/1/guest-rsvp' );
		$request->set_param( 'id', 1 );
		$request->set_param( 'email', 'guest@example.com' );

		$this->assertSame( array( 'status' => 'rsvped' ), extrachill_api_handle_guest_rsvp( $request )->get_data() );
	}

	/** Ability errors pass through with their status. */
	public function test_ability_error_passes_through() {
		$this->ability_result = new WP_Error( 'invalid_email', 'Enter a valid email address.', array( 'status' => 400 ) );

		$request = new WP_REST_Request( 'POST', '/extrachill/v1/events/1/guest-rsvp' );
		$request->set_param( 'id', 1 );
		$request->set_param( 'email', 'nope' );

		$result = extrachill_api_handle_guest_rsvp( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_email', $result->get_error_code() );
	}

	/** A missing Turnstile token is refused before the ability runs. */
	public function test_permission_requires_turnstile() {
		if ( ! function_exists( 'ec_turnstile_check_request' ) ) {
			$this->markTestSkipped( 'extrachill-network Turnstile helpers are not loaded.' );
		}
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'local' === WP_ENVIRONMENT_TYPE ) {
			$this->markTestSkipped( 'Turnstile is bypassed in local environments.' );
		}
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_false', 999 );

		$request = new WP_REST_Request( 'POST', '/extrachill/v1/events/1/guest-rsvp' );
		$request->set_param( 'email', 'guest@example.com' );

		$result = extrachill_api_guest_rsvp_permission( $request );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->ability_inputs );
	}

	/** Controlled ability callback. */
	public function execute_guest_rsvp( array $input ) {
		$this->ability_inputs[] = $input;
		return $this->ability_result;
	}
}
