<?php
/**
 * Extra Chill policy for the generic wp-native registration ability.
 *
 * @package ExtraChill\Users
 */

class Test_Wp_Native_Registration_Policy extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'extrachill_users_wp_native_pre_register' ) ) {
			require_once dirname( __DIR__, 2 ) . '/inc/wp-native-bridge.php';
		}
	}

	public function test_native_registration_fails_closed_before_user_creation(): void {
		$registration_data = array(
			'email'    => 'native-registration@example.com',
			'password' => 'secure-password',
			'username' => 'nativeuser',
		);

		$result = extrachill_users_wp_native_pre_register( null, $registration_data, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'extrachill_registration_surface_unavailable', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertFalse( email_exists( 'native-registration@example.com' ) );
	}
}
