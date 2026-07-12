<?php
/**
 * Tests for owner-native user administration abilities.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify the abilities retain their user-domain contracts.
 */
class Test_User_Administration_Abilities extends WP_UnitTestCase {

	/**
	 * Load the directly exercised user-domain primitives.
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/team-members/role.php';
		require_once dirname( __DIR__, 2 ) . '/inc/lifetime-membership.php';
		require_once dirname( __DIR__, 2 ) . '/inc/core/abilities/user-administration.php';
	}

	/**
	 * Grant and revoke use the canonical membership meta and stable response keys.
	 */
	public function test_grant_and_revoke_lifetime_membership_preserve_response_shape(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'lifetime-test',
				'user_email' => 'lifetime@example.com',
			)
		);

		$granted = extrachill_users_ability_grant_lifetime_membership( array( 'user_identifier' => 'lifetime@example.com' ) );
		$this->assertTrue( $granted['success'] );
		$this->assertSame( $user_id, $granted['user_id'] );
		$this->assertNotEmpty( get_user_meta( $user_id, 'extrachill_lifetime_membership', true ) );

		$revoked = extrachill_users_ability_revoke_lifetime_membership( array( 'user_id' => $user_id ) );
		$this->assertTrue( $revoked['success'] );
		$this->assertSame( $user_id, $revoked['user_id'] );
		$this->assertEmpty( get_user_meta( $user_id, 'extrachill_lifetime_membership', true ) );
	}

	/**
	 * Team management delegates to the existing network role primitives.
	 */
	public function test_manage_team_member_uses_role_primitives(): void {
		$user_id = self::factory()->user->create();

		$granted = extrachill_users_ability_manage_team_member(
			array(
				'user_id' => $user_id,
				'action'  => 'force_add',
			)
		);
		$this->assertTrue( $granted['is_team_member'] );
		$this->assertContains( EC_USERS_TEAM_ROLE, ( new WP_User( $user_id ) )->roles );

		$revoked = extrachill_users_ability_manage_team_member(
			array(
				'user_id' => $user_id,
				'action'  => 'force_remove',
			)
		);
		$this->assertFalse( $revoked['is_team_member'] );
		$this->assertNotContains( EC_USERS_TEAM_ROLE, ( new WP_User( $user_id ) )->roles );
	}

	/**
	 * Transition registration supplies an ability when no prior owner exists.
	 */
	public function test_registration_registers_absent_ability(): void {
		wp_unregister_ability( 'extrachill/grant-lifetime-membership' );

		$this->assertFalse( wp_has_ability( 'extrachill/grant-lifetime-membership' ) );

		extrachill_users_register_user_administration_abilities();

		$this->assertTrue( wp_has_ability( 'extrachill/grant-lifetime-membership' ) );
	}

	/**
	 * Transition registration never replaces an ability owned by Admin Tools.
	 */
	public function test_registration_does_not_replace_existing_owner(): void {
		$existing = wp_get_ability( 'extrachill/manage-team-member' );
		extrachill_users_register_user_administration_abilities();
		$this->assertSame( $existing, wp_get_ability( 'extrachill/manage-team-member' ) );
	}
}
