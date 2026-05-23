<?php
/**
 * Unit tests for the team role (#45 Phase 1).
 *
 * The extra_chill_team WP role is the source of truth for team
 * membership. No meta, no derivation. These tests verify the role
 * surface, the one-shot migration, and the cap-only shim semantics.
 */

class Test_Team_Role extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/team-members/role.php';
		require_once dirname( __DIR__, 2 ) . '/inc/team-members.php';

		// Ensure each test starts with the role NOT registered so tests
		// that verify registration are observing a real transition,
		// not stale state from a previous test in the run.
		remove_role( EC_USERS_TEAM_ROLE );
	}

	protected function tearDown(): void {
		remove_role( EC_USERS_TEAM_ROLE );
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Capability surface
	// -----------------------------------------------------------------

	public function test_role_caps_grant_upload_files(): void {
		$caps = ec_users_get_team_role_caps();
		$this->assertArrayHasKey( 'upload_files', $caps );
		$this->assertTrue( $caps['upload_files'] );
	}

	public function test_role_caps_grant_custom_access_caps(): void {
		$caps = ec_users_get_team_role_caps();
		foreach (
			array(
				'access_studio',
				'access_roadie',
				'access_transcribe',
				'access_events_admin',
				'access_admin_bar',
				'submit_for_review',
			) as $cap
		) {
			$this->assertArrayHasKey( $cap, $caps, sprintf( 'Missing custom team cap: %s', $cap ) );
			$this->assertTrue( $caps[ $cap ], sprintf( 'Custom cap %s should be granted.', $cap ) );
		}
	}

	public function test_role_caps_do_not_grant_admin_caps(): void {
		$caps = ec_users_get_team_role_caps();
		foreach ( array( 'manage_options', 'delete_others_posts', 'edit_users', 'manage_network' ) as $cap ) {
			$this->assertArrayNotHasKey( $cap, $caps, sprintf( 'Team role must not grant %s.', $cap ) );
		}
	}

	// -----------------------------------------------------------------
	// Role registration
	// -----------------------------------------------------------------

	public function test_register_team_role_creates_role_with_expected_caps(): void {
		ec_users_register_team_role();

		$role = get_role( EC_USERS_TEAM_ROLE );
		$this->assertInstanceOf( WP_Role::class, $role );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
		$this->assertTrue( $role->has_cap( 'access_studio' ) );
		$this->assertFalse( $role->has_cap( 'manage_options' ) );
	}

	public function test_register_team_role_idempotent(): void {
		ec_users_register_team_role();
		ec_users_register_team_role();
		ec_users_register_team_role();

		$role = get_role( EC_USERS_TEAM_ROLE );
		$this->assertInstanceOf( WP_Role::class, $role );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
	}

	public function test_register_team_role_picks_up_cap_drift(): void {
		// Simulate a stale registration with the wrong cap set.
		add_role( EC_USERS_TEAM_ROLE, 'Stale Team', array( 'read' => true ) );

		$stale = get_role( EC_USERS_TEAM_ROLE );
		$this->assertFalse( $stale->has_cap( 'upload_files' ) );

		ec_users_register_team_role();

		$fresh = get_role( EC_USERS_TEAM_ROLE );
		$this->assertTrue( $fresh->has_cap( 'upload_files' ) );
		$this->assertTrue( $fresh->has_cap( 'access_studio' ) );
	}

	// -----------------------------------------------------------------
	// ec_is_team_member semantics (cap-only, no meta fallback)
	// -----------------------------------------------------------------

	public function test_is_team_member_with_role_returns_true(): void {
		ec_users_register_team_role();

		$user_id = self::factory()->user->create();
		$user    = new WP_User( $user_id );
		$user->add_role( EC_USERS_TEAM_ROLE );

		$this->assertTrue( ec_is_team_member( $user_id ) );

		wp_set_current_user( $user_id );
		$this->assertTrue( ec_is_team_member() );
	}

	public function test_is_team_member_without_role_returns_false_even_with_legacy_meta(): void {
		ec_users_register_team_role();

		// User has the LEGACY meta but no role assignment. The new code
		// must NOT honor the meta — that would resurrect the parallel
		// state we just retired. Role is the only source of truth.
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'extrachill_team', '1' );
		update_user_meta( $user_id, 'extrachill_team_manual_override', 'add' );

		$this->assertFalse(
			ec_is_team_member( $user_id ),
			'Legacy meta must not be honored; only the role decides.'
		);
	}

	public function test_is_team_member_zero_user_id_returns_false(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( ec_is_team_member() );
	}

	// -----------------------------------------------------------------
	// Grant / revoke helpers
	// -----------------------------------------------------------------

	public function test_grant_team_role_assigns_on_current_site(): void {
		ec_users_register_team_role();

		$user_id = self::factory()->user->create();
		ec_users_grant_team_role( $user_id );

		$user = new WP_User( $user_id );
		$this->assertTrue( in_array( EC_USERS_TEAM_ROLE, (array) $user->roles, true ) );
	}

	public function test_grant_team_role_is_additive_not_replacing(): void {
		ec_users_register_team_role();

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		ec_users_grant_team_role( $user_id );

		$user = new WP_User( $user_id );
		$this->assertTrue(
			in_array( 'subscriber', (array) $user->roles, true ),
			'Original role must be preserved.'
		);
		$this->assertTrue(
			in_array( EC_USERS_TEAM_ROLE, (array) $user->roles, true ),
			'Team role must be added alongside.'
		);
	}

	public function test_revoke_team_role_removes_only_team_role(): void {
		ec_users_register_team_role();

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		ec_users_grant_team_role( $user_id );
		ec_users_revoke_team_role( $user_id );

		$user = new WP_User( $user_id );
		$this->assertFalse(
			in_array( EC_USERS_TEAM_ROLE, (array) $user->roles, true ),
			'Team role must be revoked.'
		);
		$this->assertTrue(
			in_array( 'subscriber', (array) $user->roles, true ),
			'Other roles must be preserved.'
		);
	}

	public function test_grant_team_role_zero_user_id_returns_empty(): void {
		$this->assertSame( array(), ec_users_grant_team_role( 0 ) );
		$this->assertSame( array(), ec_users_grant_team_role( -1 ) );
	}

	// -----------------------------------------------------------------
	// One-shot migration
	// -----------------------------------------------------------------

	public function test_migration_grants_role_to_users_with_team_flag(): void {
		ec_users_register_team_role();

		$team_user_id     = self::factory()->user->create();
		$non_team_user_id = self::factory()->user->create();
		update_user_meta( $team_user_id, 'extrachill_team', '1' );

		$summary = ec_users_migrate_team_meta_to_role();

		$this->assertSame( 1, $summary['granted'] );
		$this->assertSame( 1, $summary['meta_deleted'] );

		$this->assertTrue( user_can( $team_user_id, 'access_studio' ) );
		$this->assertFalse( user_can( $non_team_user_id, 'access_studio' ) );
	}

	public function test_migration_honors_manual_override_add(): void {
		ec_users_register_team_role();

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'extrachill_team', '0' );
		update_user_meta( $user_id, 'extrachill_team_manual_override', 'add' );

		ec_users_migrate_team_meta_to_role();

		$this->assertTrue(
			user_can( $user_id, 'access_studio' ),
			'manual_override=add must grant the role even when the flag is 0.'
		);
	}

	public function test_migration_honors_manual_override_remove(): void {
		ec_users_register_team_role();

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'extrachill_team', '1' );
		update_user_meta( $user_id, 'extrachill_team_manual_override', 'remove' );

		ec_users_migrate_team_meta_to_role();

		$this->assertFalse(
			user_can( $user_id, 'access_studio' ),
			'manual_override=remove must NOT grant the role even when the flag is 1.'
		);
	}

	public function test_migration_deletes_legacy_meta(): void {
		ec_users_register_team_role();

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'extrachill_team', '1' );
		update_user_meta( $user_id, 'extrachill_team_manual_override', 'add' );

		ec_users_migrate_team_meta_to_role();

		$this->assertSame( '', (string) get_user_meta( $user_id, 'extrachill_team', true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, 'extrachill_team_manual_override', true ) );
	}

	public function test_migration_idempotent_on_second_run(): void {
		ec_users_register_team_role();

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'extrachill_team', '1' );

		$first  = ec_users_migrate_team_meta_to_role();
		$second = ec_users_migrate_team_meta_to_role();

		$this->assertSame( 1, $first['granted'] );
		$this->assertSame( 0, $second['granted'], 'Second pass finds no meta to migrate.' );
		$this->assertSame( 0, $second['meta_deleted'] );

		// Role assignment from the first pass is preserved.
		$this->assertTrue( user_can( $user_id, 'access_studio' ) );
	}

	// -----------------------------------------------------------------
	// editable_roles filter — wp-admin user-edit dropdown protection
	// -----------------------------------------------------------------

	public function test_team_role_hidden_from_editable_roles(): void {
		ec_users_register_team_role();

		$roles = get_editable_roles();
		$this->assertArrayNotHasKey(
			EC_USERS_TEAM_ROLE,
			$roles,
			'extra_chill_team must be hidden from wp-admin role dropdowns to prevent accidental strip via single-role replacement.'
		);
	}
}
