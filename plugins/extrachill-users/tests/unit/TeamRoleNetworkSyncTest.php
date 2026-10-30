<?php
/**
 * Unit tests for team-role network synchronization (#462).
 *
 * `extra_chill_team` was granted per-site at grant time, so sites
 * created afterwards never received the assignment (live blogs 13/14
 * had 1 and 0 members respectively while every older blog had 48).
 * These tests cover the two repair surfaces:
 *
 *   1. wp_initialize_site assigns the role to existing team members
 *      on the new site (source: main-site role holders).
 *   2. ec_users_backfill_team_role() grants the role on any site
 *      where it is missing, idempotently, without welcome emails.
 */

class Test_Team_Role_Network_Sync extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Team-role network sync requires multisite.' );
		}

		require_once dirname( __DIR__, 2 ) . '/inc/team-members/role.php';

		// Hooks are backed up/restored per test by WP_UnitTestCase; attach
		// explicitly so the wp_initialize_site integration is exercised
		// regardless of bootstrap load order.
		add_action( 'wp_initialize_site', 'ec_users_on_new_site_register_role', 200 );

		ec_users_register_team_role();
	}

	protected function tearDown(): void {
		remove_role( EC_USERS_TEAM_ROLE );
		parent::tearDown();
	}

	/**
	 * Whether the user holds the team role on the given blog.
	 */
	private function user_has_team_role_on_blog( int $user_id, int $blog_id ): bool {
		try {
			switch_to_blog( $blog_id );
			$user = new WP_User( $user_id );
			return in_array( EC_USERS_TEAM_ROLE, (array) $user->roles, true );
		} finally {
			restore_current_blog();
		}
	}

	// -----------------------------------------------------------------
	// New-site assignment (wp_initialize_site)
	// -----------------------------------------------------------------

	public function test_new_site_assigns_role_to_existing_team_members(): void {
		$member_id = self::factory()->user->create();
		ec_users_grant_team_role( $member_id, false );

		$non_member_id = self::factory()->user->create();

		$new_blog_id = self::factory()->blog->create();

		$this->assertTrue(
			$this->user_has_team_role_on_blog( $member_id, $new_blog_id ),
			'Existing team member must receive the role on a site created after the grant.'
		);
		$this->assertFalse(
			$this->user_has_team_role_on_blog( $non_member_id, $new_blog_id ),
			'Non-members must not receive the role on new sites.'
		);
	}

	public function test_new_site_member_passes_cap_checks(): void {
		$member_id = self::factory()->user->create();
		ec_users_grant_team_role( $member_id, false );

		$new_blog_id = self::factory()->blog->create();

		try {
			switch_to_blog( $new_blog_id );
			$this->assertTrue(
				user_can( $member_id, 'access_studio' ),
				'Team member must pass access_studio on the new site (the exact gate ec_is_team_member / the MCP endpoint checks).'
			);
		} finally {
			restore_current_blog();
		}
	}

	public function test_new_site_skips_super_admins(): void {
		$admin_id = self::factory()->user->create();
		grant_super_admin( $admin_id );

		// Super-admins are refused by the grant helper, so seed the role
		// on the main site directly to prove the new-site path also skips.
		$user = new WP_User( $admin_id );
		$user->add_role( EC_USERS_TEAM_ROLE );

		$new_blog_id = self::factory()->blog->create();

		$this->assertFalse(
			$this->user_has_team_role_on_blog( $admin_id, $new_blog_id ),
			'Super-admins must not be assigned the team role on new sites.'
		);

		revoke_super_admin( $admin_id );
	}

	public function test_new_site_registers_role_before_assignment(): void {
		$member_id = self::factory()->user->create();
		ec_users_grant_team_role( $member_id, false );

		$new_blog_id = self::factory()->blog->create();

		try {
			switch_to_blog( $new_blog_id );
			$role = get_role( EC_USERS_TEAM_ROLE );
			$this->assertInstanceOf(
				WP_Role::class,
				$role,
				'Role definition must exist on the new site (no ghost-role assignment).'
			);
			$this->assertTrue( $role->has_cap( 'access_studio' ) );
		} finally {
			restore_current_blog();
		}
	}

	// -----------------------------------------------------------------
	// Backfill
	// -----------------------------------------------------------------

	public function test_backfill_grants_role_on_site_where_missing(): void {
		$member_id = self::factory()->user->create();
		ec_users_grant_team_role( $member_id, false );

		// Simulate a pre-fix site: created without the member assignment
		// (the legacy wp_initialize_site handler only registered the role).
		remove_action( 'wp_initialize_site', 'ec_users_on_new_site_register_role', 200 );
		$stale_blog_id = self::factory()->blog->create();

		$this->assertFalse(
			$this->user_has_team_role_on_blog( $member_id, $stale_blog_id ),
			'Precondition: member must be missing the role on the stale site.'
		);

		$granted = ec_users_backfill_team_role();

		$this->assertArrayHasKey( $member_id, $granted );
		$this->assertContains( $stale_blog_id, $granted[ $member_id ] );
		$this->assertTrue(
			$this->user_has_team_role_on_blog( $member_id, $stale_blog_id ),
			'Backfill must grant the role on the site where it was missing.'
		);
	}

	public function test_backfill_is_idempotent(): void {
		$member_id = self::factory()->user->create();
		ec_users_grant_team_role( $member_id, false );

		remove_action( 'wp_initialize_site', 'ec_users_on_new_site_register_role', 200 );
		$stale_blog_id = self::factory()->blog->create();

		$first  = ec_users_backfill_team_role();
		$second = ec_users_backfill_team_role();

		$this->assertNotEmpty( $first, 'First pass repairs the stale site.' );
		$this->assertSame( array(), $second, 'Second pass finds nothing to grant.' );
		$this->assertTrue( $this->user_has_team_role_on_blog( $member_id, $stale_blog_id ) );
	}

	public function test_backfill_sends_no_welcome_email(): void {
		reset_phpmailer_instance();

		// Never-logged-in member: the welcome email WOULD fire on a
		// notify=true grant, so a silent backfill proves $notify=false.
		$member_id = self::factory()->user->create();
		ec_users_grant_team_role( $member_id, false );

		remove_action( 'wp_initialize_site', 'ec_users_on_new_site_register_role', 200 );
		self::factory()->blog->create();

		reset_phpmailer_instance();
		ec_users_backfill_team_role();

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame(
			array(),
			$mailer->mock_sent,
			'Backfill grants must not send the team welcome email.'
		);
	}

	public function test_backfill_with_no_team_members_is_noop(): void {
		$this->assertSame( array(), ec_users_backfill_team_role() );
	}

	// -----------------------------------------------------------------
	// Option-guarded runner (deploy-time repair)
	// -----------------------------------------------------------------

	public function test_maybe_run_backfill_is_option_guarded(): void {
		$option_key = 'extrachill_users_team_role_backfill_version';
		delete_site_option( $option_key );

		$member_id = self::factory()->user->create();
		ec_users_grant_team_role( $member_id, false );

		remove_action( 'wp_initialize_site', 'ec_users_on_new_site_register_role', 200 );
		$stale_blog_id = self::factory()->blog->create();

		ec_users_maybe_run_team_role_backfill();

		$this->assertTrue(
			$this->user_has_team_role_on_blog( $member_id, $stale_blog_id ),
			'Guarded runner must perform the backfill when the option lags.'
		);
		$this->assertSame(
			defined( 'EXTRACHILL_USERS_VERSION' ) ? EXTRACHILL_USERS_VERSION : '0',
			get_site_option( $option_key ),
			'Runner must stamp the option so steady-state requests skip the work.'
		);

		// Re-arming requires a version bump; a second call at the same
		// version must not repeat the (idempotent) work.
		remove_action( 'wp_initialize_site', 'ec_users_on_new_site_register_role', 200 );
		$second_stale_blog = self::factory()->blog->create();
		ec_users_maybe_run_team_role_backfill();

		$this->assertFalse(
			$this->user_has_team_role_on_blog( $member_id, $second_stale_blog ),
			'Same-version re-run must be a no-op (option guard).'
		);

		delete_site_option( $option_key );
	}
}
