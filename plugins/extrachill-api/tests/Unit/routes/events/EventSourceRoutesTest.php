<?php
/**
 * Tests for the event-source intake adapters moved out of extrachill-events (#1426).
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the /event-source/* transport over controlled abilities. */
class EventSourceRoutesTest extends WP_UnitTestCase {

	/** @var string[] */
	private const ABILITIES = array(
		'extrachill-events/preview-event-source',
		'extrachill-events/submit-event-source',
		'extrachill-events/approve-event-source-submission',
		'extrachill-events/reject-event-source-submission',
	);

	/** @var array<string, array[]> */
	private $ability_inputs = array();

	/** @var array|WP_Error */
	private $ability_result = array( 'success' => true );

	/** Register controlled event-source abilities. */
	public function set_up() {
		parent::set_up();

		$this->ability_inputs = array();
		$this->ability_result = array( 'success' => true );

		if ( ! wp_has_ability_category( 'extrachill-api-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'extrachill-api-tests',
				array(
					'label'       => 'Extra Chill API Tests',
					'description' => 'Controlled abilities for API transport tests.',
				)
			);
		}

		foreach ( self::ABILITIES as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
			WP_Abilities_Registry::get_instance()->register(
				$name,
				array(
					'label'               => 'Test ' . $name,
					'description'         => 'Controlled event-source ability.',
					'category'            => 'extrachill-api-tests',
					'input_schema'        => array( 'type' => 'object' ),
					'execute_callback'    => function ( $input ) use ( $name ) {
						$this->ability_inputs[ $name ][] = $input;
						return $this->ability_result;
					},
					'permission_callback' => '__return_true',
				)
			);
		}

		// A fresh server fires rest_api_init once, instead of appending
		// duplicate handlers to the shared one on every test.
		$GLOBALS['wp_rest_server'] = null;
	}

	/** Remove the controlled abilities. */
	public function tear_down() {
		foreach ( self::ABILITIES as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/** The four routes keep their public URLs under extrachill/v1. */
	public function test_routes_are_registered_at_unchanged_urls() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/extrachill/v1/event-source/preview', $routes );
		$this->assertArrayHasKey( '/extrachill/v1/event-source/submit', $routes );
		$this->assertArrayHasKey( '/extrachill/v1/event-source/(?P<id>\d+)/approve', $routes );
		$this->assertArrayHasKey( '/extrachill/v1/event-source/(?P<id>\d+)/reject', $routes );
	}

	/** The family runs on the events site, where the abilities are registered. */
	public function test_routes_have_events_affinity() {
		$this->assertSame( 'events', extrachill_api_rest_ability_route_site( '/extrachill/v1/event-source/preview' ) );
		$this->assertSame( 'events', extrachill_api_rest_ability_route_site( '/extrachill/v1/event-source/12/approve' ) );
	}

	/** Preview/submit need a session; review needs manage_options. */
	public function test_permissions_match_the_previous_controller() {
		$this->assertFalse( extrachill_api_event_source_logged_in_permission() );
		$this->assertFalse( extrachill_api_event_source_admin_permission() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertTrue( extrachill_api_event_source_logged_in_permission() );
		$this->assertFalse( extrachill_api_event_source_admin_permission() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( extrachill_api_event_source_admin_permission() );
	}

	/** Direct browser navigation gets a 404 and never reaches the ability. */
	public function test_direct_navigation_is_refused() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-source/preview' );
		$request->set_param( 'url', 'https://artist.test/tour' );

		foreach ( array( 'extrachill_api_event_source_preview', 'extrachill_api_event_source_submit' ) as $callback ) {
			$result = $callback( $request );
			$this->assertWPError( $result );
			$this->assertSame( 'rest_no_route', $result->get_error_code() );
			$this->assertSame( 404, $result->get_error_data()['status'] );
		}
		$this->assertSame( array(), $this->ability_inputs );
	}

	/** XHR preview forwards the URL and returns the ability result. */
	public function test_preview_forwards_url() {
		$this->ability_result = array(
			'success'     => true,
			'source_kind' => 'artist',
		);

		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-source/preview' );
		$request->set_header( 'X-Requested-With', 'XMLHttpRequest' );
		$request->set_param( 'url', 'https://artist.test/tour' );

		$response = extrachill_api_event_source_preview( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->ability_result, $response->get_data() );
		$this->assertSame( array( array( 'url' => 'https://artist.test/tour' ) ), $this->ability_inputs['extrachill-events/preview-event-source'] );
	}

	/** Submit forwards the URL and contact fields. */
	public function test_submit_forwards_contact_fields() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-source/submit' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_param( 'url', 'https://venue.test/calendar' );
		$request->set_param( 'contact_email', 'booker@venue.test' );

		extrachill_api_event_source_submit( $request );

		$this->assertSame(
			array(
				'url'           => 'https://venue.test/calendar',
				'contact_email' => 'booker@venue.test',
				'contact_name'  => '',
			),
			$this->ability_inputs['extrachill-events/submit-event-source'][0]
		);
	}

	/** Approve maps id to submission_id and forwards only supplied overrides. */
	public function test_approve_forwards_only_supplied_overrides() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-source/42/approve' );
		$request->set_url_params( array( 'id' => 42 ) );
		$request->set_param( 'source_kind', 'venue' );
		$request->set_param( 'venue_term_id', 7 );

		extrachill_api_event_source_approve( $request );

		$this->assertSame(
			array(
				'submission_id' => 42,
				'source_kind'   => 'venue',
				'venue_term_id' => 7,
			),
			$this->ability_inputs['extrachill-events/approve-event-source-submission'][0]
		);
	}

	/** Reject forwards the submission and reason; ability errors pass through. */
	public function test_reject_forwards_reason_and_preserves_errors() {
		$this->ability_result = new WP_Error( 'submission_not_found', 'Submission not found.', array( 'status' => 404 ) );

		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-source/9/reject' );
		$request->set_url_params( array( 'id' => 9 ) );
		$request->set_param( 'reason', 'Duplicate' );

		$result = extrachill_api_event_source_reject( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'submission_not_found', $result->get_error_code() );
		$this->assertSame(
			array(
				'submission_id' => 9,
				'reason'        => 'Duplicate',
			),
			$this->ability_inputs['extrachill-events/reject-event-source-submission'][0]
		);
	}

	/** Constant-bound abilities are declared, so the parity manifest covers every route. */
	public function test_manifest_covers_each_route_with_documented_translations() {
		$contracts = array();
		foreach ( extrachill_api_rest_ability_adapter_manifest( rest_get_server()->get_routes( 'extrachill/v1' ) ) as $adapter ) {
			if ( in_array( $adapter['contract']['ability'], self::ABILITIES, true ) ) {
				$contracts[ $adapter['contract']['ability'] ] = $adapter['contract'];
			}
		}

		$this->assertSame( self::ABILITIES, array_keys( $contracts ) );
		foreach ( $contracts as $contract ) {
			$this->assertSame( 'events', $contract['site'] );
			$this->assertArrayHasKey( 'additionalProperties', $contract['exceptions']['constraints'] );
		}
		$this->assertArrayHasKey( 'id', $contracts['extrachill-events/approve-event-source-submission']['exceptions']['transport_only'] );
		$this->assertArrayHasKey( 'submission_id', $contracts['extrachill-events/reject-event-source-submission']['exceptions']['ability_only'] );
	}

	/** A missing owner ability keeps the previous ability_missing contract. */
	public function test_missing_ability_reports_ability_missing() {
		wp_unregister_ability( 'extrachill-events/reject-event-source-submission' );
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-source/9/reject' );
		$request->set_url_params( array( 'id' => 9 ) );

		$result = extrachill_api_event_source_reject( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_missing', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}
}
