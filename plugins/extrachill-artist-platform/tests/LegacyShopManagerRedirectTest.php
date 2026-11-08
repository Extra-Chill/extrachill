<?php
/**
 * The retired artist-site /manage-shop/ sends people to the Shop site manager.
 *
 * @package ExtraChillArtistPlatform
 */

require_once __DIR__ . '/support/base-test-case.php';

final class LegacyShopManagerRedirectTest extends EC_Artist_Platform_TestCase {
	private function manager_url(): string {
		return trailingslashit( (string) ec_get_site_url( 'shop' ) ) . 'manage/';
	}

	public function test_member_lands_on_their_artist_shop(): void {
		$user_id   = self::factory()->user->create();
		$artist_id = $this->create_artist_profile( 'Redirect Band' );
		$this->create_artist_membership( $user_id, $artist_id );

		$this->assertSame(
			add_query_arg( 'seller', 'artist:' . $artist_id, $this->manager_url() ),
			ec_artist_platform_legacy_shop_manager_url( $user_id )
		);
	}

	public function test_user_without_artists_lands_on_the_plain_manager(): void {
		$this->assertSame( $this->manager_url(), ec_artist_platform_legacy_shop_manager_url( self::factory()->user->create() ) );
		$this->assertSame( $this->manager_url(), ec_artist_platform_legacy_shop_manager_url( 0 ) );
	}

	public function test_stripe_refresh_survives_the_redirect(): void {
		$this->assertSame(
			add_query_arg( 'stripe_refresh', '1', $this->manager_url() ),
			ec_artist_platform_legacy_shop_manager_url( 0, array( 'stripe_refresh' => '1' ) )
		);
	}

	public function test_redirect_runs_before_template_rendering(): void {
		$this->assertSame( 5, has_action( 'template_redirect', 'ec_artist_platform_redirect_legacy_shop_manager' ) );
	}

	public function test_artist_site_no_longer_provisions_a_shop_page(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			extrachill_artist_platform_create_pages();
			$this->assertNull( get_page_by_path( 'manage-shop' ) );
			$this->assertFalse( WP_Block_Type_Registry::get_instance()->is_registered( 'extrachill/artist-shop-manager' ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_old_block_source_is_gone(): void {
		$root = dirname( __DIR__ );
		$this->assertDirectoryDoesNotExist( $root . '/src/blocks/artist-shop-manager' );
		foreach ( array( 'webpack.config.js', 'homeboy.json', 'extrachill-artist-platform.php', 'src/blocks/shared/api/client.js' ) as $file ) {
			$source = (string) file_get_contents( $root . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.
			$this->assertStringNotContainsString( 'artist-shop-manager', $source, $file );
			$this->assertStringNotContainsString( 'ecArtistShopManagerConfig', $source, $file );
		}
	}
}
