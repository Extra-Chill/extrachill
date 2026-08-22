<?php
/**
 * Unit tests for registration-email failure handling.
 *
 * Covers the fix for Extra-Chill/extrachill-users#56: when ec_send_email()
 * returns a failure envelope, the three call sites in
 * inc/core/registration-emails.php must:
 *   - capture the result
 *   - log the failure via error_log()
 *   - (admin path only) fall back to wp_mail()
 *   - (welcome-email path) return false so the orchestrator does NOT mark
 *     welcome_email_sent=1 — letting the hourly cron retry pick it up
 *
 * These tests run in separate processes so we can define our own
 * ec_send_email() stub (the real one lives in extrachill-network and is
 * unavailable in the unit-test bootstrap).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

class Test_Registration_Email_Failure extends WP_UnitTestCase {

	/**
	 * @var string Path to the captured error_log file for the current test.
	 */
	private $error_log_file;

	/**
	 * @var string|null Original error_log ini setting so we can restore it.
	 */
	private $original_error_log;

	/**
	 * @var array Captured wp_mail() calls intercepted via pre_wp_mail filter.
	 */
	public static $captured_wp_mail = array();

	protected function setUp(): void {
		parent::setUp();

		// Capture error_log() output to a per-test temp file.
		$this->error_log_file     = tempnam( sys_get_temp_dir(), 'ec_users_test_log_' );
		$this->original_error_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->error_log_file );

		// Capture wp_mail() calls without actually sending mail.
		self::$captured_wp_mail = array();
		add_filter( 'pre_wp_mail', array( __CLASS__, 'capture_wp_mail' ), 10, 2 );

		// Load the SUT and the canonical ec_send_email() stub. The stub is
		// declared in a sibling helper file so each test process can flip the
		// return value via a global before exercising the SUT.
		require_once dirname( __DIR__, 2 ) . '/inc/core/registration-emails.php';

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

		if ( ! function_exists( 'extrachill_get_user_community_profile_edit_url' ) ) {
			eval( 'function extrachill_get_user_community_profile_edit_url( $user_id, $user_email = "" ) { return "https://community.extrachill.com/u/test-user/edit/"; }' );
		}
	}

	protected function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( __CLASS__, 'capture_wp_mail' ), 10 );

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
	 * pre_wp_mail filter callback — captures the call and short-circuits
	 * wp_mail() so no real mail is sent.
	 *
	 * @param mixed $return Filter return; non-null short-circuits wp_mail().
	 * @param array $atts   wp_mail() arguments.
	 * @return bool Always true to indicate "mail sent" without sending.
	 */
	public static function capture_wp_mail( $return, $atts ) {
		self::$captured_wp_mail[] = $atts;
		return true;
	}

	/**
	 * Read the captured error_log file for this test.
	 */
	private function read_error_log(): string {
		return $this->error_log_file && file_exists( $this->error_log_file )
			? (string) file_get_contents( $this->error_log_file )
			: '';
	}

	private function make_user(): int {
		return $this->factory->user->create(
			array(
				'user_login' => 'failtest_' . wp_generate_password( 6, false ),
				'user_email' => 'failtest_' . wp_generate_password( 6, false ) . '@example.com',
			)
		);
	}

	// ---------------------------------------------------------------------
	// extrachill_notify_admin_new_user() — admin notification path
	// ---------------------------------------------------------------------

	public function test_admin_notification_logs_failure_when_ec_send_email_fails(): void {
		$GLOBALS['test_ec_send_email_result'] = array(
			'success' => false,
			'error'   => 'Ability datamachine/send-email is not registered. Is the Data Machine plugin active?',
		);

		$user_id = $this->make_user();
		extrachill_notify_admin_new_user( $user_id, 'https://extrachill.com/join/', 'web', 'standard' );

		$log = $this->read_error_log();
		$this->assertStringContainsString( 'admin_new_user_notification', $log );
		$this->assertStringContainsString( 'user_id=' . $user_id, $log );
		$this->assertStringContainsString( 'datamachine/send-email is not registered', $log );
	}

	public function test_admin_notification_falls_back_to_wp_mail_on_failure(): void {
		$GLOBALS['test_ec_send_email_result'] = array(
			'success' => false,
			'error'   => 'wp_mail returned false',
		);

		$user_id = $this->make_user();
		extrachill_notify_admin_new_user( $user_id, 'https://extrachill.com/join/', 'web', 'standard' );

		$this->assertNotEmpty(
			self::$captured_wp_mail,
			'wp_mail() fallback must fire when ec_send_email() returns failure.'
		);
		$last = end( self::$captured_wp_mail );
		$this->assertSame( get_option( 'admin_email' ), $last['to'] );
		$this->assertStringContainsString( '(fallback)', $last['subject'] );
		$this->assertStringContainsString( 'user_id', strtolower( $last['message'] ) );
	}

	public function test_admin_notification_does_not_fallback_on_success(): void {
		$GLOBALS['test_ec_send_email_result'] = array( 'success' => true );

		$user_id = $this->make_user();
		extrachill_notify_admin_new_user( $user_id, 'https://extrachill.com/join/', 'web', 'standard' );

		$this->assertEmpty(
			self::$captured_wp_mail,
			'wp_mail() fallback must NOT fire when ec_send_email() succeeds.'
		);
		$this->assertSame( '', $this->read_error_log(), 'No failure should be logged on success.' );
	}

	// ---------------------------------------------------------------------
	// extrachill_send_welcome_email_complete() — welcome email path
	// ---------------------------------------------------------------------

	public function test_welcome_complete_returns_false_and_logs_on_failure(): void {
		$GLOBALS['test_ec_send_email_result'] = array(
			'success' => false,
			'error'   => 'Template rendering failed',
		);

		$user_id   = $this->make_user();
		$user_data = get_userdata( $user_id );

		$result = extrachill_send_welcome_email_complete( $user_data );

		$this->assertFalse(
			$result,
			'Welcome email helper must return false on ec_send_email() failure so the orchestrator does not mark welcome_email_sent=1.'
		);

		$log = $this->read_error_log();
		$this->assertStringContainsString( 'welcome_email_complete', $log );
		$this->assertStringContainsString( 'user_id=' . $user_id, $log );
		$this->assertStringContainsString( 'Template rendering failed', $log );

		$this->assertEmpty( self::$captured_wp_mail, 'Welcome path must NOT trigger the wp_mail fallback (admin path only).' );
	}

	public function test_welcome_complete_returns_true_on_success(): void {
		$GLOBALS['test_ec_send_email_result'] = array( 'success' => true );

		$user_id   = $this->make_user();
		$user_data = get_userdata( $user_id );

		$this->assertTrue( extrachill_send_welcome_email_complete( $user_data ) );
		$this->assertSame( '', $this->read_error_log() );
	}

	public function test_welcome_complete_uses_participation_language_without_followers(): void {
		$GLOBALS['test_ec_send_email_result'] = array( 'success' => true );

		$user_id   = $this->make_user();
		$user_data = get_userdata( $user_id );

		$this->assertTrue( extrachill_send_welcome_email_complete( $user_data ) );

		$args = $GLOBALS['test_ec_send_email_last_args'];
		$this->assertSame( 'See What’s Happening', $args['context']['cta_label'] );
		$this->assertStringContainsString( 'Make yourself at home', $args['context']['body_html'] );
		$this->assertDoesNotMatchRegularExpression( '/\bfollow(?:er|ers|ing|s|ed)?\b/i', $args['context']['body_html'] );
	}

	// ---------------------------------------------------------------------
	// extrachill_send_welcome_email_incomplete() — onboarding-incomplete path
	// ---------------------------------------------------------------------

	public function test_welcome_incomplete_returns_false_and_logs_on_failure(): void {
		$GLOBALS['test_ec_send_email_result'] = array(
			'success' => false,
			'error'   => 'WordPress Abilities API not available',
		);

		$user_id   = $this->make_user();
		$user_data = get_userdata( $user_id );

		$result = extrachill_send_welcome_email_incomplete( $user_data );

		$this->assertFalse( $result );

		$log = $this->read_error_log();
		$this->assertStringContainsString( 'welcome_email_incomplete', $log );
		$this->assertStringContainsString( 'user_id=' . $user_id, $log );
		$this->assertStringContainsString( 'WordPress Abilities API not available', $log );
	}

	public function test_welcome_incomplete_returns_true_on_success(): void {
		$GLOBALS['test_ec_send_email_result'] = array( 'success' => true );

		$user_id   = $this->make_user();
		$user_data = get_userdata( $user_id );

		$this->assertTrue( extrachill_send_welcome_email_incomplete( $user_data ) );
		$this->assertSame( '', $this->read_error_log() );
	}

	public function test_welcome_incomplete_invites_users_into_the_clubhouse(): void {
		$GLOBALS['test_ec_send_email_result'] = array( 'success' => true );

		$user_id   = $this->make_user();
		$user_data = get_userdata( $user_id );

		$this->assertTrue( extrachill_send_welcome_email_incomplete( $user_data ) );

		$args = $GLOBALS['test_ec_send_email_last_args'];
		$this->assertSame( 'Make yourself at home at Extra Chill', $args['subject'] );
		$this->assertSame( 'https://community.extrachill.com', $args['context']['cta_url'] );
		$this->assertSame( 'See What’s Happening', $args['context']['cta_label'] );
		$this->assertStringContainsString( 'online music scene', $args['context']['body_html'] );
		$this->assertStringContainsString( 'There’s no setup checklist', $args['context']['body_html'] );
		$this->assertStringContainsString( 'A few quick answers', $args['context']['body_html'] );
		$this->assertStringContainsString( 'Do I need to finish my profile?', $args['context']['body_html'] );
		$this->assertStringContainsString( 'Is Extra Chill just a music blog?', $args['context']['body_html'] );
		$this->assertStringContainsString( 'Find shows and track concerts', $args['context']['body_html'] );
		$this->assertStringContainsString( 'https://community.extrachill.com/u/test-user/edit/', $args['context']['body_html'] );
		$this->assertStringNotContainsString( 'https://community.extrachill.com/settings/', $args['context']['body_html'] );
		$this->assertDoesNotMatchRegularExpression( '/\bfollow(?:er|ers|ing|s|ed)?\b/i', $args['context']['body_html'] );
	}

	// ---------------------------------------------------------------------
	// extrachill_log_email_failure() — log formatter
	// ---------------------------------------------------------------------

	public function test_log_formatter_uses_error_string_when_present(): void {
		extrachill_log_email_failure(
			'unit_test',
			42,
			'someone@example.com',
			'Test Subject',
			array( 'success' => false, 'error' => 'Explicit error string' )
		);

		$log = $this->read_error_log();
		$this->assertStringContainsString( '[unit_test]', $log );
		$this->assertStringContainsString( 'user_id=42', $log );
		$this->assertStringContainsString( 'recipient=someone@example.com', $log );
		$this->assertStringContainsString( 'subject="Test Subject"', $log );
		$this->assertStringContainsString( 'Explicit error string', $log );
	}

	public function test_log_formatter_falls_back_to_message_when_no_error(): void {
		extrachill_log_email_failure(
			'unit_test',
			42,
			'someone@example.com',
			'Test Subject',
			array( 'success' => false, 'message' => 'Message string instead' )
		);

		$this->assertStringContainsString( 'Message string instead', $this->read_error_log() );
	}

	public function test_log_formatter_handles_non_array_result(): void {
		extrachill_log_email_failure( 'unit_test', 42, 'someone@example.com', 'Test Subject', null );

		$log = $this->read_error_log();
		$this->assertStringContainsString( 'unknown error', $log );
	}
}
