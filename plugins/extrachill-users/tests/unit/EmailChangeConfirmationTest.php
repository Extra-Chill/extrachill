<?php
/**
 * Tests for the front-end email change confirmation.
 *
 * @package ExtraChill\Users
 */

/**
 * Members confirm email changes on Settings, not wp-admin/profile.php.
 */
class Test_Email_Change_Confirmation extends WP_UnitTestCase {

	/**
	 * Load the SUT and stub the network mail wrapper when it is unavailable.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/inc/core/email-change-confirmation.php';

		unset( $GLOBALS['test_ec_send_email_last_args'] );
		add_filter( 'wp_pre_execute_ability', array( $this, 'capture_send_email' ), 10, 3 );

		if ( ! function_exists( 'ec_send_email' ) ) {
			eval(
				'function ec_send_email( array $args ) {' .
				'    $GLOBALS["test_ec_send_email_last_args"] = $args;' .
				'    return array( "success" => true );' .
				'}'
			);
		}
	}

	/**
	 * Capture the send-email ability input.
	 *
	 * @param mixed  $pre   Short-circuit value.
	 * @param string $name  Ability name.
	 * @param mixed  $input Ability input.
	 * @return mixed
	 */
	public function capture_send_email( $pre, $name, $input ) {
		if ( 'datamachine/send-email' !== $name ) {
			return $pre;
		}
		$GLOBALS['test_ec_send_email_last_args'] = $input;
		return array( 'success' => true );
	}

	/**
	 * Create a user with a pending email change.
	 *
	 * @param string $new_email Pending address.
	 * @param string $hash      Pending hash.
	 * @return int
	 */
	private function user_with_pending_email( string $new_email = 'new-address@example.com', string $hash = 'abc123hash' ): int {
		$user_id = self::factory()->user->create( array( 'user_email' => 'old-address@example.com' ) );
		update_user_meta(
			$user_id,
			'_new_email',
			array(
				'hash'     => $hash,
				'newemail' => $new_email,
			)
		);
		return $user_id;
	}

	public function test_valid_hash_updates_email_and_clears_pending_meta(): void {
		$user_id = $this->user_with_pending_email();

		$result = extrachill_users_confirm_email_change( $user_id, 'abc123hash' );

		$this->assertSame( 'new-address@example.com', $result );
		$this->assertSame( 'new-address@example.com', get_userdata( $user_id )->user_email );
		$this->assertSame( '', get_user_meta( $user_id, '_new_email', true ) );
		$this->assertSame( 'confirmed', extrachill_users_email_change_status( $result ) );
	}

	public function test_wrong_hash_is_rejected_without_changes(): void {
		$user_id = $this->user_with_pending_email();

		$result = extrachill_users_confirm_email_change( $user_id, 'not-the-hash' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_email_hash', $result->get_error_code() );
		$this->assertSame( 'old-address@example.com', get_userdata( $user_id )->user_email );
		$this->assertIsArray( get_user_meta( $user_id, '_new_email', true ) );
		$this->assertSame( 'invalid', extrachill_users_email_change_status( $result ) );
	}

	public function test_empty_hash_is_rejected(): void {
		$user_id = $this->user_with_pending_email();

		$result = extrachill_users_confirm_email_change( $user_id, '' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_email_hash', $result->get_error_code() );
		$this->assertSame( 'old-address@example.com', get_userdata( $user_id )->user_email );
	}

	public function test_email_taken_by_another_user_is_rejected(): void {
		self::factory()->user->create( array( 'user_email' => 'taken@example.com' ) );
		$user_id = $this->user_with_pending_email( 'taken@example.com' );

		$result = extrachill_users_confirm_email_change( $user_id, 'abc123hash' );

		$this->assertWPError( $result );
		$this->assertSame( 'email_exists', $result->get_error_code() );
		$this->assertSame( 'old-address@example.com', get_userdata( $user_id )->user_email );
		$this->assertSame( 'taken', extrachill_users_email_change_status( $result ) );
	}

	public function test_no_pending_change_is_rejected(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'old-address@example.com' ) );

		$result = extrachill_users_confirm_email_change( $user_id, 'abc123hash' );

		$this->assertWPError( $result );
		$this->assertSame( 'no_pending_email', $result->get_error_code() );
		$this->assertSame( 'old-address@example.com', get_userdata( $user_id )->user_email );
	}

	public function test_hash_cannot_be_reused_after_confirmation(): void {
		$user_id = $this->user_with_pending_email();

		extrachill_users_confirm_email_change( $user_id, 'abc123hash' );
		$result = extrachill_users_confirm_email_change( $user_id, 'abc123hash' );

		$this->assertWPError( $result );
		$this->assertSame( 'no_pending_email', $result->get_error_code() );
	}

	public function test_notices_use_theme_notice_types(): void {
		$this->assertSame( 'success', extrachill_users_email_change_notice( 'confirmed' )['type'] );
		$this->assertSame( 'error', extrachill_users_email_change_notice( 'taken' )['type'] );
		$this->assertSame( 'error', extrachill_users_email_change_notice( 'invalid' )['type'] );
		$this->assertNull( extrachill_users_email_change_notice( 'bogus' ) );
	}

	public function test_change_request_email_links_to_front_end_settings(): void {
		$user = self::factory()->user->create_and_get();

		$email = extrachill_users_email_change_request_email( $user, 'new-address@example.com', 'abc123hash' );

		$this->assertSame( 'new-address@example.com', $email['to'] );
		$cta = $email['context']['cta_url'];
		$this->assertSame( extrachill_users_settings_url() . '?ec_confirm_email=abc123hash', $cta );
		$this->assertStringEndsWith( '/settings/?ec_confirm_email=abc123hash', $cta );
		$this->assertStringNotContainsString( 'profile.php', $cta );
		$this->assertStringNotContainsString( 'newuseremail', $cta );
	}

	public function test_change_request_sends_front_end_link_and_stores_pending_hash(): void {
		if ( ! $this->send_email_is_stub() && ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'datamachine/send-email' ) ) ) {
			$this->markTestSkipped( 'datamachine/send-email is not registered in this environment.' );
		}

		$user_id = self::factory()->user->create( array( 'user_email' => 'old-address@example.com' ) );
		wp_set_current_user( $user_id );

		$result = extrachill_users_ability_change_email( array( 'new_email' => 'new-address@example.com' ) );

		$this->assertIsArray( $result );
		$pending = get_user_meta( $user_id, '_new_email', true );
		$this->assertIsArray( $pending );

		$args = $GLOBALS['test_ec_send_email_last_args'] ?? array();
		$this->assertSame(
			extrachill_users_email_change_confirm_url( $pending['hash'] ),
			$args['context']['cta_url'] ?? null
		);
	}

	/**
	 * Whether ec_send_email() is this suite's stub rather than the network wrapper.
	 *
	 * @return bool
	 */
	private function send_email_is_stub(): bool {
		$fn = new ReflectionFunction( 'ec_send_email' );
		return false !== strpos( (string) $fn->getFileName(), 'eval' );
	}
}
