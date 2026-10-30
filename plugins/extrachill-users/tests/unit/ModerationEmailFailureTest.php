<?php
/**
 * Regression coverage for moderation email queue failures.
 */

class Test_Moderation_Email_Failure extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Intercept the mail ability itself (core's documented test-mocking seam),
		// so the result is controlled whether ec_send_email() is the real
		// extrachill-network wrapper or the fallback stub below.
		add_filter(
			'wp_pre_execute_ability',
			static function ( $pre, $name, $input ) {
				if ( 'datamachine/send-email-queued' !== $name ) {
					return $pre;
				}
				$GLOBALS['test_ec_send_email_queued_last_args'] = $input;
				return $GLOBALS['test_ec_send_email_queued_result'] ?? array( 'success' => true );
			},
			10,
			3
		);

		if ( ! function_exists( 'ec_send_email_queued' ) ) {
			eval(
				'function ec_send_email_queued( array $args ) {' .
				'    return $GLOBALS["test_ec_send_email_queued_result"] ?? array( "success" => true );' .
				'}'
			);
		}

		require_once dirname( __DIR__, 2 ) . '/inc/core/moderation/email.php';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['test_ec_send_email_queued_result'] );
		parent::tearDown();
	}

	public function test_wp_error_queue_result_returns_false_without_fatal(): void {
		$GLOBALS['test_ec_send_email_queued_result'] = new WP_Error( 'queue_unavailable' );
		$user = self::factory()->user->create_and_get();

		$this->assertFalse(
			extrachill_users_send_moderation_email(
				$user,
				array(
					'state'      => 'banned',
					'reason_key' => 'other',
				)
			)
		);
	}
}
