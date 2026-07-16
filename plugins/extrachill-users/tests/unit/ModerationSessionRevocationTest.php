<?php
/**
 * Tests for moderation session revocation.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify blocking moderation actions invalidate existing sessions.
 */
class Test_Moderation_Session_Revocation extends WP_UnitTestCase {

	/**
	 * A ban destroys every existing WordPress session for the user.
	 */
	public function test_ban_revokes_existing_sessions(): void {
		$user_id  = self::factory()->user->create();
		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->create( time() + HOUR_IN_SECONDS );

		$this->assertCount( 1, $sessions->get_all() );

		$result = extrachill_users_apply_moderation_action(
			$user_id,
			array(
				'reason_key' => 'other',
				'source'     => 'phpunit',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'banned', $result['state'] );
		$this->assertSame( array(), WP_Session_Tokens::get_instance( $user_id )->get_all() );
	}
}
