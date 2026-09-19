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

		/*
		 * Abilities in this plugin register into the 'extrachill-users'
		 * category, and wp_register_ability() fails when the category is
		 * absent. The category is registered on wp_abilities_api_categories_init,
		 * which the test bootstrap does not fire.
		 *
		 * Without this the registration assertions passed or failed on test
		 * order — green only when some earlier test happened to fire that
		 * action first. Firing it here makes this class self-sufficient.
		 */
		if ( function_exists( 'wp_has_ability_category' ) && ! wp_has_ability_category( 'extrachill-users' ) ) {
			do_action( 'wp_abilities_api_categories_init' );
		}

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
	/**
	 * Run this plugin's user-administration registration the way core demands.
	 *
	 * wp_register_ability() checks doing_action( 'wp_abilities_api_init' ) and
	 * reports incorrect usage otherwise, so the callback cannot simply be
	 * invoked. Firing the action as-is is no better: every other registrar on
	 * it runs again and core reports "Ability ... is already registered".
	 *
	 * Detaching the other callbacks first fires a real action with only the
	 * registrar under test attached. WP_UnitTestCase backs up and restores
	 * hooks around each test, so the detachment does not leak.
	 *
	 * Scope matters here beyond correctness of this class. The abilities
	 * registry is global state and is NOT restored between tests — only hooks
	 * are. Firing the unfiltered action from setUp registered all 43 of this
	 * plugin's abilities for the remainder of the process and broke unrelated
	 * suites that assert specific abilities are absent
	 * (Test_Account_Email_Sharing_Retirement) or that exercise permission
	 * callbacks on their own registrations (Test_Artist_Access_Abilities).
	 * Registering only what this class asserts on keeps the blast radius to
	 * this class.
	 */
	private function run_registration(): void {
		remove_all_actions( 'wp_abilities_api_init' );
		add_action( 'wp_abilities_api_init', 'extrachill_users_register_user_administration_abilities' );
		do_action( 'wp_abilities_api_init' );
	}

	public function test_registration_registers_absent_ability(): void {
		wp_unregister_ability( 'extrachill/grant-lifetime-membership' );

		$this->assertFalse( wp_has_ability( 'extrachill/grant-lifetime-membership' ) );

		$this->run_registration();

		$this->assertTrue( wp_has_ability( 'extrachill/grant-lifetime-membership' ) );
	}

	/**
	 * Transition registration never replaces an ability owned by Admin Tools.
	 */
	public function test_registration_does_not_replace_existing_owner(): void {
		// Establish the owner through the same path, rather than depending on
		// setUp or on a sibling test having registered it.
		$this->run_registration();

		$existing = wp_get_ability( 'extrachill/manage-team-member' );
		$this->assertNotNull( $existing, 'the first pass must leave an owner for the second to not replace' );

		$this->run_registration();

		$this->assertSame( $existing, wp_get_ability( 'extrachill/manage-team-member' ) );
	}
}
