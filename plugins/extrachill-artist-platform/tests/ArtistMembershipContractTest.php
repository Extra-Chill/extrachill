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

	private function artist_side_members( int $artist_id ) {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$members = get_post_meta( $artist_id, '_artist_member_ids', true );
			return is_array( $members ) ? array_map( 'intval', $members ) : array();
		} finally {
			restore_current_blog();
		}
	}

	private function user_side_profiles( int $user_id ): array {
		$profiles = get_user_meta( $user_id, '_artist_profile_ids', true );
		return is_array( $profiles ) ? array_map( 'intval', $profiles ) : array();
	}

	public function test_add_and_remove_are_idempotent_and_keep_both_sides_consistent(): void {
		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array( $this->profile_id ), $this->user_side_profiles( $this->user_id ) );
		$this->assertSame( array( $this->user_id ), $this->artist_side_members( $this->profile_id ) );

		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array(), $this->user_side_profiles( $this->user_id ) );
	}

	public function test_add_rejects_unpublished_wrong_type_and_deleted_targets(): void {
		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->private_profile_id ) );
		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->regular_post_id ) );
		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->private_profile_id + 999 ) );
		$this->assertSame( array(), $this->user_side_profiles( $this->user_id ) );
	}

	public function test_partial_add_failure_is_truthful_and_retry_reconciles_it(): void {
		// The user-side relationship does not exist yet, so the first write is
		// an add, not an update; inject the failure at the real add boundary.
		$this->fail_meta_adds( 'user', 1, '_artist_profile_ids' );

		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array(), $this->artist_side_members( $this->profile_id ) );

		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array( $this->user_id ), array_map( static fn( $user ) => (int) $user->ID, ec_get_linked_members( $this->profile_id ) ) );
	}

	public function test_partial_remove_failure_is_truthful_and_retry_reconciles_it(): void {
		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->fail_meta_updates( 'post', 1, '_artist_member_ids' );

		$this->assertFalse( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array(), ec_get_linked_members( $this->profile_id ) );

		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array(), $this->artist_side_members( $this->profile_id ) );
	}

	public function test_add_retries_compare_and_swap_conflicts_without_losing_concurrent_members(): void {
		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $this->profile_id, '_artist_member_ids', array( 9 ) );
		restore_current_blog();
		update_user_meta( $this->user_id, '_artist_profile_ids', array( 30 ) );

		$this->conflict_next_meta_update( 'post', '_artist_member_ids', array( 9, 10 ) );
		$this->conflict_next_meta_update( 'user', '_artist_profile_ids', array( 30, 31 ) );

		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array( 30, 31, $this->profile_id ), $this->user_side_profiles( $this->user_id ) );
		$this->assertSame( array( 9, 10, $this->user_id ), $this->artist_side_members( $this->profile_id ) );
	}

	public function test_remove_retries_compare_and_swap_conflicts_without_losing_concurrent_members(): void {
		$this->create_artist_membership( 9, $this->profile_id );
		$this->create_artist_membership( $this->user_id, $this->profile_id );
		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $this->profile_id, '_artist_member_ids', array( 9, 8 ) );
		restore_current_blog();
		update_user_meta( $this->user_id, '_artist_profile_ids', array( $this->profile_id, 30 ) );

		$this->conflict_next_meta_update( 'post', '_artist_member_ids', array( 9, 8, 9 ) );
		$this->conflict_next_meta_update( 'user', '_artist_profile_ids', array( 30, 31 ) );

		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array( 30, 31 ), $this->user_side_profiles( $this->user_id ) );
		$remaining = $this->artist_side_members( $this->profile_id );
		sort( $remaining );
		$this->assertSame( array( 8, 9 ), $remaining );
	}

	public function test_opposing_remove_cannot_interleave_inside_add_relationship_lock(): void {
		$state            = new stdClass();
		$state->remove_ok = null;

		$nested = function ( $meta_id, $object_id, $meta_key ) use ( $state ) {
			if ( '_artist_member_ids' !== $meta_key ) {
				return;
			}
			$state->remove_ok = ec_remove_artist_membership( $this->user_id, $this->profile_id );
		};
		$this->ec_inject_filter( 'added_post_meta', $nested, 100 );
		$this->ec_inject_filter( 'updated_post_meta', $nested, 100 );

		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertFalse( $state->remove_ok, 'A nested opposing removal inside the held lock must be denied.' );
		$this->assertSame( array( $this->profile_id ), $this->user_side_profiles( $this->user_id ) );
		$this->assertSame( array( $this->user_id ), $this->artist_side_members( $this->profile_id ) );
	}

	public function test_opposing_add_cannot_interleave_inside_remove_relationship_lock(): void {
		$this->create_artist_membership( $this->user_id, $this->profile_id );

		$state         = new stdClass();
		$state->add_ok = null;

		$this->ec_inject_filter(
			'updated_user_meta',
			function ( $meta_id, $object_id, $meta_key ) use ( $state ) {
				if ( '_artist_profile_ids' !== $meta_key ) {
					return;
				}
				$state->add_ok = ec_add_artist_membership( $this->user_id, $this->profile_id );
			},
			100
		);

		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertFalse( $state->add_ok, 'A nested opposing add inside the held lock must be denied.' );
		$this->assertSame( array(), $this->user_side_profiles( $this->user_id ) );
		$this->assertSame( array(), $this->artist_side_members( $this->profile_id ) );
	}

	public function test_same_request_relock_is_denied_while_a_fallback_lock_is_held(): void {
		$this->ec_require_non_mysql_wpdb();

		$lock_name = sprintf( 'ec_artist_membership_%d_%d', $this->user_id, $this->profile_id );
		$this->assertTrue( ec_acquire_artist_membership_lock( $this->user_id, $this->profile_id ) );
		$this->assertFalse( ec_acquire_artist_membership_lock( $this->user_id, $this->profile_id ) );

		unset( $GLOBALS['ec_artist_membership_locks'][ $lock_name ] );
		// A second same-name acquire after losing the in-request marker must
		// still see the persisted network option lock.
		$this->assertFalse( ec_acquire_artist_membership_lock( $this->user_id, $this->profile_id ) );

		ec_release_artist_membership_lock( $this->user_id, $this->profile_id );
	}

	public function test_fallback_lock_contention_from_a_stale_owner_is_reported_as_busy(): void {
		$this->ec_require_non_mysql_wpdb();

		$lock_name = sprintf( 'ec_artist_membership_%d_%d', $this->user_id, $this->profile_id );
		$option    = 'ec_artist_membership_lock_' . md5( $lock_name );
		$competing = array(
			'owner'   => 'competing-owner',
			'expires' => time() + 30,
		);
		switch_to_blog( get_main_site_id() );
		add_option( $option, $competing, '', false );
		restore_current_blog();

		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( 'artist_membership_busy', ec_get_artist_membership_failure()->get_error_code() );

		switch_to_blog( get_main_site_id() );
		delete_option( $option );
		restore_current_blog();
	}

	public function test_fallback_lock_stale_owner_is_recovered_atomically(): void {
		$this->ec_require_non_mysql_wpdb();

		$lock_name = sprintf( 'ec_artist_membership_%d_%d', $this->user_id, $this->profile_id );
		$option    = 'ec_artist_membership_lock_' . md5( $lock_name );
		$stale     = array(
			'owner'   => 'stale-owner',
			'expires' => time() - 1,
		);
		switch_to_blog( get_main_site_id() );
		add_option( $option, $stale, '', false );
		restore_current_blog();

		$this->assertTrue( ec_acquire_artist_membership_lock( $this->user_id, $this->profile_id ) );

		switch_to_blog( get_main_site_id() );
		$stored = get_option( $option );
		restore_current_blog();
		$this->assertNotSame( 'stale-owner', $stored['owner'] );

		ec_release_artist_membership_lock( $this->user_id, $this->profile_id );
	}

	public function test_fallback_release_never_deletes_another_owner_lock(): void {
		$this->ec_require_non_mysql_wpdb();

		$lock_name = sprintf( 'ec_artist_membership_%d_%d', $this->user_id, $this->profile_id );
		$this->assertTrue( ec_acquire_artist_membership_lock( $this->user_id, $this->profile_id ) );
		$lock  = $GLOBALS['ec_artist_membership_locks'][ $lock_name ];
		$other = array(
			'owner'   => 'replacement-owner',
			'expires' => time() + 30,
		);
		switch_to_blog( get_main_site_id() );
		update_option( $lock['option'], $other );
		$stored = get_option( $lock['option'] );
		restore_current_blog();

		ec_release_artist_membership_lock( $this->user_id, $this->profile_id );

		switch_to_blog( get_main_site_id() );
		$remaining = get_option( $lock['option'] );
		delete_option( $lock['option'] );
		restore_current_blog();
		$this->assertSame( 'replacement-owner', $remaining['owner'] );
		$this->assertArrayNotHasKey( $lock_name, $GLOBALS['ec_artist_membership_locks'] );
	}

	public function test_add_reports_actionable_error_when_compensating_rollback_fails(): void {
		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $this->profile_id, '_artist_member_ids', array() );
		restore_current_blog();
		delete_user_meta( $this->user_id, '_artist_profile_ids' );

		// The artist-side add succeeds; the user-side add fails; the artist-side
		// rollback swap must also fail for the manual-repair error.
		$this->fail_meta_updates( 'user', 5, '_artist_profile_ids' );
		$this->fail_meta_adds( 'user', 5, '_artist_profile_ids' );
		$state          = new stdClass();
		$state->count   = 0;
		$state->tracker = $this;
		$this->ec_inject_filter(
			'update_post_metadata',
			function ( $check, $object_id, $meta_key ) use ( $state ) {
				if ( '_artist_member_ids' !== $meta_key ) {
					return $check;
				}
				++$state->count;
				if ( 2 === $state->count ) {
					return false;
				}
				return $check;
			},
			100
		);

		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( 'artist_membership_rollback_failed', ec_get_artist_membership_failure()->get_error_code() );
		$this->assertFalse( ec_get_artist_membership_failure()->get_error_data()['retryable'] );

		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $this->profile_id, '_artist_member_ids', array() );
		restore_current_blog();
	}

	public function test_remove_resolves_artist_site_before_mutating_user_record(): void {
		// The caller sits on the main blog; removal must resolve the canonical
		// artist site for the artist side before touching the user record.
		$this->create_artist_membership( $this->user_id, $this->profile_id );
		$this->assertNotSame( $this->artist_blog_id(), get_current_blog_id() );

		$this->assertTrue( ec_remove_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array(), $this->user_side_profiles( $this->user_id ) );
		$this->assertSame( array(), $this->artist_side_members( $this->profile_id ) );
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

	public function test_invitation_failure_rolls_back_and_retains_retry_token(): void {
		update_post_meta( $this->profile_id, '_pending_invitations', array( array( 'id' => 'invite-1' ) ) );
		$this->fail_meta_adds( 'user', 1, '_artist_profile_ids' );

		$result = ec_accept_artist_membership_invitation( $this->user_id, $this->profile_id, 'invite-1' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_membership_update_failed', $result->get_error_code() );
		$this->assertSame( array( array( 'id' => 'invite-1' ) ), (array) get_post_meta( $this->profile_id, '_pending_invitations', true ) );
		$this->assertSame( array(), $this->user_side_profiles( $this->user_id ) );
	}

	public function test_invitation_cleanup_failure_is_truthful_and_retryable(): void {
		update_post_meta( $this->profile_id, '_pending_invitations', array( array( 'id' => 'invite-1' ) ) );
		$this->fail_meta_updates( 'post', 5, '_pending_invitations' );

		$result = ec_accept_artist_membership_invitation( $this->user_id, $this->profile_id, 'invite-1' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invitation_cleanup_failed', $result->get_error_code() );
		$this->assertSame( array( $this->profile_id ), $this->user_side_profiles( $this->user_id ) );
		$this->assertSame( array( array( 'id' => 'invite-1' ) ), (array) get_post_meta( $this->profile_id, '_pending_invitations', true ) );

		// Retry after clearing the injected failure reconciles everything.
		$this->ec_clear_injected_filters();
		$this->assertTrue( ec_accept_artist_membership_invitation( $this->user_id, $this->profile_id, 'invite-1' ) );
		$this->assertSame( array(), (array) get_post_meta( $this->profile_id, '_pending_invitations', true ) );
	}

	public function test_invitation_busy_failure_never_removes_preexisting_membership(): void {
		$this->create_artist_membership( $this->user_id, $this->profile_id );
		update_post_meta( $this->profile_id, '_pending_invitations', array( array( 'id' => 'invite-1' ) ) );

		$lock_name = sprintf( 'ec_artist_membership_%d_%d', $this->user_id, $this->profile_id );
		$GLOBALS['ec_artist_membership_locks'][ $lock_name ] = true;

		$result = ec_accept_artist_membership_invitation( $this->user_id, $this->profile_id, 'invite-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_membership_busy', $result->get_error_code() );
		$this->assertSame( array( $this->profile_id ), $this->user_side_profiles( $this->user_id ) );
		$this->assertSame( array( $this->user_id ), $this->artist_side_members( $this->profile_id ) );

		unset( $GLOBALS['ec_artist_membership_locks'][ $lock_name ] );
	}

	public function test_invitation_preserves_preexisting_artist_side_after_user_write_failure(): void {
		$this->create_artist_membership( $this->user_id, $this->profile_id );
		update_post_meta( $this->profile_id, '_pending_invitations', array( array( 'id' => 'invite-1' ) ) );
		delete_user_meta( $this->user_id, '_artist_profile_ids' );
		$this->fail_meta_adds( 'user', 1, '_artist_profile_ids' );

		$result = ec_accept_artist_membership_invitation( $this->user_id, $this->profile_id, 'invite-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_membership_update_failed', $result->get_error_code() );
		$this->assertSame( array( $this->user_id ), $this->artist_side_members( $this->profile_id ) );
	}

	public function test_invitation_reports_manual_repair_when_created_partial_state_cannot_be_compensated(): void {
		update_post_meta( $this->profile_id, '_pending_invitations', array( array( 'id' => 'invite-1' ) ) );
		// Pre-seed both sides empty so the writes take the update path, where
		// the counters below can order the injected failures deterministically.
		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $this->profile_id, '_artist_member_ids', array() );
		restore_current_blog();
		update_user_meta( $this->user_id, '_artist_profile_ids', array() );

		// The user-side write fails immediately; the artist-side compensation
		// update fails as well, so the partial state needs manual repair.
		$this->fail_meta_updates( 'user', 5, '_artist_profile_ids' );
		$post_state        = new stdClass();
		$post_state->count = 0;
		$this->ec_inject_filter(
			'update_post_metadata',
			function ( $check, $object_id, $meta_key ) use ( $post_state ) {
				if ( '_artist_member_ids' !== $meta_key ) {
					return $check;
				}
				++$post_state->count;
				return 1 === $post_state->count ? $check : false;
			},
			100
		);

		$result = ec_accept_artist_membership_invitation( $this->user_id, $this->profile_id, 'invite-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_invitation_rollback_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
	}

	public function test_profile_save_propagates_member_removal_failure(): void {
		$author_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->create_artist_membership( $this->user_id, $this->profile_id );
		$this->fail_meta_updates( 'post', 5, '_artist_member_ids' );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$result = ec_handle_artist_profile_save( $this->profile_id, array( 'remove_member_ids' => (string) $this->user_id ) );
		} finally {
			restore_current_blog();
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_membership_partial_remove', $result->get_error_code() );
	}

	public function test_platform_artist_creation_rolls_back_when_membership_fails(): void {
		$this->ec_require_mysql_advisory_locks();

		$this->fail_meta_adds( 'post', 10, '_artist_member_ids' );

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
		$this->fail_meta_adds( 'post', 10, '_artist_member_ids' );

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

	public function test_reverse_roster_requires_reciprocal_membership_and_valid_artist(): void {
		$member  = $this->user_id;
		$one_way = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->create_artist_membership( $member, $this->profile_id );

		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $this->profile_id, '_artist_member_ids', array( $member, $one_way ) );
		update_post_meta( $this->private_profile_id, '_artist_member_ids', array( $member ) );
		restore_current_blog();
		update_user_meta( $one_way, '_artist_profile_ids', array() );

		$this->assertSame( array( $member ), array_map( static fn( $user ) => (int) $user->ID, ec_get_linked_members( $this->profile_id ) ) );
		$this->assertSame( array(), ec_get_linked_members( $this->private_profile_id ) );
		$this->assertSame( array(), ec_get_linked_members( $this->private_profile_id + 999 ) );
	}

	public function test_admin_handlers_report_partial_write_failures(): void {
		wp_set_current_user( $this->create_admin_user( 'superadmin' ) );

		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $this->profile_id, '_artist_member_ids', array() );
		restore_current_blog();
		update_user_meta( $this->user_id, '_artist_profile_ids', array() );
		$this->fail_meta_updates( 'user', 5, '_artist_profile_ids' );
		$result = extrachill_artist_platform_ability_admin_link_artist_relationship( array(
			'user_id'   => $this->user_id,
			'artist_id' => $this->profile_id,
		) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_membership_update_failed', $result->get_error_code() );

		$this->create_artist_membership( $this->user_id, $this->profile_id );
		$this->fail_meta_updates( 'post', 5, '_artist_member_ids' );
		$result = extrachill_artist_platform_ability_admin_unlink_artist_relationship( array(
			'user_id'   => $this->user_id,
			'artist_id' => $this->profile_id,
		) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_membership_partial_remove', $result->get_error_code() );
	}

	public function test_mysql_lock_acquires_and_releases_advisory_lock(): void {
		$this->ec_require_mysql_advisory_locks();

		$lock_name = sprintf( 'ec_artist_membership_%d_%d', $this->user_id, $this->profile_id );

		$this->assertTrue( ec_acquire_artist_membership_lock( $this->user_id, $this->profile_id ) );
		$this->assertSame( 'mysql', $GLOBALS['ec_artist_membership_locks'][ $lock_name ]['backend'] );

		ec_release_artist_membership_lock( $this->user_id, $this->profile_id );

		$this->assertArrayNotHasKey( $lock_name, $GLOBALS['ec_artist_membership_locks'] );
	}

	public function test_mysql_lock_query_failure_is_not_downgraded_to_fallback(): void {
		$this->ec_require_mysql_advisory_locks();

		$boundary                          = new EC_Test_FailingAdvisoryReleaseWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$boundary->fail_next_advisory_lock = true;
		$this->ec_swap_wpdb( $boundary );

		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( 'artist_membership_lock_failed', ec_get_artist_membership_failure()->get_error_code() );
		$this->assertSame( 'MySQL advisory lock query failed.', ec_get_artist_membership_failure()->get_error_data()['database_error'] );
	}

	public function test_mysql_lock_null_without_database_error_is_not_downgraded_to_fallback(): void {
		$this->ec_require_mysql_advisory_locks();

		$boundary = new EC_Test_FailingAdvisoryReleaseWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		// GET_LOCK returning an unexpected (non-'1', non-'0') result with no
		// database error is reported as an invalid result, never as busy.
		$boundary->injected_advisory_result = '';
		$this->ec_swap_wpdb( $boundary );

		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( 'artist_membership_lock_failed', ec_get_artist_membership_failure()->get_error_code() );
		$this->assertSame( 'MySQL GET_LOCK() returned an invalid result.', ec_get_artist_membership_failure()->get_error_data()['database_error'] );
	}

	public function test_sqlite_runtime_skips_mysql_queries_and_uses_fallback(): void {
		$this->ec_require_non_mysql_wpdb();

		$this->assertTrue( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( array( $this->profile_id ), $this->user_side_profiles( $this->user_id ) );
		$this->assertNull( ec_get_artist_membership_failure() );
	}

	public function test_fallback_storage_failure_is_actionable(): void {
		$this->ec_require_mysql_advisory_locks();

		$boundary                     = new EC_Test_SQLitePathWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$boundary->fail_option_insert = true;
		$this->ec_swap_wpdb( $boundary );

		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( 'artist_membership_lock_failed', ec_get_artist_membership_failure()->get_error_code() );
		$this->assertSame( 'Network lock option insert failed.', ec_get_artist_membership_failure()->get_error_data()['database_error'] );
	}

	public function test_fallback_stale_recovery_query_failure_is_actionable(): void {
		$this->ec_require_mysql_advisory_locks();

		$lock_name = sprintf( 'ec_artist_membership_%d_%d', $this->user_id, $this->profile_id );
		$option    = 'ec_artist_membership_lock_' . md5( $lock_name );
		switch_to_blog( get_main_site_id() );
		add_option( $option, array(
			'owner'   => 'stale-owner',
			'expires' => time() - 1,
		), '', false );
		restore_current_blog();

		$boundary                  = new EC_Test_SQLitePathWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$boundary->fail_next_query = true;
		$this->ec_swap_wpdb( $boundary );

		$this->assertFalse( ec_add_artist_membership( $this->user_id, $this->profile_id ) );
		$this->assertSame( 'artist_membership_lock_failed', ec_get_artist_membership_failure()->get_error_code() );
		$this->assertSame( 'Network lock query failed.', ec_get_artist_membership_failure()->get_error_data()['database_error'] );

		switch_to_blog( get_main_site_id() );
		$stored = get_option( $option );
		delete_option( $option );
		restore_current_blog();
		$this->assertSame( 'stale-owner', $stored['owner'] );
	}
}
