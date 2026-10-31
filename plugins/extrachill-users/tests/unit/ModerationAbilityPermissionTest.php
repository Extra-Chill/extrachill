<?php
/**
 * Tests for the moderation abilities' permission boundary.
 *
 * @package ExtraChill\Users
 */

/**
 * The moderation abilities must not rely on `show_in_rest: false` alone:
 * any in-process dispatcher can execute a registered ability, so the
 * permission callback is the boundary.
 */
class Test_Moderation_Ability_Permission extends WP_UnitTestCase {

	private const ABILITIES = array(
		'extrachill/get-user-moderation-status',
		'extrachill/moderate-user',
		'extrachill/clear-user-moderation',
	);

	public function tear_down(): void {
		remove_all_filters( 'extrachill_users_can_moderate_users' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function skip_under_cli(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->markTestSkipped( 'WP-CLI is an allowed operator context.' );
		}
	}

	public function test_non_admin_users_are_denied(): void {
		$this->skip_under_cli();

		foreach ( array( 'subscriber', 'author', 'editor' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$target = self::factory()->user->create();

			foreach ( self::ABILITIES as $name ) {
				$ability = wp_get_ability( $name );
				$this->assertNotNull( $ability, $name );
				$this->assertNotTrue(
					$ability->check_permissions( array( 'user_id' => $target, 'state' => 'banned', 'reason_key' => 'other' ) ),
					"$role must not reach $name"
				);
			}
		}
	}

	public function test_anonymous_is_denied(): void {
		$this->skip_under_cli();
		wp_set_current_user( 0 );

		foreach ( self::ABILITIES as $name ) {
			$this->assertNotTrue( wp_get_ability( $name )->check_permissions( array( 'user_id' => 1 ) ), $name );
		}
	}

	public function test_denied_execute_does_not_ban(): void {
		$this->skip_under_cli();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$target = self::factory()->user->create();

		$result = wp_get_ability( 'extrachill/moderate-user' )->execute(
			array(
				'user_id'    => $target,
				'state'      => 'banned',
				'reason_key' => 'other',
			)
		);

		$this->assertWPError( $result );
		$status = extrachill_users_ability_get_user_moderation_status( array( 'user_id' => $target ) );
		$this->assertNotSame( 'banned', is_array( $status ) ? ( $status['state'] ?? null ) : null );
	}

	public function test_network_user_manager_is_allowed(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );

		foreach ( self::ABILITIES as $name ) {
			$this->assertTrue( wp_get_ability( $name )->check_permissions( array( 'user_id' => 1 ) ), $name );
		}
	}

	public function test_filter_can_grant_a_dedicated_moderator(): void {
		$this->skip_under_cli();
		$moderator = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $moderator );
		add_filter( 'extrachill_users_can_moderate_users', static fn( bool $allowed ): bool => $allowed || get_current_user_id() === $moderator );

		$this->assertTrue( extrachill_users_can_moderate_users() );
	}
}
