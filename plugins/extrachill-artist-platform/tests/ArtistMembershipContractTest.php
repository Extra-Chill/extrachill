<?php

use PHPUnit\Framework\TestCase;

final class ArtistMembershipContractTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['ec_artist_membership_locks'] );
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 1,
			'blog_stack'      => array(),
			'blogs'           => array(
				4 => array(
					'posts' => array(
						20 => (object) array(
							'ID'          => 20,
							'post_type'   => 'artist_profile',
							'post_status' => 'publish',
						),
						21 => (object) array(
							'ID'          => 21,
							'post_type'   => 'artist_profile',
							'post_status' => 'private',
						),
						22 => (object) array(
							'ID'          => 22,
							'post_type'   => 'post',
							'post_status' => 'publish',
						),
					),
				),
			),
		);
	}

	public function test_add_and_remove_are_idempotent_and_keep_both_sides_consistent(): void {
		$this->assertTrue( ec_add_artist_membership( 7, 20 ) );
		$this->assertTrue( ec_add_artist_membership( 7, 20 ) );
		$this->assertSame( array( 20 ), get_user_meta( 7, '_artist_profile_ids', true ) );

		switch_to_blog( 4 );
		$this->assertSame( array( 7 ), get_post_meta( 20, '_artist_member_ids', true ) );
		restore_current_blog();

		$this->assertTrue( ec_remove_artist_membership( 7, 20 ) );
		$this->assertTrue( ec_remove_artist_membership( 7, 20 ) );
		$this->assertSame( array(), get_user_meta( 7, '_artist_profile_ids', true ) );
	}

	public function test_add_rejects_unpublished_wrong_type_and_deleted_targets(): void {
		$this->assertFalse( ec_add_artist_membership( 7, 21 ) );
		$this->assertFalse( ec_add_artist_membership( 7, 22 ) );
		$this->assertFalse( ec_add_artist_membership( 7, 999 ) );
		$this->assertSame( '', get_user_meta( 7, '_artist_profile_ids', true ) );
	}

	public function test_partial_add_failure_is_truthful_and_retry_reconciles_it(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array();
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array();
		$GLOBALS['ec_test']['fail_user_meta_update'] = true;
		$this->assertFalse( ec_add_artist_membership( 7, 20 ) );
		$this->assertSame( array(), ec_get_linked_members( 20 ) );
		switch_to_blog( 4 );
		$this->assertSame( array(), get_post_meta( 20, '_artist_member_ids', true ) );
		restore_current_blog();

		$this->assertTrue( ec_add_artist_membership( 7, 20 ) );
		$this->assertSame( array( 7 ), array_map( static fn( $user ) => $user->ID, ec_get_linked_members( 20 ) ) );
	}

	public function test_partial_remove_failure_is_truthful_and_retry_reconciles_it(): void {
		$this->assertTrue( ec_add_artist_membership( 7, 20 ) );
		$GLOBALS['ec_test']['fail_post_meta_update'] = true;
		$this->assertFalse( ec_remove_artist_membership( 7, 20 ) );
		$this->assertSame( array(), ec_get_linked_members( 20 ) );

		$this->assertTrue( ec_remove_artist_membership( 7, 20 ) );
		switch_to_blog( 4 );
		$this->assertSame( array(), get_post_meta( 20, '_artist_member_ids', true ) );
		restore_current_blog();
	}

	public function test_add_retries_compare_and_swap_conflicts_without_losing_concurrent_members(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array( 9 );
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array( 30 );
		$GLOBALS['ec_test']['post_meta_conflict']                              = array( 9, 10 );
		$GLOBALS['ec_test']['user_meta_conflict']                              = array( 30, 31 );

		$this->assertTrue( ec_add_artist_membership( 7, 20 ) );
		$this->assertSame( array( 30, 31, 20 ), get_user_meta( 7, '_artist_profile_ids', true ) );
		switch_to_blog( 4 );
		$this->assertSame( array( 9, 10, 7 ), get_post_meta( 20, '_artist_member_ids', true ) );
		restore_current_blog();
	}

	public function test_remove_retries_compare_and_swap_conflicts_without_losing_concurrent_members(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array( 7, 8 );
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array( 20, 30 );
		$GLOBALS['ec_test']['post_meta_conflict']                              = array( 7, 8, 9 );
		$GLOBALS['ec_test']['user_meta_conflict']                              = array( 20, 30, 31 );

		$this->assertTrue( ec_remove_artist_membership( 7, 20 ) );
		$this->assertSame( array( 30, 31 ), get_user_meta( 7, '_artist_profile_ids', true ) );
		switch_to_blog( 4 );
		$this->assertSame( array( 8, 9 ), get_post_meta( 20, '_artist_member_ids', true ) );
		restore_current_blog();
	}

	public function test_opposing_remove_cannot_interleave_inside_add_relationship_lock(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array();
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array();
		$GLOBALS['ec_test']['after_post_meta_update'] = static function () {
			$GLOBALS['ec_test']['nested_remove_result'] = ec_remove_artist_membership( 7, 20 );
		};

		$this->assertTrue( ec_add_artist_membership( 7, 20 ) );
		$this->assertFalse( $GLOBALS['ec_test']['nested_remove_result'] );
		$this->assertSame( 1, $GLOBALS['ec_test']['db_lock_get_calls']['ec_artist_membership_7_20'] );
		$this->assertSame( array( 20 ), get_user_meta( 7, '_artist_profile_ids', true ) );
		switch_to_blog( 4 );
		$this->assertSame( array( 7 ), get_post_meta( 20, '_artist_member_ids', true ) );
		restore_current_blog();
	}

	public function test_opposing_add_cannot_interleave_inside_remove_relationship_lock(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array( 7 );
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array( 20 );
		$GLOBALS['ec_test']['after_user_meta_update'] = static function () {
			$GLOBALS['ec_test']['nested_add_result'] = ec_add_artist_membership( 7, 20 );
		};

		$this->assertTrue( ec_remove_artist_membership( 7, 20 ) );
		$this->assertFalse( $GLOBALS['ec_test']['nested_add_result'] );
		$this->assertSame( 1, $GLOBALS['ec_test']['db_lock_get_calls']['ec_artist_membership_7_20'] );
		$this->assertSame( array(), get_user_meta( 7, '_artist_profile_ids', true ) );
		switch_to_blog( 4 );
		$this->assertSame( array(), get_post_meta( 20, '_artist_member_ids', true ) );
		restore_current_blog();
	}

	public function test_add_reports_actionable_error_when_compensating_rollback_fails(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array();
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array();
		$GLOBALS['ec_test']['fail_user_meta_update']                           = true;
		$GLOBALS['ec_test']['fail_post_meta_update_on_call']                   = 2;

		$this->assertFalse( ec_add_artist_membership( 7, 20 ) );
		$this->assertSame( 'artist_membership_rollback_failed', ec_get_artist_membership_failure()->get_error_code() );

		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array();
		$GLOBALS['ec_test']['post_meta_update_calls']        = 0;
		$GLOBALS['ec_test']['fail_user_meta_update']         = true;
		$GLOBALS['ec_test']['fail_post_meta_update_on_call'] = 2;
		$GLOBALS['ec_test']['capabilities']['manage_network_options'] = true;
		$result = extrachill_artist_platform_ability_admin_link_artist_relationship( array( 'user_id' => 7, 'artist_id' => 20 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_membership_rollback_failed', $result->get_error_code() );
	}

	public function test_remove_resolves_artist_site_before_mutating_user_record(): void {
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids'] = array( 20 );
		$GLOBALS['ec_test']['artist_blog_unavailable']              = true;

		$this->assertFalse( ec_remove_artist_membership( 7, 20 ) );
		$this->assertSame( 'artist_site_unavailable', ec_get_artist_membership_failure()->get_error_code() );
		$this->assertSame( array( 20 ), get_user_meta( 7, '_artist_profile_ids', true ) );
	}

	public function test_pending_invitation_creation_retries_cas_conflict(): void {
		$existing = array( 'id' => 'existing', 'email' => 'one@example.com' );
		$concurrent = array( 'id' => 'concurrent', 'email' => 'two@example.com' );
		$GLOBALS['ec_test']['blogs'][1]['post_meta'][20]['_pending_invitations'] = array( $existing );
		$GLOBALS['ec_test']['post_meta_conflict'] = array( $existing, $concurrent );

		$result = ec_add_pending_invitation( 20, 'Three', 'three@example.com' );
		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'one@example.com', 'two@example.com', 'three@example.com' ),
			array_column( get_post_meta( 20, '_pending_invitations', true ), 'email' )
		);
	}

	public function test_pending_invitation_acceptance_retries_cleanup_conflict(): void {
		$accepted   = array( 'id' => 'invite-1', 'email' => 'user-7@example.com' );
		$concurrent = array( 'id' => 'invite-2', 'email' => 'other@example.com' );
		$GLOBALS['ec_test']['blogs'][1]['post_meta'][20]['_pending_invitations'] = array( $accepted );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array( 7 );
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array( 20 );
		$GLOBALS['ec_test']['post_meta_conflict'] = array( $accepted, $concurrent );

		$this->assertTrue( ec_accept_artist_membership_invitation( 7, 20, 'invite-1' ) );
		$this->assertSame( array( $concurrent ), get_post_meta( 20, '_pending_invitations', true ) );
	}

	public function test_invitation_failure_rolls_back_and_retains_retry_token(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array();
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array();
		$GLOBALS['ec_test']['blogs'][1]['post_meta'][20]['_pending_invitations'] = array( array( 'id' => 'invite-1' ) );
		$GLOBALS['ec_test']['fail_user_meta_update'] = true;

		$result = ec_accept_artist_membership_invitation( 7, 20, 'invite-1' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_membership_update_failed', $result->get_error_code() );
		$this->assertSame( array( array( 'id' => 'invite-1' ) ), get_post_meta( 20, '_pending_invitations', true ) );
		$this->assertSame( array(), get_user_meta( 7, '_artist_profile_ids', true ) );
	}

	public function test_invitation_cleanup_failure_is_truthful_and_retryable(): void {
		$GLOBALS['ec_test']['blogs'][1]['post_meta'][20]['_pending_invitations'] = array( array( 'id' => 'invite-1' ) );
		$GLOBALS['ec_test']['fail_post_meta_update'] = true;

		$result = ec_accept_artist_membership_invitation( 7, 20, 'invite-1' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invitation_cleanup_failed', $result->get_error_code() );
		$this->assertSame( array( 20 ), get_user_meta( 7, '_artist_profile_ids', true ) );
		$this->assertSame( array( array( 'id' => 'invite-1' ) ), get_post_meta( 20, '_pending_invitations', true ) );

		$GLOBALS['ec_artist_membership_locks']['ec_artist_membership_7_20'] = true;
		$busy_result = ec_accept_artist_membership_invitation( 7, 20, 'invite-1' );
		$this->assertInstanceOf( WP_Error::class, $busy_result );
		$this->assertSame( 'artist_membership_busy', $busy_result->get_error_code() );
		$this->assertSame( array( 20 ), get_user_meta( 7, '_artist_profile_ids', true ) );
		switch_to_blog( 4 );
		$this->assertSame( array( 7 ), get_post_meta( 20, '_artist_member_ids', true ) );
		restore_current_blog();
		$this->assertSame( array( array( 'id' => 'invite-1' ) ), get_post_meta( 20, '_pending_invitations', true ) );
		unset( $GLOBALS['ec_artist_membership_locks'] );

		$this->assertTrue( ec_accept_artist_membership_invitation( 7, 20, 'invite-1' ) );
		$this->assertSame( array(), get_post_meta( 20, '_pending_invitations', true ) );
	}

	public function test_invitation_busy_failure_never_removes_preexisting_membership(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array( 7 );
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array( 20 );
		$GLOBALS['ec_test']['blogs'][1]['post_meta'][20]['_pending_invitations'] = array( array( 'id' => 'invite-1' ) );
		$GLOBALS['ec_artist_membership_locks']['ec_artist_membership_7_20'] = true;

		$result = ec_accept_artist_membership_invitation( 7, 20, 'invite-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_membership_busy', $result->get_error_code() );
		$this->assertSame( array( 20 ), get_user_meta( 7, '_artist_profile_ids', true ) );
		switch_to_blog( 4 );
		$this->assertSame( array( 7 ), get_post_meta( 20, '_artist_member_ids', true ) );
		restore_current_blog();
	}

	public function test_invitation_preserves_preexisting_artist_side_after_user_write_failure(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array( 7 );
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array();
		$GLOBALS['ec_test']['fail_user_meta_update'] = true;

		$result = ec_accept_artist_membership_invitation( 7, 20, 'invite-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_membership_update_failed', $result->get_error_code() );
		switch_to_blog( 4 );
		$this->assertSame( array( 7 ), get_post_meta( 20, '_artist_member_ids', true ) );
		restore_current_blog();
	}

	public function test_invitation_compensates_only_partial_state_created_by_this_attempt(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array();
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array();
		$GLOBALS['ec_test']['fail_user_meta_update']                           = true;
		$GLOBALS['ec_test']['fail_post_meta_update_on_call']                   = 2;

		$result = ec_accept_artist_membership_invitation( 7, 20, 'invite-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_membership_retry_required', $result->get_error_code() );
		$this->assertSame( array(), get_user_meta( 7, '_artist_profile_ids', true ) );
		switch_to_blog( 4 );
		$this->assertSame( array(), get_post_meta( 20, '_artist_member_ids', true ) );
		restore_current_blog();
	}

	public function test_invitation_reports_manual_repair_when_created_partial_state_cannot_be_compensated(): void {
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array();
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array();
		$GLOBALS['ec_test']['fail_user_meta_update']                           = true;
		$GLOBALS['ec_test']['fail_post_meta_update_on_calls']                  = array( 2, 3 );

		$result = ec_accept_artist_membership_invitation( 7, 20, 'invite-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_invitation_rollback_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
	}

	public function test_profile_save_propagates_member_removal_failure(): void {
		$GLOBALS['ec_test']['current_user_id'] = 99;
		$GLOBALS['ec_test']['blogs'][1]['posts'][20] = (object) array(
			'ID'          => 20,
			'post_type'   => 'artist_profile',
			'post_status' => 'publish',
		);
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array( 7 );
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array( 20 );
		$GLOBALS['ec_test']['fail_post_meta_update']                           = true;

		$result = ec_handle_artist_profile_save( 20, array( 'remove_member_ids' => '7' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_membership_partial_remove', $result->get_error_code() );
	}

	public function test_platform_artist_creation_rolls_back_when_membership_fails(): void {
		$GLOBALS['ec_test']['blogs'][4]['posts'] = array();
		$GLOBALS['ec_test']['fail_post_meta_add'] = true;

		$this->assertFalse( ec_provision_platform_artist() );
		$this->assertSame( array( 1 ), $GLOBALS['ec_test']['deleted_posts'] );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertArrayNotHasKey( 'ec_platform_artist_id', $GLOBALS['ec_test']['site_options'] ?? array() );
	}

	public function test_provisioning_failure_does_not_set_success_throttle(): void {
		$GLOBALS['ec_test']['blogs'][4]['posts'] = array();
		$GLOBALS['ec_test']['capabilities']['manage_options'] = true;
		$GLOBALS['ec_test']['fail_post_meta_add'] = true;

		ec_maybe_provision_platform_artist();

		$this->assertArrayNotHasKey( 'ec_platform_artist_provisioned', $GLOBALS['ec_test']['site_transients'] ?? array() );
	}

	public function test_platform_provisioning_reports_failed_profile_rollback(): void {
		$GLOBALS['ec_test']['blogs'][4]['posts'] = array();
		$GLOBALS['ec_test']['fail_post_meta_add'] = true;
		$GLOBALS['ec_test']['fail_post_delete']   = true;

		$this->assertFalse( ec_provision_platform_artist() );
		$this->assertArrayHasKey( 1, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertArrayNotHasKey( 'ec_platform_artist_id', $GLOBALS['ec_test']['site_options'] ?? array() );
	}

	public function test_artist_invitation_ability_validates_and_applies_on_owner_site(): void {
		$GLOBALS['ec_test']['current_blog_id'] = 4;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_pending_invitations'] = array(
			array(
				'id'     => 'invite-1',
				'email'  => 'user-7@example.com',
				'token'  => 'secret-token',
				'status' => EC_INVITE_STATUS_NEW_USER,
			),
		);
		$GLOBALS['ec_test']['user_emails'][7] = 'user-7@example.com';

		$input = array( 'artist_id' => 20, 'email' => 'user-7@example.com', 'token' => 'secret-token' );
		$this->assertSame( array( 'status' => 'valid', 'artist_id' => 20 ), extrachill_artist_platform_ability_artist_invitation( $input ) );

		$result = extrachill_artist_platform_ability_artist_invitation( array_merge( $input, array( 'user_id' => 7 ) ) );
		$this->assertSame( array( 'status' => 'applied', 'artist_id' => 20 ), $result );
		$this->assertSame( array(), get_post_meta( 20, '_pending_invitations', true ) );
	}

	public function test_reverse_roster_requires_reciprocal_membership_and_valid_artist(): void {
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids'] = array( 20 );
		$GLOBALS['ec_test']['user_meta'][8]['_artist_profile_ids'] = array();
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array( 7, 8 );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][21]['_artist_member_ids'] = array( 7 );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][22]['_artist_member_ids'] = array( 7 );

		$this->assertSame( array( 7 ), array_map( static fn( $user ) => $user->ID, ec_get_linked_members( 20 ) ) );
		$this->assertSame( array(), ec_get_linked_members( 21 ) );
		$this->assertSame( array(), ec_get_linked_members( 22 ) );
		$this->assertSame( array(), ec_get_linked_members( 999 ) );
	}

	public function test_admin_handlers_report_partial_write_failures(): void {
		$GLOBALS['ec_test']['capabilities']['manage_network_options'] = true;
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array();
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array();
		$GLOBALS['ec_test']['fail_user_meta_update']                  = true;
		$result = extrachill_artist_platform_ability_admin_link_artist_relationship( array( 'user_id' => 7, 'artist_id' => 20 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_membership_update_failed', $result->get_error_code() );

		$GLOBALS['ec_test']['blogs'][4]['post_meta'][20]['_artist_member_ids'] = array( 7 );
		$GLOBALS['ec_test']['user_meta'][7]['_artist_profile_ids']             = array( 20 );
		$GLOBALS['ec_test']['fail_post_meta_update']                           = true;
		$result = extrachill_artist_platform_ability_admin_unlink_artist_relationship( array( 'user_id' => 7, 'artist_id' => 20 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_membership_partial_remove', $result->get_error_code() );
	}
}
