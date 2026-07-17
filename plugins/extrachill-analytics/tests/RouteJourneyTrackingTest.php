<?php
/**
 * Tests for non-singular route journey collection.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/route-classifier.php';
require_once dirname( __DIR__ ) . '/inc/core/referrer-host-classifier.php';
require_once dirname( __DIR__ ) . '/inc/core/assets.php';
require_once dirname( __DIR__ ) . '/inc/core/write-integrity.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/track-page-view.php';

/**
 * Verify route identity stays bounded, query-free, and cache-safe.
 */
final class RouteJourneyTrackingTest extends TestCase {
	/**
	 * Establish a first-party browser beacon fixture.
	 */
	protected function setUp(): void {
		$_SERVER['HTTP_HOST']                                    = 'extrachill.com';
		$_SERVER['HTTP_ORIGIN']                                  = 'https://extrachill.com';
		$_SERVER['HTTP_USER_AGENT']                              = 'Mozilla/5.0';
		$_SERVER['REMOTE_ADDR']                                  = '203.0.113.20';
		$_SERVER['REQUEST_METHOD']                               = 'GET';
		$_COOKIE['ec_vid']                                       = '123e4567-e89b-42d3-a456-426614174000';
		$GLOBALS['extrachill_analytics_test_tracked_post_views'] = array();
		$GLOBALS['extrachill_analytics_test_events']             = array();
		$GLOBALS['extrachill_analytics_test_actions']            = array();
		$GLOBALS['extrachill_analytics_test_cache']              = array();
		$GLOBALS['extrachill_analytics_test_ext_object_cache']   = true;
	}

	/**
	 * Restore conditional fixtures after each test.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['extrachill_analytics_test_is_singular'],
			$GLOBALS['extrachill_analytics_test_is_search'],
			$GLOBALS['extrachill_analytics_test_is_front_page'],
			$GLOBALS['extrachill_analytics_test_is_home'],
			$GLOBALS['extrachill_analytics_test_is_archive'],
			$_SERVER['HTTP_HOST'],
			$_SERVER['HTTP_ORIGIN'],
			$_SERVER['HTTP_USER_AGENT'],
			$_SERVER['REMOTE_ADDR'],
			$_SERVER['HTTP_SEC_GPC'],
			$_SERVER['REQUEST_METHOD'],
			$_COOKIE['ec_vid']
		);
	}

	/**
	 * Query strings and fragments never enter the route identity.
	 */
	public function test_route_path_normalization_removes_query_and_fragment(): void {
		$this->assertSame( '/', extrachill_analytics_normalize_route_path( 'https://extrachill.com/?s=user@example.com' ) );
		$this->assertSame( '/events/charleston/', extrachill_analytics_normalize_route_path( '/events//charleston/?utm_source=email#shows' ) );
		$this->assertSame( '/register/', extrachill_analytics_normalize_route_path( 'register/?email=user@example.com' ) );
		$this->assertSame( '', extrachill_analytics_normalize_route_path( '' ) );
	}

	/**
	 * Public route families cover the required network surfaces.
	 *
	 * @dataProvider route_family_provider
	 *
	 * @param string $path     Browser route path.
	 * @param string $fixture  Conditional fixture global, or empty.
	 * @param string $expected Expected bounded family.
	 */
	public function test_public_routes_have_bounded_families( $path, $fixture, $expected ): void {
		if ( '' !== $fixture ) {
			$GLOBALS[ $fixture ] = true;
		}

		$this->assertSame( $expected, extrachill_analytics_classify_current_route( $path ) );
		$this->assertContains( $expected, extrachill_analytics_route_families() );
	}

	/**
	 * Route classification fixtures.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function route_family_provider() {
		return array(
			'homepage'       => array( '/', '', 'home' ),
			'archive'        => array( '/2026/07/', 'extrachill_analytics_test_is_archive', 'archive' ),
			'search results' => array( '/?s=dead', 'extrachill_analytics_test_is_search', 'search' ),
			'login'          => array( '/login/', '', 'auth' ),
			'register'       => array( '/register/', '', 'auth' ),
			'directory'      => array( '/events/', '', 'directory' ),
			'singular post'  => array( '/story/', 'extrachill_analytics_test_is_singular', 'singular' ),
			'singular event' => array( '/events/show/', 'extrachill_analytics_test_is_singular', 'singular' ),
			'other public'   => array( '/about/', '', 'other' ),
		);
	}

	/**
	 * Browser writes use Core's ability runner and its required input envelope.
	 */
	public function test_browser_uses_core_abilities_runner_contract(): void {
		$assets  = $this->read_source( 'inc/core/assets.php' );
		$script  = $this->read_source( 'assets/js/view-tracking.js' );
		$ability = $this->read_source( 'inc/core/abilities/track-page-view.php' );

		$this->assertStringContainsString( "rest_url( 'wp-abilities/v1/abilities/extrachill/track-page-view/run' )", $assets );
		$this->assertStringContainsString( 'JSON.stringify( { input } )', $script );
		$this->assertStringContainsString( 'source_path: config.sourcePath', $script );
		$this->assertStringContainsString( 'route_family: config.routeFamily', $script );
		$this->assertStringNotContainsString( "rest_url( 'extrachill/v1/analytics/view' )", $assets );
		$this->assertStringContainsString( "'required'   => array( 'source_path', 'route_family', 'proof' )", $ability );
	}

	/**
	 * Route events cannot increment post counters or link-page actions.
	 */
	public function test_post_side_effects_remain_guarded_by_valid_post_id(): void {
		$ability = $this->read_source( 'inc/core/abilities/track-page-view.php' );

		$this->assertStringContainsString( 'if ( $post_id > 0 ) {', $ability );
		$this->assertStringContainsString( "if ( \$post_id > 0 && get_post_type( \$post_id ) === 'artist_link_page' )", $ability );
		$this->assertStringContainsString( "'view_kind'    => \$post_id > 0 ? 'post' : 'route'", $ability );
		$this->assertStringContainsString( 'extrachill_analytics_validate_pageview_write', $ability );
	}

	/**
	 * A first-party route writes one route event and no post side effects.
	 */
	public function test_first_party_route_view_writes_event_only(): void {
		$result = extrachill_analytics_ability_track_page_view(
			$this->with_proof(
				array(
					'source_path'  => '/events/?city=charleston',
					'route_family' => 'directory',
					'referrer'     => 'https://community.extrachill.com/story/?email=user@example.com',
				)
			)
		);

		$this->assertSame( array( 'recorded' => true ), $result );
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_tracked_post_views'] );
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_actions'] );
		$this->assertCount( 1, $GLOBALS['extrachill_analytics_test_events'] );
		$this->assertSame( 'route', $GLOBALS['extrachill_analytics_test_events'][0][1]['view_kind'] );
		$this->assertSame( 'directory', $GLOBALS['extrachill_analytics_test_events'][0][1]['route_family'] );
		$this->assertArrayNotHasKey( 'post_id', $GLOBALS['extrachill_analytics_test_events'][0][1] );
		$this->assertSame( 'community.extrachill.com', $GLOBALS['extrachill_analytics_test_events'][0][1]['referrer_host'] );
		$this->assertSame( 'https://extrachill.com/events/', $GLOBALS['extrachill_analytics_test_events'][0][2] );
	}

	/**
	 * Third-party route views are rejected rather than stitched or stored.
	 */
	public function test_custom_domain_route_view_is_rejected(): void {
		$_SERVER['HTTP_ORIGIN'] = 'https://artist.example';

		$result = extrachill_analytics_ability_track_page_view(
			$this->with_proof(
				array(
					'source_path'  => '/directory/',
					'route_family' => 'directory',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_pageview_origin', $result->code );
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_events'] );
	}

	/**
	 * GPC suppresses identity without suppressing anonymous aggregate views.
	 */
	public function test_privacy_opt_out_preserves_anonymous_route_eligibility(): void {
		$_SERVER['HTTP_SEC_GPC'] = '1';

		$this->assertTrue( extrachill_analytics_is_eligible_public_template_request() );
		$this->assertFalse( extrachill_analytics_should_prime_visitor_cookie() );
	}

	/**
	 * Singular posts retain legacy counters and artist link-page actions.
	 */
	public function test_post_backed_view_preserves_legacy_side_effects(): void {
		$GLOBALS['extrachill_analytics_test_permalinks'][42] = 'https://artist.extrachill.com/example/';
		$GLOBALS['extrachill_analytics_test_post_types'][42] = 'artist_link_page';
		$post     = new WP_Post();
		$post->ID = 42;
		$GLOBALS['extrachill_analytics_classifier_posts'][42] = $post;

		$result = extrachill_analytics_ability_track_page_view(
			$this->with_proof(
				array(
					'post_id'      => 42,
					'source_path'  => '/example/',
					'route_family' => 'singular',
				)
			)
		);

		$this->assertSame( array( 'recorded' => true ), $result );
		$this->assertSame( array( 42 ), $GLOBALS['extrachill_analytics_test_tracked_post_views'] );
		$this->assertSame( 'extrachill_link_page_view_recorded', $GLOBALS['extrachill_analytics_test_actions'][0][0] );
		$this->assertSame( 'post', $GLOBALS['extrachill_analytics_test_events'][0][1]['view_kind'] );
		$this->assertSame( 42, $GLOBALS['extrachill_analytics_test_events'][0][1]['post_id'] );
	}

	/**
	 * Add the cache-safe proof emitted with a pageview configuration.
	 *
	 * @param array $input Pageview input.
	 * @return array
	 */
	private function with_proof( $input ) {
		$path           = extrachill_analytics_normalize_route_path( $input['source_path'] );
		$input['proof'] = extrachill_analytics_pageview_proof(
			isset( $input['post_id'] ) ? (int) $input['post_id'] : 0,
			$path,
			$input['route_family'],
			extrachill_analytics_public_write_source_host()
		);
		return $input;
	}

	/**
	 * Retention reports disclose mixed historical collection coverage.
	 */
	public function test_retention_contract_discloses_historical_coverage(): void {
		$retention = $this->read_source( 'inc/core/abilities/get-retention-stats.php' );

		$this->assertStringContainsString( "'collection_coverage'", $retention );
		$this->assertStringContainsString( "'post_backed_pageviews'", $retention );
		$this->assertStringContainsString( "'route_pageviews'", $retention );
		$this->assertStringContainsString( "'historical_unclassified_pageviews'", $retention );
		$this->assertStringContainsString( 'periods spanning deployment', $retention );
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
