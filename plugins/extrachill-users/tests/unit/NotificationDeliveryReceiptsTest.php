<?php
/**
 * Tests for idempotent notification delivery receipts.
 *
 * @package ExtraChill\Users
 */

class Test_Notification_Delivery_Receipts extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		extrachill_users_install_notifications_table();

		global $wpdb;
		$table = extrachill_users_notifications_table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test table from trusted helper.
	}

	private function payload( int $actor_id, array $overrides = array() ): array {
		return array_merge(
			array(
				'actor_id'        => $actor_id,
				'type'            => 'test_notice',
				'title'           => 'A test notification',
				'link'            => 'https://example.org/notice',
				'item_id'         => 42,
				'producer'        => 'tests.notifications',
				'idempotency_key' => 'delivery-42',
			),
			$overrides
		);
	}

	public function test_replay_returns_existing_row_receipt(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();

		$this->assertSame( 0, ec_users_get_unread_count( $recipient ) );

		$first  = ec_users_notify_with_receipts( $recipient, $this->payload( $actor ) );
		$second = ec_users_notify_with_receipts( $recipient, $this->payload( $actor ) );

		$this->assertSame( 1, $first['inserted'] );
		$this->assertSame( 'inserted', $first['recipients'][ $recipient ]['status'] );
		$this->assertSame( 1, $second['existing'] );
		$this->assertSame( 'existing', $second['recipients'][ $recipient ]['status'] );
		$this->assertSame( $first['recipients'][ $recipient ]['notification_id'], $second['recipients'][ $recipient ]['notification_id'] );
		$this->assertSame( 1, ec_users_get_unread_count( $recipient ) );
	}

	public function test_same_key_is_isolated_by_producer_and_recipient(): void {
		$actor      = self::factory()->user->create();
		$recipient1 = self::factory()->user->create();
		$recipient2 = self::factory()->user->create();

		$first = ec_users_notify_with_receipts( array( $recipient1, $recipient2 ), $this->payload( $actor ) );
		$other = ec_users_notify_with_receipts(
			$recipient1,
			$this->payload( $actor, array( 'producer' => 'tests.other-producer' ) )
		);

		$this->assertSame( 2, $first['inserted'] );
		$this->assertSame( 1, $other['inserted'] );
		$this->assertSame( 2, ec_users_get_unread_count( $recipient1 ) );
		$this->assertSame( 1, ec_users_get_unread_count( $recipient2 ) );
	}

	public function test_partial_failure_identifies_retryable_recipient(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$missing   = 999999999;

		$receipt = ec_users_notify_with_receipts( array( $recipient, $missing ), $this->payload( $actor ) );
		$retry   = ec_users_notify_with_receipts( $recipient, $this->payload( $actor ) );

		$this->assertSame( 1, $receipt['inserted'] );
		$this->assertSame( 1, $receipt['failed'] );
		$this->assertSame( 'invalid_user', $receipt['recipients'][ $missing ]['error'] );
		$this->assertSame( 'existing', $retry['recipients'][ $recipient ]['status'] );
	}

	public function test_invalid_actor_and_incomplete_idempotency_fail_each_recipient(): void {
		$recipient = self::factory()->user->create();

		$invalid_actor = ec_users_notify_with_receipts( $recipient, $this->payload( 999999999 ) );
		$incomplete    = ec_users_notify_with_receipts(
			$recipient,
			$this->payload( self::factory()->user->create(), array( 'idempotency_key' => '' ) )
		);

		$this->assertSame( 'invalid_actor', $invalid_actor['recipients'][ $recipient ]['error'] );
		$this->assertSame( 'incomplete_idempotency', $incomplete['recipients'][ $recipient ]['error'] );
		$this->assertSame( 0, ec_users_get_unread_count( $recipient ) );
	}

	public function test_legacy_api_remains_non_idempotent_and_returns_count(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$payload   = $this->payload( $actor );
		unset( $payload['producer'], $payload['idempotency_key'] );

		$this->assertSame( 1, ec_users_notify( $recipient, $payload ) );
		$this->assertSame( 1, ec_users_notify( $recipient, $payload ) );
		$this->assertSame( 2, ec_users_get_unread_count( $recipient ) );
	}

	public function test_legacy_count_wrapper_supports_idempotent_payloads(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$payload   = $this->payload( $actor );

		$this->assertSame( 1, ec_users_notify( $recipient, $payload ) );
		$this->assertSame( 0, ec_users_notify( $recipient, $payload ) );
	}

	public function test_replay_is_network_wide_across_blog_contexts(): void {
		$actor       = self::factory()->user->create();
		$recipient   = self::factory()->user->create();
		$second_blog = self::factory()->blog->create();
		$payload     = $this->payload( $actor );

		$first = ec_users_notify_with_receipts( $recipient, $payload );

		switch_to_blog( $second_blog );
		try {
			$second = ec_users_notify_with_receipts( $recipient, $payload );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( 'existing', $second['recipients'][ $recipient ]['status'] );
		$this->assertSame( $first['recipients'][ $recipient ]['notification_id'], $second['recipients'][ $recipient ]['notification_id'] );
	}

	public function test_replay_does_not_create_new_digest_eligible_row(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$payload   = $this->payload( $actor );

		ec_users_notify_with_receipts( $recipient, $payload );
		$this->assertSame( 1, ec_notifications_email_count_unmailed_unread( $recipient ) );

		ec_users_notify_with_receipts( $recipient, $payload );
		$this->assertSame( 1, ec_notifications_email_count_unmailed_unread( $recipient ) );
	}

	public function test_producer_owned_email_is_suppressed_but_remains_unread(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$receipt   = ec_users_notify_with_receipts(
			$recipient,
			$this->payload( $actor, array( 'producer_owns_email' => true ) )
		);

		$this->assertSame( 1, $receipt['inserted'] );
		$notification_id = $receipt['recipients'][ $recipient ]['notification_id'];
		$this->assertGreaterThan( 0, $notification_id );
		$this->assertSame( 1, ec_users_get_unread_count( $recipient ) );
		$this->assertSame( 0, ec_notifications_email_count_unmailed_unread( $recipient ) );

		global $wpdb;
		$table = extrachill_users_notifications_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT created_at, emailed_at, producer_owns_email FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test table from trusted helper.
				$notification_id
			),
			ARRAY_A
		);
		$this->assertSame( $row['created_at'], $row['emailed_at'] );
		$this->assertSame( '1', $row['producer_owns_email'] );
	}

	public function test_email_reader_excludes_owned_rows_without_changing_in_app_reader(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();

		$owned = ec_users_notify_with_receipts(
			$recipient,
			$this->payload( $actor, array( 'producer_owns_email' => true ) )
		);
		$ordinary = ec_users_notify_with_receipts(
			$recipient,
			$this->payload( $actor, array( 'idempotency_key' => 'ordinary-delivery' ) )
		);

		$in_app = ec_users_get_notifications( $recipient, array( 'unread' => true ) );
		$email  = ec_users_get_notifications(
			$recipient,
			array(
				'unread'                       => true,
				'exclude_producer_owned_email' => true,
			)
		);

		$this->assertSame( 2, $in_app['unread_count'] );
		$this->assertCount( 2, $in_app['notifications'] );
		$this->assertSame( 1, $email['unread_count'] );
		$this->assertCount( 1, $email['notifications'] );
		$this->assertSame( 1, ec_notifications_email_count_unmailed_unread( $recipient ) );
		$this->assertSame( $ordinary['recipients'][ $recipient ]['notification_id'], $email['notifications'][0]['id'] );
		$this->assertNotSame( $owned['recipients'][ $recipient ]['notification_id'], $email['notifications'][0]['id'] );
	}

	public function test_normal_idempotent_notification_remains_email_eligible(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();

		ec_users_notify_with_receipts(
			$recipient,
			$this->payload( $actor, array( 'producer_owns_email' => false ) )
		);

		$this->assertSame( 1, ec_notifications_email_count_unmailed_unread( $recipient ) );
	}

	public function test_producer_owned_email_requires_idempotency_fields(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$payload   = $this->payload( $actor, array( 'producer_owns_email' => true ) );
		unset( $payload['producer'], $payload['idempotency_key'] );

		$receipt = ec_users_notify_with_receipts( $recipient, $payload );

		$this->assertSame( 'email_ownership_requires_idempotency', $receipt['recipients'][ $recipient ]['error'] );
		$this->assertSame( 0, ec_users_get_unread_count( $recipient ) );
	}

	public function test_producer_owned_replay_returns_same_suppressed_receipt(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$payload   = $this->payload( $actor, array( 'producer_owns_email' => true ) );

		$first  = ec_users_notify_with_receipts( $recipient, $payload );
		$second = ec_users_notify_with_receipts( $recipient, $payload );

		$this->assertSame( 'existing', $second['recipients'][ $recipient ]['status'] );
		$this->assertSame( $first['recipients'][ $recipient ]['notification_id'], $second['recipients'][ $recipient ]['notification_id'] );
		$this->assertSame( 1, ec_users_get_unread_count( $recipient ) );
		$this->assertSame( 0, ec_notifications_email_count_unmailed_unread( $recipient ) );
	}

	public function test_replay_rejects_email_ownership_contract_mismatch(): void {
		$actor           = self::factory()->user->create();
		$recipient       = self::factory()->user->create();
		$other_recipient = self::factory()->user->create();
		$payload         = $this->payload( $actor, array( 'producer_owns_email' => true ) );

		$first = ec_users_notify_with_receipts( $recipient, $payload );
		unset( $payload['producer_owns_email'] );
		$replay = ec_users_notify_with_receipts( $recipient, $payload );

		$this->assertSame( 'inserted', $first['recipients'][ $recipient ]['status'] );
		$this->assertSame( 'failed', $replay['recipients'][ $recipient ]['status'] );
		$this->assertSame( 'idempotency_contract_mismatch', $replay['recipients'][ $recipient ]['error'] );
		$this->assertSame( 1, ec_users_get_unread_count( $recipient ) );
		$this->assertSame( 0, ec_notifications_email_count_unmailed_unread( $recipient ) );

		$normal = $this->payload( $actor );
		ec_users_notify_with_receipts( $other_recipient, $normal );
		$normal['producer_owns_email'] = true;
		$owned_replay                  = ec_users_notify_with_receipts( $other_recipient, $normal );

		$this->assertSame( 'failed', $owned_replay['recipients'][ $other_recipient ]['status'] );
		$this->assertSame( 'idempotency_contract_mismatch', $owned_replay['recipients'][ $other_recipient ]['error'] );
		$this->assertSame( 1, ec_notifications_email_count_unmailed_unread( $other_recipient ) );
	}

	public function test_release_removes_exact_producer_owned_receipt(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$payload   = $this->payload( $actor, array( 'producer_owns_email' => true ) );
		$receipt   = ec_users_notify_with_receipts( $recipient, $payload );
		$id        = $receipt['recipients'][ $recipient ]['notification_id'];

		$this->assertTrue( ec_users_release_notification_receipt( $id, $recipient, $payload['producer'], $payload['idempotency_key'] ) );
		$this->assertSame( 0, ec_users_get_unread_count( $recipient ) );
		$this->assertFalse( ec_users_release_notification_receipt( $id, $recipient, $payload['producer'], $payload['idempotency_key'] ) );
	}

	public function test_release_rejects_mismatched_and_normal_receipts(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$owned     = $this->payload( $actor, array( 'producer_owns_email' => true ) );
		$receipt   = ec_users_notify_with_receipts( $recipient, $owned );
		$id        = $receipt['recipients'][ $recipient ]['notification_id'];

		$this->assertFalse( ec_users_release_notification_receipt( $id, $recipient, 'tests.other-producer', $owned['idempotency_key'] ) );
		$this->assertFalse( ec_users_release_notification_receipt( $id, $recipient, $owned['producer'], 'other-key' ) );
		$this->assertSame( 1, ec_users_get_unread_count( $recipient ) );

		$normal         = $this->payload(
			$actor,
			array(
				'idempotency_key' => 'normal-delivery',
			)
		);
		$normal_receipt = ec_users_notify_with_receipts( $recipient, $normal );
		$normal_id      = $normal_receipt['recipients'][ $recipient ]['notification_id'];

		$this->assertFalse( ec_users_release_notification_receipt( $normal_id, $recipient, $normal['producer'], $normal['idempotency_key'] ) );
		$this->assertSame( 2, ec_users_get_unread_count( $recipient ) );
	}

	public function test_release_rejects_read_receipt_and_replay_does_not_recreate_it_unread(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$payload   = $this->payload( $actor, array( 'producer_owns_email' => true ) );
		$receipt   = ec_users_notify_with_receipts( $recipient, $payload );
		$id        = $receipt['recipients'][ $recipient ]['notification_id'];

		$this->assertSame( 1, ec_users_mark_notifications_read( $recipient, $id ) );
		$this->assertFalse( ec_users_release_notification_receipt( $id, $recipient, $payload['producer'], $payload['idempotency_key'] ) );

		$replay = ec_users_notify_with_receipts( $recipient, $payload );

		$this->assertSame( 'existing', $replay['recipients'][ $recipient ]['status'] );
		$this->assertSame( $id, $replay['recipients'][ $recipient ]['notification_id'] );
		$this->assertSame( 0, ec_users_get_unread_count( $recipient ) );
	}

	public function test_retry_inserts_again_after_release(): void {
		$actor     = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$payload   = $this->payload( $actor, array( 'producer_owns_email' => true ) );
		$first     = ec_users_notify_with_receipts( $recipient, $payload );
		$first_id  = $first['recipients'][ $recipient ]['notification_id'];

		$this->assertTrue( ec_users_release_notification_receipt( $first_id, $recipient, $payload['producer'], $payload['idempotency_key'] ) );

		$retry = ec_users_notify_with_receipts( $recipient, $payload );

		$this->assertSame( 'inserted', $retry['recipients'][ $recipient ]['status'] );
		$this->assertNotSame( $first_id, $retry['recipients'][ $recipient ]['notification_id'] );
		$this->assertSame( 1, ec_users_get_unread_count( $recipient ) );
		$this->assertSame( 0, ec_notifications_email_count_unmailed_unread( $recipient ) );
	}

	public function test_schema_health_requires_atomic_delivery_index(): void {
		$this->assertTrue( extrachill_users_notifications_receipt_schema_is_healthy() );
	}
}
