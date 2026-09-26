<?php
/**
 * Review-notify tests: the core pending transition schedules the editor alert.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify review-notify observes core's pending edge on main only.
 */
class Test_Review_Notify extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/notifications/review-notify.php';

		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded in this environment.' );
		}
	}

	private function scheduled( int $blog_id, int $post_id ): bool {
		return (bool) as_has_scheduled_action( EC_USERS_REVIEW_NOTIFY_ACTION, array( $blog_id, $post_id ), 'extrachill-users-email' );
	}

	public function test_hook_is_registered_on_core_transition(): void {
		$this->assertSame( 10, has_action( 'transition_post_status', 'ec_users_review_notify_on_transition' ) );
	}

	public function test_draft_to_pending_on_main_schedules_alert(): void {
		$main    = (int) ec_get_blog_id( 'main' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		$this->assertTrue( $this->scheduled( $main, $post_id ) );
	}

	public function test_resaving_a_pending_post_does_not_reschedule(): void {
		$main    = (int) ec_get_blog_id( 'main' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		as_unschedule_all_actions( EC_USERS_REVIEW_NOTIFY_ACTION, array( $main, $post_id ), 'extrachill-users-email' );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Edited while pending',
			)
		);

		$this->assertFalse( $this->scheduled( $main, $post_id ) );
	}

	public function test_other_post_types_are_ignored(): void {
		$main    = (int) ec_get_blog_id( 'main' );
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'pending',
			)
		);

		$this->assertFalse( $this->scheduled( $main, $page_id ) );
	}

	public function test_non_main_sites_are_ignored(): void {
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		$post_id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		restore_current_blog();

		$this->assertFalse( $this->scheduled( $blog_id, $post_id ) );
	}

	public function test_deliver_skips_posts_that_left_review(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertFalse( ec_users_review_notify_deliver( (int) ec_get_blog_id( 'main' ), $post_id ) );
	}
}
