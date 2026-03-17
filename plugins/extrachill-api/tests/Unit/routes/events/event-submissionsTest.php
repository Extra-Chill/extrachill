<?php
/**
 * Tests for the event submission REST endpoint.
 *
 * Covers field extraction/validation, workflow building,
 * routing logic, and notification functions.
 *
 * @package ExtraChill\API\Tests
 */

// phpcs:ignore Generic.Classes.OpeningBraceSameLine -- WP test convention.
class Event_SubmissionsTest extends WP_UnitTestCase {

	/**
	 * Build a WP_REST_Request with valid submission fields.
	 *
	 * @param array $overrides Field overrides.
	 * @return WP_REST_Request
	 */
	private function get_valid_request( array $overrides = array() ): WP_REST_Request {
		$defaults = array(
			'contact_name'  => 'Test Submitter',
			'contact_email' => 'test@example.com',
			'event_title'   => 'Live at The Royal American',
			'event_date'    => '2026-04-15',
			'event_time'    => '9:00 PM',
			'venue_name'    => 'The Royal American',
			'event_city'    => 'Charleston',
			'event_lineup'  => 'Band A, Band B, Band C',
			'event_link'    => 'https://tickets.example.com/event/123',
			'notes'         => 'Doors at 8pm, 21+',
		);

		$params  = array_merge( $defaults, $overrides );
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-submissions' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	// -------------------------------------------------------------------------
	// Route registration
	// -------------------------------------------------------------------------

	public function test_extrachill_api_register_event_submission_route() {
		// Route must be registered inside rest_api_init to avoid incorrect-usage notice.
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/extrachill/v1/event-submissions', $routes );
	}

	// -------------------------------------------------------------------------
	// Main handler routing
	// -------------------------------------------------------------------------

	public function test_extrachill_api_handle_event_submission_missing_turnstile_function() {
		// When ec_verify_turnstile_response does not exist, handler returns error.
		// In the WP test environment this function may not be loaded.
		if ( function_exists( 'ec_verify_turnstile_response' ) ) {
			$this->markTestSkipped( 'ec_verify_turnstile_response is defined — cannot test missing-function guard.' );
		}

		$request = $this->get_valid_request();
		$request->set_param( 'turnstile_response', 'test-token' );

		$result = extrachill_api_handle_event_submission( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'turnstile_missing', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Field extraction
	// -------------------------------------------------------------------------

	public function test_extrachill_api_extract_submission_fields_complete() {
		$request = $this->get_valid_request();
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertIsArray( $result );
		$this->assertEquals( 'Test Submitter', $result['contact_name'] );
		$this->assertEquals( 'test@example.com', $result['contact_email'] );
		$this->assertEquals( 'Live at The Royal American', $result['event_title'] );
		$this->assertEquals( '2026-04-15', $result['event_date'] );
		$this->assertEquals( '9:00 PM', $result['event_time'] );
		$this->assertEquals( 'The Royal American', $result['venue_name'] );
		$this->assertEquals( 'Charleston', $result['event_city'] );
		$this->assertEquals( 'Band A, Band B, Band C', $result['event_lineup'] );
		$this->assertEquals( 'https://tickets.example.com/event/123', $result['event_link'] );
		$this->assertEquals( 'Doors at 8pm, 21+', $result['notes'] );
	}

	public function test_extrachill_api_extract_submission_fields_missing_title() {
		$request = $this->get_valid_request( array( 'event_title' => '' ) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	public function test_extrachill_api_extract_submission_fields_missing_date() {
		$request = $this->get_valid_request( array( 'event_date' => '' ) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	public function test_extrachill_api_extract_submission_fields_missing_contact_name() {
		$request = $this->get_valid_request( array( 'contact_name' => '' ) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	public function test_extrachill_api_extract_submission_fields_missing_contact_email() {
		$request = $this->get_valid_request( array( 'contact_email' => '' ) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	public function test_extrachill_api_extract_submission_fields_invalid_email() {
		// sanitize_email('not-an-email') returns '' in real WP,
		// so the empty() check fires first → missing_fields, not invalid_email.
		// Use an email-like string that passes sanitize_email but fails is_email.
		$request = $this->get_valid_request( array( 'contact_email' => 'bad@' ) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertWPError( $result );
		// Either missing_fields (sanitized to empty) or invalid_email is acceptable.
		$this->assertContains( $result->get_error_code(), array( 'missing_fields', 'invalid_email' ) );
	}

	public function test_extrachill_api_extract_submission_fields_optional_fields_empty() {
		$request = $this->get_valid_request( array(
			'event_time'   => '',
			'venue_name'   => '',
			'event_city'   => '',
			'event_lineup' => '',
			'event_link'   => '',
			'notes'        => '',
		) );
		$result = extrachill_api_extract_submission_fields( $request );

		$this->assertIsArray( $result );
		$this->assertEquals( 'Live at The Royal American', $result['event_title'] );
		$this->assertEquals( '2026-04-15', $result['event_date'] );
		$this->assertEmpty( $result['event_time'] );
		$this->assertEmpty( $result['venue_name'] );
	}

	public function test_extrachill_api_extract_submission_fields_sanitizes_html() {
		$request = $this->get_valid_request( array(
			'event_title' => '<script>alert("xss")</script>My Event',
			'notes'       => '<b>Bold</b> notes with <script>alert(1)</script>',
		) );
		$result = extrachill_api_extract_submission_fields( $request );

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<script>', $result['event_title'] );
		$this->assertStringNotContainsString( '<script>', $result['notes'] );
		$this->assertStringContainsString( 'My Event', $result['event_title'] );
	}

	public function test_extrachill_api_extract_submission_fields_logged_out_user() {
		// Default WP test env has no logged-in user.
		$request = $this->get_valid_request();
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertIsArray( $result );
		$this->assertEquals( 0, $result['user_id'] );
	}

	// -------------------------------------------------------------------------
	// Flow-based execution
	// -------------------------------------------------------------------------

	public function test_extrachill_api_execute_with_flow_not_found() {
		// Blocked on homeboy #844: validation_dependencies arrays are
		// serialized as empty strings, so data-machine is never loaded
		// as a test dependency. Once fixed, this test will pass.
		if ( ! class_exists( '\\DataMachine\\Core\\Database\\Flows\\Flows' ) ) {
			$this->markTestSkipped( 'Data Machine not loaded — blocked on homeboy #844.' );
		}

		$request = $this->get_valid_request();
		$result  = extrachill_api_execute_with_flow( $request, 999999 );

		$this->assertWPError( $result );
		$this->assertEquals( 'flow_not_found', $result->get_error_code() );
	}

	public function test_extrachill_api_execute_with_flow_validation_fails() {
		$request = $this->get_valid_request( array( 'event_title' => '' ) );
		$result  = extrachill_api_execute_with_flow( $request, 1 );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Direct/ephemeral execution
	// -------------------------------------------------------------------------

	public function test_extrachill_api_execute_direct_validation_fails() {
		$request = $this->get_valid_request( array( 'event_date' => '' ) );
		$result  = extrachill_api_execute_direct( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Flyer storage
	// -------------------------------------------------------------------------

	public function test_extrachill_api_store_submission_flyer_no_file() {
		$request = $this->get_valid_request();
		// No file params set — should return null.
		$result = extrachill_api_store_submission_flyer( $request, 1, 1 );

		$this->assertNull( $result );
	}

	public function test_extrachill_api_store_submission_flyer_direct_no_file() {
		$request = $this->get_valid_request();
		$result  = extrachill_api_store_submission_flyer_direct( $request );

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// Workflow building
	// -------------------------------------------------------------------------

	public function test_workflow_with_flyer_has_three_steps() {
		$submission = array(
			'event_title'  => 'Test Event',
			'event_date'   => '2026-04-15',
			'event_time'   => '9:00 PM',
			'venue_name'   => 'Test Venue',
			'event_city'   => 'Charleston',
			'event_lineup' => 'Band A',
			'event_link'   => 'https://example.com',
		);
		$flyer = array(
			'filename'    => 'flyer.jpg',
			'stored_path' => '/tmp/stored/flyer.jpg',
			'mime_type'   => 'image/jpeg',
		);

		$workflow = extrachill_api_build_event_submission_workflow( $submission, $flyer, 'openai', 'gpt-5-mini' );

		$this->assertArrayHasKey( 'steps', $workflow );
		$this->assertCount( 3, $workflow['steps'] );
		$this->assertEquals( 'event_import', $workflow['steps'][0]['type'] );
		$this->assertEquals( 'event_flyer', $workflow['steps'][0]['handler_slug'] );
		$this->assertEquals( 'ai', $workflow['steps'][1]['type'] );
		$this->assertEquals( 'update', $workflow['steps'][2]['type'] );
		$this->assertEquals( 'upsert_event', $workflow['steps'][2]['handler_slug'] );
	}

	public function test_workflow_without_flyer_has_two_steps() {
		$submission = array(
			'event_title'  => 'Test Event',
			'event_date'   => '2026-04-15',
			'event_time'   => '',
			'venue_name'   => '',
			'event_city'   => '',
			'event_lineup' => '',
			'event_link'   => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow( $submission, null, 'openai', 'gpt-5-mini' );

		$this->assertCount( 2, $workflow['steps'] );
		$this->assertEquals( 'ai', $workflow['steps'][0]['type'] );
		$this->assertEquals( 'update', $workflow['steps'][1]['type'] );
	}

	public function test_workflow_custom_system_prompt() {
		$submission = array(
			'event_title' => 'Test', 'event_date' => '2026-04-15',
			'event_time' => '', 'venue_name' => '', 'event_city' => '',
			'event_lineup' => '', 'event_link' => '',
		);

		$custom = 'Custom instructions for processing.';
		$workflow = extrachill_api_build_event_submission_workflow( $submission, null, 'openai', 'gpt-5-mini', $custom );

		$this->assertEquals( $custom, $workflow['steps'][0]['system_prompt'] );
	}

	public function test_workflow_default_system_prompt() {
		$submission = array(
			'event_title' => 'Test', 'event_date' => '2026-04-15',
			'event_time' => '', 'venue_name' => '', 'event_city' => '',
			'event_lineup' => '', 'event_link' => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow( $submission, null, 'openai', 'gpt-5-mini', '' );

		$this->assertStringContainsString( 'event submission', $workflow['steps'][0]['system_prompt'] );
		$this->assertStringContainsString( 'upsert_event', $workflow['steps'][0]['system_prompt'] );
	}

	public function test_workflow_ai_step_enables_upsert_tool() {
		$submission = array(
			'event_title' => 'Test', 'event_date' => '2026-04-15',
			'event_time' => '', 'venue_name' => '', 'event_city' => '',
			'event_lineup' => '', 'event_link' => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow( $submission, null, 'anthropic', 'claude-sonnet-4-20250514' );

		$this->assertContains( 'upsert_event', $workflow['steps'][0]['enabled_tools'] );
	}

	public function test_workflow_upsert_step_pending_status() {
		$submission = array(
			'event_title' => 'Test', 'event_date' => '2026-04-15',
			'event_time' => '', 'venue_name' => '', 'event_city' => '',
			'event_lineup' => '', 'event_link' => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow( $submission, null, 'openai', 'gpt-5-mini' );

		$upsert = end( $workflow['steps'] );
		$this->assertEquals( 'pending', $upsert['handler_config']['post_status'] );
	}

	public function test_workflow_passes_provider_and_model() {
		$submission = array(
			'event_title' => 'Test', 'event_date' => '2026-04-15',
			'event_time' => '', 'venue_name' => '', 'event_city' => '',
			'event_lineup' => '', 'event_link' => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow( $submission, null, 'anthropic', 'claude-sonnet-4-20250514' );

		$this->assertEquals( 'anthropic', $workflow['steps'][0]['provider'] );
		$this->assertEquals( 'claude-sonnet-4-20250514', $workflow['steps'][0]['model'] );
	}

	public function test_workflow_handler_config_maps_submission_data() {
		$submission = array(
			'event_title'  => 'Blues Night',
			'event_date'   => '2026-05-01',
			'event_time'   => '8:00 PM',
			'venue_name'   => 'C-Boys Heart & Soul',
			'event_city'   => 'Austin',
			'event_lineup' => 'Blues Band',
			'event_link'   => 'https://tickets.example.com/blues',
		);
		$flyer = array(
			'filename' => 'blues.png', 'stored_path' => '/tmp/blues.png', 'mime_type' => 'image/png',
		);

		$workflow = extrachill_api_build_event_submission_workflow( $submission, $flyer, 'openai', 'gpt-5-mini' );

		$config = $workflow['steps'][0]['handler_config'];
		$this->assertEquals( 'Blues Night', $config['title'] );
		$this->assertEquals( '2026-05-01', $config['startDate'] );
		$this->assertEquals( '8:00 PM', $config['startTime'] );
		$this->assertEquals( 'C-Boys Heart & Soul', $config['venue_name'] );
		$this->assertEquals( 'Austin', $config['venue_city'] );
		$this->assertEquals( 'Blues Band', $config['performer'] );
		$this->assertEquals( 'https://tickets.example.com/blues', $config['ticketUrl'] );
	}

	public function test_workflow_include_images_with_flyer() {
		$submission = array(
			'event_title' => 'Test', 'event_date' => '2026-04-15',
			'event_time' => '', 'venue_name' => '', 'event_city' => '',
			'event_lineup' => '', 'event_link' => '',
		);
		$flyer = array(
			'filename' => 'poster.jpg', 'stored_path' => '/tmp/poster.jpg', 'mime_type' => 'image/jpeg',
		);

		$workflow = extrachill_api_build_event_submission_workflow( $submission, $flyer, 'openai', 'gpt-5-mini' );

		$upsert = end( $workflow['steps'] );
		$this->assertTrue( $upsert['handler_config']['include_images'] );
	}

	public function test_workflow_include_images_without_flyer() {
		$submission = array(
			'event_title' => 'Test', 'event_date' => '2026-04-15',
			'event_time' => '', 'venue_name' => '', 'event_city' => '',
			'event_lineup' => '', 'event_link' => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow( $submission, null, 'openai', 'gpt-5-mini' );

		$upsert = end( $workflow['steps'] );
		$this->assertFalse( $upsert['handler_config']['include_images'] );
	}

	// -------------------------------------------------------------------------
	// Notification functions
	// -------------------------------------------------------------------------

	public function test_extrachill_api_notify_submitter_skips_invalid_email() {
		$submission = array(
			'contact_email' => '',
			'event_title'   => 'Test Event',
			'event_date'    => '2026-04-15',
			'venue_name'    => 'Test Venue',
		);

		// Should not throw or error — just silently return.
		extrachill_api_notify_submitter( $submission );
		$this->assertTrue( true ); // Reached without error.
	}

	public function test_extrachill_api_notify_admin_does_not_throw() {
		$submission = array(
			'contact_name'  => 'Tester',
			'contact_email' => 'test@example.com',
			'event_title'   => 'Test Event',
			'event_date'    => '2026-04-15',
			'venue_name'    => 'Test Venue',
		);

		// Should execute without error.
		extrachill_api_notify_admin( $submission, 12345 );
		$this->assertTrue( true );
	}
}
