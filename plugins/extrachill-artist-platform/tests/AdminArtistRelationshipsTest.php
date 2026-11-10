<?php

require_once __DIR__ . '/support/base-test-case.php';

final class AdminArtistRelationshipsTest extends EC_Artist_Platform_TestCase {
	private $admin_id;
	private $profile_id;
	private $user_id;

	protected function setUp(): void {
		parent::setUp();

		$this->admin_id   = $this->create_admin_user( 'superadmin' );
		$this->user_id    = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->profile_id = $this->create_artist_profile( 'Related Artist' );
		wp_set_current_user( $this->admin_id );
	}

	public function test_list_preserves_items_envelope_and_filters_input(): void {
		$result = extrachill_artist_platform_ability_admin_list_artist_relationships(
			array(
				'view'   => 'artists',
				'search' => '  Related  ',
			)
		);

		$this->assertArrayHasKey( 'items', $result );
		$this->assertSame(
			array( 'id' => $this->profile_id ),
			array(
				'id' => $result['items'][0]['id'] ?? 0,
			)
		);
	}

	public function test_link_uses_canonical_membership_mutator(): void {
		$result = extrachill_artist_platform_ability_admin_link_artist_relationship(
			array(
				'user_id'   => $this->user_id,
				'artist_id' => $this->profile_id,
			)
		);

		$this->assertSame( array( 'success' => true ), $result );
		$this->assertSame( array( $this->user_id ), $this->artist_team_member_ids( $this->profile_id ) );
		$this->assertSame( array( $this->profile_id ), $this->user_artist_team_ids( $this->user_id ) );
	}

	public function test_link_rejects_missing_user(): void {
		$missing_user_id = $this->user_id + 100;

		$result = extrachill_artist_platform_ability_admin_link_artist_relationship(
			array(
				'user_id'   => $missing_user_id,
				'artist_id' => $this->profile_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_user', $result->get_error_code() );
	}

	public function test_unlink_and_cleanup_use_canonical_membership_mutator(): void {
		$unlink_user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$unlink_profile = $this->create_artist_profile( 'Unlink Artist' );
		$this->create_artist_membership( $unlink_user_id, $unlink_profile );

		$this->assertSame(
			array( 'success' => true ),
			extrachill_artist_platform_ability_admin_unlink_artist_relationship( array(
				'user_id'   => $unlink_user_id,
				'artist_id' => $unlink_profile,
			) )
		);

		$cleanup_user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->create_artist_membership( $cleanup_user_id, $this->profile_id );

		extrachill_artist_platform_ability_admin_cleanup_artist_relationships( array(
			'user_id'   => $cleanup_user_id,
			'artist_id' => $this->profile_id,
		) );
		$this->assertSame( array(), $this->user_artist_team_ids( $cleanup_user_id ) );
		$this->assertSame( array(), $this->user_artist_team_ids( $unlink_user_id ) );
	}

	public function test_admin_mutations_fail_closed_when_handlers_are_called_directly(): void {
		wp_set_current_user( $this->user_id );

		$handlers = array(
			'extrachill_artist_platform_ability_admin_link_artist_relationship',
			'extrachill_artist_platform_ability_admin_unlink_artist_relationship',
			'extrachill_artist_platform_ability_admin_cleanup_artist_relationships',
		);

		foreach ( $handlers as $handler ) {
			$result = $handler( array(
				'user_id'   => $this->user_id,
				'artist_id' => $this->profile_id,
			) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'admin_access_denied', $result->get_error_code() );
		}

		$this->assertSame( array(), $this->user_artist_team_ids( $this->user_id ) );
	}

	public function test_orphan_list_preserves_orphans_envelope(): void {
		$result = extrachill_artist_platform_ability_admin_list_orphan_artist_relationships( array() );

		$this->assertArrayHasKey( 'orphans', $result );
		$this->assertIsArray( $result['orphans'] );
	}
}
