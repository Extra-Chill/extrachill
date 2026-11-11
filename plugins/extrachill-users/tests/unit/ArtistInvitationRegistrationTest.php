<?php
/** Old invitation payloads must not participate in registration. */
class Test_Artist_Invitation_Registration extends WP_UnitTestCase {
	protected function tearDown(): void {
		remove_all_filters( 'extrachill_users_registration_turnstile_verifier' );
		remove_all_filters( 'extrachill_users_registration_admitter' );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	public function test_old_invite_payload_is_silently_ignored(): void {
		add_filter( 'extrachill_users_registration_turnstile_verifier', '__return_true' );
		add_filter( 'extrachill_users_registration_admitter', '__return_true' );
		$requests = 0;
		add_filter( 'pre_http_request', static function ( $preempt ) use ( &$requests ) {
			++$requests;
			return $preempt;
		} );
		$email = 'ignored-invite@example.com';
		$result = extrachill_users_register_with_tokens( array(
			'email'            => $email,
			'password'         => 'secure-password',
			'password_confirm' => 'secure-password',
			'device_id'        => '123e4567-e89b-42d3-a456-426614174000',
			'invite_token'     => 'obsolete-token',
			'invite_artist_id' => 20,
		) );
		$this->assertIsArray( $result );
		$this->assertNotFalse( email_exists( $email ) );
		$this->assertArrayNotHasKey( 'artist_invitation_status', $result );
		$this->assertArrayNotHasKey( 'invite_artist_id', $result );
		$this->assertSame( 0, $requests );
	}
}
