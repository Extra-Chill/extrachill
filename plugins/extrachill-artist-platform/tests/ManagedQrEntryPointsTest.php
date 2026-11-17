<?php
/**
 * Entry points from artist tools into the managed QR code panel (#1629).
 *
 * @package ExtraChillArtistPlatform
 */

require_once __DIR__ . '/support/base-test-case.php';

final class ManagedQrEntryPointsTest extends EC_Artist_Platform_TestCase {
	public function test_managed_qr_url_points_at_the_main_site_generator_panel(): void {
		$url = extrachill_artist_platform_managed_qr_url();

		$this->assertSame(
			add_query_arg( 'view', 'mine', trailingslashit( (string) ec_get_site_url( 'main' ) ) . 'qr-code-generator/' ),
			$url
		);
		$this->assertMatchesRegularExpression( '#^https?://[^/]+/qr-code-generator/\?view=mine$#', $url );
	}

	public function test_link_page_editor_exposes_qr_codes_as_a_related_page(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source read.
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/link-pages/editor-adapter.php' );

		$this->assertMatchesRegularExpression(
			"#'label'\s*=>\s*__\(\s*'QR Codes',\s*'extrachill-artist-platform'\s*\),\s*'url'\s*=>\s*extrachill_artist_platform_managed_qr_url\(\)#",
			$source
		);
	}

	public function test_artist_manager_passes_and_renders_the_qr_codes_link(): void {
		$block = dirname( __DIR__ ) . '/src/blocks/artist-manager';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source read.
		$render = (string) file_get_contents( $block . '/render.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source read.
		$view = (string) file_get_contents( $block . '/view.js' );

		$this->assertStringContainsString( "'qrCodesUrl'", $render );
		$this->assertStringContainsString( 'extrachill_artist_platform_managed_qr_url()', $render );
		$this->assertStringContainsString( "qrCodesUrl: config.qrCodesUrl || ''", $view );
		$this->assertMatchesRegularExpression( '#href=\{config\.qrCodesUrl\}\s*className="button-3 button-small"#', $view );
	}
}
