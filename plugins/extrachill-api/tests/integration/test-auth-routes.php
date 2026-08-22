<?php
/**
 * Integration tests for optional auth dependency boundaries.
 *
 * @package ExtraChill\API\Tests
 */

/** Verifies API auth adapters fail closed without extrachill-users. */
class Test_Auth_Routes extends WP_UnitTestCase {

	/** Registration transports expose default-off newsletter consent. */
	public function test_registration_routes_declare_default_off_newsletter_consent() {
		$routes = rest_get_server()->get_routes();

		foreach ( array( '/extrachill/v1/auth/register', '/extrachill/v1/auth/google' ) as $path ) {
			$this->assertArrayHasKey( $path, $routes );
			$this->assertArrayHasKey( 'newsletter_consent', $routes[ $path ][0]['args'] );
			$this->assertFalse( $routes[ $path ][0]['args']['newsletter_consent']['default'] );
		}
	}

	/** Password registration forwards affirmative consent to Users policy. */
	public function test_password_registration_forwards_newsletter_consent() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/register' );
		$request->set_param( 'newsletter_consent', true );
		$payload = extrachill_api_auth_registration_payload( $request, '550e8400-e29b-41d4-a716-446655440000' );

		$this->assertTrue( $payload['newsletter_consent'] );
	}

	/** Google registration forwards affirmative consent to Users policy. */
	public function test_google_registration_forwards_newsletter_consent() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/google' );
		$request->set_param( 'newsletter_consent', true );
		$options = extrachill_api_auth_google_options( $request );

		$this->assertTrue( $options['newsletter_consent'] );
	}

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
