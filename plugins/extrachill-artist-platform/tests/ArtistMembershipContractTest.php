<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistMembershipContractTest extends EC_Artist_Platform_TestCase {
	/** @var int[] Artist profiles whose fixtures the team store's own transaction committed. */
	private $committed_artists = array();

	/**
	 * The team store commits its own transactions, which also commits the WP
	 * test transaction. Remove those fixtures so later suites see a clean blog.
	 */
	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->committed_artists as $artist ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE team_type = %s AND team_id = %d', \ExtraChill\Users\Teams\TeamMembershipRepository::table(), 'artist', $artist ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE team_type = %s AND team_id = %d', \ExtraChill\Users\Teams\TeamMembershipRepository::invitations_table(), 'artist', $artist ) );
			switch_to_blog( $this->artist_blog_id() );
			wp_delete_post( $artist, true );
			restore_current_blog();
		}
		$this->committed_artists = array();
		parent::tearDown();
	}

	private function committed_artist( string $title ): int {
		$artist                    = $this->create_artist_profile( $title );
		$this->committed_artists[] = $artist;
		return $artist;
	}

	public function test_membership_is_idempotent_and_only_active_rows_grant_authority(): void {
		$user = (int) self::factory()->user->create();
		$artist = $this->create_artist_profile( 'Membership Artist' );
		$this->assertTrue( ec_add_artist_membership( $user, $artist ) );
		$this->assertTrue( ec_add_artist_membership( $user, $artist ) );
		$this->assertTrue( ec_can_manage_artist( $user, $artist ) );
		$this->assertTrue( ec_remove_artist_membership( $user, $artist ) );
		$this->assertTrue( ec_remove_artist_membership( $user, $artist ) );
		$this->assertFalse( ec_can_manage_artist( $user, $artist ) );
		$this->assertSame( array(), ec_get_artists_for_user( $user ) );
		$this->assertTrue( ec_add_artist_membership( $user, $artist ) );
		$this->assertTrue( ec_can_manage_artist( $user, $artist ) );
	}

	public function test_network_artist_invitation_accepts_and_cancels_without_granting_pending_authority(): void {
		$user = (int) self::factory()->user->create();
		$actor = $this->create_admin_user( 'admin' );
		$artist = $this->committed_artist( 'Bound Artist' );
		$adapter = \ExtraChill\Users\Teams\TeamOnboardingAdapters::get( 'artist' );
		$store = new \ExtraChill\Users\Teams\TeamOnboardingRepository( $adapter );
		$members = new \ExtraChill\Users\Teams\TeamMembershipRepository();
		$this->assertSame( 1, $members->insert( 'artist', $members->new_row( 'artist', $artist, $actor, true, $actor ) ) );
		$invite = $store->create_invitation( $actor, $artist, $user, false, hash_hmac( 'sha256', strtolower( get_userdata( $user )->user_email ), wp_salt( 'auth' ) ) );
		$this->assertIsArray( $invite );
		$this->assertSame( 'invited', $members->get( 'artist', $artist, $user )['status'] );
		$this->assertFalse( ec_can_manage_artist( $user, $artist ) );
		$this->assertFalse( ec_is_user_artist_member( $user, $artist ) );
		$this->assertFalse( ec_user_can_manage_artist_object( $user, $artist ) );
		$this->assertSame( array(), ec_get_artists_for_user( $user ) );
		$prepared = $store->prepare_delivery( $invite['_delivery_id'], 3600 );
		$this->assertIsArray( $prepared );
		$result = $store->respond_to_invitation( $user, $prepared['public_id'], $prepared['_delivery_token'], $artist, false, (int) $prepared['version'], $store::INVITE_ACCEPTED );
		$this->assertIsArray( $result );
		$this->assertSame( 'active', $members->get( 'artist', $artist, $user )['status'] );
		$this->assertTrue( ec_can_manage_artist( $user, $artist ) );

		$other = (int) self::factory()->user->create();
		$invite = $store->create_invitation( $actor, $artist, $other, false, hash_hmac( 'sha256', strtolower( get_userdata( $other )->user_email ), wp_salt( 'auth' ) ) );
		$this->assertIsArray( $invite );
		$service = new \ExtraChill\Users\Teams\TeamOnboardingService( $adapter );
		$this->assertIsArray( $service->cancel_invitation( $actor, (int) $invite['id'], (int) $invite['version'] ) );
		$this->assertSame( 'revoked', $members->get( 'artist', $artist, $other )['status'] );
	}

	public function test_roster_consumers_list_only_pending_network_invitations(): void {
		$actor = $this->create_admin_user( 'admin' );
		$user = (int) self::factory()->user->create();
		$artist = $this->committed_artist( 'Roster Artist' );
		$this->create_artist_membership( $actor, $artist );
		wp_set_current_user( $actor );
		$adapter = \ExtraChill\Users\Teams\TeamOnboardingAdapters::get( 'artist' );
		$store = new \ExtraChill\Users\Teams\TeamOnboardingRepository( $adapter );
		$invite = $store->create_invitation( $actor, $artist, $user, false, hash_hmac( 'sha256', strtolower( get_userdata( $user )->user_email ), wp_salt( 'auth' ) ) );
		$this->assertIsArray( $invite );
		switch_to_blog( $this->artist_blog_id() );
		try {
			$roster = extrachill_artist_platform_ability_artist_get_roster( array( 'id' => $artist ) );
			$this->assertIsArray( $roster );
			$this->assertSame( (int) $invite['id'], $roster['invites'][0]['id'] );
			$this->assertArrayNotHasKey( 'token', $roster['invites'][0] );
			$this->assertIsArray( $store->cancel_invitation( $actor, (int) $invite['id'], (int) $invite['version'] ) );
			$roster = extrachill_artist_platform_ability_artist_get_roster( array( 'id' => $artist ) );
			$this->assertSame( array(), $roster['invites'] );
		} finally {
			restore_current_blog();
		}
	}
}
