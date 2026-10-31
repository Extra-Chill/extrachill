<?php

require_once __DIR__ . '/support/base-test-case.php';

/**
 * Artist Platform's CSS var sanitizer delegates to the Link Pages runtime.
 *
 * extrachill-link-pages is a hard dependency, so there is no lenient
 * Artist Platform fallback to cover: the wrapper must return exactly what the
 * strict runtime validator returns.
 */
final class SanitizeCssVarsTest extends EC_Artist_Platform_TestCase {
	public function test_wrapper_delegates_valid_input_to_the_link_pages_runtime(): void {
		$valid = array(
			'--link-page-text-color' => '#123456',
			'--link-page-accent'     => '#ff5733',
		);

		$this->assertSame( ec_sanitize_link_page_css_vars( $valid ), extrachill_artist_platform_sanitize_css_vars( $valid ) );
		$this->assertSame( '#123456', extrachill_artist_platform_sanitize_css_vars( $valid )['--link-page-text-color'] );
	}

	public function test_wrapper_surfaces_the_runtime_rejections(): void {
		$this->assertWPError( extrachill_artist_platform_sanitize_css_vars( null ) );
		$this->assertWPError( extrachill_artist_platform_sanitize_css_vars( array( '--link-page-text-color' => null ) ) );
		$this->assertWPError( extrachill_artist_platform_sanitize_css_vars( array( '--link-page-text-color' => 'not-a-color' ) ) );
		$this->assertWPError( extrachill_artist_platform_sanitize_css_vars( array( '--evil-injected-css' => '#123456' ) ) );
	}
}
