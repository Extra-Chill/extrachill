<?php
/**
 * Integration tests for user creation.
 *
 * Tests the ec_multisite_create_community_user function in inc/core/user-creation.php
 */

use PHPUnit\Framework\TestCase;

class Test_User_Creation extends TestCase {

	/**
	 * Set up before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		reset_wp_mocks();
	}

	/**
	 * Test successful user creation with minimal data.
	 */
	public function test_create_user_minimal_data() {
		$registration_data = array(
			'username' => 'newuser',
			'password' => 'securepassword',
			'email'    => 'newuser@example.com',
		);

		$user_id = ec_multisite_create_community_user( false, $registration_data );

		$this->assertIsInt( $user_id );
		$this->assertGreaterThan( 0, $user_id );

		// Verify user was created.
		$user = get_userdata( $user_id );
		$this->assertNotFalse( $user );
		$this->assertEquals( 'newuser', $user->user_login );
		$this->assertEquals( 'newuser@example.com', $user->user_email );
	}

	/**
	 * Test user creation with full registration data.
	 */
	public function test_create_user_full_data() {
		$registration_data = array(
			'username'            => 'fulluser',
			'password'            => 'securepassword',
			'email'               => 'fulluser@example.com',
			'from_join'           => true,
			'registration_page'   => 'https://artist.extrachill.com/join/',
			'registration_source' => 'web',
			'registration_method' => 'standard',
		);

		$user_id = ec_multisite_create_community_user( false, $registration_data );

		$this->assertIsInt( $user_id );

		// Verify metadata was stored.
		$this->assertEquals(
			'https://artist.extrachill.com/join/',
			get_user_meta( $user_id, 'registration_page', true )
		);
		$this->assertEquals(
			'web',
			get_user_meta( $user_id, 'registration_source', true )
		);
		$this->assertEquals(
			'standard',
			get_user_meta( $user_id, 'registration_method', true )
		);
	}

	/**
	 * Test user creation sets onboarding meta.
	 */
	public function test_create_user_sets_onboarding_meta() {
		$registration_data = array(
			'username'  => 'onboardinguser',
			'password'  => 'securepassword',
			'email'     => 'onboard@example.com',
			'from_join' => false,
		);

		$user_id = ec_multisite_create_community_user( false, $registration_data );

		$this->assertEquals( '0', get_user_meta( $user_id, 'onboarding_completed', true ) );
		$this->assertEquals( '0', get_user_meta( $user_id, 'onboarding_from_join', true ) );
	}

	/**
	 * Test user creation with from_join flag.
	 */
	public function test_create_user_from_join_flag() {
		$registration_data = array(
			'username'  => 'joinuser',
			'password'  => 'securepassword',
			'email'     => 'join@example.com',
			'from_join' => true,
		);

		$user_id = ec_multisite_create_community_user( false, $registration_data );

		$this->assertEquals( '1', get_user_meta( $user_id, 'onboarding_from_join', true ) );
	}

	/**
	 * Test user creation fails with missing username.
	 */
	public function test_create_user_missing_username() {
		$registration_data = array(
			'password' => 'securepassword',
			'email'    => 'nouser@example.com',
		);

		$result = ec_multisite_create_community_user( false, $registration_data );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	/**
	 * Test user creation fails with missing password.
	 */
	public function test_create_user_missing_password() {
		$registration_data = array(
			'username' => 'nopassuser',
			'email'    => 'nopass@example.com',
		);

		$result = ec_multisite_create_community_user( false, $registration_data );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	/**
	 * Test user creation fails with missing email.
	 */
	public function test_create_user_missing_email() {
		$registration_data = array(
			'username' => 'noemailuser',
			'password' => 'securepassword',
		);

		$result = ec_multisite_create_community_user( false, $registration_data );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	/**
	 * Test user creation fails with duplicate username.
	 */
	public function test_create_user_duplicate_username() {
		// Create first user.
		$GLOBALS['__test_users'][1] = array(
			'ID'         => 1,
			'user_login' => 'existinguser',
			'user_email' => 'existing@example.com',
		);

		$registration_data = array(
			'username' => 'existinguser',
			'password' => 'securepassword',
			'email'    => 'new@example.com',
		);

		$result = ec_multisite_create_community_user( false, $registration_data );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'existing_user_login', $result->get_error_code() );
	}

	/**
	 * Test user creation fails with duplicate email.
	 */
	public function test_create_user_duplicate_email() {
		// Create first user.
		$GLOBALS['__test_users'][1] = array(
			'ID'         => 1,
			'user_login' => 'firstuser',
			'user_email' => 'duplicate@example.com',
		);

		$registration_data = array(
			'username' => 'seconduser',
			'password' => 'securepassword',
			'email'    => 'duplicate@example.com',
		);

		$result = ec_multisite_create_community_user( false, $registration_data );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'existing_user_email', $result->get_error_code() );
	}

	/**
	 * Test user creation fires action hook on success.
	 */
	public function test_create_user_fires_action() {
		$registration_data = array(
			'username'            => 'actionuser',
			'password'            => 'securepassword',
			'email'               => 'action@example.com',
			'registration_page'   => 'https://test.com/',
			'registration_source' => 'test',
			'registration_method' => 'test',
		);

		$user_id = ec_multisite_create_community_user( false, $registration_data );

		$this->assertContains( 'extrachill_new_user_registered', $GLOBALS['__wp_actions_fired'] );
	}

	/**
	 * Test user creation via filter.
	 */
	public function test_create_user_via_filter() {
		// Re-register the filter since reset_wp_mocks cleared it.
		add_filter( 'extrachill_create_community_user', 'ec_multisite_create_community_user', 10, 2 );

		$registration_data = array(
			'username' => 'filteruser',
			'password' => 'securepassword',
			'email'    => 'filter@example.com',
		);

		$user_id = apply_filters( 'extrachill_create_community_user', false, $registration_data );

		$this->assertIsInt( $user_id );
		$this->assertGreaterThan( 0, $user_id );
	}

	/**
	 * Test blog switching happens when not on community blog.
	 */
	public function test_create_user_blog_switching() {
		// Start on main blog (ID 1).
		$GLOBALS['__test_current_blog_id'] = 1;
		$GLOBALS['__test_switched_stack']  = array();

		$registration_data = array(
			'username' => 'switchuser',
			'password' => 'securepassword',
			'email'    => 'switch@example.com',
		);

		$user_id = ec_multisite_create_community_user( false, $registration_data );

		// Should have switched and restored.
		$this->assertEquals( 1, $GLOBALS['__test_current_blog_id'] );
		$this->assertIsInt( $user_id );
	}

	/**
	 * Test no blog switching when already on community blog.
	 */
	public function test_create_user_no_switch_on_community() {
		// Start on community blog (ID 2).
		$GLOBALS['__test_current_blog_id'] = 2;
		$initial_stack_count = count( $GLOBALS['__test_switched_stack'] ?? array() );

		$registration_data = array(
			'username' => 'communityuser',
			'password' => 'securepassword',
			'email'    => 'community@example.com',
		);

		$user_id = ec_multisite_create_community_user( false, $registration_data );

		// Should not have switched (stack unchanged).
		$this->assertEquals( 2, $GLOBALS['__test_current_blog_id'] );
	}
}
