<?php
/**
 * The artist-site secondary header leaves the shop link to the avatar menu.
 *
 * @package ExtraChillArtistPlatform
 */

require_once __DIR__ . '/support/base-test-case.php';

final class SecondaryHeaderNavTest extends EC_Artist_Platform_TestCase {
	public function test_secondary_header_has_no_shop_link(): void {
		$user_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$artist_id = $this->create_artist_profile( 'Nav Band' );
		$this->create_artist_membership( $user_id, $artist_id );
		wp_set_current_user( $user_id );
		if ( function_exists( 'ec_can_manage_shop' ) ) {
			$this->assertTrue( ec_can_manage_shop( $user_id ), 'Precondition: the user can manage a shop, so the old code would add the link.' );
		}

		$items = ec_artist_platform_secondary_header_items( array() );

		$this->assertNotEmpty( $items, 'Artist members still get their artist links.' );
		$shop_url = function_exists( 'ec_get_shop_manager_url' ) ? ec_get_shop_manager_url() : '';
		foreach ( $items as $item ) {
			$this->assertNotSame( $shop_url, $item['url'] );
			$this->assertNotContains( $item['label'], array( 'Manage Shop', 'Create Shop' ) );
		}
	}
}
