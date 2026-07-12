<?php
/**
 * Unit tests for password-reset email failure handling.
 *
 * Regression coverage for the production fatal:
 *   "Cannot use object of type WP_Error as array" at inc/auth/password-reset.php:369
 *
 * Root cause: `ec_send_password_reset_email()` called `ec_send_email()` directly
 * from the anonymous `admin_post_nopriv` context. The underlying
 * `datamachine/send-email` ability gates on PermissionHelper::can_manage(), so
 * `WP_Ability::execute()` short-circuited and returned a `WP_Error` — NOT the
 * documented array envelope — and `! empty( $result['success'] )` fataled.
 *
 * The fix routes the send through extrachill_send_registration_email()
 * (run_as_authenticated seam, same as #110) and guards the envelope with
 * is_wp_error()/is_array() before indexing.
 *
 * These tests run in separate processes so we can define our own
 * ec_send_email() stub (the real one lives in extrachill-network and is
 * unavailable in the unit-test bootstrap).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

class Test_Password_Reset_Email_Failure extends WP_UnitTestCase {

	/**
	 * @var string Path to the captured error_log file for the current test.
	 */
	private $error_log_file;

	/**
	 * @var string|null Original error_log ini setting so we can restore it.
	 */
	private $original_error_log;

	protected function setUp(): void {
		parent::setUp();

		// Capture error_log() output to a per-test temp file.
		$this->error_log_file     = tempnam( sys_get_temp_dir(), 'ec_users_test_log_' );
		$this->original_error_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->error_log_file );

		// Load the SUT, the registration-email wrapper it routes through, and
		// the canonical ec_send_email() stub. The stub return value is flipped
		// via a global before exercising the SUT.
		require_once dirname( __DIR__, 2 ) . '/inc/core/registration-emails.php';
		require_once dirname( __DIR__, 2 ) . '/inc/auth/password-reset.php';

		if ( ! function_exists( 'ec_send_email' ) ) {
			eval(
				'function ec_send_email( array $args ) {' .
				'    $GLOBALS["test_ec_send_email_last_args"] = $args;' .
				'    if ( isset( $GLOBALS["test_ec_send_email_result"] ) ) {' .
				'        return $GLOBALS["test_ec_send_email_result"];' .
				'    }' .
				'    return array( "success" => true );' .
				'}'
			);
		}

		if ( ! function_exists( 'ec_get_site_url' ) ) {
			eval( 'function ec_get_site_url( $site ) { return "https://community.extrachill.com"; }' );
		}
	}

	protected function tearDown(): void {
		if ( $this->error_log_file && file_exists( $this->error_log_file ) ) {
			@unlink( $this->error_log_file );
		}
		if ( null !== $this->original_error_log ) {
			ini_set( 'error_log', $this->original_error_log );
		}

		unset( $GLOBALS['test_ec_send_email_result'], $GLOBALS['test_ec_send_email_last_args'] );

		parent::tearDown();
	}

	/**
	 * Read the captured error_log file for this test.
	 */
	private function read_error_log(): string {
		return $this->error_log_file && file_exists( $this->error_log_file )
			? (string) file_get_contents( $this->error_log_file )
			: '';
	}

	private function make_user(): WP_User {
		$user_id = $this->factory->user->create(
			array(
				'user_login' => 'resettest_' . wp_generate_password( 6, false ),
				'user_email' => 'resettest_' . wp_generate_password( 6, false ) . '@example.com',
			)
		);

		return get_userdata( $user_id );
	}

	// ---------------------------------------------------------------------
	// ec_send_password_reset_email()
	// ---------------------------------------------------------------------

	public function test_returns_true_on_success_envelope(): void {
		$GLOBALS['test_ec_send_email_result'] = array( 'success' => true );

		$user = $this->make_user();

		$this->assertTrue( ec_send_password_reset_email( $user, 'dummy-reset-key' ) );
		$this->assertSame( '', $this->read_error_log(), 'No failure should be logged on success.' );
	}

	public function test_wp_error_result_does_not_fatal_and_returns_false(): void {
		// This is the exact production failure mode: WP_Ability::execute()
		// returns WP_Error( 'ability_invalid_permissions', ... ) when the
		// anonymous reset request fails the ability's permission callback.
		$GLOBALS['test_ec_send_email_result'] = new WP_Error(
			'ability_invalid_permissions',
			'The current user does not have permission to execute this ability.'
		);

		$user = $this->make_user();

		$result = ec_send_password_reset_email( $user, 'dummy-reset-key' );

		$this->assertFalse(
			$result,
			'A WP_Error envelope must yield false (graceful "Failed to send reset email" path), not a fatal.'
		);

		$log = $this->read_error_log();
		$this->assertStringContainsString( 'password_reset', $log );
		$this->assertStringContainsString( 'user_id=' . $user->ID, $log );
		$this->assertStringContainsString( 'ability_invalid_permissions', $log );
	}

	public function test_failure_envelope_returns_false_and_logs(): void {
		$GLOBALS['test_ec_send_email_result'] = array(
			'success' => false,
			'error'   => 'Ability datamachine/send-email is not registered. Is the Data Machine plugin active?',
		);

		$user = $this->make_user();

		$this->assertFalse( ec_send_password_reset_email( $user, 'dummy-reset-key' ) );

		$log = $this->read_error_log();
		$this->assertStringContainsString( 'password_reset', $log );
		$this->assertStringContainsString( 'datamachine/send-email is not registered', $log );
	}

	public function test_send_routes_through_registration_email_wrapper_args(): void {
		$GLOBALS['test_ec_send_email_result'] = array( 'success' => true );

		$user = $this->make_user();
		ec_send_password_reset_email( $user, 'the-reset-key' );

		$args = $GLOBALS['test_ec_send_email_last_args'] ?? null;
		$this->assertIsArray( $args, 'ec_send_email() must receive the send args through the wrapper.' );
		$this->assertSame( $user->user_email, $args['to'] );
		$this->assertSame( 'extrachill/minimal', $args['template'] );
		$this->assertStringContainsString( 'key=the-reset-key', $args['context']['cta_url'] );
		$this->assertStringContainsString( '/reset-password/', $args['context']['cta_url'] );
	}
}
