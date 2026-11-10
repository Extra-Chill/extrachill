<?php

require_once __DIR__ . '/support/base-test-case.php';
require_once dirname( __DIR__ ) . '/inc/core/ArtistTeamMembersMigration.php';

use ExtraChill\ArtistPlatform\ArtistTeamMembersMigration;
use ExtraChill\Users\Teams\TeamMembershipRepository;

final class ArtistTeamMembersMigrationTest extends EC_Artist_Platform_TestCase {
	public function test_duplicate_add_and_reactivation_preserve_one_row(): void {
		$user   = (int) self::factory()->user->create();
		$artist = $this->create_artist_profile( 'Team Artist' );
		$store  = new TeamMembershipRepository();
		$this->assertTrue( ec_add_artist_membership( $user, $artist ) );
		$this->assertTrue( ec_add_artist_membership( $user, $artist ) );
		$this->assertCount( 1, $store->select( 'artist', array( 'term' => $artist, 'user_id' => $user ), 'id ASC' ) );
		$this->assertTrue( ec_remove_artist_membership( $user, $artist ) );
		$this->assertFalse( ec_can_manage_artist( $user, $artist ) );
		$this->assertTrue( ec_add_artist_membership( $user, $artist ) );
		$row = $store->get( 'artist', $artist, $user );
		$this->assertSame( 3, $row['version'] );
		$this->assertTrue( $row['is_owner'] );
		$this->assertTrue( ec_can_manage_artist( $user, $artist ) );
	}

	public function test_team_types_are_isolated(): void {
		$user   = (int) self::factory()->user->create();
		$artist = $this->create_artist_profile( 'Isolated Artist' );
		$store  = new TeamMembershipRepository();
		foreach ( TeamMembershipRepository::TYPES as $type ) {
			$this->assertNotFalse( $store->insert( $type, $store->new_row( $type, $artist, $user, true, 0 ) ) );
		}
		$this->assertTrue( ec_remove_artist_membership( $user, $artist ) );
		$this->assertSame( 'active', $store->get( 'venue', $artist, $user )['status'] );
		$this->assertSame( 'active', $store->get( 'promoter', $artist, $user )['status'] );
		$this->assertSame( 'revoked', $store->get( 'artist', $artist, $user )['status'] );
	}

	public function test_copy_reports_one_direction_pairs_and_restores_legacy_access(): void {
		$user   = (int) self::factory()->user->create();
		$other  = (int) self::factory()->user->create();
		$artist = $this->create_artist_profile( 'Legacy Artist' );
		update_user_meta( $user, '_artist_profile_ids', array( $artist ) );
		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $artist, '_artist_member_ids', array( $user, $other ) );
		restore_current_blog();
		delete_site_option( ArtistTeamMembersMigration::COPIED_OPTION );
		$before = ArtistTeamMembersMigration::inspect();
		$this->assertGreaterThanOrEqual( 2, $before['missing'] );
		$this->assertContains( array( 'artist_id' => $artist, 'user_id' => $other, 'artist' => true ), $before['one_direction_pairs'] );
		$this->assertFalse( ec_can_manage_artist( $user, $artist ) );
		ArtistTeamMembersMigration::maybe_copy_legacy();
		$this->assertTrue( ec_can_manage_artist( $user, $artist ) );
		$this->assertTrue( ec_can_manage_artist( $other, $artist ) );
		$this->assertSame( array( $artist ), get_user_meta( $user, '_artist_profile_ids', true ) );
		$store = new TeamMembershipRepository();
		$this->assertSame( 0, $store->get( 'artist', $artist, $user )['created_by_user_id'] );
		$this->assertTrue( ec_remove_artist_membership( $user, $artist ) );
		ArtistTeamMembersMigration::maybe_copy_legacy();
		$this->assertFalse( ec_can_manage_artist( $user, $artist ) );
		// A later revocation is a decision, not a missing copy.
		$after = ArtistTeamMembersMigration::inspect();
		$this->assertSame( 0, $after['missing'] );
		$this->assertSame( 'complete', $after['status'] );
	}

	public function test_apply_requires_cli(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->markTestSkipped( 'CLI runtime.' );
		}
		$this->assertFalse( ArtistTeamMembersMigration::apply()['success'] );
	}
}
