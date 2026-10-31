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

		foreach ( array( '/extrachill/v1/auth/register', '/extrachill/v1/auth/google', '/extrachill/v1/auth/apple' ) as $path ) {
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

	/** Apple is a public POST transport. */
	public function test_apple_route_is_registered() {
		$route = rest_get_server()->get_routes()['/extrachill/v1/auth/apple'][0];
		$this->assertTrue( $route['methods']['POST'] );
		$this->assertSame( '__return_true', $route['permission_callback'] );
		$this->assertSame( 'extrachill_api_auth_apple_handler', $route['callback'] );
	}

	/** REST rejects omitted required credentials before calling the service. */
	public function test_apple_requires_token_nonce_and_device_id() {
		foreach ( array( 'identity_token', 'nonce', 'device_id' ) as $missing ) {
			$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/apple' );
			$params  = array(
				'identity_token' => 'apple-token',
				'nonce'          => 'raw-nonce',
				'device_id'      => '550e8400-e29b-41d4-a716-446655440000',
			);
			unset( $params[ $missing ] );
			$request->set_body_params( $params );
			$response = rest_get_server()->dispatch( $request );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );
		}
	}

	/** Apple options preserve the raw nonce and forward registration metadata. */
	public function test_apple_options_mapping() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/apple' );
		$params  = array(
			'nonce'               => 'raw+nonce/unchanged=',
			'device_name'         => 'iPhone',
			'given_name'          => 'Jane',
			'family_name'         => 'Doe',
			'registration_source' => 'app',
			'registration_method' => 'apple',
			'registration_page'   => 'https://extrachill.com/join',
			'newsletter_consent'  => true,
		);
		$request->set_body_params( $params );
		$expected = $params;
		unset( $expected['nonce'] );
		$expected['raw_nonce'] = $params['nonce'];
		$this->assertEquals( $expected, extrachill_api_auth_apple_options( $request ) );

		foreach ( array( null, false, 'false', '0' ) as $consent ) {
			$request->set_param( 'newsletter_consent', $consent );
			$this->assertFalse( extrachill_api_auth_apple_options( $request )['newsletter_consent'] );
		}
	}

	/** Apple fails closed when the Users service is absent. */
	public function test_apple_fails_closed_without_users_dependency() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/apple' );
		$request->set_body_params( array(
			'identity_token' => 'apple-token',
			'nonce'          => 'raw-nonce',
			'device_id'      => '550e8400-e29b-41d4-a716-446655440000',
		) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'extrachill_dependency_missing', $response->get_data()['code'] );
	}

	/**
	 * Apple validates UUIDs through Users and delegates successful requests unchanged.
	 */
	public function test_apple_service_boundary() {
		require __DIR__ . '/fixtures/apple-service.php';
		$this->set_apple_configured( true );

		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/apple' );
		$request->set_body_params( array(
			'identity_token' => 'apple-token',
			'nonce'          => 'raw-nonce',
			'device_id'      => 'not-a-uuid',
		) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_device_id', $response->get_data()['code'] );
		$this->assertArrayNotHasKey( 'apple_service_call', $GLOBALS );

		$request->set_param( 'device_id', '550e8400-e29b-41d4-a716-446655440000' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'access_token' => 'service-token' ), $response->get_data() );
		$this->assertSame( array( 'apple-token', '550e8400-e29b-41d4-a716-446655440000', extrachill_api_auth_apple_options( $request ) ), $GLOBALS['apple_service_call'] );

		$GLOBALS['apple_service_result'] = new WP_Error( 'invalid_apple_token', 'Invalid token.', array( 'status' => 401 ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'invalid_apple_token', $response->get_data()['code'] );

		$this->set_apple_configured( false );
		unset( $GLOBALS['apple_service_call'] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'apple_not_configured', $response->get_data()['code'] );
		$this->assertArrayNotHasKey( 'apple_service_call', $GLOBALS );
		unset( $GLOBALS['apple_configured'], $GLOBALS['apple_service_result'] );
		$this->set_apple_configured( false );
	}

	/**
	 * Toggle Apple configuration for both the test double and the real
	 * extrachill-network helper, which reads network site options.
	 *
	 * @param bool $configured Whether Apple sign-in should be configured.
	 */
	private function set_apple_configured( bool $configured ): void {
		$GLOBALS['apple_configured'] = $configured;
		$options = array(
			'extrachill_apple_bundle_id'   => 'com.extrachill.app',
			'extrachill_apple_client_id'   => 'com.extrachill.web',
			'extrachill_apple_team_id'     => 'TEAMID1234',
			'extrachill_apple_key_id'      => 'KEYID12345',
			'extrachill_apple_private_key' => 'test-private-key',
		);
		foreach ( $options as $name => $value ) {
			if ( $configured ) {
				update_site_option( $name, $value );
			} else {
				delete_site_option( $name );
			}
		}
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
