<?php
/**
 * Tests for cache-safe browser analytics identity.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

/**
 * Prevent visitor-specific UUIDs from returning to cacheable page markup.
 */
final class VisitorIdentityCacheSafetyTest extends TestCase {
	/**
	 * Load the visitor identity helpers under the lightweight test bootstrap.
	 */
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/inc/core/assets.php';
	}

	/**
	 * Restore request globals after each origin fixture.
	 */
	protected function tearDown(): void {
		unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
	}

	/**
	 * Frontend configuration must contain no render-time visitor UUID.
	 */
	public function test_frontend_assets_do_not_localize_visitor_identity(): void {
		$assets   = $this->read_source( 'inc/core/assets.php' );
		$pageview = $this->read_source( 'assets/js/view-tracking.js' );
		$outbound = $this->read_source( 'assets/js/outbound-tracking.js' );

		$this->assertStringNotContainsString( "'visitorId'", $assets );
		$this->assertStringNotContainsString( 'config.visitorId', $pageview );
		$this->assertStringNotContainsString( 'config.visitorId', $outbound );
		$this->assertStringNotContainsString( 'payload.visitor_id', $pageview );
		$this->assertStringNotContainsString( 'payload.visitor_id', $outbound );
	}

	/**
	 * Browser beacon abilities must resolve identity from the request cookie.
	 */
	public function test_browser_beacons_resolve_request_identity(): void {
		$pageview = $this->read_source( 'inc/core/abilities/track-page-view.php' );
		$events   = $this->read_source( 'inc/core/abilities.php' );

		$this->assertStringContainsString(
			'extrachill_analytics_get_or_mint_visitor_id()',
			$pageview
		);
		$this->assertStringNotContainsString( "\$input['visitor_id']", $pageview );
		$this->assertStringNotContainsString( "'outbound_click' === \$event_type", $events );
		$this->assertStringContainsString(
			'$is_first_party_beacon && function_exists( \'extrachill_analytics_read_visitor_id\' )',
			$pageview
		);
		$this->assertStringContainsString(
			"wp_parse_url( home_url( '/' ), PHP_URL_HOST )",
			$this->read_source( 'inc/core/assets.php' )
		);
	}

	/**
	 * Identity is limited to the cookie's first-party site, not a custom domain.
	 */
	public function test_beacon_origin_must_match_the_first_party_cookie_domain(): void {
		$_SERVER['HTTP_ORIGIN'] = 'https://events.extrachill.com';
		$this->assertTrue( extrachill_analytics_beacon_is_first_party() );

		$_SERVER['HTTP_ORIGIN'] = 'https://artist.example';
		$this->assertFalse( extrachill_analytics_beacon_is_first_party() );
	}

	/**
	 * Read a production source file.
	 *
	 * @param string $relative_path Repository-relative path.
	 * @return string
	 */
	private function read_source( $relative_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local source fixtures.
		$source = file_get_contents( dirname( __DIR__ ) . '/' . $relative_path );

		$this->assertNotFalse( $source );

		return $source;
	}
}
