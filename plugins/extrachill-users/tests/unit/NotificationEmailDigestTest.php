<?php
// phpcs:ignoreFile -- test harness requires dynamic stubs and fixture SQL.
/**
 * Regression tests for queued notification digest delivery.
 *
 * @package ExtraChill\Users
 */
class Test_Notification_Email_Digest extends WP_UnitTestCase {

	/**
	 * Set up the digest test fixture.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/inc/notifications/email.php';
		extrachill_users_install_notifications_table();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . extrachill_users_notifications_table_name() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- test table from trusted helper.

		$GLOBALS['test_digest_queue_called'] = false;
		delete_user_meta( get_current_user_id(), EC_NOTIFICATIONS_LAST_EMAILED_META );
	}

	/**
	 * Tear down the digest test fixture.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_digest_queue_args'], $GLOBALS['test_digest_queue_called'] );
		parent::tearDown();
	}

	/**
	 * Build a deterministic queue callback.
	 *
	 * @param mixed $result Queue result.
	 * @return callable
	 */
	private function queue_result( $result ): callable {
		return static function ( array $args ) use ( $result ) {
			$GLOBALS['test_digest_queue_called'] = true;
			$GLOBALS['test_digest_queue_args']   = $args;
			return $result;
		};
	}

	/**
	 * Create an eligible unread notification.
	 *
	 * @return array{int, string}
	 */
	private function make_candidate(): array {
		$user_id = $this->factory->user->create(
			array(
				'user_email' => 'digest-' . wp_generate_password( 8, false ) . '@example.com',
			)
		);
		ec_users_notify_with_receipts(
			$user_id,
			array(
				'actor_id'        => $user_id,
				'type'            => 'digest_test',
				'title'           => 'Unread digest test',
				'link'            => 'https://example.com/notice',
				'item_id'         => 1,
				'producer'        => 'tests.digest',
				'idempotency_key' => 'digest-' . wp_generate_uuid4(),
			)
		);

		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture requires an old notification.
			$wpdb->prepare(
				'UPDATE ' . extrachill_users_notifications_table_name() . ' SET created_at = %s WHERE user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test table from trusted helper.
				gmdate( 'Y-m-d H:i:s', time() - EC_NOTIFICATIONS_EMAIL_DELAY - MINUTE_IN_SECONDS ),
				$user_id
			)
		);

		return array( $user_id, extrachill_users_notifications_table_name() );
	}

	/**
	 * Send a candidate with a controlled queue result.
	 *
	 * @param mixed $result Queue result.
	 * @return array{int, string|null}
	 */
	private function run_with_result( $result ): array {
		list( $user_id, $table ) = $this->make_candidate();
		$this->assertFalse( is_user_logged_in(), 'Digest fixture must run in scheduler context.' );
		$this->assertFalse( ec_notifications_email_send_digest( $user_id, $this->queue_result( $result ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test table from trusted helper.
		$row = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( "SELECT emailed_at FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array( $user_id, $row['emailed_at'] );
	}

	/**
	 * Create multiple backdated unread notifications for one user.
	 *
	 * @param int   $user_id Recipient user ID.
	 * @param int[] $titles  Title per notification, in insert order.
	 * @return string Notifications table name.
	 */
	private function make_unread_batch( int $user_id, array $titles ): string {
		foreach ( array_values( $titles ) as $index => $title ) {
			ec_users_notify_with_receipts(
				$user_id,
				array(
					'actor_id'        => $user_id,
					'type'            => 'digest_test',
					'title'           => $title,
					'link'            => 'https://example.com/notice-' . $index,
					'item_id'         => 100 + $index,
					'producer'        => 'tests.digest',
					'idempotency_key' => 'digest-' . wp_generate_uuid4(),
				)
			);
		}

		global $wpdb;
		$table = extrachill_users_notifications_table_name();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture requires old notifications.
			$wpdb->prepare(
				"UPDATE {$table} SET created_at = %s WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test table from trusted helper.
				gmdate( 'Y-m-d H:i:s', time() - EC_NOTIFICATIONS_EMAIL_DELAY - MINUTE_IN_SECONDS ),
				$user_id
			)
		);

		return $table;
	}

	/**
	 * Verify scheduled sends use the authenticated ability seam.
	 */
	public function test_scheduler_context_runs_queue_as_authenticated(): void {
		list( $user_id, $table ) = $this->make_candidate();
		$this->assertTrue( ec_notifications_email_send_digest( $user_id, $this->queue_result( array( 'success' => true ) ) ) );
		$this->assertTrue( $GLOBALS['test_digest_queue_called'] );
		$this->assertNotEmpty( $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT emailed_at FROM {$table} WHERE user_id = %d", $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test table from trusted helper.
	}

	/**
	 * Verify WP_Error results do not stamp delivery.
	 */
	public function test_wp_error_does_not_stamp_delivery(): void {
		list( $user_id, $emailed_at ) = $this->run_with_result( new WP_Error( 'email_queue_issuer_required', 'Issuer is required.' ) );
		$this->assertEmpty( $emailed_at );
		$this->assertEmpty( get_user_meta( $user_id, EC_NOTIFICATIONS_LAST_EMAILED_META, true ) );
	}

	/**
	 * Verify invalid results do not stamp delivery.
	 */
	public function test_non_array_does_not_stamp_delivery(): void {
		list( $user_id, $emailed_at ) = $this->run_with_result( 'invalid' );
		$this->assertEmpty( $emailed_at );
		$this->assertEmpty( get_user_meta( $user_id, EC_NOTIFICATIONS_LAST_EMAILED_META, true ) );
	}

	/**
	 * Verify unsuccessful queue results do not stamp delivery.
	 */
	public function test_unsuccessful_array_does_not_stamp_delivery(): void {
		list( $user_id, $emailed_at ) = $this->run_with_result(
			array(
				'success'    => false,
				'error_code' => 'queue_rejected',
				'error'      => 'Rejected.',
			)
		);
		$this->assertEmpty( $emailed_at );
		$this->assertEmpty( get_user_meta( $user_id, EC_NOTIFICATIONS_LAST_EMAILED_META, true ) );
	}

	/**
	 * Verify successful queue results stamp delivery and cooldown.
	 */
	public function test_success_stamps_delivery_and_cooldown(): void {
		list( $user_id, $table ) = $this->make_candidate();
		$this->assertTrue( ec_notifications_email_send_digest( $user_id, $this->queue_result( array( 'success' => true ) ) ) );
		$this->assertNotEmpty( $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT emailed_at FROM {$table} WHERE user_id = %d", $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test table from trusted helper.
		$this->assertNotEmpty( get_user_meta( $user_id, EC_NOTIFICATIONS_LAST_EMAILED_META, true ) );
	}

	/**
	 * Regression for #384: the digest counts and previews only never-emailed
	 * unread notifications. Already-emailed backlog stays out of the count,
	 * preview, and "…and N more" footer — mentioned only once as a trailing
	 * line.
	 */
	public function test_digest_counts_and_previews_only_unmailed_notifications(): void {
		$user_id = $this->factory->user->create(
			array(
				'user_email' => 'digest-new-only-' . wp_generate_password( 8, false ) . '@example.com',
			)
		);

		$table = $this->make_unread_batch(
			$user_id,
			array( 'Old notification A', 'Old notification B', 'Old notification C', 'Brand new notification' )
		);

		// Three of the four were already surfaced by prior digests.
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture stamps prior delivery.
			$wpdb->prepare(
				"UPDATE {$table} SET emailed_at = %s WHERE user_id = %d AND title IN ( 'Old notification A', 'Old notification B', 'Old notification C' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test table from trusted helper.
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				$user_id
			)
		);

		$this->assertTrue( ec_notifications_email_send_digest( $user_id, $this->queue_result( array( 'success' => true ) ) ) );
		$this->assertTrue( $GLOBALS['test_digest_queue_called'] );

		$args    = $GLOBALS['test_digest_queue_args'];
		$subject = (string) $args['subject'];
		$body    = (string) $args['context']['body_html'];

		// Subject reflects only the single new notification.
		$this->assertStringContainsString( 'You have 1 unread notification on Extra Chill', $subject );

		// The preview contains exactly the new item, none of the emailed backlog.
		$this->assertSame( 1, substr_count( $body, '<li>' ) );
		$this->assertStringContainsString( 'Brand new notification', $body );
		$this->assertStringNotContainsString( 'Old notification A', $body );
		$this->assertStringNotContainsString( 'Old notification B', $body );
		$this->assertStringNotContainsString( 'Old notification C', $body );

		// No "…and N more" footer, and one trailing mention of the backlog.
		$this->assertStringNotContainsString( '…and', $body );
		$this->assertStringContainsString( 'You also have 3 older unread notifications.', $body );
	}

	/**
	 * Verify the older-backlog trailing line is omitted entirely when the user
	 * has no already-emailed unread notifications.
	 */
	public function test_digest_omits_older_backlog_line_when_none(): void {
		list( $user_id ) = $this->make_candidate();
		$this->assertTrue( ec_notifications_email_send_digest( $user_id, $this->queue_result( array( 'success' => true ) ) ) );
		$this->assertTrue( $GLOBALS['test_digest_queue_called'] );

		$body = (string) $GLOBALS['test_digest_queue_args']['context']['body_html'];

		$this->assertSame( 1, substr_count( $body, '<li>' ) );
		$this->assertStringNotContainsString( 'You also have', $body );
		$this->assertStringNotContainsString( '…and', $body );
	}
}
