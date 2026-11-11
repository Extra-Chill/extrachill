<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistMembershipContractTest extends EC_Artist_Platform_TestCase {
	private $user_id;
	private $admin_id;
	private $profile_id;
	private $private_profile_id;
	private $regular_post_id;

	protected function setUp(): void {
		parent::setUp();

		$this->user_id            = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin_id           = $this->create_admin_user( 'admin' );
		$this->profile_id         = $this->create_artist_profile( 'Bound Artist' );
		$this->private_profile_id = $this->create_artist_profile( 'Private Artist', array( 'post_status' => 'private' ) );
		$this->regular_post_id    = (int) self::factory()->post->create( array( 'post_title' => 'Regular Post' ) );
	}

	private function membership_row( int $user_id, int $artist_id ) {
		$repository = new \ExtraChill\Users\Teams\TeamMembershipRepository();
		return $repository->get( 'artist', $artist_id, $user_id );
	}

	public function test_add_and_remove_are_idempotent_and_read_back_from_the_team_store(): void {
		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array( $this->user_id ), $this->artist_team_member_ids( $this->profile_id ) );
		$this->assertSame( array( $this->profile_id ), ec_get_artists_for_user( $this->user_id ) );
		$this->assertTrue( ec_can_manage_artist( $this->user_id, $this->profile_id ) );

		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array(), $this->artist_team_member_ids( $this->profile_id ) );
		$this->assertSame( array(), ec_get_artists_for_user( $this->user_id ) );
		$this->assertFalse( ec_can_manage_artist( $this->user_id, $this->profile_id ) );
		$this->assertSame( 'revoked', $this->membership_row( $this->user_id, $this->profile_id )['status'] );
	}

	public function test_network_artist_invitation_accepts_and_cancels_without_granting_pending_authority(): void {
		$adapter = new \ExtraChillArtistPlatform\ArtistOnboardingAdapter();
		$store   = new \ExtraChill\Users\Teams\TeamOnboardingRepository( $adapter );
		$members = new \ExtraChill\Users\Teams\TeamMembershipRepository();
		$this->assertSame( 1, $members->insert( 'artist', $members->new_row( 'artist', $this->profile_id, $this->admin_id, true, $this->admin_id ) ) );
		$invite = $store->create_invitation( $this->admin_id, $this->profile_id, $this->user_id, false, $this->email_hash( $this->user_id ) );
		$this->assertIsArray( $invite );
		$this->assertSame( 'invited', $this->membership_row( $this->user_id, $this->profile_id )['status'] );
		$this->assertFalse( ec_can_manage_artist( $this->user_id, $this->profile_id ) );
		$this->assertFalse( ec_is_user_artist_member( $this->user_id, $this->profile_id ) );
		$this->assertFalse( ec_user_can_manage_artist_object( $this->user_id, $this->profile_id ) );
		$this->assertSame( array(), ec_get_artists_for_user( $this->user_id ) );
		$this->assertNotContains( $this->user_id, $this->artist_team_member_ids( $this->profile_id ) );
		$prepared = $store->prepare_delivery( $invite['_delivery_id'], 3600 );
		$this->assertIsArray( $prepared );
		$result = $store->respond_to_invitation( $this->user_id, $prepared['public_id'], $prepared['_delivery_token'], $this->profile_id, false, (int) $prepared['version'], $store::INVITE_ACCEPTED );
		$this->assertIsArray( $result );
		$this->assertSame( 'active', $this->membership_row( $this->user_id, $this->profile_id )['status'] );
		$this->assertTrue( ec_can_manage_artist( $this->user_id, $this->profile_id ) );

		$other = (int) self::factory()->user->create();
		$invite = $store->create_invitation( $this->admin_id, $this->profile_id, $other, false, $this->email_hash( $other ) );
		$this->assertIsArray( $invite );
		$this->assertIsArray( $store->cancel_invitation( $this->admin_id, (int) $invite['id'], (int) $invite['version'] ) );
		$this->assertSame( 'revoked', $this->membership_row( $other, $this->profile_id )['status'] );
	}

	private function email_hash( int $user_id ): string {
		return hash_hmac( 'sha256', strtolower( get_userdata( $user_id )->user_email ), wp_salt( 'auth' ) );
	}

	public function test_removing_a_never_linked_pair_succeeds_without_a_row(): void {
		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertNull( $this->membership_row( $this->user_id, $this->profile_id ) );
		$this->assertNull( ec_get_artist_membership_failure() );
	}

	public function test_re_adding_a_revoked_member_reactivates_the_same_row(): void {
		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$first = $this->membership_row( $this->user_id, $this->profile_id );
		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );

		$row = $this->membership_row( $this->user_id, $this->profile_id );
		$this->assertSame( $first['id'], $row['id'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertGreaterThan( $first['version'], $row['version'] );
		$this->assertNull( $row['revoked_at'] );
	}

	public function test_add_rejects_unpublished_wrong_type_and_deleted_targets(): void {
		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->private_profile_id ) );
		$this->assertSame( 'invalid_artist_profile', ec_get_artist_membership_failure()->get_error_code() );
		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->regular_post_id ) );
		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->private_profile_id + 999 ) );
		$this->assertFalse( ec_add_artist_membership( 0, $this->profile_id ) );
		$this->assertSame( 'invalid_artist_membership', ec_get_artist_membership_failure()->get_error_code() );
		$this->assertSame( array(), $this->user_artist_team_ids( $this->user_id ) );
	}

	public function test_add_store_failure_is_retryable_and_a_retry_applies_it(): void {
		$this->fail_team_writes( 10 );
		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$failure = ec_get_artist_membership_failure();
		$this->assertSame( 'artist_roster_update_failed', $failure->get_error_code() );
		$this->assertTrue( $failure->get_error_data()['retryable'] );
		$this->assertSame( array(), $this->artist_team_member_ids( $this->profile_id ) );

		$this->ec_clear_injected_filters();
		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertNull( ec_get_artist_membership_failure() );
		$this->assertSame( array( $this->user_id ), $this->artist_team_member_ids( $this->profile_id ) );
	}

	public function test_remove_store_failure_keeps_the_member_and_a_retry_revokes_it(): void {
		$this->create_artist_membership( $this->user_id, $this->profile_id );
		$this->fail_team_writes( 10 );
		$this->assertFalse( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( 'artist_membership_remove_failed', ec_get_artist_membership_failure()->get_error_code() );
		$this->assertSame( array( $this->user_id ), $this->artist_team_member_ids( $this->profile_id ) );

		$this->ec_clear_injected_filters();
		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array(), $this->artist_team_member_ids( $this->profile_id ) );
	}

	public function test_concurrent_members_are_independent_rows(): void {
		$other = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertTrue( ec_add_artist_membership( $other, $this->profile_id ) );
		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertTrue( ec_remove_artist_membership( $other, $this->profile_id ) );
		$this->assertSame( array( $this->user_id ), $this->artist_team_member_ids( $this->profile_id ) );
	}

	public function test_remove_from_the_main_blog_resolves_the_artist_site(): void {
		$this->create_artist_membership( $this->user_id, $this->profile_id );
		$this->assertNotSame( $this->artist_blog_id(), get_current_blog_id() );

		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array(), $this->artist_team_member_ids( $this->profile_id ) );
		$this->assertNull( ec_get_artist_membership_failure() );
	}

	public function test_pending_invitation_creation_retries_cas_conflict(): void {
		$existing   = array(
			'id'    => 'existing',
			'email' => 'one@example.com',
		);
		$concurrent = array(
			'id'    => 'concurrent',
			'email' => 'two@example.com',
		);
		update_post_meta( $this->profile_id, '_pending_invitations', array( $existing ) );
		$this->conflict_next_meta_update( 'post', '_pending_invitations', array( $existing, $concurrent ) );

		$result = ec_add_pending_invitation( $this->profile_id, 'Three', 'three@example.com' );
		$this->assertIsArray( $result );
		$stored = get_post_meta( $this->profile_id, '_pending_invitations', true );
		$this->assertSame(
			array( 'one@example.com', 'two@example.com', 'three@example.com' ),
			array_column( (array) $stored, 'email' )
		);
	}

	public function test_pending_invitation_acceptance_retries_cleanup_conflict(): void {
		$accepted   = array(
			'id'    => 'invite-1',
			'email' => 'user-' . $this->user_id . '@example.com',
		);
		$concurrent = array(
			'id'    => 'invite-2',
			'email' => 'other@example.com',
		);
		update_post_meta( $this->profile_id, '_pending_invitations', array( $accepted ) );
		switch_to_blog( $this->main_blog_id() );
		update_post_meta( $this->profile_id, '_pending_invitations', array( $accepted ) );
		restore_current_blog();
		$this->create_artist_membership( $this->user_id, $this->profile_id );
		$this->conflict_next_meta_update( 'post', '_pending_invitations', array( $accepted, $concurrent ) );

		$this->assertTrue( ec_accept_artist_membership_invitation( $this->user_id, $this->profile_id, 'invite-1' ) );
		$this->assertSame( array( $concurrent ), (array) get_post_meta( $this->profile_id, '_pending_invitations', true ) );
	}

	public function test_invitation_store_failure_is_retryable_and_retains_the_invitation(): void {
		update_post_meta( $this->profile_id, '_pending_invitations', array( array( 'id' => 'invite-1' ) ) );
		$this->fail_team_writes( 10 );

		$result = ec_accept_artist_membership_invitation( $this->user_id, $this->profile_id, 'invite-1' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_roster_update_failed', $result->get_error_code() );
		$this->assertSame( array( array( 'id' => 'invite-1' ) ), (array) get_post_meta( $this->profile_id, '_pending_invitations', true ) );
		$this->assertSame( array(), $this->user_artist_team_ids( $this->user_id ) );
	}

	public function test_invitation_cleanup_failure_is_truthful_and_retryable(): void {
		update_post_meta( $this->profile_id, '_pending_invitations', array( array( 'id' => 'invite-1' ) ) );
		$this->fail_meta_updates( 'post', 5, '_pending_invitations' );

		$result = ec_accept_artist_membership_invitation( $this->user_id, $this->profile_id, 'invite-1' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invitation_cleanup_failed', $result->get_error_code() );
		$this->assertSame( array( $this->profile_id ), $this->user_artist_team_ids( $this->user_id ) );
		$this->assertSame( array( array( 'id' => 'invite-1' ) ), (array) get_post_meta( $this->profile_id, '_pending_invitations', true ) );

		// Retry after clearing the injected failure reconciles everything.
		$this->ec_clear_injected_filters();
		$this->assertTrue( ec_accept_artist_membership_invitation( $this->user_id, $this->profile_id, 'invite-1' ) );
		$this->assertSame( array(), (array) get_post_meta( $this->profile_id, '_pending_invitations', true ) );
	}

	public function test_invitation_store_failure_never_removes_preexisting_membership(): void {
		$this->create_artist_membership( $this->user_id, $this->profile_id );
		update_post_meta( $this->profile_id, '_pending_invitations', array( array( 'id' => 'invite-1' ) ) );
		$this->fail_team_writes( 10 );

		$this->assertTrue( ec_accept_artist_membership_invitation( $this->user_id, $this->profile_id, 'invite-1' ) );
		$this->assertSame( array( $this->user_id ), $this->artist_team_member_ids( $this->profile_id ) );
	}

	public function test_profile_save_propagates_member_removal_failure(): void {
		$this->create_artist_membership( $this->user_id, $this->profile_id );
		$this->fail_team_writes( 10 );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$result = ec_handle_artist_profile_save( $this->profile_id, array( 'remove_member_ids' => (string) $this->user_id ) );
		} finally {
			restore_current_blog();
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_membership_remove_failed', $result->get_error_code() );
		$this->assertSame( array( $this->user_id ), $this->artist_team_member_ids( $this->profile_id ) );
	}

	public function test_platform_artist_creation_rolls_back_when_membership_fails(): void {
		$this->ec_require_mysql_advisory_locks();

		$this->fail_team_writes( 10 );

		$this->assertFalse( ec_provision_platform_artist() );
		switch_to_blog( $this->artist_blog_id() );
		$stranded = get_posts(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'any',
				'title'       => 'Extra Chill',
				'fields'      => 'ids',
			)
		);
		restore_current_blog();
		$this->assertSame( array(), $stranded );
		$this->assertNotSame( $this->profile_id, (int) get_site_option( 'ec_platform_artist_id', 0 ) );
	}

	public function test_provisioning_failure_does_not_set_success_throttle(): void {
		$this->ec_require_mysql_advisory_locks();

		wp_set_current_user( $this->admin_id );
		delete_site_transient( 'ec_platform_artist_provisioned' );
		$this->fail_team_writes( 10 );

		ec_maybe_provision_platform_artist();

		$this->assertFalse( get_site_transient( 'ec_platform_artist_provisioned' ) );
	}

	public function test_artist_invitation_ability_validates_and_applies_on_owner_site(): void {
		switch_to_blog( $this->main_blog_id() );
		update_post_meta(
			$this->profile_id,
			'_pending_invitations',
			array(
				array(
					'id'     => 'invite-1',
					'email'  => 'user-' . $this->user_id . '@example.com',
					'token'  => 'secret-token',
					'status' => EC_INVITE_STATUS_NEW_USER,
				),
			)
		);
		restore_current_blog();

		$user_id = $this->user_id;
		wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => 'user-' . $user_id . '@example.com',
			)
		);

		$input     = array(
			'artist_id' => $this->profile_id,
			'email'     => 'user-' . $user_id . '@example.com',
			'token'     => 'secret-token',
		);
		$validated = extrachill_artist_platform_ability_artist_invitation( $input );
		if ( is_wp_error( $validated ) ) {
			$this->fail( 'invitation validation failed: ' . $validated->get_error_code() . ' ' . $validated->get_error_message() );
		}
		$this->assertSame( array(
			'status'    => 'valid',
			'artist_id' => $this->profile_id,
		), $validated );

		$result = extrachill_artist_platform_ability_artist_invitation( array_merge( $input, array( 'user_id' => $user_id ) ) );
		$this->assertSame( array(
			'status'    => 'applied',
			'artist_id' => $this->profile_id,
		), $result );
		$this->assertSame( array(), (array) get_post_meta( $this->profile_id, '_pending_invitations', true ) );
	}

	public function test_linked_members_are_active_rows_of_a_published_artist(): void {
		$member  = $this->user_id;
		$revoked = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->create_artist_membership( $member, $this->profile_id );
		$this->create_artist_membership( $revoked, $this->profile_id );
		$this->assertTrue( ec_remove_artist_membership( $revoked, $this->profile_id ) );
		$this->create_artist_membership( $member, $this->private_profile_id );

		$this->assertSame( array( $member ), array_map( static fn( $user ) => (int) $user->ID, ec_get_linked_members( $this->profile_id ) ) );
		$this->assertSame( array(), ec_get_linked_members( $this->private_profile_id ) );
		$this->assertSame( array(), ec_get_linked_members( $this->private_profile_id + 999 ) );
		$this->assertSame( array( $this->profile_id ), ec_get_artists_for_user( $member ) );
	}

	public function test_admin_handlers_report_store_failures(): void {
		wp_set_current_user( $this->create_admin_user( 'superadmin' ) );

		$this->fail_team_writes( 10 );
		$result = extrachill_artist_platform_ability_admin_link_artist_relationship( array(
			'user_id'   => $this->user_id,
			'artist_id' => $this->profile_id,
		) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_roster_update_failed', $result->get_error_code() );

		$this->ec_clear_injected_filters();
		$this->create_artist_membership( $this->user_id, $this->profile_id );
		$this->fail_team_writes( 10 );
		$result = extrachill_artist_platform_ability_admin_unlink_artist_relationship( array(
			'user_id'   => $this->user_id,
			'artist_id' => $this->profile_id,
		) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_membership_remove_failed', $result->get_error_code() );
	}
}
