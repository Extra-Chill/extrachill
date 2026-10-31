<?php
/**
 * Coverage for the public core multisite signup surface (#239).
 */

class Test_Core_Signup_Surface extends WP_UnitTestCase {
	/**
	 * Original request server state.
	 *
	 * @var array<string, mixed>
	 */
	private $original_server;

	protected function setUp(): void {
		parent::setUp();
		$this->original_server = $_SERVER;
	}

	public function test_policy_is_attached_before_core_processes_signup(): void {
		$this->assertSame( 0, has_action( 'before_signup_header', 'extrachill_users_close_core_signup_surface' ) );
	}

	public function test_main_site_get_redirects_to_branded_registration(): void {
		$this->assertCoreSignupRedirectsToBrandedRegistration();
	}

	public function test_subsite_get_uses_network_branded_registration_after_core_canonicalization(): void {
		$this->assertCoreCanonicalizesSubsiteSignup();
		$this->assertCoreSignupRedirectsToBrandedRegistration();
	}

	public function test_post_is_rejected_before_an_account_can_be_created(): void {
		$this->assertCoreSignupPostIsRejected();
	}

	public function test_subsite_post_is_rejected_after_core_canonicalization(): void {
		$this->assertCoreCanonicalizesSubsiteSignup();
		$this->assertCoreSignupPostIsRejected();
	}

	public function test_branded_registration_and_login_handlers_remain_registered(): void {
		$this->assertNotFalse( has_action( 'admin_post_nopriv_extrachill_register_user', 'extrachill_handle_registration' ) );
		$this->assertNotFalse( has_filter( 'extrachill_create_community_user', 'ec_multisite_create_community_user' ) );
		$this->assertNotFalse( has_action( 'init', 'extrachill_redirect_wp_login_access' ) );
	}

	/**
	 * Exercise the real rejection callback without allowing wp_die() to exit.
	 */
	private function assertCoreSignupPostIsRejected(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$user_count                = count_users()['total_users'];
		$die_handler               = static function () {
			return static function ( $message, $title, $args ) {
				throw new RuntimeException(
					wp_json_encode(
						array(
							'message' => $message,
							'args'    => $args,
						)
					)
				);
			};
		};
		add_filter( 'wp_die_handler', $die_handler );

		try {
			extrachill_users_close_core_signup_surface();
			$this->fail( 'Expected a direct core signup POST to be rejected.' );
		} catch ( RuntimeException $exception ) {
			$failure = json_decode( $exception->getMessage(), true );
			$this->assertSame( 403, $failure['args']['response'] );
			$this->assertSame( 'Direct signup submissions are not allowed.', $failure['message'] );
			$this->assertSame( $user_count, count_users()['total_users'] );
		} finally {
			remove_filter( 'wp_die_handler', $die_handler );
		}
	}

	/**
	 * Exercise the real redirect callback without allowing it to exit PHPUnit.
	 */
	private function assertCoreSignupRedirectsToBrandedRegistration(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$registration_setting      = get_site_option( 'registration' );
		$redirect_filter           = static function ( $location, $status ) {
			throw new RuntimeException( wp_json_encode( compact( 'location', 'status' ) ) );
		};
		add_filter( 'wp_redirect', $redirect_filter, 10, 2 );

		try {
			extrachill_users_close_core_signup_surface();
			$this->fail( 'Expected a direct core signup GET to redirect.' );
		} catch ( RuntimeException $exception ) {
			$redirect = json_decode( $exception->getMessage(), true );
			$this->assertSame( network_home_url( '/login/', 'https' ) . '#tab-register', $redirect['location'] );
			$this->assertSame( 302, $redirect['status'] );
			$this->assertSame( $registration_setting, get_site_option( 'registration' ) );
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter, 10 );
		}
	}

	/**
	 * Confirm core's subsite endpoint resolves to the network signup endpoint.
	 */
	private function assertCoreCanonicalizesSubsiteSignup(): void {
		$subsite_id = self::factory()->blog->create();
		switch_to_blog( $subsite_id );

		try {
			$this->assertSame( network_site_url( 'wp-signup.php' ), get_site_url( get_main_site_id(), 'wp-signup.php' ) );
		} finally {
			restore_current_blog();
		}
	}

	protected function tearDown(): void {
		$_SERVER = $this->original_server;
		parent::tearDown();
	}
}
