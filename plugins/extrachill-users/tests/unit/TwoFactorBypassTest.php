<?php
/**
 * No login path may skip a 2FA member's second factor.
 *
 * Covers the paths that cannot hand the member to a code step while the
 * `two-factor` challenge policy is inactive (Google, Apple), and password
 * reset completion, which used to sign every member straight in.
 */

require_once dirname( __DIR__ ) . '/support/fixtures.php';

class Test_Two_Factor_Bypass extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private $original_post;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__ ) . '/support/two-factor-core-double.php';

		if ( ! defined( 'EXTRACHILL_USERS_TWO_FACTOR_DOUBLE' ) ) {
			$this->markTestSkipped( 'The real Two Factor plugin is loaded.' );
		}

		$this->original_post = $_POST;
		Two_Factor_Core::reset();
	}

	protected function tearDown(): void {
		$_POST = $this->original_post;
		if ( defined( 'EXTRACHILL_USERS_TWO_FACTOR_DOUBLE' ) ) {
			Two_Factor_Core::reset();
		}
		parent::tearDown();
	}

	private function user(): WP_User {
		return get_user_by( 'id', self::factory()->user->create( array( 'user_pass' => 'original-password' ) ) );
	}

	private function require_inactive_policy(): void {
		if ( extrachill_users_two_factor_policy_active() ) {
			$this->markTestSkipped( 'In-app 2FA is active, so 2FA members are challenged instead.' );
		}
	}

	public function test_social_login_fails_closed_for_a_two_factor_member_without_the_policy(): void {
		$this->require_inactive_policy();
		Two_Factor_Core::$using = true;

		$result = extrachill_users_maybe_challenge_login(
			$this->user(),
			wp_generate_uuid4(),
			array( 'set_cookie' => true ),
			array( 'flow' => 'oauth' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_required', $result->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
		$this->assertSame( array(), Two_Factor_Core::$verify_calls );
	}

	public function test_members_without_two_factor_are_not_refused(): void {
		Two_Factor_Core::$using = false;

		$this->assertNull( extrachill_users_two_factor_required_error( $this->user() ) );
	}

	public function test_two_factor_use_is_read_from_two_factor(): void {
		$user = $this->user();

		$this->assertFalse( extrachill_users_user_uses_two_factor( $user ) );
		Two_Factor_Core::$using = true;
		$this->assertTrue( extrachill_users_user_uses_two_factor( $user ) );
	}

	public function test_google_login_mints_no_tokens_for_a_two_factor_member_without_the_policy(): void {
		$this->require_inactive_policy();
		Two_Factor_Core::$using = true;

		$community_blog_id = (int) ec_get_blog_id( 'community' );
		if ( ! ec_users_tests_ensure_site( $community_blog_id ) ) {
			$this->markTestSkipped( 'The community blog cannot be created in this environment.' );
		}

		$user = $this->user();
		add_user_to_blog( $community_blog_id, $user->ID, 'subscriber' );
		$this->assertTrue( is_user_member_of_blog( $user->ID, $community_blog_id ) );

		$cookies = 0;
		$count   = static function () use ( &$cookies ): void {
			++$cookies;
		};
		add_action( 'set_auth_cookie', $count );

		try {
			$result = ec_oauth_complete_token_login(
				array(
					'user'    => $user,
					'user_id' => $user->ID,
					'is_new'  => false,
				),
				wp_generate_uuid4(),
				array(
					'set_cookie'          => true,
					'registration_method' => 'google',
				),
				array(
					'registration_source' => 'web',
					'registration_page'   => '',
				)
			);
		} finally {
			remove_action( 'set_auth_cookie', $count );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_required', $result->get_error_code() );
		$this->assertSame( 0, $cookies );
	}

	public function test_reset_completion_sends_a_two_factor_member_to_the_login_page(): void {
		Two_Factor_Core::$using = true;

		$this->assertSame( home_url( user_trailingslashit( '/login' ) ), ec_users_reset_password_login_url( $this->user() ) );
	}

	public function test_reset_completion_signs_in_members_without_two_factor(): void {
		$this->assertNull( ec_users_reset_password_login_url( $this->user() ) );
	}

	public function test_reset_submission_sets_no_cookie_for_a_two_factor_member(): void {
		Two_Factor_Core::$using = true;

		$user = $this->user();
		$key  = get_password_reset_key( $user );
		$this->assertNotWPError( $key );

		$_POST = array(
			'ec_reset_password_nonce' => wp_create_nonce( 'ec_reset_password' ),
			'key'                     => $key,
			'login'                   => $user->user_login,
			'pass1'                   => 'new-secure-password-1',
			'pass2'                   => 'new-secure-password-1',
		);

		$cookies  = 0;
		$count    = static function () use ( &$cookies ): void {
			++$cookies;
		};
		$redirect = static function ( $location ) {
			throw new RuntimeException( (string) $location );
		};
		add_action( 'set_auth_cookie', $count );
		add_filter( 'wp_redirect', $redirect );

		$location = '';
		try {
			ec_handle_reset_password();
		} catch ( RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_action( 'set_auth_cookie', $count );
			remove_filter( 'wp_redirect', $redirect );
		}

		$this->assertSame( home_url( user_trailingslashit( '/login' ) ), $location );
		$this->assertSame( 0, $cookies );
		$this->assertTrue( wp_check_password( 'new-secure-password-1', get_userdata( $user->ID )->user_pass, $user->ID ) );
	}
}
