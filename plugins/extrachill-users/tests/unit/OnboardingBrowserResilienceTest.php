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
		$this->assertStringContainsString( 'pattern="[a-zA-Z0-9_\-]+"', $html );
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

	/**
	 * An unmatched Local Scene is no longer a bounded onboarding diagnostic.
	 *
	 * Typed-but-unselected text used to block submission and emit
	 * `client_local_scene_unselected`. The field is optional, so that stranded
	 * members whose scene has no location term (issue #380). The code is now
	 * retired and must not be accepted back into the onboarding payload.
	 */
	public function test_local_scene_unselected_is_no_longer_a_bounded_diagnostic(): void {
		$result = extrachill_users_get_onboarding_client_event( 'client_failed', 'local_scene_unselected' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_onboarding_diagnostic', $result->get_error_code() );
	}

	/**
	 * Unmatched Local Scene demand is captured through the search contract.
	 *
	 * The raw term is free-form user input, which the onboarding payload
	 * contract forbids, so it travels as a zero-result `search` event instead.
	 * That reuses the existing search-gaps primitive rather than adding a new
	 * event type or storage.
	 */
	public function test_local_scene_gap_records_zero_result_search(): void {
		$this->assertSame(
			10,
			has_action( 'wp_ajax_extrachill_onboarding_local_scene_gap', 'extrachill_users_onboarding_local_scene_gap' )
		);

		$captured = array();
		$filter   = function ( $should_track, $event_type, $event_data ) use ( &$captured ) {
			$captured[] = array(
				'event_type' => $event_type,
				'event_data' => $event_data,
			);
			return false;
		};
		add_filter( 'extrachill_should_track_analytics_event', $filter, 10, 3 );

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability = wp_get_ability( 'extrachill/track-analytics-event' );
		$this->assertNotNull( $ability, 'The analytics tracking ability must be registered.' );

		$ability->execute(
			array(
				'event_type' => EC_ANALYTICS_EVENT_SEARCH,
				'event_data' => array(
					'search_term'  => 'Nowheresville',
					'result_count' => 0,
					'source'       => 'onboarding_local_scene',
				),
				'source_url' => home_url( '/onboarding/' ),
			)
		);

		remove_filter( 'extrachill_should_track_analytics_event', $filter, 10 );

		$this->assertCount( 1, $captured );
		$this->assertSame( EC_ANALYTICS_EVENT_SEARCH, $captured[0]['event_type'] );
		$this->assertSame( 'Nowheresville', $captured[0]['event_data']['search_term'] );
		$this->assertSame( 0, $captured[0]['event_data']['result_count'] );

		// The analytics layer only derives a source when the caller omits one,
		// so the onboarding surface must survive intact and not be reclassified
		// as nav/archive search.
		$this->assertSame( 'onboarding_local_scene', $captured[0]['event_data']['source'] );
	}
}
