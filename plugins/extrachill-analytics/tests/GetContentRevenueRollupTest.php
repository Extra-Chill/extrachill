<?php
/**
 * Behavioral + contract tests for the content-revenue rollup (issue #130).
 *
 * The pure core (route-family classifier + rollup builder) is unit-tested
 * directly; the WordPress-dependent resolution gate is locked down via a
 * source-string contract on the ability callback.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/revenue-ad-policy.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue.php';

/**
 * Verify resolved published content is separated from unresolved routes.
 */
final class GetContentRevenueRollupTest extends TestCase {

	/**
	 * Route-family classifier maps the issue's example paths to honest buckets.
	 */
	public function test_route_family_classification(): void {
		$this->assertSame( 'home', extrachill_analytics_revenue_classify_route_family( '/' ) );
		$this->assertSame( 'pagination', extrachill_analytics_revenue_classify_route_family( '/page/2/' ) );
		$this->assertSame( 'pagination', extrachill_analytics_revenue_classify_route_family( '/page/12' ) );
		$this->assertSame( 'taxonomy-archive', extrachill_analytics_revenue_classify_route_family( '/location/charleston/' ) );
		$this->assertSame( 'taxonomy-archive', extrachill_analytics_revenue_classify_route_family( '/t/grateful-dead' ) );
		$this->assertSame( 'taxonomy-archive', extrachill_analytics_revenue_classify_route_family( '/festival/bonaroo/' ) );
		$this->assertSame( 'app-account', extrachill_analytics_revenue_classify_route_family( '/account' ) );
		$this->assertSame( 'app-account', extrachill_analytics_revenue_classify_route_family( '/wp-admin/edit.php' ) );
		$this->assertSame( 'legacy-html', extrachill_analytics_revenue_classify_route_family( '/old-ghost-page.html' ) );
		$this->assertSame( 'other', extrachill_analytics_revenue_classify_route_family( '/some-mystery-path/' ) );

		// Full-URL form is reduced to its path before classification.
		$this->assertSame( 'home', extrachill_analytics_revenue_classify_route_family( 'https://extrachill.com/' ) );
		$this->assertSame( 'pagination', extrachill_analytics_revenue_classify_route_family( 'https://wire.extrachill.com/page/3/' ) );
	}

	/**
	 * Mixed rows: content totals must reflect only the resolved published post,
	 * while unresolved routes land in their own diagnostic cohort.
	 */
	public function test_unresolved_routes_excluded_from_content_totals(): void {
		$records = array(
			// Published song-meaning post: $100 over 1,000 views = $100 RPM.
			array(
				'is_content' => true,
				'page_key'   => 'p100',
				'format'     => 'song-meaning',
				'categories' => array( 'song-meanings' ),
				'views'      => 1000,
				'revenue'    => 100.0,
				'url'        => '/song-meaning-post/',
			),
			// Unresolved home route: 5,000 views, $5 (the RPM-diluter).
			array(
				'is_content' => false,
				'page_key'   => 'u' . md5( '/' ),
				'views'      => 5000,
				'revenue'    => 5.0,
				'url'        => '/',
			),
			// Unresolved pagination: 1,000 views, $1.
			array(
				'is_content' => false,
				'page_key'   => 'u' . md5( 'page/2' ),
				'views'      => 1000,
				'revenue'    => 1.0,
				'url'        => '/page/2/',
			),
		);

		$result = extrachill_analytics_revenue_build_rollups( $records, 'both' );

		// Content totals = the single published post.
		$this->assertSame( 1, $result['totals']['pages'] );
		$this->assertSame( 1000, $result['totals']['views'] );
		$this->assertEquals( 100.0, $result['totals']['revenue'] );
		$this->assertEquals( 100.0, $result['totals']['rpm'] );

		// Unresolved cohort = the two unresolved paths.
		$this->assertSame( 2, $result['unresolved']['pages'] );
		$this->assertSame( 6000, $result['unresolved']['views'] );
		$this->assertEquals( 6.0, $result['unresolved']['revenue'] );
		// ~$1 RPM for unresolved, not the contaminated $15.38 the combined rollup
		// would have produced (the exact distortion issue #130 reports).
		$this->assertEquals( round( 6.0 / 6.0, 2 ), $result['unresolved']['rpm'] );
	}

	/**
	 * Category and format buckets must never contain unresolved routes, and the
	 * bogus `uncategorized`/`legacy-html` entries they used to create are gone.
	 */
	public function test_buckets_contain_only_content(): void {
		$records = array(
			array(
				'is_content' => true,
				'page_key'   => 'p100',
				'format'     => 'song-meaning',
				'categories' => array( 'song-meanings' ),
				'views'      => 1000,
				'revenue'    => 100.0,
				'url'        => '/song/',
			),
			array(
				'is_content' => false,
				'page_key'   => 'u' . md5( '/' ),
				'views'      => 5000,
				'revenue'    => 5.0,
				'url'        => '/',
			),
			array(
				'is_content' => false,
				'page_key'   => 'u' . md5( 'old.html' ),
				'views'      => 500,
				'revenue'    => 2.0,
				'url'        => '/old.html',
			),
		);

		$result = extrachill_analytics_revenue_build_rollups( $records, 'both' );

		$format_buckets   = $this->pluck( $result['rollups']['by_format'], 'bucket' );
		$category_buckets = $this->pluck( $result['rollups']['by_category'], 'bucket' );

		$this->assertSame( array( 'song-meaning' ), $format_buckets );
		$this->assertSame( array( 'song-meanings' ), $category_buckets );
		$this->assertNotContains( 'uncategorized', $format_buckets );
		$this->assertNotContains( 'uncategorized', $category_buckets );
		$this->assertNotContains( 'legacy-html', $format_buckets );
	}

	/**
	 * A genuinely resolved post with no category IS the real `uncategorized`
	 * cohort and must not be conflated with unresolved routes.
	 */
	public function test_resolved_post_without_category_is_uncategorized(): void {
		$records = array(
			array(
				'is_content' => true,
				'page_key'   => 'p200',
				'format'     => 'uncategorized',
				'categories' => array( 'uncategorized' ),
				'views'      => 2000,
				'revenue'    => 50.0,
				'url'        => '/orphan-post/',
			),
			array(
				'is_content' => false,
				'page_key'   => 'u' . md5( '/' ),
				'views'      => 4000,
				'revenue'    => 4.0,
				'url'        => '/',
			),
		);

		$result = extrachill_analytics_revenue_build_rollups( $records, 'category' );

		// The real uncategorized post is counted as content.
		$this->assertSame( 1, $result['totals']['pages'] );
		$this->assertSame( 2000, $result['totals']['views'] );
		$this->assertEquals( 50.0, $result['totals']['revenue'] );

		$category_buckets = $this->pluck( $result['rollups']['by_category'], 'bucket' );
		$this->assertContains( 'uncategorized', $category_buckets );

		// The unresolved home route is NOT in the category buckets.
		$this->assertSame( 1, $result['unresolved']['pages'] );
		$this->assertEquals( 4.0, $result['unresolved']['revenue'] );
		// Exactly one category row — the real uncategorized post, not the route.
		$this->assertCount( 1, $result['rollups']['by_category'] );
	}

	/**
	 * Duplicate URL variants of the same post/page count once for pages while
	 * revenue and views still sum (the existing de-dupe contract, preserved).
	 */
	public function test_content_and_unresolved_dedupe_independently(): void {
		$records = array(
			// Same post via two URL variants.
			array(
				'is_content' => true,
				'page_key'   => 'p300',
				'format'     => 'trivia',
				'categories' => array( 'trivia' ),
				'views'      => 500,
				'revenue'    => 10.0,
				'url'        => '/quiz-one/',
			),
			array(
				'is_content' => true,
				'page_key'   => 'p300',
				'format'     => 'trivia',
				'categories' => array( 'trivia' ),
				'views'      => 500,
				'revenue'    => 10.0,
				'url'        => '/quiz-one/?ref=x',
			),
			// Same unresolved path twice.
			array(
				'is_content' => false,
				'page_key'   => 'u' . md5( '/' ),
				'views'      => 100,
				'revenue'    => 1.0,
				'url'        => '/',
			),
			array(
				'is_content' => false,
				'page_key'   => 'u' . md5( '/' ),
				'views'      => 100,
				'revenue'    => 1.0,
				'url'        => '/',
			),
		);

		$result = extrachill_analytics_revenue_build_rollups( $records, 'format' );

		// One content page, summed views/revenue.
		$this->assertSame( 1, $result['totals']['pages'] );
		$this->assertSame( 1000, $result['totals']['views'] );
		$this->assertEquals( 20.0, $result['totals']['revenue'] );

		// One unresolved page, summed views/revenue.
		$this->assertSame( 1, $result['unresolved']['pages'] );
		$this->assertSame( 200, $result['unresolved']['views'] );
		$this->assertEquals( 2.0, $result['unresolved']['revenue'] );
	}

	/**
	 * Group_by axis controls which rollup keys are present.
	 */
	public function test_group_by_controls_rollup_keys(): void {
		$records = array(
			array(
				'is_content' => true,
				'page_key'   => 'p1',
				'format'     => 'news',
				'categories' => array( 'news' ),
				'views'      => 100,
				'revenue'    => 1.0,
				'url'        => '/p1/',
			),
		);

		$format_only = extrachill_analytics_revenue_build_rollups( $records, 'format' );
		$this->assertArrayHasKey( 'by_format', $format_only['rollups'] );
		$this->assertArrayNotHasKey( 'by_category', $format_only['rollups'] );

		$category_only = extrachill_analytics_revenue_build_rollups( $records, 'category' );
		$this->assertArrayNotHasKey( 'by_format', $category_only['rollups'] );
		$this->assertArrayHasKey( 'by_category', $category_only['rollups'] );
	}

	/**
	 * The unresolved by_route_family breakdown must sum back to the cohort totals
	 * and bucket the issue's example paths correctly.
	 */
	public function test_unresolved_route_family_breakdown(): void {
		$records = array(
			array(
				'is_content' => false,
				'page_key'   => 'u' . md5( '/' ),
				'views'      => 5000,
				'revenue'    => 5.0,
				'url'        => '/',
			),
			array(
				'is_content' => false,
				'page_key'   => 'u' . md5( 'page/2' ),
				'views'      => 1000,
				'revenue'    => 1.0,
				'url'        => '/page/2/',
			),
			array(
				'is_content' => false,
				'page_key'   => 'u' . md5( 'location/c' ),
				'views'      => 2000,
				'revenue'    => 2.0,
				'url'        => '/location/charleston/',
			),
			array(
				'is_content' => false,
				'page_key'   => 'u' . md5( 'old.html' ),
				'views'      => 300,
				'revenue'    => 1.5,
				'url'        => '/old.html',
			),
		);

		$result  = extrachill_analytics_revenue_build_rollups( $records, 'format' );
		$family  = array();
		$views   = 0;
		$revenue = 0.0;
		foreach ( $result['unresolved']['by_route_family'] as $row ) {
			$family[ $row['route_family'] ] = $row;
			$views                         += $row['views'];
			$revenue                       += $row['revenue'];
		}

		$this->assertArrayHasKey( 'home', $family );
		$this->assertArrayHasKey( 'pagination', $family );
		$this->assertArrayHasKey( 'taxonomy-archive', $family );
		$this->assertArrayHasKey( 'legacy-html', $family );

		// Breakdown sums back to cohort totals.
		$this->assertSame( $result['unresolved']['views'], $views );
		$this->assertEquals( round( $result['unresolved']['revenue'], 2 ), round( $revenue, 2 ) );
		$this->assertSame( $result['unresolved']['pages'], $family['home']['pages'] + $family['pagination']['pages'] + $family['taxonomy-archive']['pages'] + $family['legacy-html']['pages'] );
	}

	/**
	 * Intentional no-ads traffic remains visible without reading as underperformance.
	 */
	public function test_blocked_home_revenue_is_not_applicable_but_preserved(): void {
		$result = extrachill_analytics_revenue_build_rollups(
			array(
				array(
					'is_content' => false,
					'page_key'   => 'home',
					'views'      => 5000,
					'revenue'    => 2.5,
					'url'        => '/',
					'ad_policy'  => array(
						'site_enabled' => true,
						'serve_ads'    => false,
						'reason'       => 'route_blocked',
					),
				),
			),
			'format'
		);

		$this->assertSame( 5000, $result['unresolved']['views'] );
		$this->assertSame( 2.5, $result['unresolved']['revenue'] );
		$this->assertSame( 'not_applicable', $result['unresolved']['revenue_status'] );
		$this->assertSame( 1, $result['unresolved']['policy_conflicts'] );
		$this->assertSame( 'not_applicable', $result['unresolved']['by_route_family'][0]['revenue_status'] );
	}

	/**
	 * Source-string contract: the callback must publish-gate content and build
	 * is_content records (the WP-dependent half that can't be unit-tested).
	 */
	public function test_callback_publish_gates_content_and_reports_unresolved(): void {
		$source = $this->ability_source();

		// A row is content only when its post is currently published.
		$this->assertStringContainsString( "'publish' === get_post_status( \$post_id )", $source );
		// Unresolved routes are marked is_content => false and excluded.
		$this->assertStringContainsString( "'is_content' => false", $source );
		$this->assertStringContainsString( "'is_content' => true", $source );
		// The response exposes an explicit unresolved cohort.
		$this->assertStringContainsString( "'unresolved'", $source );
		$this->assertStringContainsString( "'by_route_family'", $source );
		// The old conflation (unresolved rows bucketed as uncategorized/legacy-html
		// content) is gone.
		$this->assertStringNotContainsString( "preg_match( '/\\.html(?:[\\/?#]|$)/i', (string) \$row->url ) ? 'legacy-html' : 'uncategorized'", $source );
	}

	/**
	 * Helper: pull one column from a list of rows, indexed by another column.
	 *
	 * @param array  $rows  Rollup rows.
	 * @param string $field Field to extract.
	 * @return array<int, mixed>
	 */
	private function pluck( array $rows, $field ) {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = $row[ $field ];
		}
		return $out;
	}

	/**
	 * Read the production ability source.
	 *
	 * @return string
	 */
	private function ability_source() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue.php' );
		$this->assertNotFalse( $source );

		return $source;
	}
}
