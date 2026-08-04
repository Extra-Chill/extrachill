<?php
/**
 * Tests for Users-owned notification producer contracts.
 *
 * @package ExtraChill\Users
 */

/**
 * Verifies stable contracts for every Users-owned notification producer.
 */
class Test_Notification_Producer_Contracts extends WP_UnitTestCase {

	/**
	 * Events site fixture ID.
	 *
	 * @var int
	 */
	private int $events_blog_id;

	/**
	 * Event fixture ID.
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * Recipient fixture ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Creates notification, attendance, and event fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->events_blog_id = self::factory()->blog->create();
		$this->user_id        = self::factory()->user->create();

		extrachill_users_install_notifications_table();
		extrachill_users_install_concert_tracking_table();
		$wpdb->query( 'DELETE FROM ' . extrachill_users_notifications_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test table from trusted helper.
		$wpdb->query( 'DELETE FROM ' . extrachill_users_concert_tracking_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test table from trusted helper.

		switch_to_blog( $this->events_blog_id );
		try {
			if ( ! post_type_exists( 'data_machine_events' ) ) {
				register_post_type( 'data_machine_events', array( 'public' => true ) );
			}
			$this->event_id = self::factory()->post->create(
				array(
					'post_type'   => 'data_machine_events',
					'post_status' => 'publish',
					'post_title'  => 'Producer Contract Show',
				)
			);

			\DataMachineEvents\Core\EventDatesTable::create_table();
			\DataMachineEvents\Core\EventDatesTable::upsert(
				$this->event_id,
				wp_date( 'Y-m-d H:i:s', time() + ( 5 * DAY_IN_SECONDS ) ),
				null,
				'publish'
			);
		} finally {
			restore_current_blog();
		}

		$wpdb->insert(
			extrachill_users_concert_tracking_table_name(),
			array(
				'user_id'    => $this->user_id,
				'event_id'   => $this->event_id,
				'blog_id'    => $this->events_blog_id,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * A retried reminder converges on its user/event/blog receipt.
	 */
	public function test_show_reminder_replay_uses_user_event_blog_contract(): void {
		ec_users_deliver_show_reminder( $this->user_id, $this->event_id, $this->events_blog_id );
		ec_users_deliver_show_reminder( $this->user_id, $this->event_id, $this->events_blog_id );

		$row = $this->notification_row( EC_USERS_SHOW_REMINDER_TYPE );

		$this->assertSame( EC_USERS_SHOW_REMINDER_PRODUCER, $row['producer'] );
		$this->assertSame( sprintf( 'user:%d:event:%d:blog:%d', $this->user_id, $this->event_id, $this->events_blog_id ), $row['idempotency_key'] );
		$this->assertSame( 1, $this->notification_count( EC_USERS_SHOW_REMINDER_TYPE ) );
	}

	/**
	 * A repeated milestone converges on its user/count receipt.
	 */
	public function test_milestone_replay_uses_user_count_contract(): void {
		ec_users_maybe_notify_milestone( $this->user_id, $this->event_id );
		ec_users_maybe_notify_milestone( $this->user_id, $this->event_id );

		$row = $this->notification_row( 'milestone' );

		$this->assertSame( EC_USERS_CONCERT_MILESTONE_PRODUCER, $row['producer'] );
		$this->assertSame( sprintf( 'user:%d:count:1', $this->user_id ), $row['idempotency_key'] );
		$this->assertSame( 1, $this->notification_count( 'milestone' ) );
	}

	/**
	 * A registered source uses context, blog, and post identity.
	 */
	public function test_publish_source_replay_uses_context_blog_post_contract(): void {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Published Contract' ) );
		update_post_meta( $post->ID, '_producer_contract_submitter', $this->user_id );
		$descriptor = array(
			'meta_key'       => '_producer_contract_submitter',
			'type'           => 'producer_contract_published',
			'title_template' => 'Published: %s',
		);

		ec_users_publish_notify_apply_source( 'producer-contract', $descriptor, $post );
		delete_post_meta( $post->ID, EC_USERS_PUBLISH_NOTIFIED_META_PREFIX . 'producer-contract' );
		ec_users_publish_notify_apply_source( 'producer-contract', $descriptor, $post );

		$row = $this->notification_row( 'producer_contract_published' );

		$this->assertSame( EC_USERS_PUBLISH_NOTIFY_PRODUCER, $row['producer'] );
		$this->assertSame( sprintf( 'context:producer-contract:blog:%d:post:%d', get_current_blog_id(), $post->ID ), $row['idempotency_key'] );
		$this->assertSame( '1', $row['producer_owns_email'] );
		$this->assertNotEmpty( $row['emailed_at'] );
		$this->assertSame( 1, $this->notification_count( 'producer_contract_published' ) );
	}

	/**
	 * Failed publish delivery retains the source's attempt-once guard.
	 */
	public function test_publish_source_preserves_attempt_guard_after_failed_receipt(): void {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Failed Contract' ) );
		update_post_meta( $post->ID, '_failed_contract_submitter', 999999999 );

		ec_users_publish_notify_apply_source(
			'failed-contract',
			array(
				'meta_key'       => '_failed_contract_submitter',
				'type'           => 'failed_contract_published',
				'title_template' => 'Published: %s',
			),
			$post
		);

		$this->assertNotEmpty( get_post_meta( $post->ID, EC_USERS_PUBLISH_NOTIFIED_META_PREFIX . 'failed-contract', true ) );
		$this->assertSame( 0, $this->notification_count( 'failed_contract_published' ) );
	}

	/**
	 * A failed immediate-email admission releases the receipt for retry.
	 */
	public function test_publish_source_releases_receipt_when_email_cannot_queue(): void {
		wp_update_user(
			array(
				'ID'         => $this->user_id,
				'user_email' => '',
			)
		);
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Retry Contract' ) );
		update_post_meta( $post->ID, '_retry_contract_submitter', $this->user_id );
		$descriptor = array(
			'meta_key'       => '_retry_contract_submitter',
			'type'           => 'retry_contract_published',
			'title_template' => 'Published: %s',
		);

		ec_users_publish_notify_apply_source(
			'retry-contract',
			$descriptor,
			$post
		);

		$this->assertEmpty( get_post_meta( $post->ID, EC_USERS_PUBLISH_NOTIFIED_META_PREFIX . 'retry-contract', true ) );
		$this->assertSame( 0, $this->notification_count( 'retry_contract_published' ) );

		wp_update_user(
			array(
				'ID'         => $this->user_id,
				'user_email' => 'retry@example.com',
			)
		);
		ec_users_publish_notify_apply_source( 'retry-contract', $descriptor, $post );

		$this->assertNotEmpty( get_post_meta( $post->ID, EC_USERS_PUBLISH_NOTIFIED_META_PREFIX . 'retry-contract', true ) );
		$this->assertSame( 1, $this->notification_count( 'retry_contract_published' ) );
	}

	/**
	 * Fetch one notification contract row.
	 *
	 * @param string $type Notification type.
	 * @return array<string, string>
	 */
	private function notification_row( string $type ): array {
		global $wpdb;

		$table = extrachill_users_notifications_table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT producer, idempotency_key, producer_owns_email, emailed_at FROM {$table} WHERE user_id = %d AND type = %s", $this->user_id, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.

		$this->assertIsArray( $row );
		return $row;
	}

	/**
	 * Count recipient notifications of one type.
	 *
	 * @param string $type Notification type.
	 */
	private function notification_count( string $type ): int {
		global $wpdb;

		$table = extrachill_users_notifications_table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND type = %s", $this->user_id, $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
	}
}
