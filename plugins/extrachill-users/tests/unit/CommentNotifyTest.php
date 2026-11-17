<?php
/**
 * Comment notifications: replies to a comment and comments on a marked event (#1634).
 *
 * @package ExtraChill\Users
 */

require_once dirname( __DIR__ ) . '/support/fixtures.php';

/**
 * Verify recipients, self-exclusion, opt-outs, approval edges, and idempotency.
 */
class Test_Comment_Notify extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	private int $main_blog_id;
	private int $events_blog_id;
	private int $parent_author;
	private int $commenter;
	private int $attendee_one;
	private int $attendee_two;

	public static function wpSetUpBeforeClass(): void {
		ec_users_tests_ensure_site( (int) ec_get_blog_id( 'main' ) );
		ec_users_tests_ensure_site( (int) ec_get_blog_id( 'events' ) );
		ec_users_tests_ensure_site( (int) ec_get_blog_id( 'community' ) );
	}

	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Comment notifications span sites and require multisite.' );
		}

		global $wpdb;

		$this->main_blog_id   = (int) ec_get_blog_id( 'main' );
		$this->events_blog_id = (int) ec_get_blog_id( 'events' );
		if ( ! get_site( $this->events_blog_id ) ) {
			$this->markTestSkipped( 'The events site could not be provisioned.' );
		}

		$this->parent_author = (int) self::factory()->user->create();
		$this->commenter     = (int) self::factory()->user->create();
		$this->attendee_one  = (int) self::factory()->user->create();
		$this->attendee_two  = (int) self::factory()->user->create();

		extrachill_users_install_notifications_table();
		extrachill_users_install_concert_tracking_table();
		$wpdb->query( 'DELETE FROM ' . extrachill_users_notifications_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test table from trusted helper.
		$wpdb->query( 'DELETE FROM ' . extrachill_users_concert_tracking_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test table from trusted helper.

		// Deliver inline so hook-driven tests observe the receipts directly.
		add_filter( 'ec_users_comment_notify_async', '__return_false' );
	}

	/**
	 * Create a published post on a blog.
	 */
	private function post_on( int $blog_id, string $post_type = 'post' ): int {
		switch_to_blog( $blog_id );
		try {
			if ( ! post_type_exists( $post_type ) ) {
				register_post_type( $post_type, array( 'public' => true ) );
			}
			return (int) self::factory()->post->create(
				array(
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'post_title'  => 'Amanda Russell at The Royal',
				)
			);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Insert a comment without firing comment_post.
	 */
	private function comment_on( int $blog_id, int $post_id, int $user_id, array $args = array() ): int {
		switch_to_blog( $blog_id );
		try {
			return (int) self::factory()->comment->create(
				array_merge(
					array(
						'comment_post_ID'  => $post_id,
						'user_id'          => $user_id,
						'comment_approved' => 1,
						'comment_type'     => 'comment',
					),
					$args
				)
			);
		} finally {
			restore_current_blog();
		}
	}

	private function mark( int $user_id, int $event_id ): void {
		global $wpdb;

		$wpdb->insert(
			extrachill_users_concert_tracking_table_name(),
			array(
				'user_id'    => $user_id,
				'event_id'   => $event_id,
				'blog_id'    => $this->events_blog_id,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Notification rows of a type, keyed by recipient.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function rows( string $type ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . extrachill_users_notifications_table_name() . ' WHERE type = %s ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- test table from trusted helper.
				$type
			),
			ARRAY_A
		);

		$by_user = array();
		foreach ( (array) $rows as $row ) {
			$by_user[ (int) $row['user_id'] ] = $row;
		}
		ksort( $by_user );

		return $by_user;
	}

	private function total(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . extrachill_users_notifications_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test table from trusted helper.
	}

	public function test_reply_notifies_the_parent_comment_author(): void {
		$post_id = $this->post_on( $this->main_blog_id );
		$parent  = $this->comment_on( $this->main_blog_id, $post_id, $this->parent_author );
		$reply   = $this->comment_on( $this->main_blog_id, $post_id, $this->commenter, array( 'comment_parent' => $parent ) );

		$addressed = ec_users_comment_notify_deliver( $this->main_blog_id, $reply );

		$this->assertSame( array( $this->parent_author ), $addressed['reply'] );
		$rows = $this->rows( EC_USERS_COMMENT_REPLY_TYPE );
		$this->assertSame( array( $this->parent_author ), array_keys( $rows ) );

		$row = $rows[ $this->parent_author ];
		$this->assertSame( (string) $this->commenter, $row['actor_id'] );
		$this->assertSame( EC_USERS_COMMENT_REPLY_PRODUCER, $row['producer'] );
		$this->assertSame( sprintf( 'comment:%d:blog:%d', $reply, $this->main_blog_id ), $row['idempotency_key'] );
		$this->assertSame( 'Amanda Russell at The Royal', $row['title'] );
		$this->assertStringEndsWith( '#comment-' . $reply, $row['link'] );
		$this->assertSame( (string) $post_id, $row['item_id'] );
	}

	public function test_replying_to_your_own_comment_notifies_nobody(): void {
		$post_id = $this->post_on( $this->main_blog_id );
		$parent  = $this->comment_on( $this->main_blog_id, $post_id, $this->commenter );
		$reply   = $this->comment_on( $this->main_blog_id, $post_id, $this->commenter, array( 'comment_parent' => $parent ) );

		ec_users_comment_notify_deliver( $this->main_blog_id, $reply );

		$this->assertSame( 0, $this->total() );
	}

	public function test_event_comment_notifies_members_going_except_commenter_and_reply_recipient(): void {
		$event_id = $this->post_on( $this->events_blog_id, EC_USERS_COMMENT_NOTIFY_EVENT_POST_TYPE );
		foreach ( array( $this->parent_author, $this->commenter, $this->attendee_one, $this->attendee_two ) as $user_id ) {
			$this->mark( $user_id, $event_id );
		}

		$parent = $this->comment_on( $this->events_blog_id, $event_id, $this->parent_author );
		$reply  = $this->comment_on( $this->events_blog_id, $event_id, $this->commenter, array( 'comment_parent' => $parent ) );

		$addressed = ec_users_comment_notify_deliver( $this->events_blog_id, $reply );

		$expected_event = array( $this->attendee_one, $this->attendee_two );
		sort( $expected_event );
		$event_rows = $this->rows( EC_USERS_EVENT_COMMENT_TYPE );

		$this->assertSame( array( $this->parent_author ), array_keys( $this->rows( EC_USERS_COMMENT_REPLY_TYPE ) ) );
		$this->assertSame( $expected_event, array_keys( $event_rows ) );
		$this->assertSame( array( $this->parent_author ), $addressed['reply'] );
		$this->assertArrayNotHasKey( $this->commenter, $event_rows );
		$this->assertSame( EC_USERS_EVENT_COMMENT_PRODUCER, $event_rows[ $this->attendee_one ]['producer'] );
		$this->assertSame( sprintf( 'comment:%d:blog:%d', $reply, $this->events_blog_id ), $event_rows[ $this->attendee_one ]['idempotency_key'] );
		$this->assertSame( 3, $this->total() );
	}

	public function test_comments_on_articles_never_reach_event_attendees(): void {
		$post_id = $this->post_on( $this->main_blog_id );
		$this->mark( $this->attendee_one, $post_id );

		ec_users_comment_notify_deliver( $this->main_blog_id, $this->comment_on( $this->main_blog_id, $post_id, $this->commenter ) );

		$this->assertSame( 0, $this->total() );
	}

	public function test_delivery_is_idempotent_per_comment(): void {
		$event_id = $this->post_on( $this->events_blog_id, EC_USERS_COMMENT_NOTIFY_EVENT_POST_TYPE );
		$this->mark( $this->attendee_one, $event_id );
		$parent = $this->comment_on( $this->events_blog_id, $event_id, $this->parent_author );
		$reply  = $this->comment_on( $this->events_blog_id, $event_id, $this->commenter, array( 'comment_parent' => $parent ) );

		ec_users_comment_notify_deliver( $this->events_blog_id, $reply );
		ec_users_comment_notify_deliver( $this->events_blog_id, $reply );
		switch_to_blog( $this->events_blog_id );
		try {
			do_action( 'comment_post', $reply, 1 );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( 2, $this->total() );

		// A second comment on the same event is a new notification.
		ec_users_comment_notify_deliver( $this->events_blog_id, $this->comment_on( $this->events_blog_id, $event_id, $this->commenter ) );
		$this->assertSame( 3, $this->total() );
	}

	public function test_pending_comment_notifies_only_once_approved(): void {
		$post_id = $this->post_on( $this->main_blog_id );
		$parent  = $this->comment_on( $this->main_blog_id, $post_id, $this->parent_author );
		$reply   = $this->comment_on(
			$this->main_blog_id,
			$post_id,
			$this->commenter,
			array(
				'comment_parent'   => $parent,
				'comment_approved' => 0,
			)
		);

		switch_to_blog( $this->main_blog_id );
		try {
			do_action( 'comment_post', $reply, 0 );
			ec_users_comment_notify_deliver( $this->main_blog_id, $reply );
			$this->assertSame( 0, $this->total() );

			wp_set_comment_status( $reply, 'approve' );
			$this->assertSame( array( $this->parent_author ), array_keys( $this->rows( EC_USERS_COMMENT_REPLY_TYPE ) ) );

			// Unapprove then re-approve converges on the same receipt.
			wp_set_comment_status( $reply, 'hold' );
			wp_set_comment_status( $reply, 'approve' );
			$this->assertSame( 1, $this->total() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_new_approved_comment_notifies_through_comment_post(): void {
		$post_id = $this->post_on( $this->main_blog_id );
		$parent  = $this->comment_on( $this->main_blog_id, $post_id, $this->parent_author );
		$reply   = $this->comment_on( $this->main_blog_id, $post_id, $this->commenter, array( 'comment_parent' => $parent ) );

		switch_to_blog( $this->main_blog_id );
		try {
			do_action( 'comment_post', $reply, 1 );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( array( $this->parent_author ), array_keys( $this->rows( EC_USERS_COMMENT_REPLY_TYPE ) ) );
	}

	public function test_recipients_who_blocked_the_commenter_are_skipped(): void {
		$event_id = $this->post_on( $this->events_blog_id, EC_USERS_COMMENT_NOTIFY_EVENT_POST_TYPE );
		$this->mark( $this->attendee_one, $event_id );
		$this->mark( $this->attendee_two, $event_id );
		$this->mark( $this->parent_author, $event_id );
		update_user_meta( $this->attendee_one, 'ec_member_blocked_user_ids', array( $this->commenter ) );
		update_user_meta( $this->parent_author, 'ec_member_blocked_user_ids', array( $this->commenter ) );

		$parent = $this->comment_on( $this->events_blog_id, $event_id, $this->parent_author );
		$reply  = $this->comment_on( $this->events_blog_id, $event_id, $this->commenter, array( 'comment_parent' => $parent ) );

		ec_users_comment_notify_deliver( $this->events_blog_id, $reply );

		$this->assertSame( array(), $this->rows( EC_USERS_COMMENT_REPLY_TYPE ) );
		$this->assertSame( array( $this->attendee_two ), array_keys( $this->rows( EC_USERS_EVENT_COMMENT_TYPE ) ) );
	}

	public function test_guest_comments_and_non_hosting_sites_notify_nobody(): void {
		$post_id = $this->post_on( $this->main_blog_id );
		$parent  = $this->comment_on( $this->main_blog_id, $post_id, $this->parent_author );
		$guest   = $this->comment_on(
			$this->main_blog_id,
			$post_id,
			0,
			array(
				'comment_parent' => $parent,
				'comment_author' => 'Guest',
			)
		);
		ec_users_comment_notify_deliver( $this->main_blog_id, $guest );

		$other_blog = (int) self::factory()->blog->create();
		$other_post = $this->post_on( $other_blog );
		$other_par  = $this->comment_on( $other_blog, $other_post, $this->parent_author );
		$other      = $this->comment_on( $other_blog, $other_post, $this->commenter, array( 'comment_parent' => $other_par ) );
		ec_users_comment_notify_deliver( $other_blog, $other );

		$this->assertSame( 0, $this->total() );
	}
}
