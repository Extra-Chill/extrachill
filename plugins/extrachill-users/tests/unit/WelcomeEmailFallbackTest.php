<?php
/**
 * Tests for the welcome-email fallback schedule and recipient window (#454).
 *
 * @package ExtraChill\Users
 */
class Test_Welcome_Email_Fallback extends WP_UnitTestCase {
	/** Remove any schedule left by a test. */
	protected function tearDown(): void {
		wp_clear_scheduled_hook( 'extrachill_welcome_email_fallback' );
		parent::tearDown();
	}

	/**
	 * Create a signup that never finished onboarding.
	 *
	 * @param int $age_seconds How long ago the user registered.
	 */
	private function create_incomplete_signup( int $age_seconds ): int {
		$user_id = self::factory()->user->create(
			array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - $age_seconds ) )
		);
		update_user_meta( $user_id, 'onboarding_completed', '0' );
		update_user_meta( $user_id, 'welcome_email_sent', '0' );
		return (int) $user_id;
	}

	/** Candidate IDs, sorted. */
	private function candidate_ids(): array {
		$ids = array_map( 'intval', wp_list_pluck( extrachill_welcome_email_fallback_candidates(), 'ID' ) );
		sort( $ids );
		return $ids;
	}

	/** The fallback re-registers itself on the main site when it has dropped off. */
	public function test_schedule_is_restored_on_main_site(): void {
		wp_clear_scheduled_hook( 'extrachill_welcome_email_fallback' );
		$this->assertFalse( wp_next_scheduled( 'extrachill_welcome_email_fallback' ) );

		extrachill_welcome_email_fallback_maybe_schedule();

		$this->assertNotFalse( wp_next_scheduled( 'extrachill_welcome_email_fallback' ) );
		$this->assertSame( 'hourly', wp_get_schedule( 'extrachill_welcome_email_fallback' ) );
	}

	/** Non-main sites never own the schedule, and stray copies are cleared. */
	public function test_non_main_site_clears_stray_schedule(): void {
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		wp_schedule_event( time(), 'hourly', 'extrachill_welcome_email_fallback' );

		extrachill_welcome_email_fallback_maybe_schedule();

		$this->assertFalse( wp_next_scheduled( 'extrachill_welcome_email_fallback' ) );
		restore_current_blog();
	}

	/** Only signups between one hour and the window are targeted. */
	public function test_candidates_are_limited_to_recent_signups(): void {
		$too_new = $this->create_incomplete_signup( 10 * MINUTE_IN_SECONDS );
		$recent  = $this->create_incomplete_signup( 2 * HOUR_IN_SECONDS );
		$edge    = $this->create_incomplete_signup( ( EXTRACHILL_WELCOME_EMAIL_FALLBACK_WINDOW_DAYS * DAY_IN_SECONDS ) - HOUR_IN_SECONDS );
		$dormant = $this->create_incomplete_signup( 30 * DAY_IN_SECONDS );

		$ids = $this->candidate_ids();

		$this->assertContains( $recent, $ids );
		$this->assertContains( $edge, $ids );
		$this->assertNotContains( $too_new, $ids );
		$this->assertNotContains( $dormant, $ids );
	}

	/** Users already welcomed or onboarded are excluded. */
	public function test_candidates_exclude_sent_and_completed_users(): void {
		$sent = $this->create_incomplete_signup( 2 * HOUR_IN_SECONDS );
		update_user_meta( $sent, 'welcome_email_sent', '1' );
		$done = $this->create_incomplete_signup( 2 * HOUR_IN_SECONDS );
		update_user_meta( $done, 'onboarding_completed', '1' );

		$ids = $this->candidate_ids();

		$this->assertNotContains( $sent, $ids );
		$this->assertNotContains( $done, $ids );
	}

	/** Users who are not members of the current blog are still found. */
	public function test_candidates_are_network_wide(): void {
		$user_id = $this->create_incomplete_signup( 2 * HOUR_IN_SECONDS );
		remove_user_from_blog( $user_id, get_current_blog_id() );

		$this->assertContains( $user_id, $this->candidate_ids() );
	}
}
