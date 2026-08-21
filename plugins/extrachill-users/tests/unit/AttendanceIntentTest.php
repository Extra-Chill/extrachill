<?php
// phpcs:ignoreFile -- Unit tests intentionally exercise signed request state without form nonces.
/**
 * Tests for signed attendance continuations.
 *
 * @package ExtraChill\Users
 * @file
 */
class Test_Attendance_Intent extends WP_UnitTestCase {
	/** Clear request state after each test. */
	protected function tearDown(): void {
		unset( $_GET[ EC_USERS_ATTENDANCE_INTENT_PARAM ] );
		parent::tearDown();
	}

	/** A changed signature must not become pending state. */
	public function test_tampered_intent_fails_closed(): void {
		$token                                    = ec_users_create_attendance_intent( 123, 456 );
		$_GET[ EC_USERS_ATTENDANCE_INTENT_PARAM ] = substr( $token, 0, -1 ) . ( 'a' === substr( $token, -1 ) ? 'b' : 'a' );

		$this->assertNull( ec_users_get_attendance_intent( 123, 456 ) );
	}

	/** An expired signed token must not be replayable. */
	public function test_expired_intent_fails_closed(): void {
		$payload = '123|456|mark|' . ( time() - 1 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Match the production URL-safe token format.
		$token                                    = rtrim( strtr( base64_encode( $payload . '|' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ) ), '+/', '-_' ), '=' );
		$_GET[ EC_USERS_ATTENDANCE_INTENT_PARAM ] = $token;

		$this->assertNull( ec_users_get_attendance_intent( 123, 456 ) );
	}

	/** The signed target must match the page event and canonical blog. */
	public function test_wrong_event_and_blog_do_not_replay_intent(): void {
		$_GET[ EC_USERS_ATTENDANCE_INTENT_PARAM ] = ec_users_create_attendance_intent( 123, 456 );

		$this->assertNull( ec_users_get_attendance_intent( 124, 456 ) );
		$this->assertNull( ec_users_get_attendance_intent( 123, 457 ) );
	}
}
