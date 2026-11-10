<?php
/** Network membership storage tests. @package ExtraChill\Users */

use ExtraChill\Users\Teams\TeamMembershipRepository;

class Test_Team_Membership_Repository extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		extrachill_users_install_teams_table();
	}

	public function test_unique_key_and_type_isolation(): void {
		global $wpdb;
		$repository = new TeamMembershipRepository();
		$user = self::factory()->user->create();
		$this->assertSame( $wpdb->base_prefix . 'ec_' . 'team_members', TeamMembershipRepository::table() );
		foreach ( TeamMembershipRepository::TYPES as $type ) {
			$row = $repository->new_row( $type, 445566, $user, true, $user );
			$this->assertSame( 1, $repository->insert( $type, $row ) );
			$previous = $wpdb->suppress_errors();
			$this->assertFalse( $repository->insert( $type, $row ) );
			$wpdb->suppress_errors( $previous );
			$membership = $repository->get( $type, 445566, $user );
			$this->assertSame( 445566, $membership[ 'artist' === $type ? 'artist_id' : $type . '_term_id' ] );
			$this->assertTrue( $membership['is_owner'] );
		}
	}

	public function test_artist_rows_preserve_the_typed_shape_and_revocation_audit(): void {
		$repository = new TeamMembershipRepository();
		$user       = self::factory()->user->create();
		$row        = $repository->new_row( 'artist', 445566, $user, true, $user );
		$this->assertArrayHasKey( 'artist_id', $row );
		$this->assertArrayNotHasKey( 'artist_term_id', $row );
		$this->assertSame( 1, $repository->insert( 'artist', $row ) );
		$this->assertSame( 1, $repository->mutate( 'artist', 445566, $user, 1, null, true, $user ) );
		$membership = $repository->get( 'artist', 445566, $user );
		$this->assertSame( 'revoked', $membership['status'] );
		$this->assertSame( 2, $membership['version'] );
		$this->assertSame( $user, $membership['revoked_by_user_id'] );
		$this->assertNotNull( $membership['revoked_at'] );
	}

	public function test_artist_corruption_errors_use_artist_codes_and_messages(): void {
		$repository = new TeamMembershipRepository();
		$error      = $repository->hydrate( 'artist', array( 'status' => 'invited' ) );
		$this->assertSame( 'artist_membership_corrupt_status', $error->get_error_code() );
		$this->assertStringContainsString( 'artist', $error->get_error_message() );
		$error = $repository->hydrate( 'artist', array( 'status' => 'active', 'is_owner' => 'invalid' ) );
		$this->assertSame( 'artist_membership_corrupt_owner', $error->get_error_code() );
		$this->assertStringContainsString( 'artist', $error->get_error_message() );
	}

	public function test_lock_queries_join_the_callers_transaction(): void {
		global $wpdb;
		$repository = new TeamMembershipRepository();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$repository->lock_team( 'venue', 445566 );
			$this->assertStringContainsString( 'team_id = 445566', $wpdb->last_query );
			$this->assertStringEndsWith( 'FOR UPDATE', $wpdb->last_query );
			$repository->lock_member( 'promoter', 445566, 123 );
			$this->assertStringContainsString( "team_type = 'promoter'", $wpdb->last_query );
			$this->assertStringEndsWith( 'FOR UPDATE', $wpdb->last_query );
			$repository->lock_user( 123 );
			$this->assertStringContainsString( 'team_id AS team_term_id', $wpdb->last_query );
			$this->assertStringEndsWith( 'FOR UPDATE', $wpdb->last_query );
		} finally {
			$wpdb->query( 'ROLLBACK' );
		}
	}
}
