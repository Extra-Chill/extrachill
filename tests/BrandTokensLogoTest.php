<?php
/**
 * Tests for the OG card logo brand tokens (issues #856, #348).
 *
 * Covers `inc/og-cards/brand-tokens.php`: `provide_tokens()` setting a
 * `logo_path` token from the main site's media library, and
 * `main_site_upload_path()` resolving that path correctly regardless of
 * which site in the network is currently active — the case that matters
 * for this file, since OG cards for events.extrachill.com (blog 7)
 * render while that site, not the main site, is current.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

use function ExtraChillNetwork\OgCards\inverse_logo_path;
use function ExtraChillNetwork\OgCards\main_site_upload_path;
use function ExtraChillNetwork\OgCards\provide_tokens;

/**
 * @group og-cards
 */
class BrandTokensLogoTest extends WP_UnitTestCase {

	/**
	 * Absolute path of a logo asset seeded by
	 * test_provide_tokens_sets_logo_path_when_the_asset_exists(), cleaned
	 * up here so the fixture never leaks into other tests or runs.
	 */
	private ?string $seeded_logo_path = null;

	public function tear_down() {
		if ( null !== $this->seeded_logo_path ) {
			@unlink( $this->seeded_logo_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort test cleanup.
			$this->seeded_logo_path = null;
		}

		parent::tear_down();
	}

	public function test_main_site_upload_path_resolves_against_the_main_site_even_when_on_another_blog(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite to exercise the cross-blog resolution path.' );
		}

		$main_site_id = get_main_site_id();
		$upload_dir   = wp_upload_dir();

		// Write a real file into the *main* site's uploads dir directly
		// (bypassing switch_to_blog for the write, to isolate what we're
		// testing: does main_site_upload_path() find it from elsewhere?).
		switch_to_blog( $main_site_id );
		$main_upload_dir = wp_upload_dir();
		$relative        = 'brand-tokens-test-' . uniqid() . '.png';
		$absolute        = trailingslashit( $main_upload_dir['basedir'] ) . $relative;
		$image           = imagecreatetruecolor( 4, 4 );
		imagepng( $image, $absolute );
		imagedestroy( $image );
		restore_current_blog();

		try {
			// Create (or reuse) a second site and make it current, so we are
			// definitely not on the main site when resolving.
			$other_site_id = self::factory()->blog->create();
			switch_to_blog( $other_site_id );

			$resolved = main_site_upload_path( $relative );

			$this->assertSame( $absolute, $resolved, 'Must resolve to the main site\'s file, not the current site\'s uploads dir' );
			$this->assertSame(
				(int) $main_site_id,
				get_current_blog_id(),
				'main_site_upload_path() must restore the original current blog before returning'
			);
		} finally {
			restore_current_blog();
			@unlink( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort test cleanup.
		}
	}

	public function test_main_site_upload_path_returns_null_for_a_missing_file(): void {
		$resolved = main_site_upload_path( 'definitely/does-not-exist-' . uniqid() . '.png' );

		$this->assertNull( $resolved );
	}

	public function test_provide_tokens_sets_logo_path_when_the_asset_exists(): void {
		// provide_tokens() looks up this exact relative path on the main
		// site's uploads dir (see the comment above the lookup in
		// provide_tokens()). Seed it here instead of assuming it
		// pre-exists in whatever WordPress boot the suite runs against —
		// production and CI/sandbox environments start with different
		// media libraries, and this test only exercises what
		// provide_tokens()/main_site_upload_path() do when the asset is
		// present, not whether any particular environment happens to
		// have it uploaded.
		$this->seeded_logo_path = $this->seed_main_site_logo_asset();

		$tokens = provide_tokens( array() );

		$this->assertArrayHasKey( 'logo_path', $tokens );
		$this->assertIsString( $tokens['logo_path'] );
		$this->assertFileExists( $tokens['logo_path'] );
		$this->assertSame( $this->seeded_logo_path, $tokens['logo_path'] );
	}

	/**
	 * Write a real file at the exact relative path
	 * `provide_tokens()`/`main_site_upload_path()` look up
	 * ('2023/04/extra-chill-logo-no-bg.png') under the *main* site's
	 * uploads dir, mirroring how
	 * test_main_site_upload_path_resolves_against_the_main_site_even_when_on_another_blog
	 * writes its own fixture file directly into the main site's uploads
	 * dir regardless of which site is currently active.
	 *
	 * @return string Absolute path of the seeded file.
	 */
	private function seed_main_site_logo_asset(): string {
		$main_site_id = is_multisite() ? get_main_site_id() : get_current_blog_id();
		$switched     = is_multisite() && $main_site_id !== get_current_blog_id();

		if ( $switched ) {
			switch_to_blog( $main_site_id );
		}

		$upload_dir = wp_upload_dir();
		$absolute   = trailingslashit( $upload_dir['basedir'] ) . '2023/04/extra-chill-logo-no-bg.png';

		wp_mkdir_p( dirname( $absolute ) );

		$image = imagecreatetruecolor( 4, 4 );
		imagepng( $image, $absolute );
		imagedestroy( $image );

		if ( $switched ) {
			restore_current_blog();
		}

		return $absolute;
	}

	public function test_provide_tokens_sets_logo_path_inverse_to_the_shipped_light_ink_asset(): void {
		$tokens = provide_tokens( array() );

		$this->assertArrayHasKey( 'logo_path_inverse', $tokens );
		$this->assertFileExists( $tokens['logo_path_inverse'] );
		$this->assertSame( inverse_logo_path(), $tokens['logo_path_inverse'] );
	}

	public function test_inverse_logo_is_a_light_ink_transparent_png_at_least_1024_wide(): void {
		$path  = inverse_logo_path();
		$this->assertNotNull( $path );

		$image = imagecreatefrompng( $path );
		$this->assertNotFalse( $image );
		imagepalettetotruecolor( $image );
		$this->assertGreaterThanOrEqual( 1024, imagesx( $image ) );

		$transparent = 0;
		$light       = 0;
		$opaque      = 0;
		for ( $x = 0; $x < imagesx( $image ); $x += 8 ) {
			for ( $y = 0; $y < imagesy( $image ); $y += 8 ) {
				$rgba  = imagecolorat( $image, $x, $y );
				$alpha = ( $rgba >> 24 ) & 0x7f;
				if ( $alpha > 100 ) {
					++$transparent;
					continue;
				}
				++$opaque;
				$luma = ( ( ( $rgba >> 16 ) & 0xff ) + ( ( $rgba >> 8 ) & 0xff ) + ( $rgba & 0xff ) ) / 3;
				if ( $luma > 200 ) {
					++$light;
				}
			}
		}
		imagedestroy( $image );

		$this->assertGreaterThan( 0, $transparent, 'Background must be transparent' );
		$this->assertGreaterThan( 0.5, $light / max( 1, $opaque ), 'Most visible ink must be light so it reads on dark backgrounds' );
	}

	public function test_provide_tokens_includes_the_theme_dark_mode_palette(): void {
		$colors = provide_tokens( array() )['colors'];

		$this->assertSame( '#1a1a1a', $colors['dark_background'] );
		$this->assertSame( '#e5e5e5', $colors['dark_text'] );
		$this->assertSame( '#b0b0b0', $colors['dark_muted'] );
		$this->assertSame( '#6ab312', $colors['dark_accent'] );
	}

	public function test_provide_tokens_preserves_existing_brand_text_and_site_label(): void {
		$tokens = provide_tokens( array() );

		$this->assertSame( 'Extra Chill', $tokens['brand_text'] );
		$this->assertArrayHasKey( 'site_label', $tokens );
	}
}
