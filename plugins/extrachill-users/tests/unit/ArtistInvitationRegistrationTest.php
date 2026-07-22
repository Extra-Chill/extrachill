<?php
/**
 * Artist invitation registration tests.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify invitation failures stop before user creation when possible.
 */
class Test_Artist_Invitation_Registration extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_EXTRACHILL_CLIENT'] );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'extrachill_users_registration_turnstile_verifier' );
		delete_transient( extrachill_users_registration_attempt_key() );
		parent::tearDown();
	}

	public function test_incomplete_invitation_is_rejected_before_token_registration_creates_user(): void {
		add_filter( 'extrachill_users_registration_turnstile_verifier', array( $this, 'pass_turnstile' ) );
		$email = 'failed-invite@example.com';

		$result = extrachill_users_register_with_tokens(
			array(
				'email'            => $email,
				'password'         => 'secure-password',
				'password_confirm' => 'secure-password',
				'device_id'        => '123e4567-e89b-42d3-a456-426614174000',
				'invite_token'     => 'token-without-artist',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_artist_invitation', $result->get_error_code() );
		$this->assertFalse( email_exists( $email ) );
	}

	public function test_incomplete_browser_invitation_contract_is_rejected(): void {
		$result = ec_users_request_artist_invitation( 'new@example.com', 'token', 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_artist_invitation', $result->get_error_code() );
	}

	public function test_invitation_request_uses_artist_site_http_ability(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->assertStringContainsString( '/wp-json/wp-abilities/v1/abilities/extrachill/artist-invitation/run', $url );
				$this->assertStringContainsString( '"artist_id":20', $args['body'] );
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'status'    => 'valid',
							'artist_id' => 20,
						)
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$result = ec_users_request_artist_invitation( 'new@example.com', 'secret', 20 );
		$this->assertSame(
			array(
				'status'    => 'valid',
				'artist_id' => 20,
			),
			$result
		);
		$this->assertFalse( has_filter( 'ec_cross_site_use_http_loopback' ) );
	}

	public function test_token_registration_returns_account_with_pending_repair_status(): void {
		$this->assert_token_registration_invitation_outcome( 'artist_membership_busy', 409, 'pending_repair', true );
	}

	public function test_token_registration_preserves_permanent_invitation_failure(): void {
		$this->assert_token_registration_invitation_outcome( 'invalid_artist_invitation', 400, 'failed', false );
	}

	public function test_token_registration_preserves_manual_repair_invitation_failure(): void {
		$this->assert_token_registration_invitation_outcome( 'artist_invitation_rollback_failed', 500, 'manual_repair', false );
	}

	public function test_browser_registration_reports_retryable_invitation_outcome(): void {
		$this->assert_browser_invitation_outcome( 'artist_membership_busy', 409, 'pending_repair', true );
	}

	public function test_browser_registration_reports_permanent_invitation_outcome(): void {
		$this->assert_browser_invitation_outcome( 'invalid_artist_invitation', 400, 'failed', false );
	}

	public function test_browser_registration_reports_manual_repair_invitation_outcome(): void {
		$this->assert_browser_invitation_outcome( 'artist_invitation_rollback_failed', 500, 'manual_repair', false );
	}

	private function assert_token_registration_invitation_outcome( string $error_code, int $error_status, string $expected_status, bool $expected_retryable ): void {
		add_filter( 'extrachill_users_registration_turnstile_verifier', array( $this, 'pass_turnstile' ) );
		$request_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$request_count, $error_code, $error_status ) {
				++$request_count;
				if ( 1 === $request_count ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'status'    => 'valid',
								'artist_id' => 20,
							)
						),
						'cookies'  => array(),
					);
				}
				return array(
					'response' => array(
						'code'    => $error_status,
						'message' => 'Invitation failure',
					),
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'code'    => $error_code,
							'message' => 'Precise invitation failure.',
						)
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$email  = sanitize_key( $error_code ) . '@example.com';
		$result = extrachill_users_register_with_tokens(
			array(
				'email'            => $email,
				'password'         => 'secure-password',
				'password_confirm' => 'secure-password',
				'device_id'        => '123e4567-e89b-42d3-a456-426614174000',
				'invite_token'     => 'secret-token',
				'invite_artist_id' => 20,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $expected_status, $result['artist_invitation_status'] );
		$this->assertSame( $expected_retryable, $result['artist_invitation_retryable'] );
		$this->assertSame( $error_code, $result['artist_invitation_error']['code'] );
		$this->assertSame( 'Precise invitation failure.', $result['artist_invitation_error']['message'] );
		$this->assertSame( $error_status, $result['artist_invitation_error']['status'] );
		$this->assertNotFalse( email_exists( $email ) );
		$this->assertSame( 2, $request_count );
	}

	public function pass_turnstile(): bool {
		return true;
	}

	private function assert_browser_invitation_outcome( string $error_code, int $error_status, string $expected_status, bool $expected_retryable ): void {
		$user_id  = self::factory()->user->create( array( 'user_email' => sanitize_key( $error_code ) . '-browser@example.com' ) );
		$redirect = new class( 'https://example.org/register' ) extends EC_Redirect_Handler {
			/**
			 * Captured redirect URL.
			 *
			 * @var string
			 */
			public string $captured_url = '';

			public function redirect_to( string $url ): void {
				$this->captured_url = $url;
			}
		};
		$outcome  = ec_users_classify_artist_invitation_error(
			new WP_Error( $error_code, 'Precise invitation failure.', array( 'status' => $error_status ) )
		);

		extrachill_auto_login_new_user( $user_id, $redirect, null, '', $outcome );

		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertSame( $expected_status, $outcome['status'] );
		$this->assertSame( $expected_retryable, $outcome['retryable'] );
		$this->assertStringContainsString( 'artist_invitation=' . $expected_status, $redirect->captured_url );
		$this->assertStringContainsString( 'artist_invitation_error=' . $error_code, $redirect->captured_url );
		$this->assertStringContainsString( 'artist_invitation_error_status=' . $error_status, $redirect->captured_url );
	}
}
