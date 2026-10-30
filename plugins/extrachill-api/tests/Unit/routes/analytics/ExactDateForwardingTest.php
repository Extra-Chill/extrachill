<?php
/**
 * Exact analytics date-window adapter contracts.
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the four handlers through controlled ability doubles. */
final class ExactDateForwardingTest extends WP_UnitTestCase {

	/** @var array<string, array<string, mixed>> */
	private $ability_inputs = array();

	/** Install controlled analytics abilities. */
	public function set_up() {
		parent::set_up();
		$this->ability_inputs = array();

		if ( ! wp_has_ability_category( 'analytics-date-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'analytics-date-tests',
				array(
					'label'       => 'Analytics date tests',
					'description' => 'Controlled analytics adapter contracts.',
				)
			);
		}

		foreach ( $this->ability_names() as $ability_name ) {
			$this->register_ability( $ability_name );
		}
	}

	/** Remove test-owned ability state. */
	public function tear_down() {
		foreach ( $this->ability_names() as $ability_name ) {
			if ( isset( wp_get_abilities()[ $ability_name ] ) ) {
				wp_unregister_ability( $ability_name );
			}
		}

		if ( wp_has_ability_category( 'analytics-date-tests' ) ) {
			wp_unregister_ability_category( 'analytics-date-tests' );
		}

		parent::tear_down();
	}

	/** Exact dates join the existing surface-growth window unchanged. */
	public function test_surface_growth_forwards_exact_dates_and_weeks() {
		$request = $this->request(
			array(
				'weeks'      => 6,
				'start_date' => '2026-05-03',
				'end_date'   => '2026-06-14',
			)
		);

		extrachill_api_analytics_surface_growth_handler( $request );

		$this->assertSame( $request->get_params(), $this->ability_inputs['extrachill/get-surface-growth'] );
	}

	/** Exact dates preserve site and cohort controls on retention. */
	public function test_retention_forwards_exact_dates_and_legacy_controls() {
		$request = $this->request(
			array(
				'days'         => 45,
				'blog_id'      => 7,
				'cohort_weeks' => 12,
				'start_date'   => '2026-04-01',
				'end_date'     => '2026-05-15',
			)
		);

		extrachill_api_analytics_retention_handler( $request );

		$this->assertSame( $request->get_params(), $this->ability_inputs['extrachill/get-retention-stats'] );
	}

	/** Exact dates preserve every conversion report control. */
	public function test_conversion_map_forwards_exact_dates_and_legacy_controls() {
		$request = $this->request(
			array(
				'days'               => 60,
				'session_gap_mins'   => 20,
				'top_articles'       => 40,
				'min_entry_sessions' => 3,
				'author_id'          => 81,
				'start_date'         => '2026-03-02',
				'end_date'           => '2026-04-30',
			)
		);

		extrachill_api_analytics_conversion_map_handler( $request );

		$this->assertSame( $request->get_params(), $this->ability_inputs['extrachill/get-conversion-map'] );
	}

	/** Exact dates preserve artist identity and the legacy date range. */
	public function test_artist_analytics_forwards_exact_dates_and_legacy_controls() {
		$request = $this->request(
			array(
				'id'         => 123,
				'date_range' => 90,
				'start_date' => '2026-01-01',
				'end_date'   => '2026-03-31',
			)
		);

		extrachill_api_artist_analytics_handler( $request );

		$this->assertSame( $request->get_params(), $this->ability_inputs['extrachill/artist-get-analytics'] );
	}

	/** Omitting exact dates leaves the legacy payload unchanged. */
	public function test_exact_dates_remain_optional() {
		$request = $this->request( array( 'weeks' => 4 ) );

		extrachill_api_analytics_surface_growth_handler( $request );

		$this->assertSame( array( 'weeks' => 4 ), $this->ability_inputs['extrachill/get-surface-growth'] );
	}

	/** Route schemas expose optional strings without adapter sanitization. */
	public function test_route_schemas_expose_unsanitized_optional_date_strings() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$paths  = array(
			'/extrachill/v1/analytics/surface-growth',
			'/extrachill/v1/analytics/retention',
			'/extrachill/v1/analytics/conversion-map',
			'/extrachill/v1/artists/(?P<id>\d+)/analytics',
		);

		foreach ( $paths as $path ) {
			foreach ( array( 'start_date', 'end_date' ) as $date_param ) {
				$schema = $routes[ $path ][0]['args'][ $date_param ];
				$this->assertSame( 'string', $schema['type'] );
				$this->assertFalse( $schema['required'] );
				$this->assertArrayNotHasKey( 'default', $schema );
				$this->assertArrayNotHasKey( 'sanitize_callback', $schema );
			}
		}
	}

	/** Build a read request with the supplied route parameters. */
	private function request( $params ) {
		$request = new WP_REST_Request( 'GET' );
		$request->set_query_params( $params );

		return $request;
	}

	/** Return all ability names wrapped by the handlers under test. */
	private function ability_names() {
		return array(
			'extrachill/get-surface-growth',
			'extrachill/get-retention-stats',
			'extrachill/get-conversion-map',
			'extrachill/artist-get-analytics',
		);
	}

	/** Register an ability that records its exact input payload. */
	private function register_ability( $ability_name ) {
		if ( isset( wp_get_abilities()[ $ability_name ] ) ) {
			wp_unregister_ability( $ability_name );
		}

		$test = $this;
		WP_Abilities_Registry::get_instance()->register(
			$ability_name,
			array(
				'label'               => 'Capture analytics input',
				'description'         => 'Records exact API adapter input.',
				'category'            => 'analytics-date-tests',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $this->ability_input_properties( $ability_name ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) use ( $test, $ability_name ) {
					$test->ability_inputs[ $ability_name ] = $input;
					return array( 'captured' => true );
				},
			)
		);
	}

	/** Return a closed schema matching each production ability adapter. */
	private function ability_input_properties( $ability_name ) {
		$string = array( 'type' => 'string' );
		$int    = array( 'type' => 'integer' );

		$properties = array(
			'extrachill/get-surface-growth'   => array(
				'weeks'      => $int,
				'start_date' => $string,
				'end_date'   => $string,
			),
			'extrachill/get-retention-stats'  => array(
				'days'         => $int,
				'blog_id'      => $int,
				'cohort_weeks' => $int,
				'start_date'   => $string,
				'end_date'     => $string,
			),
			'extrachill/get-conversion-map'   => array(
				'days'               => $int,
				'session_gap_mins'   => $int,
				'top_articles'       => $int,
				'min_entry_sessions' => $int,
				'author_id'          => $int,
				'start_date'         => $string,
				'end_date'           => $string,
			),
			'extrachill/artist-get-analytics' => array(
				'id'         => $int,
				'date_range' => $int,
				'start_date' => $string,
				'end_date'   => $string,
			),
		);

		return $properties[ $ability_name ];
	}
}
