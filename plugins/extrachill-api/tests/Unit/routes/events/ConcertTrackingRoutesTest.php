<?php
/**
 * Tests for bounded public concert tracking REST routes.
 *
 * @package ExtraChill\API\Tests
 */

/**
 * Exercises the registered Extra Chill transport, including REST validation.
 */
class Concert_Tracking_RoutesTest extends WP_UnitTestCase {

	/** @var array<int, array<string, mixed>> */
	private $history_inputs = array();

	/** @var array<int, array<string, mixed>> */
	private $attendance_inputs = array();

	/** @var bool */
	private $registered_category = false;

	/** @var string[] */
	private $registered_abilities = array();

	/**
	 * Register controlled abilities behind the real REST adapters.
	 */
	public function set_up() {
		parent::set_up();

		$category_registry = WP_Ability_Categories_Registry::get_instance();
		if ( ! wp_has_ability_category( 'extrachill-api-tests' ) ) {
			$category_registry->register(
				'extrachill-api-tests',
				array(
					'label'       => 'Extra Chill API Tests',
					'description' => 'Controlled abilities for REST adapter tests.',
				)
			);
			$this->registered_category = true;
		}

		$this->register_test_ability(
			'extrachill/get-user-shows',
			array(
				'user_id'   => array( 'type' => 'integer' ),
				'period'    => array( 'type' => 'string' ),
				'year'      => array( 'type' => 'integer' ),
				'date_from' => array( 'type' => 'string' ),
				'date_to'   => array( 'type' => 'string' ),
				'page'      => array( 'type' => 'integer' ),
				'per_page'  => array( 'type' => 'integer' ),
			),
			array( $this, 'execute_history' )
		);
		$this->register_test_ability(
			'extrachill/get-event-attendance',
			array(
				'event_id'          => array( 'type' => 'integer' ),
				'include_attendees' => array( 'type' => 'boolean' ),
				'limit'             => array( 'type' => 'integer' ),
			),
			array( $this, 'execute_attendance' )
		);

		do_action( 'rest_api_init' );
	}

	/**
	 * Remove test registry entries.
	 */
	public function tear_down() {
		foreach ( $this->registered_abilities as $ability_name ) {
			wp_unregister_ability( $ability_name );
		}
		if ( $this->registered_category ) {
			wp_unregister_ability_category( 'extrachill-api-tests' );
		}

		parent::tear_down();
	}

	/**
	 * Route schemas mirror the canonical Users bounds without coercive sanitizers.
	 */
	public function test_route_schemas_mirror_canonical_bounds() {
		$routes = rest_get_server()->get_routes();
		$history_schema = $routes['/extrachill/v1/concert-tracking/user/(?P<user_id>\d+)/shows'][0]['args']['per_page'];
		$attendee_schema = $routes['/extrachill/v1/concert-tracking/event/(?P<event_id>\d+)'][0]['args']['limit'];

		$this->assertSame( array( 1, 20, 100 ), array( $history_schema['minimum'], $history_schema['default'], $history_schema['maximum'] ) );
		$this->assertSame( array( 1, 10, 100 ), array( $attendee_schema['minimum'], $attendee_schema['default'], $attendee_schema['maximum'] ) );
		$this->assertArrayNotHasKey( 'sanitize_callback', $history_schema );
		$this->assertArrayNotHasKey( 'sanitize_callback', $attendee_schema );
	}

	/**
	 * Defaults and both valid boundaries reach the canonical history ability unchanged.
	 */
	public function test_history_route_forwards_default_minimum_and_maximum() {
		foreach ( array( null, 1, 100 ) as $per_page ) {
			$params = null === $per_page ? array() : array( 'per_page' => $per_page );
			$response = $this->dispatch( '/extrachill/v1/concert-tracking/user/7/shows', $params );

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $per_page ?? 20, $response->get_data()['per_page'] );
		}
	}

	/**
	 * Defaults and both valid boundaries reach the canonical attendee ability unchanged.
	 */
	public function test_attendance_route_forwards_default_minimum_and_maximum() {
		foreach ( array( null, 1, 100 ) as $limit ) {
			$params = array( 'include_attendees' => true );
			if ( null !== $limit ) {
				$params['limit'] = $limit;
			}
			$response = $this->dispatch( '/extrachill/v1/concert-tracking/event/42', $params );

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $limit ?? 10, $response->get_data()['limit'] );
		}
	}

	/**
	 * Invalid public pagination never reaches the history ability.
	 *
	 * @dataProvider invalid_bound_provider
	 * @param mixed $value Invalid request value.
	 */
	public function test_history_route_rejects_invalid_values_before_ability( $value ) {
		$calls_before = count( $this->history_inputs );
		$response = $this->dispatch(
			'/extrachill/v1/concert-tracking/user/7/shows',
			array( 'per_page' => $value )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertCount( $calls_before, $this->history_inputs );
	}

	/**
	 * Invalid public attendee limits never reach the attendance ability.
	 *
	 * @dataProvider invalid_bound_provider
	 * @param mixed $value Invalid request value.
	 */
	public function test_attendance_route_rejects_invalid_values_before_ability( $value ) {
		$calls_before = count( $this->attendance_inputs );
		$response = $this->dispatch(
			'/extrachill/v1/concert-tracking/event/42',
			array(
				'include_attendees' => true,
				'limit'             => $value,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertCount( $calls_before, $this->attendance_inputs );
	}

	/**
	 * Invalid values shared by both public bounded parameters.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function invalid_bound_provider() {
		return array(
			'negative'       => array( -5 ),
			'zero'           => array( 0 ),
			'nonnumeric'     => array( 'all' ),
			'over limit'     => array( 101 ),
			'realistic huge' => array( PHP_INT_MAX ),
		);
	}

	/**
	 * Controlled history ability callback.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute_history( array $input ) {
		$this->history_inputs[] = $input;

		return array(
			'shows'    => array(),
			'total'    => 0,
			'pages'    => 0,
			'page'     => $input['page'],
			'per_page' => $input['per_page'],
		);
	}

	/**
	 * Controlled attendance ability callback.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute_attendance( array $input ) {
		$this->attendance_inputs[] = $input;

		return array(
			'count'       => 0,
			'count_label' => '0 were there',
			'timing'      => 'past',
			'user_marked' => false,
			'attendees'   => array(),
			'limit'       => $input['limit'],
		);
	}

	/**
	 * Register a controlled ability directly in the initialized test registry.
	 *
	 * @param string   $name Ability name.
	 * @param array    $properties Input properties.
	 * @param callable $callback Execute callback.
	 */
	private function register_test_ability( $name, array $properties, $callback ) {
		if ( wp_has_ability( $name ) ) {
			$this->fail( 'Unexpected ability dependency already registered: ' . $name );
		}

		$ability = WP_Abilities_Registry::get_instance()->register(
			$name,
			array(
				'label'               => 'Test ' . $name,
				'description'         => 'Controlled ability for API transport tests.',
				'category'            => 'extrachill-api-tests',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
				),
				'execute_callback'    => $callback,
				'permission_callback' => '__return_true',
			)
		);

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->registered_abilities[] = $name;
	}

	/**
	 * Dispatch a GET request through the actual Extra Chill REST namespace.
	 *
	 * @param string $route Route path.
	 * @param array  $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function dispatch( $route, array $params ) {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );

		return rest_do_request( $request );
	}
}
