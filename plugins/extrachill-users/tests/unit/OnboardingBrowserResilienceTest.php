<?php
/**
 * Tests for resilient browser onboarding.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify onboarding remains actionable without JavaScript and emits bounded diagnostics.
 */
class Test_Onboarding_Browser_Resilience extends WP_UnitTestCase {

	/**
	 * Reset the authenticated user between tests.
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * The block view script has an actual dependency on the shared auth utility.
	 */
	public function test_onboarding_script_declares_auth_utils_dependency(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'extrachill/onboarding' );

		$this->assertInstanceOf( WP_Block_Type::class, $block );
		$script = wp_scripts()->registered[ $block->view_script_handles[0] ];
		$this->assertContains( 'extrachill-auth-utils', $script->deps );
	}

	/**
	 * The rendered form has a native authenticated POST fallback.
	 */
	public function test_onboarding_form_has_native_submission_path(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'onboarding_completed', '0' );
		wp_set_current_user( $user_id );

		$html = render_block( array( 'blockName' => 'extrachill/onboarding' ) );

		$this->assertStringContainsString( 'method="post"', $html );
		$this->assertStringContainsString( 'admin-post.php', $html );
		$this->assertStringContainsString( 'name="action" value="extrachill_complete_onboarding"', $html );
		$this->assertStringContainsString( 'name="onboarding_nonce"', $html );
		$this->assertStringContainsString( 'name="local_scene"', $html );
		$this->assertSame( 10, has_action( 'admin_post_extrachill_complete_onboarding', 'extrachill_users_handle_onboarding_form' ) );
		$this->assertSame( 10, has_action( 'admin_post_nopriv_extrachill_complete_onboarding', 'extrachill_users_handle_onboarding_form' ) );
	}

	/**
	 * Client events reuse analytics-owned lifecycle event names.
	 */
	public function test_client_diagnostics_map_to_bounded_analytics_events(): void {
		$failed = extrachill_users_get_onboarding_client_event( 'client_failed', 'auth_utils_missing' );

		$this->assertSame( EC_ANALYTICS_EVENT_ONBOARDING_SUBMISSION_FAILED, $failed['event_type'] );
		$this->assertSame( array( 'error_code' => 'client_auth_utils_missing' ), $failed['event_data'] );
	}

	/**
	 * Arbitrary client strings cannot enter analytics payloads.
	 */
	public function test_client_diagnostics_reject_unbounded_values(): void {
		$result = extrachill_users_get_onboarding_client_event( 'client_failed', 'raw user input' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_onboarding_diagnostic', $result->get_error_code() );
	}
}
