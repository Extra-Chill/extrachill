<?php
/** Sitemap health uses each site's live index, independent of caller context. */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use function ExtraChill\SEO\Core\ec_seo_check_sitemap_health;
use function ExtraChill\SEO\Core\ec_seo_parse_sitemap_sample_urls;

final class SitemapHealthDisabledSiteTest extends TestCase {
	private array $requests = array();
	private array $responses = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/SitemapHealthStubs.php';
		require_once dirname( __DIR__, 3 ) . '/inc/core/sitemap-health.php';
	}

	protected function setUp(): void {
		$GLOBALS['ec_seo_test_blog_id'] = 1;
		$GLOBALS['ec_seo_test_blog_stack'] = array();
		$GLOBALS['ec_seo_test_blog_public'] = 1;
		$GLOBALS['ec_seo_test_sitemaps_enabled'] = true;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			$this->requests[] = $url;
			return $this->responses[ $url ] ?? array( 'response' => array( 'code' => 200 ), 'body' => '' );
		}, 10, 3 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ec_seo_test_filters']['pre_http_request'], $GLOBALS['ec_seo_test_blog_id'], $GLOBALS['ec_seo_test_blog_stack'], $GLOBALS['ec_seo_test_blog_public'], $GLOBALS['ec_seo_test_sitemaps_enabled'] );
	}

	private function index( array $urls ): string {
		$xml = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		foreach ( $urls as $url ) {
			$xml .= '<sitemap><loc>' . htmlspecialchars( $url, ENT_XML1 ) . '</loc></sitemap>';
		}
		return $xml . '</sitemapindex>';
	}

	public function test_samples_numeric_boundaries_per_subtype_and_users(): void {
		$base = 'https://events.extrachill.com/wp-sitemap-';
		$urls = array_map( static fn( $suffix ) => $base . $suffix . '.xml', array( 'posts-event-10', 'posts-event-2', 'posts-event-1', 'taxonomies-venue-1', 'users-1' ) );
		$this->assertSame( array( $urls[2], $urls[0], $urls[3], $urls[4] ), ec_seo_parse_sitemap_sample_urls( $this->index( $urls ) ) );
	}

	public function test_query_string_sitemaps_are_sampled_and_entities_decoded(): void {
		$base = 'https://example.com/?sitemap=posts&sitemap-subtype=event&paged=';
		$this->assertSame( array( $base . '1', $base . '12' ), ec_seo_parse_sitemap_sample_urls( $this->index( array( $base . '12', $base . '2', $base . '1' ) ) ) );
	}

	public function test_network_result_is_identical_from_main_shop_and_events(): void {
		foreach ( array( 1 => 'post', 3 => 'product', 7 => 'data_machine_events' ) as $id => $type ) {
			$urls = array( get_home_url( $id, '/wp-sitemap-posts-' . $type . '-1.xml' ) );
			if ( 7 === $id ) {
				$urls[] = get_home_url( $id, '/wp-sitemap-taxonomies-venue-1.xml' );
			}
			$this->responses[ get_home_url( $id, '/wp-sitemap.xml' ) ] = array( 'response' => array( 'code' => 200 ), 'body' => $this->index( $urls ) );
		}
		$failing_url = get_home_url( 7, '/wp-sitemap-taxonomies-venue-1.xml' );
		$this->responses[ $failing_url ] = array( 'response' => array( 'code' => 410 ), 'body' => '' );
		$expected = null;
		foreach ( array( 1, 3, 7 ) as $caller ) {
			$GLOBALS['ec_seo_test_blog_id'] = $caller;
			$result = ec_seo_check_sitemap_health();
			$this->assertSame( $caller, get_current_blog_id() );
			$this->assertFalse( $result['healthy'] );
			$this->assertSame( $failing_url, $result['failures'][0]['url'] );
			$this->assertSame( 410, $result['failures'][0]['status'] );
			$this->assertSame( 4, $result['checked'] );
			$this->assertSame( 2, $result['by_site'][7]['sampled'] );
			unset( $result['timestamp'] );
			if ( null !== $expected ) {
				$this->assertSame( $expected, $result );
			}
			$expected = $result;
		}
		$this->assertContains( get_home_url( 7, '/wp-sitemap-posts-data_machine_events-1.xml' ), $this->requests );
	}

	public function test_index_failures_are_reported_including_invalid_xml_and_transport_errors(): void {
		$url = get_home_url( 7, '/wp-sitemap.xml' );
		foreach ( array( array( 503, 'unavailable' ), array( 200, '<broken' ), array( 200, '<html/>' ), array( 0, '' ) ) as list( $status, $body ) ) {
			$this->responses[ $url ] = 0 === $status ? new \WP_Error() : array( 'response' => array( 'code' => $status ), 'body' => $body );
			$result = ec_seo_check_sitemap_health( array( 'blog_id' => 7 ) );
			$this->assertFalse( $result['healthy'] );
			$this->assertSame( array( array( 'url' => $url, 'status' => $status, 'blog_id' => 7, 'label' => 'Extra Chill Events' ) ), $result['failures'] );
			$this->assertSame( 1, $result['by_site'][7]['failures'] );
		}
	}

	public function test_disabled_sites_make_no_http_requests(): void {
		$GLOBALS['ec_seo_test_sitemaps_enabled'] = false;
		$this->assertTrue( ec_seo_check_sitemap_health()['healthy'] );
		$GLOBALS['ec_seo_test_sitemaps_enabled'] = true;
		$GLOBALS['ec_seo_test_blog_public'] = 0;
		$this->assertSame( 0, ec_seo_check_sitemap_health()['checked'] );
		$this->assertSame( array(), $this->requests );
	}
}
