<?php
/**
 * Studio Intelligence permission bridge tests.
 *
 * @package ExtraChill\Users
 */

// Shared fixture helpers (abilities registered on wp_abilities_api_init, mapped sites).
require_once dirname( __DIR__ ) . '/support/fixtures.php';

/**
 * Verify the team receives the foreign Intelligence read cap only on Studio.
 */
class Test_Intelligence_Permissions extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	private int $studio_blog_id;
	private int $other_blog_id;

	public static function wpSetUpBeforeClass(): void {
		ec_users_tests_ensure_site( (int) ec_get_blog_id( 'studio' ) );
		ec_users_tests_ensure_site( (int) ec_get_blog_id( 'main' ) );
	}

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/team-members/role.php';
		require_once dirname( __DIR__, 2 ) . '/inc/intelligence-permissions.php';

		$this->studio_blog_id = (int) ec_get_blog_id( 'studio' );
		$this->other_blog_id  = (int) ec_get_blog_id( 'main' );
		$this->assertGreaterThan( 0, $this->studio_blog_id );
		$this->assertNotFalse( get_blog_details( $this->studio_blog_id ), 'The mapped Studio site must exist.' );
		$this->assertNotFalse( get_blog_details( $this->other_blog_id ), 'The mapped main site must exist.' );
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		foreach ( array( $this->studio_blog_id, $this->other_blog_id ) as $blog_id ) {
			switch_to_blog( $blog_id );
			remove_role( EC_USERS_TEAM_ROLE );
			restore_current_blog();
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function add_user_role_on_blog( int $user_id, int $blog_id, string $role ): void {
		switch_to_blog( $blog_id );
		if ( EC_USERS_TEAM_ROLE === $role ) {
			ec_users_register_team_role();
		}
		$user = new WP_User( $user_id );
		$user->add_role( $role );
		restore_current_blog();
	}

	public function test_studio_team_member_receives_intelligence_read(): void {
		$user_id = self::factory()->user->create();
		$this->add_user_role_on_blog( $user_id, $this->studio_blog_id, EC_USERS_TEAM_ROLE );

		switch_to_blog( $this->studio_blog_id );
		$this->assertTrue( user_can( $user_id, EC_USERS_INTELLIGENCE_READ_CAP ) );
		restore_current_blog();
	}

	public function test_studio_subscriber_does_not_receive_intelligence_read(): void {
		$user_id = self::factory()->user->create();
		$this->add_user_role_on_blog( $user_id, $this->studio_blog_id, 'subscriber' );

		switch_to_blog( $this->studio_blog_id );
		$this->assertFalse( user_can( $user_id, EC_USERS_INTELLIGENCE_READ_CAP ) );
		restore_current_blog();
	}

	public function test_team_member_outside_studio_does_not_receive_intelligence_read(): void {
		$user_id = self::factory()->user->create();
		$this->add_user_role_on_blog( $user_id, $this->other_blog_id, EC_USERS_TEAM_ROLE );

		switch_to_blog( $this->other_blog_id );
		$this->assertFalse( user_can( $user_id, EC_USERS_INTELLIGENCE_READ_CAP ) );
		restore_current_blog();
	}

	public function test_existing_true_grant_remains_true_outside_studio(): void {
		$user_id = self::factory()->user->create();
		switch_to_blog( $this->other_blog_id );
		$user = new WP_User( $user_id );
		$user->add_cap( EC_USERS_INTELLIGENCE_READ_CAP );

		$this->assertTrue( user_can( $user_id, EC_USERS_INTELLIGENCE_READ_CAP ) );
		restore_current_blog();
	}

	public function test_missing_user_does_not_receive_intelligence_read(): void {
		switch_to_blog( $this->studio_blog_id );
		$allcaps = ec_users_grant_studio_intelligence_read(
			array(),
			array( EC_USERS_INTELLIGENCE_READ_CAP ),
			array( EC_USERS_INTELLIGENCE_READ_CAP, 0 ),
			null
		);
		restore_current_blog();

		$this->assertSame( array(), $allcaps );
	}

	public function test_malformed_roles_do_not_receive_intelligence_read(): void {
		$user        = new stdClass();
		$user->roles = EC_USERS_TEAM_ROLE;

		switch_to_blog( $this->studio_blog_id );
		$allcaps = ec_users_grant_studio_intelligence_read(
			array(),
			array( EC_USERS_INTELLIGENCE_READ_CAP ),
			array( EC_USERS_INTELLIGENCE_READ_CAP, 1 ),
			$user
		);
		restore_current_blog();

		$this->assertSame( array(), $allcaps );
	}

	public function test_unrelated_capability_check_is_unchanged(): void {
		$user        = new stdClass();
		$user->roles = array( EC_USERS_TEAM_ROLE );
		$allcaps     = array( 'read' => true );

		switch_to_blog( $this->studio_blog_id );
		$filtered = ec_users_grant_studio_intelligence_read(
			$allcaps,
			array( 'read' ),
			array( 'read', 1 ),
			$user
		);
		restore_current_blog();

		$this->assertSame( $allcaps, $filtered );
	}
}
