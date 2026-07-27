<?php
/**
 * Integration tests for optional auth dependency boundaries.
 *
 * @package ExtraChill\API\Tests
 */

/** Verifies API auth adapters fail closed without extrachill-users. */
class Test_Auth_Routes extends WP_UnitTestCase {

	/** Login reports the missing optional dependency before processing credentials. */
	public function test_login_fails_closed_without_users_dependency() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/login' );
		$result  = extrachill_api_auth_login_handler( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'extrachill_dependency_missing', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	/** Registration reports the missing optional dependency before processing input. */
	public function test_registration_fails_closed_without_users_dependency() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/register' );
		$result  = extrachill_api_auth_register_handler( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'extrachill_dependency_missing', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}
}
