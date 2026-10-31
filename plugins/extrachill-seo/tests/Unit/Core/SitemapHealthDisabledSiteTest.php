<?php
/**
 * Sitemap health skips sites whose sitemaps are disabled.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

final class SitemapHealthDisabledSiteTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			eval( 'function wp_sitemaps_get_server() { return new class() { public function sitemaps_enabled() { return ! empty( $GLOBALS["ec_seo_test_sitemaps_enabled"] ); } }; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test stub for a core function.
		}
		if ( ! function_exists( 'wp_get_sitemap_providers' ) ) {
			eval( 'function wp_get_sitemap_providers() { $GLOBALS["ec_seo_test_providers_read"] = true; return array(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test stub for a core function.
		}
		require_once dirname( __DIR__, 3 ) . '/inc/core/sitemap-health.php';
	}

	public function test_disabled_site_is_not_sampled(): void {
		$GLOBALS['ec_seo_test_sitemaps_enabled'] = false;
		$GLOBALS['ec_seo_test_providers_read']   = false;
		$this->assertSame( array(), \ExtraChill\SEO\Core\ec_seo_collect_sitemap_sample_urls() );
		$this->assertFalse( $GLOBALS['ec_seo_test_providers_read'] );
	}

	public function test_enabled_site_reads_providers(): void {
		$GLOBALS['ec_seo_test_sitemaps_enabled'] = true;
		$GLOBALS['ec_seo_test_providers_read']   = false;
		\ExtraChill\SEO\Core\ec_seo_collect_sitemap_sample_urls();
		$this->assertTrue( $GLOBALS['ec_seo_test_providers_read'] );
	}
}
