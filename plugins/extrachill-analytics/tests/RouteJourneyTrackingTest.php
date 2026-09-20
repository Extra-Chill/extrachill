<?php
/**
 * Tests for non-singular route journey collection.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify route identity stays bounded, query-free, and cache-safe.
 */
final class RouteJourneyTrackingTest extends Extrachill_Analytics_TestCase {
	/**
	 * Establish a first-party browser beacon fixture.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->set_ext_object_cache( true );
		$this->set_request( 'example.org', 'GET' );
		$_SERVER['HTTP_ORIGIN']     = 'http://localhost';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.20';
		$_COOKIE['ec_vid']          = '123e4567-e89b-42d3-a456-426614174000';
	}

	/**
	 * Route strings and fragments never enter the route identity.
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
	 * @param array  $flags    Conditional flags for the main query.
	 * @param string $expected Expected bounded family.
	 */
	public function test_public_routes_have_bounded_families( $path, $flags, $expected ): void {
		$this->set_query_flags( $flags );

		$this->assertSame( $expected, extrachill_analytics_classify_current_route( $path ) );
		$this->assertContains( $expected, extrachill_analytics_route_families() );
	}

	/**
	 * Route classification fixtures.
	 *
	 * @return array<string,array{string,array<string,bool>,string}>
	 */
	public function route_family_provider() {
		return array(
			'homepage'       => array( '/', array(), 'home' ),
			'archive'        => array( '/2026/07/', array( 'is_archive' => true ), 'archive' ),
			'search results' => array( '/?s=dead', array( 'is_search' => true ), 'search' ),
			'login'          => array( '/login/', array(), 'auth' ),
			'register'       => array( '/register/', array(), 'auth' ),
			'directory'      => array( '/events/', array(), 'directory' ),
			'singular post'  => array( '/story/', array( 'is_singular' => true ), 'singular' ),
			'singular event' => array( '/events/show/', array( 'is_singular' => true ), 'singular' ),
			'other public'   => array( '/about/', array(), 'other' ),
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
		$this->assertStringContainsString( 'Number.parseInt( config.postId, 10 )', $script );
		$this->assertStringContainsString( 'wp_add_inline_script(', $assets );
		$this->assertStringContainsString( 'window.ecViewTracking = ', $assets );
		$this->assertStringContainsString( "'singular' === \$route_family && is_singular()", $assets );
		$this->assertStringNotContainsString( "wp_localize_script(\n\t\t'extrachill-view-tracking'", $assets );
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
		$this->assertSame( 0, did_action( 'extrachill_link_page_view_recorded' ) );
		$this->assertSame( 1, $this->event_count() );
		$row   = $this->event_rows()[0];
		$data  = $this->event_data( $row );
		$this->assertSame( 'route', $data['view_kind'] );
		$this->assertSame( 'directory', $data['route_family'] );
		$this->assertSame( 'community.extrachill.com', $data['referrer_host'] );
		$this->assertArrayNotHasKey( 'post_id', $data );
		$this->assertSame( home_url( '/events/' ), $row->source_url );
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
		$this->assertSame( 'invalid_pageview_origin', $result->get_error_code() );
		$this->assertSame( 0, $this->event_count() );
	}

	/**
	 * GPC suppresses identity without suppressing anonymous aggregate views.
	 */
	public function test_privacy_opt_out_preserves_anonymous_route_eligibility(): void {
		$_SERVER['HTTP_SEC_GPC'] = '1';

		$this->assertTrue( extrachill_analytics_is_eligible_public_template_request() );
		$this->assertSame(
			'',
			extrachill_analytics_visitor_cookie_client_config()['cookieDomain']
		);
	}

	/**
	 * Privacy signals suppress identity while preserving aggregate pageviews.
	 *
	 * @dataProvider privacy_signal_provider
	 *
	 * @param string $header Privacy request header.
	 */
	public function test_privacy_signal_records_anonymous_route_view( $header ): void {
		$_SERVER[ $header ] = '1';

		$result = extrachill_analytics_ability_track_page_view(
			$this->with_proof(
				array(
					'source_path'  => '/locations/charleston/?scope=weekend&search=indie',
					'route_family' => 'archive',
				)
			)
		);

		$this->assertSame( array( 'recorded' => true ), $result );
		$this->assertSame( 1, $this->event_count() );
		$row = $this->event_rows()[0];
		$this->assertNull( $row->visitor_id );
		$this->assertSame( home_url( '/locations/charleston/' ), $row->source_url );
	}

	/**
	 * Privacy request headers.
	 *
	 * @return array<string,array{string}>
	 */
	public function privacy_signal_provider() {
		return array(
			'gpc' => array( 'HTTP_SEC_GPC' ),
			'dnt' => array( 'HTTP_DNT' ),
		);
	}

	/**
	 * Known automation receives a successful no-op without any side effect.
	 */
	public function test_intentional_bot_exclusion_is_successful_no_op(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 HeadlessChrome/127.0';

		$result = extrachill_analytics_ability_track_page_view(
			array(
				'source_path'  => '/events/show/',
				'route_family' => 'singular',
				'proof'        => 'not-used-for-an-excluded-request',
				'post_id'      => 42,
			)
		);

		$this->assertSame( array( 'recorded' => false ), $result );
		$this->assertSame( 0, $this->event_count() );
		$this->assertSame( 0, did_action( 'extrachill_link_page_view_recorded' ) );
	}

	/**
	 * Singular posts retain legacy counters and artist link-page actions.
	 */
	public function test_post_backed_view_preserves_legacy_side_effects(): void {
		if ( ! post_type_exists( 'artist_link_page' ) ) {
			register_post_type( 'artist_link_page', array( 'public' => true ) );
		}
		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'example',
				'post_status' => 'publish',
				'post_type'   => 'artist_link_page',
			)
		);

		$actions = array();
		add_action(
			'extrachill_link_page_view_recorded',
			static function ( $recorded_post_id ) use ( &$actions ) {
				$actions[] = $recorded_post_id;
			}
		);

		$result = extrachill_analytics_ability_track_page_view(
			$this->with_proof(
				array(
					'post_id'      => $post_id,
					'source_path'  => '/example/',
					'route_family' => 'singular',
				)
			)
		);

		$this->assertSame( array( 'recorded' => true ), $result );
		global $wp_query;
		$this->assertSame(
			1,
			(int) get_post_meta( $post_id, 'ec_post_views', true ),
			sprintf( 'post meta view counter; raw=%s post=%d preview=%d ec_track_exists=%d', var_export( get_post_meta( $post_id, 'ec_post_views', true ), true ), $post_id, is_preview() ? 1 : 0, function_exists( 'ec_track_post_views' ) ? 1 : 0 )
		);
		unset( $wp_query );
		$this->assertSame( array( $post_id ), $actions, 'link page action payloads' );
		$this->assertSame( 1, $this->event_count(), 'event row count' );
		$row  = $this->event_rows()[0];
		$data = $this->event_data( $row );
		$this->assertSame( 'post', $data['view_kind'] );
		$this->assertSame( $post_id, $data['post_id'] );
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
