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
				'actor_id'       => $actor_id,
				'type'           => 'test_notice',
				'title'          => 'A test notification',
				'link'           => 'https://example.org/notice',
				'item_id'        => 42,
				'producer'       => 'tests.notifications',
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

	public function test_schema_health_requires_atomic_delivery_index(): void {
		$this->assertTrue( extrachill_users_notifications_receipt_schema_is_healthy() );
	}
}
