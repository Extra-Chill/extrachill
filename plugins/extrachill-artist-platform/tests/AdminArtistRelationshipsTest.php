<?php

use PHPUnit\Framework\TestCase;

final class AdminArtistRelationshipsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
			'blogs'           => array(
				4 => array(
					'posts' => array(
						19 => (object) array(
							'ID'          => 19,
							'post_type'   => 'artist_profile',
							'post_status' => 'publish',
						),
					),
				),
			),
		);
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
		$GLOBALS['ec_test']['capabilities']['manage_network_options'] = true;

		$result = extrachill_artist_platform_ability_admin_link_artist_relationship(
			array( 'user_id' => 7, 'artist_id' => 19 )
		);

		$this->assertSame( array( 19 ), get_user_meta( 7, '_artist_profile_ids', true ) );
		$this->assertSame( array( 7 ), get_post_meta( 19, '_artist_member_ids', true ) );
		$this->assertSame( array( 'success' => true ), $result );
	}

	public function test_link_rejects_missing_user(): void {
		$GLOBALS['ec_test']['capabilities']['manage_network_options'] = true;
		$GLOBALS['ec_test']['missing_user']                            = true;

		$result = extrachill_artist_platform_ability_admin_link_artist_relationship(
			array( 'user_id' => 7, 'artist_id' => 19 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_user', $result->get_error_code() );
	}

	public function test_unlink_and_cleanup_use_canonical_membership_mutator(): void {
		$GLOBALS['ec_test']['capabilities']['manage_network_options'] = true;

		$this->assertSame(
			array( 'success' => true ),
			extrachill_artist_platform_ability_admin_unlink_artist_relationship( array( 'user_id' => 4, 'artist_id' => 8 ) )
		);
		$GLOBALS['ec_test']['user_meta'][5]['_artist_profile_ids'] = array( 9 );
		extrachill_artist_platform_ability_admin_cleanup_artist_relationships( array( 'user_id' => 5, 'artist_id' => 9 ) );
		$this->assertSame( array(), get_user_meta( 5, '_artist_profile_ids', true ) );
	}

	public function test_admin_mutations_fail_closed_when_handlers_are_called_directly(): void {
		$handlers = array(
			'extrachill_artist_platform_ability_admin_link_artist_relationship',
			'extrachill_artist_platform_ability_admin_unlink_artist_relationship',
			'extrachill_artist_platform_ability_admin_cleanup_artist_relationships',
		);

		foreach ( $handlers as $handler ) {
			$result = $handler( array( 'user_id' => 7, 'artist_id' => 19 ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'admin_access_denied', $result->get_error_code() );
		}

		$this->assertArrayNotHasKey( 'user_meta', $GLOBALS['ec_test'] );
	}

	public function test_orphan_list_preserves_orphans_envelope(): void {
		$GLOBALS['ec_test']['orphan_result'] = array( array( 'invalid_artist_id' => 44 ) );

		$this->assertSame(
			array( 'orphans' => array( array( 'invalid_artist_id' => 44 ) ) ),
			extrachill_artist_platform_ability_admin_list_orphan_artist_relationships( array() )
		);
	}
}
