<?php
/**
 * Tests for the push delivery channel (inc/notifications/push.php).
 *
 * @package ExtraChill\Users
 */

class Test_Notification_Push_Channel extends WP_UnitTestCase {

	/**
	 * Set up a clean notification table and outbox.
	 */
	protected function setUp(): void {
		parent::setUp();

		extrachill_users_install_notifications_table();

		global $wpdb;
		$table = extrachill_users_notifications_table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test table from trusted helper.

		$GLOBALS['ec_users_push_test_outbox'] = array();

		// Forum items live on the current test blog.
		add_filter( 'ec_users_notification_push_forum_blog_id', 'get_current_blog_id' );
	}

	/**
	 * Reset the outbox.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['ec_users_push_test_outbox'] );
		remove_filter( 'ec_users_notification_push_forum_blog_id', 'get_current_blog_id' );
		parent::tearDown();
	}

	/**
	 * Make the wp-native-auth primitive available (test double).
	 */
	private function with_push_primitive(): void {
		require_once dirname( __DIR__ ) . '/support/wp-native-auth-push-double.php';

		if ( ! defined( 'EC_USERS_TEST_PUSH_DOUBLE' ) ) {
			$this->markTestSkipped( 'The real wp-native-auth primitive is loaded; the recording double cannot be installed.' );
		}
	}

	/**
	 * Recorded pushes.
	 *
	 * @return array<int,array{user_id:int,message:array}>
	 */
	private function outbox(): array {
		return $GLOBALS['ec_users_push_test_outbox'] ?? array();
	}

	/**
	 * Create a forum + topic chain.
	 *
	 * @param string $forum_status Forum post status.
	 * @param string $topic_title  Topic title.
	 * @param string $forum_open   Forum _bbp_status (open|closed).
	 * @return int Topic ID.
	 */
	private function make_topic( string $forum_status, string $topic_title, string $forum_open = 'open' ): int {
		$forum_id = self::factory()->post->create(
			array(
				'post_type'   => 'forum',
				'post_status' => $forum_status,
				'post_title'  => 'Forum',
			)
		);
		update_post_meta( $forum_id, '_bbp_status', $forum_open );

		return self::factory()->post->create(
			array(
				'post_type'   => 'topic',
				'post_status' => 'publish',
				'post_title'  => $topic_title,
				'post_parent' => $forum_id,
			)
		);
	}

	/**
	 * Build a substrate payload.
	 *
	 * @param int   $actor_id  Actor.
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function payload( int $actor_id, array $overrides = array() ): array {
		return array_merge(
			array(
				'actor_id'        => $actor_id,
				'type'            => 'reply',
				'title'           => 'Best basement shows this spring',
				'link'            => 'https://community.example.org/t/basement-shows/#post-77',
				'item_id'         => 0,
				'producer'        => 'tests.push',
				'idempotency_key' => 'reply:77',
			),
			$overrides
		);
	}

	/**
	 * Create an actor with a known display name.
	 *
	 * @return int
	 */
	private function make_actor(): int {
		return self::factory()->user->create( array( 'display_name' => 'Riley Replier' ) );
	}

	/**
	 * Runs first: without wp-native-auth the channel is a silent no-op.
	 */
	public function test_noop_without_wp_native_auth(): void {
		if ( function_exists( 'wp_native_auth_send_push' ) ) {
			$this->markTestSkipped( 'wp-native-auth push primitive is already loaded in this process.' );
		}

		$actor     = $this->make_actor();
		$recipient = self::factory()->user->create();
		$topic_id  = $this->make_topic( 'publish', 'Best basement shows this spring' );

		$receipt = ec_users_notify_with_receipts( $recipient, $this->payload( $actor, array( 'item_id' => $topic_id ) ) );

		$this->assertSame( 1, $receipt['inserted'] );
		$this->assertSame( array(), $this->outbox() );
		$this->assertNull(
			ec_users_notification_push_on_insert(
				$receipt['recipients'][ $recipient ]['notification_id'],
				$recipient,
				$this->payload( $actor, array( 'item_id' => $topic_id ) )
			)
		);
	}

	public function test_insert_pushes_allow_listed_type(): void {
		$this->with_push_primitive();

		$actor     = $this->make_actor();
		$recipient = self::factory()->user->create();
		$topic_id  = $this->make_topic( 'publish', 'Best basement shows this spring' );

		$receipt = ec_users_notify_with_receipts( $recipient, $this->payload( $actor, array( 'item_id' => $topic_id ) ) );
		$outbox  = $this->outbox();

		$this->assertSame( 1, $receipt['inserted'] );
		$this->assertCount( 1, $outbox );
		$this->assertSame( $recipient, $outbox[0]['user_id'] );
		$this->assertSame(
			array(
				'title'       => 'New reply from Riley Replier',
				'data'        => array(
					'notification_id' => $receipt['recipients'][ $recipient ]['notification_id'],
					'type'            => 'reply',
					'url'             => 'https://community.example.org/t/basement-shows/#post-77',
				),
				'collapse_id' => 'ec-topic-' . $topic_id,
				'body'        => 'Best basement shows this spring',
			),
			$outbox[0]['message']
		);
	}

	public function test_replies_on_one_topic_share_a_collapse_id(): void {
		$this->with_push_primitive();

		$actor     = $this->make_actor();
		$recipient = self::factory()->user->create();
		$topic_id  = $this->make_topic( 'publish', 'Busy thread' );
		$reply_id  = self::factory()->post->create(
			array(
				'post_type'   => 'reply',
				'post_status' => 'publish',
				'post_parent' => $topic_id,
			)
		);

		ec_users_notify_with_receipts( $recipient, $this->payload( $actor, array( 'item_id' => $topic_id ) ) );
		ec_users_notify_with_receipts(
			$recipient,
			$this->payload(
				$actor,
				array(
					'type'            => 'mention',
					'item_id'         => $reply_id,
					'producer'        => 'tests.push.mentions',
					'idempotency_key' => 'content:' . $reply_id,
				)
			)
		);

		$outbox = $this->outbox();
		$this->assertCount( 2, $outbox );
		$this->assertSame( 'Riley Replier mentioned you', $outbox[1]['message']['title'] );
		$this->assertSame( $outbox[0]['message']['collapse_id'], $outbox[1]['message']['collapse_id'] );
	}

	public function test_replay_does_not_push(): void {
		$this->with_push_primitive();

		$actor     = $this->make_actor();
		$recipient = self::factory()->user->create();
		$topic_id  = $this->make_topic( 'publish', 'Replayed topic' );
		$payload   = $this->payload( $actor, array( 'item_id' => $topic_id ) );

		$first  = ec_users_notify_with_receipts( $recipient, $payload );
		$replay = ec_users_notify_with_receipts( $recipient, $payload );

		$this->assertSame( 1, $first['inserted'] );
		$this->assertSame( 1, $replay['existing'] );
		$this->assertCount( 1, $this->outbox() );
	}

	public function test_opted_out_user_is_not_pushed(): void {
		$this->with_push_primitive();

		$actor     = $this->make_actor();
		$recipient = self::factory()->user->create();
		$topic_id  = $this->make_topic( 'publish', 'Quiet please' );

		$this->assertTrue( ec_users_notification_push_enabled( $recipient ) );
		ec_users_set_notification_push_disabled( $recipient, true );
		$this->assertFalse( ec_users_notification_push_enabled( $recipient ) );

		$receipt = ec_users_notify_with_receipts( $recipient, $this->payload( $actor, array( 'item_id' => $topic_id ) ) );

		$this->assertSame( 1, $receipt['inserted'] );
		$this->assertSame( array(), $this->outbox() );

		ec_users_set_notification_push_disabled( $recipient, false );
		$this->assertSame( '', get_user_meta( $recipient, EC_NOTIFICATIONS_PUSH_DISABLED_META, true ) );
		ec_users_notify_with_receipts( $recipient, $this->payload( $actor, array( 'item_id' => $topic_id, 'idempotency_key' => 'reply:78' ) ) );
		$this->assertCount( 1, $this->outbox() );
	}

	public function test_non_allow_listed_type_is_not_pushed(): void {
		$this->with_push_primitive();

		$actor     = $this->make_actor();
		$recipient = self::factory()->user->create();

		foreach ( array( 'upvote', 'milestone', 'local_scene_digest', 'festival_wire_update' ) as $index => $type ) {
			ec_users_notify_with_receipts( $recipient, $this->payload( $actor, array( 'type' => $type, 'idempotency_key' => 'nudge:' . $index ) ) );
		}

		$this->assertSame( array(), $this->outbox() );
		$this->assertArrayNotHasKey( 'upvote', ec_users_notification_push_types() );
	}

	public function test_private_content_is_not_on_the_lock_screen(): void {
		$this->with_push_primitive();

		$actor     = $this->make_actor();
		$recipient = self::factory()->user->create();
		$cases     = array(
			'private forum' => array( 'item_id' => $this->make_topic( 'private', 'Secret private plans' ) ),
			'hidden forum'  => array( 'item_id' => $this->make_topic( 'hidden', 'Secret hidden plans' ) ),
			'closed forum'  => array( 'item_id' => $this->make_topic( 'publish', 'Secret closed plans', 'closed' ) ),
			'missing item'  => array( 'item_id' => 999999999 ),
			'venue scope'   => array(
				'type'    => 'guest_list_request',
				'item_id' => 4242,
			),
			'booking scope' => array( 'type' => 'booking_inquiry_submitted' ),
		);

		$index    = 0;
		$messages = array();
		foreach ( $cases as $label => $overrides ) {
			ec_users_notify_with_receipts(
				$recipient,
				$this->payload(
					$actor,
					array_merge(
						array(
							'title'           => 'Secret: Jamie Doe +2 for the Room 3 show',
							'idempotency_key' => 'private:' . $index++,
						),
						$overrides
					)
				)
			);

			$outbox = $this->outbox();
			$this->assertCount( $index, $outbox, $label );
			$message            = end( $outbox )['message'];
			$messages[ $label ] = $message;

			$this->assertArrayNotHasKey( 'body', $message, $label );
			$this->assertStringNotContainsString( 'Secret', $message['title'], $label );
			$this->assertStringNotContainsString( 'Jamie', wp_json_encode( array( $message['title'], $message['collapse_id'] ) ), $label );
			$this->assertNotSame( '', $message['title'], $label );
		}

		$this->assertSame( 'New reply from Riley Replier', $messages['private forum']['title'] );
		$this->assertSame( 'New guest list request', $messages['venue scope']['title'] );
		$this->assertSame( 'New booking inquiry', $messages['booking scope']['title'] );
	}

	public function test_push_preference_is_exposed_through_abilities(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$this->assertTrue( extrachill_users_ability_get_notification_preferences()['push_enabled'] );

		$updated = extrachill_users_ability_update_notification_preferences( array( 'push_enabled' => false ) );
		$this->assertFalse( $updated['push_enabled'] );
		$this->assertTrue( $updated['emails_enabled'] );
		$this->assertFalse( extrachill_users_ability_get_notification_preferences()['push_enabled'] );
		$this->assertFalse( ec_users_notification_push_enabled( $user_id ) );

		$updated = extrachill_users_ability_update_notification_preferences( array( 'push_enabled' => true ) );
		$this->assertTrue( $updated['push_enabled'] );
	}
}
