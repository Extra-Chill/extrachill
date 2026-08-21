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
}
