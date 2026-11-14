<?php
/**
 * REST 5xx counter tests (#1353).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once dirname( __DIR__ ) . '/inc/core/rest-5xx-counter.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-rest-5xx-summary.php';

/** REST responses >= 500 are counted by route pattern; nothing else is. */
final class Rest5xxCounterTest extends Extrachill_Analytics_TestCase {

	/** Register fixture routes on a fresh server. */
	public function set_up(): void {
		parent::set_up();
		delete_site_option( EC_ANALYTICS_REST_5XX_OPTION );
		if ( false === has_filter( 'rest_post_dispatch', 'extrachill_analytics_rest_5xx_observe' ) ) {
			add_filter( 'rest_post_dispatch', 'extrachill_analytics_rest_5xx_observe', 999, 3 );
		}
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/** Reset the server. */
	public function tear_down(): void {
		remove_action( 'rest_api_init', array( $this, 'register_routes' ) );
		delete_site_option( EC_ANALYTICS_REST_5XX_OPTION );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/** Fixture routes: one per status class. */
	public function register_routes(): void {
		$open = '__return_true';
		register_rest_route(
			'ec-test/v1',
			'/boom/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => static fn() => new WP_Error( 'ec_test_boom', 'Boom.', array( 'status' => 500 ) ),
			)
		);
		register_rest_route(
			'ec-test/v1',
			'/fine',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => static fn() => array( 'ok' => true ),
			)
		);
		register_rest_route(
			'ec-test/v1',
			'/missing',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => static fn() => new WP_Error( 'ec_test_missing', 'Missing.', array( 'status' => 404 ) ),
			)
		);
	}

	/**
	 * Dispatch exactly as WP_REST_Server::serve_request() does for HTTP requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Filtered response.
	 */
	private function serve( WP_REST_Request $request ) {
		$server = rest_get_server();
		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $server->dispatch( $request ) ), $server, $request );
	}

	/** A 500 WP_Error is counted under its route pattern, not the raw URL. */
	public function test_500_counted_by_route_pattern_and_code(): void {
		$this->serve( new WP_REST_Request( 'GET', '/ec-test/v1/boom/41' ) );
		$this->serve( new WP_REST_Request( 'GET', '/ec-test/v1/boom/42' ) );

		$summary = extrachill_analytics_rest_5xx_summarize( extrachill_analytics_rest_5xx_buckets(), 24, time() );

		$this->assertSame( 2, $summary['total'] );
		$this->assertSame( 1, $summary['distinct_signatures'] );
		$this->assertSame( '/ec-test/v1/boom/(?P<id>\d+)', $summary['top']['route'] );
		$this->assertSame( 'ec_test_boom', $summary['top']['code'] );
		$this->assertSame( 2, $summary['top']['peak_per_hour'] );
	}

	/** 200 and 404 responses are never counted. */
	public function test_200_and_404_not_counted(): void {
		$this->assertSame( 200, $this->serve( new WP_REST_Request( 'GET', '/ec-test/v1/fine' ) )->get_status() );
		$this->assertSame( 404, $this->serve( new WP_REST_Request( 'GET', '/ec-test/v1/missing' ) )->get_status() );
		$this->assertSame( 404, $this->serve( new WP_REST_Request( 'GET', '/ec-test/v1/nope' ) )->get_status() );

		$this->assertSame( array(), extrachill_analytics_rest_5xx_buckets() );
	}

	/** Distinct keys per hour are capped; excess folds into the overflow key. */
	public function test_cardinality_cap_holds(): void {
		$buckets = array();
		$now     = 1800000000;
		for ( $i = 0; $i < EC_ANALYTICS_REST_5XX_MAX_KEYS + 25; $i++ ) {
			$buckets = extrachill_analytics_rest_5xx_add( $buckets, '/r/' . $i, 'c', $now );
		}
		$hour = (int) ( floor( $now / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );

		$this->assertCount( EC_ANALYTICS_REST_5XX_MAX_KEYS, $buckets[ $hour ] );
		$this->assertSame( 26, $buckets[ $hour ][ EC_ANALYTICS_REST_5XX_OVERFLOW_KEY ] );
		$this->assertSame( EC_ANALYTICS_REST_5XX_MAX_KEYS + 25, extrachill_analytics_rest_5xx_summarize( $buckets, 1, $now )['total'] );
	}

	/** Hours past retention are pruned; the window excludes older hours. */
	public function test_retention_and_window(): void {
		$now     = 1800000000;
		$buckets = extrachill_analytics_rest_5xx_add( array(), '/old', 'c', $now - ( EC_ANALYTICS_REST_5XX_RETENTION_HOURS + 1 ) * HOUR_IN_SECONDS );
		$buckets = extrachill_analytics_rest_5xx_add( $buckets, '/day-ago', 'c', $now - 30 * HOUR_IN_SECONDS );
		$buckets = extrachill_analytics_rest_5xx_add( $buckets, '/now', 'c', $now );

		$this->assertCount( 2, $buckets );
		$last_day = extrachill_analytics_rest_5xx_summarize( $buckets, 24, $now );
		$this->assertSame( 1, $last_day['total'] );
		$this->assertSame( '/now', $last_day['top']['route'] );
		$this->assertSame( 2, extrachill_analytics_rest_5xx_summarize( $buckets, 48, $now )['total'] );
		$this->assertNull( extrachill_analytics_rest_5xx_summarize( array(), 24, $now )['top'] );
	}

	/** The abilities run route is keyed by registered ability name. */
	public function test_ability_route_includes_registered_name(): void {
		$response = new WP_REST_Response( array( 'code' => 'ability_invalid_output' ), 500 );
		$response->set_matched_route( '/wp-abilities/v1/abilities/(?P<name>[a-zA-Z0-9\-\/]+?)/run' );
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/extrachill/get-rest-5xx-summary/run' );
		$request->set_url_params( array( 'name' => 'extrachill/get-rest-5xx-summary' ) );

		$key = extrachill_analytics_rest_5xx_route_key( $response, $request );
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'extrachill/get-rest-5xx-summary' ) ) {
			$this->assertStringEndsWith( ' [extrachill/get-rest-5xx-summary]', $key );
		}
		$request->set_url_params( array( 'name' => 'made-up/' . str_repeat( 'x', 40 ) ) );
		$this->assertStringNotContainsString( 'made-up', extrachill_analytics_rest_5xx_route_key( $response, $request ) );
		$this->assertSame( 'ability_invalid_output', extrachill_analytics_rest_5xx_error_code( $response ) );
	}
}
