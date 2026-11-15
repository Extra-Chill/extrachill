<?php
/**
 * Tests for the supporter badge earned by lifetime membership.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify lifetime members carry the supporter badge everywhere badges render.
 */
class Test_Supporter_Badge extends WP_UnitTestCase {
	/**
	 * Index badges by key.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,array<string,string>>
	 */
	private function badges_by_key( int $user_id ): array {
		$indexed = array();
		foreach ( ec_get_user_badges( $user_id ) as $badge ) {
			$indexed[ $badge['key'] ] = $badge;
		}
		return $indexed;
	}

	/**
	 * A lifetime member gets the supporter badge.
	 */
	public function test_lifetime_member_has_supporter_badge(): void {
		$user_id = self::factory()->user->create();
		update_user_meta(
			$user_id,
			'extrachill_lifetime_membership',
			array(
				'purchased' => '2026-11-15 00:00:00',
				'order_id'  => 1,
			)
		);

		$this->assertTrue( ec_is_supporter( $user_id ) );

		$badges = $this->badges_by_key( $user_id );
		$this->assertArrayHasKey( 'supporter', $badges );
		$this->assertSame( 'heart', $badges['supporter']['icon'] );
		$this->assertSame( 'user-is-supporter', $badges['supporter']['class_name'] );
		$this->assertSame( 'Extra Chill Supporter', $badges['supporter']['title'] );
	}

	/**
	 * Someone without a membership has no supporter badge.
	 */
	public function test_non_member_has_no_supporter_badge(): void {
		$user_id = self::factory()->user->create();

		$this->assertFalse( ec_is_supporter( $user_id ) );
		$this->assertArrayNotHasKey( 'supporter', $this->badges_by_key( $user_id ) );
		$this->assertFalse( ec_is_supporter( 0 ) );
	}

	/**
	 * The supporter badge comes after role badges.
	 */
	public function test_supporter_badge_follows_role_badges(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'user_is_artist', '1' );
		update_user_meta( $user_id, 'extrachill_lifetime_membership', array( 'purchased' => '2026-11-15 00:00:00' ) );

		$this->assertSame( array( 'artist', 'supporter' ), array_keys( $this->badges_by_key( $user_id ) ) );
	}

	/**
	 * The username lookup used by checkout still resolves membership.
	 */
	public function test_username_lookup_uses_supporter_check(): void {
		$user_id = self::factory()->user->create( array( 'user_login' => 'supporterfan' ) );
		update_user_meta( $user_id, 'extrachill_lifetime_membership', array( 'purchased' => '2026-11-15 00:00:00' ) );

		$this->assertTrue( is_user_lifetime_member( array( 'username' => 'supporterfan' ) ) );
		$this->assertFalse( is_user_lifetime_member( array( 'username' => 'nobodyhere' ) ) );
	}
}
