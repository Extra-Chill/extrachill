<?php

use PHPUnit\Framework\TestCase;

final class AdminArtistRelationshipsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array();
	}

	public function test_list_preserves_items_envelope_and_filters_input(): void {
		$GLOBALS['ec_test']['list_result'] = array( array( 'id' => 12 ) );

		$result = extrachill_artist_platform_ability_admin_list_artist_relationships(
			array( 'view' => 'artists', 'search' => '  Band  ' )
		);

		$this->assertSame( array( 'artists', 'Band' ), $GLOBALS['ec_test']['list'] );
		$this->assertSame( array( 'items' => array( array( 'id' => 12 ) ) ), $result );
	}

	public function test_link_uses_canonical_membership_mutator(): void {
		$result = extrachill_artist_platform_ability_admin_link_artist_relationship(
			array( 'user_id' => 7, 'artist_id' => 19 )
		);

		$this->assertSame( array( 7, 19 ), $GLOBALS['ec_test']['added'] );
		$this->assertSame( array( 'success' => true ), $result );
	}

	public function test_link_rejects_missing_user(): void {
		$GLOBALS['ec_test']['missing_user'] = true;

		$result = extrachill_artist_platform_ability_admin_link_artist_relationship(
			array( 'user_id' => 7, 'artist_id' => 19 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_user', $result->get_error_code() );
	}

	public function test_unlink_and_cleanup_use_canonical_membership_mutator(): void {
		$this->assertSame(
			array( 'success' => true ),
			extrachill_artist_platform_ability_admin_unlink_artist_relationship( array( 'user_id' => 4, 'artist_id' => 8 ) )
		);
		$this->assertSame( array( 4, 8 ), $GLOBALS['ec_test']['removed'] );

		extrachill_artist_platform_ability_admin_cleanup_artist_relationships( array( 'user_id' => 5, 'artist_id' => 9 ) );
		$this->assertSame( array( 5, 9 ), $GLOBALS['ec_test']['removed'] );
	}

	public function test_orphan_list_preserves_orphans_envelope(): void {
		$GLOBALS['ec_test']['orphan_result'] = array( array( 'invalid_artist_id' => 44 ) );

		$this->assertSame(
			array( 'orphans' => array( array( 'invalid_artist_id' => 44 ) ) ),
			extrachill_artist_platform_ability_admin_list_orphan_artist_relationships( array() )
		);
	}
}
