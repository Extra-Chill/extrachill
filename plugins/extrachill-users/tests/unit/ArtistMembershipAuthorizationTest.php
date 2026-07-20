<?php
/**
 * Artist membership authorization tests.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify artist authorization uses the reciprocal membership contract.
 */
class Test_Artist_Membership_Authorization extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	private int $artist_blog_id;

	protected function setUp(): void {
		parent::setUp();
		$this->artist_blog_id = (int) ec_get_blog_id( 'artist' );
		while ( ! get_blog_details( $this->artist_blog_id ) ) {
			self::factory()->blog->create();
		}

		switch_to_blog( $this->artist_blog_id );
		register_post_type( 'artist_profile', array( 'public' => true ) );
		restore_current_blog();
	}

	private function create_artist( int $author, string $status = 'publish', string $type = 'artist_profile' ): int {
		switch_to_blog( $this->artist_blog_id );
		$artist_id = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => $status,
				'post_type'   => $type,
			)
		);
		restore_current_blog();

		return $artist_id;
	}

	private function set_artist_members( int $artist_id, array $user_ids ): void {
		switch_to_blog( $this->artist_blog_id );
		update_post_meta( $artist_id, '_artist_member_ids', $user_ids );
		restore_current_blog();
	}

	public function test_valid_reciprocal_membership_can_manage_artist(): void {
		$user_id   = self::factory()->user->create();
		$artist_id = $this->create_artist( self::factory()->user->create() );
		update_user_meta( $user_id, '_artist_profile_ids', array( $artist_id ) );
		$this->set_artist_members( $artist_id, array( $user_id ) );

		$this->assertTrue( ec_can_manage_artist( $user_id, $artist_id ) );
	}

	public function test_one_sided_memberships_cannot_manage_artist(): void {
		$user_side_id   = self::factory()->user->create();
		$artist_side_id = self::factory()->user->create();
		$artist_id      = $this->create_artist( self::factory()->user->create() );
		update_user_meta( $user_side_id, '_artist_profile_ids', array( $artist_id ) );
		$this->set_artist_members( $artist_id, array( $artist_side_id ) );

		$this->assertFalse( ec_can_manage_artist( $user_side_id, $artist_id ) );
		$this->assertFalse( ec_can_manage_artist( $artist_side_id, $artist_id ) );
	}

	public function test_invalid_targets_cannot_be_managed(): void {
		$user_id    = self::factory()->user->create();
		$private_id = $this->create_artist( $user_id, 'private' );
		$wrong_id   = $this->create_artist( $user_id, 'publish', 'post' );
		$deleted_id = $this->create_artist( $user_id );
		$target_ids = array( $private_id, $wrong_id, $deleted_id );
		update_user_meta( $user_id, '_artist_profile_ids', $target_ids );
		foreach ( $target_ids as $target_id ) {
			$this->set_artist_members( $target_id, array( $user_id ) );
		}
		switch_to_blog( $this->artist_blog_id );
		wp_delete_post( $deleted_id, true );
		restore_current_blog();

		foreach ( $target_ids as $target_id ) {
			$this->assertFalse( ec_can_manage_artist( $user_id, $target_id ) );
		}
	}

	public function test_former_post_author_cannot_manage_without_reciprocal_membership(): void {
		$user_id   = self::factory()->user->create();
		$artist_id = $this->create_artist( $user_id );

		$this->assertFalse( ec_can_manage_artist( $user_id, $artist_id ) );

		update_user_meta( $user_id, '_artist_profile_ids', array( $artist_id ) );
		$this->set_artist_members( $artist_id, array( $user_id ) );
		$this->assertTrue( ec_can_manage_artist( $user_id, $artist_id ) );

		$this->set_artist_members( $artist_id, array() );
		$this->assertFalse( ec_can_manage_artist( $user_id, $artist_id ) );
	}
}
