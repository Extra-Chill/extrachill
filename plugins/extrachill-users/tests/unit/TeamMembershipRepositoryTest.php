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
			$this->assertSame( 445566, $membership[ $type . '_term_id' ] );
			$this->assertTrue( $membership['is_owner'] );
		}
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
