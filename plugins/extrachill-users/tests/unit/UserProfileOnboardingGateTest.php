<?php
/**
 * Tests for the public profile onboarding gate.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify profile writes use the canonical onboarding lifecycle state.
 */
class Test_User_Profile_Onboarding_Gate extends WP_UnitTestCase {

	/**
	 * Reset the authenticated user between tests.
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Create and authenticate a test user.
	 *
	 * @return int User ID.
	 */
	private function create_authenticated_user(): int {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Incomplete users cannot publish profile fields or links.
	 */
	public function test_incomplete_user_cannot_update_public_profile(): void {
		$user_id = $this->create_authenticated_user();
		update_user_meta( $user_id, 'onboarding_completed', '0' );

		$profile_result = extrachill_users_ability_update_profile(
			array(
				'bio'          => 'Promotional profile',
				'custom_title' => 'Promotional title',
			)
		);
		$links_result   = extrachill_users_ability_update_links(
			array(
				'links' => array(
					array(
						'type_key' => 'website',
						'url'      => 'https://example.com',
					),
				),
			)
		);

		$this->assertWPError( $profile_result );
		$this->assertSame( 'onboarding_incomplete', $profile_result->get_error_code() );
		$this->assertWPError( $links_result );
		$this->assertSame( 'onboarding_incomplete', $links_result->get_error_code() );
		$this->assertSame( '', get_userdata( $user_id )->description );
		$this->assertSame( '', get_user_meta( $user_id, 'ec_custom_title', true ) );
		$this->assertSame( '', get_user_meta( $user_id, '_user_profile_dynamic_links', true ) );
	}

	/**
	 * Minimal onboarding completion permits profile writes.
	 */
	public function test_completed_user_can_update_public_profile(): void {
		$user_id = $this->create_authenticated_user();
		update_user_meta( $user_id, 'onboarding_completed', '1' );

		$profile_result = extrachill_users_ability_update_profile( array( 'bio' => 'Music fan' ) );
		$links_result   = extrachill_users_ability_update_links(
			array(
				'links' => array(
					array(
						'type_key' => 'website',
						'url'      => 'https://example.com',
					),
				),
			)
		);

		$this->assertNotWPError( $profile_result );
		$this->assertNotWPError( $links_result );
		$this->assertSame( 'Music fan', get_userdata( $user_id )->description );
		$this->assertCount( 1, get_user_meta( $user_id, '_user_profile_dynamic_links', true ) );
	}

	/**
	 * Grandfathered users without onboarding metadata remain eligible.
	 */
	public function test_grandfathered_user_can_update_public_profile(): void {
		$user_id = $this->create_authenticated_user();

		$result = extrachill_users_ability_update_profile( array( 'bio' => 'Legacy member' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'Legacy member', get_userdata( $user_id )->description );
	}
}
