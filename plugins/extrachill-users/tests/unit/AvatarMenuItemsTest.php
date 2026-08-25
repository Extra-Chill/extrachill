<?php
/**
 * Tests for canonical profile links in the avatar menu.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify avatar-menu profile links use the canonical Community identity.
 */
class Test_Avatar_Menu_Items extends WP_UnitTestCase {
	/**
	 * Verify normalized public slugs are used instead of login identifiers.
	 *
	 * @dataProvider normalized_login_provider
	 *
	 * @param string $user_login        Login fixture.
	 * @param string $expected_nicename Expected public slug.
	 */
	public function test_profile_links_use_nicename_when_login_is_normalized( string $user_login, string $expected_nicename ): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => $user_login,
				'user_email' => $expected_nicename . '@example.com',
			)
		);
		$user    = get_userdata( $user_id );
		$items   = $this->index_items_by_id( extrachill_users_get_avatar_menu_items( $user_id ) );

		$this->assertSame( $expected_nicename, $user->user_nicename );
		$this->assertNotSame( $user->user_login, $user->user_nicename );
		$this->assertArrayHasKey( 'view_profile', $items );
		$this->assertArrayHasKey( 'edit_profile', $items );
		$this->assertSame(
			extrachill_get_user_community_profile_url( $user_id, $user->user_email ),
			$items['view_profile']['url']
		);
		$this->assertSame(
			trailingslashit( extrachill_get_user_community_profile_url( $user_id, $user->user_email ) ) . 'edit/',
			$items['edit_profile']['url']
		);
		$this->assertStringContainsString( '/u/' . $expected_nicename, $items['view_profile']['url'] );
		$this->assertStringNotContainsString( $user->user_login, $items['view_profile']['url'] );
		$this->assertStringNotContainsString( $user->user_login, $items['edit_profile']['url'] );
	}

	/**
	 * Provide WordPress-supported login values that normalize to different slugs.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function normalized_login_provider(): array {
		return array(
			'email-like login' => array( 'Fan.Name@example.com', 'fan-nameexample-com' ),
			'spaced login'     => array( 'Scene Fan', 'scene-fan' ),
		);
	}

	/**
	 * Verify unavailable public identities never fall back to login identifiers.
	 */
	public function test_profile_links_are_omitted_when_canonical_identity_is_unavailable(): void {
		global $wpdb;

		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'private.login@example.com',
				'user_email' => 'private-avatar-menu@example.com',
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Simulates a malformed legacy identity that the public resolver must reject.
			$wpdb->users,
			array( 'user_nicename' => '' ),
			array( 'ID' => $user_id )
		);
		clean_user_cache( $user_id );
		$this->setExpectedIncorrectUsage( 'WP_Object_Cache::add' );

		$items = $this->index_items_by_id( extrachill_users_get_avatar_menu_items( $user_id ) );

		$this->assertArrayNotHasKey( 'view_profile', $items );
		$this->assertArrayNotHasKey( 'edit_profile', $items );
		$this->assertSame( ec_get_site_url( 'community' ) . '/settings/', $items['settings']['url'] );
		foreach ( $items as $item ) {
			$this->assertStringNotContainsString( 'private.login@example.com', $item['url'] );
		}
	}

	/** Verify anonymous and identity-free account navigation. */
	public function test_anonymous_and_users_without_identities_preserve_artist_creation_discovery(): void {
		$this->assertSame( array(), extrachill_users_get_avatar_menu_items( 0 ) );

		$user_id = self::factory()->user->create();
		$items   = $this->index_items_by_id( extrachill_users_get_avatar_menu_items( $user_id ) );

		$this->assertArrayHasKey( 'view_profile', $items );
		$this->assertArrayHasKey( 'edit_profile', $items );
		$this->assertArrayHasKey( 'settings', $items );
		$this->assertArrayHasKey( 'logout', $items );
		if ( isset( $items['create_artist'] ) ) {
			$this->assertSame( ec_get_site_url( 'artist' ) . '/create-artist/', $items['create_artist']['url'] );
		}
		$this->assertArrayNotHasKey( 'manage_link_pages', $items );
	}

	/** Verify domain contributions cannot replace universal account actions. */
	public function test_universal_account_destinations_are_reserved(): void {
		$user_id = self::factory()->user->create();
		$filter  = static function ( $items ) {
			$items[] = array(
				'id'       => 'logout',
				'label'    => 'Not Logout',
				'url'      => 'https://example.com/not-logout/',
				'priority' => 1,
			);
			$items[] = array(
				'id'       => 'settings',
				'label'    => 'Not Settings',
				'url'      => 'https://example.com/not-settings/',
				'priority' => 1,
			);
			return $items;
		};
		add_filter( 'ec_avatar_menu_items', $filter );

		$items = $this->index_items_by_id( extrachill_users_get_avatar_menu_items( $user_id ) );
		remove_filter( 'ec_avatar_menu_items', $filter );

		$this->assertSame( 'Settings', $items['settings']['label'] );
		$this->assertSame( 'Log Out', $items['logout']['label'] );
		$this->assertTrue( $items['logout']['danger'] );
	}

	/** Verify shop discovery composes without duplicate IDs. */
	public function test_shop_contribution_is_preserved_without_duplication(): void {
		$user_id = self::factory()->user->create();
		$filter  = static function ( $items ) {
			$items[] = array(
				'id'       => 'manage_shop',
				'label'    => 'Manage Shop',
				'url'      => 'https://artist.extrachill.com/manage-shop/',
				'priority' => 50,
			);
			return $items;
		};
		add_filter( 'ec_avatar_menu_items', $filter );

		$items = extrachill_users_get_avatar_menu_items( $user_id );
		remove_filter( 'ec_avatar_menu_items', $filter );

		$this->assertCount( 1, array_filter( $items, static fn( $item ) => 'manage_shop' === $item['id'] ) );
		$this->assertSame( 'Manage Shop', $this->index_items_by_id( $items )['manage_shop']['label'] );
	}

	/**
	 * Index menu items for direct assertions.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	private function index_items_by_id( array $items ): array {
		$indexed = array();

		foreach ( $items as $item ) {
			$indexed[ $item['id'] ] = $item;
		}

		return $indexed;
	}
}
