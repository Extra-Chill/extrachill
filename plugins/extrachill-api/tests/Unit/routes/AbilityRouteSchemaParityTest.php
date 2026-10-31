<?php
/**
 * REST-to-ability input schema parity contracts.
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the repository-local parity checker with controlled abilities. */
final class AbilityRouteSchemaParityTest extends WP_UnitTestCase {

	/** @var string[] */
	private $ability_names = array();

	/** @var array<string, mixed>|null */
	private $captured_input;

	/** Register the controlled ability category. */
	public function set_up() {
		parent::set_up();
		$this->captured_input = null;
		if ( ! wp_has_ability_category( 'api-schema-parity-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'api-schema-parity-tests',
				array(
					'label'       => 'API schema parity tests',
					'description' => 'Controlled route adapter schemas.',
				)
			);
		}
	}

	/** Remove controlled abilities and their category. */
	public function tear_down() {
		foreach ( $this->ability_names as $ability_name ) {
			if ( wp_has_ability( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
		}
		if ( wp_has_ability_category( 'api-schema-parity-tests' ) ) {
			wp_unregister_ability_category( 'api-schema-parity-tests' );
		}
		parent::tear_down();
	}

	/** Exact schemas include required fields, defaults, enums, bounds, and closed objects. */
	public function test_exact_parity_has_no_findings() {
		$schema = $this->schema();
		$route  = $this->route_from_schema( $schema );

		$this->assertSame( array(), $this->findings( 'exact', $route, $schema ) );
	}

	/** Missing and extra arguments are reported independently. */
	public function test_missing_and_extra_args_are_reported() {
		$schema = $this->schema();
		$route  = $this->route_from_schema( $schema );
		unset( $route['args']['mode'] );
		$route['args']['nonce'] = array( 'type' => 'string' );

		$this->assertSame(
			array( 'missing_route_arg:mode', 'extra_route_arg:nonce' ),
			$this->finding_codes( $this->findings( 'names', $route, $schema ) )
		);
	}

	/** Constraint drift includes nested schemas, enums, bounds, required, and defaults. */
	public function test_changed_constraints_are_reported() {
		$schema = $this->schema();
		$route  = $this->route_from_schema( $schema );
		$route['args']['mode']['enum']       = array( 'summary' );
		$route['args']['limit']['maximum']   = 200;
		$route['args']['limit']['default']   = 20;
		$route['args']['limit']['required']  = false;
		$route['args']['filters']['properties']['tag']['maxLength'] = 80;

		$paths = array_column( $this->findings( 'constraints', $route, $schema ), 'path' );
		$this->assertContains( 'mode.enum.1', $paths );
		$this->assertContains( 'limit.maximum', $paths );
		$this->assertContains( 'limit.default', $paths );
		$this->assertContains( 'limit.required', $paths );
		$this->assertContains( 'filters.properties.tag.maxLength', $paths );
	}

	/** Method and additionalProperties drift are first-class findings. */
	public function test_methods_and_additional_properties_are_reported() {
		$schema = $this->schema();
		$route  = $this->route_from_schema( $schema );
		$route['methods'] = array( 'POST' => true );

		$findings = $this->findings(
			'methods',
			$route,
			$schema,
			array(
				'methods'             => array( 'GET' ),
				'additionalProperties' => true,
			)
		);

		$this->assertSame(
			array( 'method_mismatch:POST', 'additional_properties_mismatch:additionalProperties' ),
			$this->finding_codes( $findings )
		);
	}

	/** Invalid and incomplete ability object schemas cannot masquerade as parity. */
	public function test_malformed_schemas_are_reported() {
		$schemas = array(
			array( 'type' => 'array', 'additionalProperties' => false ),
			array( 'type' => 'object', 'additionalProperties' => 'invalid' ),
			array( 'type' => 'object', 'properties' => 'invalid', 'additionalProperties' => false ),
			array( 'type' => 'object', 'required' => 'name', 'additionalProperties' => false ),
		);

		foreach ( $schemas as $index => $schema ) {
			$this->assertSame(
				array( 'malformed_schema:input_schema' ),
				$this->finding_codes( $this->findings( 'malformed-' . $index, $this->endpoint(), $schema ) )
			);
		}
	}

	/** An absent backing ability is reported explicitly. */
	public function test_absent_ability_is_reported() {
		$contract = $this->contract( 'absent' );

		$this->assertSame(
			array( 'absent_ability:extrachill/parity-absent' ),
			$this->finding_codes( extrachill_api_rest_ability_schema_findings( $this->endpoint(), null, $contract ) )
		);
	}

	/** Route affinity resolves existing owner contracts plus API-owned exact routes. */
	public function test_route_site_affinity_resolution() {
		$this->assertSame( 'community', extrachill_api_rest_ability_route_site( '/extrachill/v1/community/topics' ) );
		$this->assertSame( 'artist', extrachill_api_rest_ability_route_site( '/extrachill/v1/artists' ) );
		$this->assertSame( 'events', extrachill_api_rest_ability_route_site( '/extrachill/v1/event-submissions' ) );
		$this->assertSame( 'studio', extrachill_api_rest_ability_route_site( '/extrachill/v1/giveaway/run' ) );
		$this->assertSame( 'main', extrachill_api_rest_ability_route_site( '/extrachill/v1/users/me' ) );
	}

	/** An absent ability is expected only when concrete affinity names another site. */
	public function test_affinity_audit_classifies_expected_absence() {
		$routes = $this->registered_route_fixture( '/extrachill/v1/community/parity-absent', 'extrachill/parity-absent' );

		$report = extrachill_api_rest_ability_adapter_audit( $routes, 'main' );

		$this->assertSame( 'expected_absence', $report[0]['code'] );
		$this->assertSame( 'main', $report[0]['site'] );
		$this->assertSame( 'community', $report[0]['owner_site'] );
		$this->assertStringContainsString( 'affinity contract', $report[0]['reason'] );
	}

	/** Explicit audit contexts cannot claim a runtime that was not bootstrapped. */
	public function test_affinity_audit_rejects_wrong_site_execution() {
		$report = extrachill_api_rest_ability_adapter_audit( array(), 'community' );

		$this->assertSame( 'wrong_site_context', $report[0]['code'] );
		$this->assertSame( 'community', $report[0]['owner_site'] );
	}

	/** Malformed affinity metadata remains an unexplained finding. */
	public function test_affinity_audit_reports_malformed_metadata() {
		$filter = static function ( $contract ) {
			$contract['site'] = 'not-configured';
			return $contract;
		};
		add_filter( 'extrachill_api_rest_ability_adapter_contract', $filter );
		$routes = $this->registered_route_fixture( '/extrachill/v1/parity-malformed-affinity', 'extrachill/parity-absent' );

		$report = extrachill_api_rest_ability_adapter_audit( $routes, 'main' );
		remove_filter( 'extrachill_api_rest_ability_adapter_contract', $filter );

		$this->assertSame( 'malformed_affinity', $report[0]['code'] );
		$this->assertSame( 'not-configured', $report[0]['owner_site'] );
	}

	/** Legitimate transport metadata requires a field-level documented exception. */
	public function test_documented_transport_metadata_is_allowed() {
		$schema = $this->schema();
		$route  = $this->route_from_schema( $schema );
		$route['args']['turnstile_response'] = array( 'type' => 'string' );

		$this->assertSame(
			array(),
			$this->findings(
				'transport',
				$route,
				$schema,
				array(
					'exceptions' => array(
						'transport_only' => array(
							'turnstile_response' => 'Consumed by public-write admission before ability execution.',
						),
					),
				)
			)
		);
	}

	/** Empty exception reasons are rejected instead of silently suppressing drift. */
	public function test_undocumented_exception_is_reported() {
		$schema = $this->schema();
		$route  = $this->route_from_schema( $schema );

		$findings = $this->findings(
			'exception',
			$route,
			$schema,
			array( 'exceptions' => array( 'constraints' => array( 'limit.maximum' => '' ) ) )
		);

		$this->assertSame( 'malformed_exception', $findings[0]['code'] );
	}

	/** The production upcoming-counts route exposes the complete current ability contract. */
	public function test_upcoming_counts_route_matches_current_ability_schema() {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'      => array( 'type' => 'string', 'enum' => array( 'venue', 'location', 'artist', 'festival' ) ),
				'slug'          => array( 'type' => array( 'string', 'null' ) ),
				'location_slug' => array( 'type' => array( 'string', 'null' ) ),
				'limit'         => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0 ),
				'rollup'        => array( 'type' => 'boolean', 'default' => false ),
			),
			'required'             => array( 'taxonomy' ),
			'additionalProperties' => false,
		);
		$this->register_ability( 'extrachill/parity-upcoming-counts', $schema );
		do_action( 'rest_api_init' );
		$route = rest_get_server()->get_routes()['/extrachill/v1/events/upcoming-counts'][0];

		$this->assertSame(
			array(),
			extrachill_api_rest_ability_schema_findings(
				$route,
				wp_get_ability( 'extrachill/parity-upcoming-counts' ),
				array(
					'route'                => '/extrachill/v1/events/upcoming-counts',
					'ability'              => 'extrachill/parity-upcoming-counts',
					'methods'              => array( 'GET' ),
					'additionalProperties' => false,
				)
			)
		);
	}

	/** The route forwards newly added optional ability fields instead of silently dropping them. */
	public function test_upcoming_counts_forwards_rollup() {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array( 'type' => 'string' ),
				'limit'    => array( 'type' => 'integer' ),
				'rollup'   => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'taxonomy' ),
			'additionalProperties' => false,
		);
		$this->register_ability( 'extrachill/events-upcoming-counts', $schema, true );
		$request = new WP_REST_Request( 'GET' );
		$request->set_query_params( array( 'taxonomy' => 'location', 'limit' => 25, 'rollup' => true ) );

		$response = extrachill_api_events_upcoming_counts_handler( $request );

		$this->assertSame( array( 'taxonomy' => 'location', 'limit' => 25, 'rollup' => true ), $this->captured_input );
		$this->assertSame( array( 'captured' => true ), $response->get_data() );
	}

	/** Omitting rollup preserves the ability's existing leaf-count behavior. */
	public function test_upcoming_counts_defaults_rollup_to_false() {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array( 'type' => 'string' ),
				'limit'    => array( 'type' => 'integer' ),
				'rollup'   => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'taxonomy' ),
			'additionalProperties' => false,
		);
		$this->register_ability( 'extrachill/events-upcoming-counts', $schema, true );
		$request = new WP_REST_Request( 'GET' );
		$request->set_query_params( array( 'taxonomy' => 'location', 'limit' => 25 ) );

		$response = extrachill_api_events_upcoming_counts_handler( $request );

		$this->assertSame( array( 'taxonomy' => 'location', 'limit' => 25, 'rollup' => false ), $this->captured_input );
		$this->assertSame( array( 'captured' => true ), $response->get_data() );
	}

	/** Every executing API route is represented, including dynamic ability constants. */
	public function test_manifest_covers_all_ability_executing_route_callbacks() {
		do_action( 'rest_api_init' );
		$routes   = rest_get_server()->get_routes( 'extrachill/v1' );
		$manifest = extrachill_api_rest_ability_adapter_manifest( $routes );
		$covered  = array_unique( array_column( array_column( $manifest, 'contract' ), 'callback' ) );
		$expected = array();

		foreach ( $routes as $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				$callback = $endpoint['callback'] ?? null;
				if ( ! is_string( $callback ) || ! function_exists( $callback ) ) {
					continue;
				}
				$reflection = new ReflectionFunction( $callback );
				$file       = wp_normalize_path( (string) $reflection->getFileName() );
				if ( ! str_starts_with( $file, wp_normalize_path( EXTRACHILL_API_PATH . 'inc/routes/' ) ) ) {
					continue;
				}
				$lines  = file( $file );
				$source = implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
				if ( str_contains( $source, '->execute(' ) ) {
					$expected[] = $callback;
				}
			}
		}

		$this->assertNotEmpty( $expected );
		$this->assertEmpty( array_diff( array_unique( $expected ), $covered ) );
		$this->assertContains( 'extrachill_api_handle_booking_inquiry', $covered );
		$this->assertContains( 'extrachill_api_handle_booking_availability', $covered );
	}

	/** Build findings for one controlled ability. */
	private function findings( $slug, array $route, array $schema, array $overrides = array() ) {
		$name = 'extrachill/parity-' . $slug;
		$this->register_ability( $name, $schema );
		$contract = array_replace_recursive( $this->contract( $slug ), $overrides );

		return extrachill_api_rest_ability_schema_findings( $route, wp_get_ability( $name ), $contract );
	}

	/** Register one controlled ability. */
	private function register_ability( $name, array $schema, $capture = false ) {
		if ( wp_has_ability( $name ) ) {
			wp_unregister_ability( $name );
		}
		$this->ability_names[] = $name;
		$test = $this;
		WP_Abilities_Registry::get_instance()->register(
			$name,
			array(
				'label'               => 'Parity fixture',
				'description'         => 'Controlled schema parity fixture.',
				'category'            => 'api-schema-parity-tests',
				'input_schema'        => $schema,
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input = null ) use ( $capture, $test ) {
					if ( $capture ) {
						$test->captured_input = $input;
					}
					return array( 'captured' => true );
				},
			)
		);
	}

	/** Return the complete fixture ability schema. */
	private function schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'mode'    => array( 'type' => 'string', 'enum' => array( 'summary', 'detail' ) ),
				'limit'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
				'filters' => array(
					'type'                 => 'object',
					'properties'           => array( 'tag' => array( 'type' => 'string', 'maxLength' => 40 ) ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'mode', 'limit' ),
			'additionalProperties' => false,
		);
	}

	/** Project a fixture schema through the core primitive. */
	private function route_from_schema( array $schema ) {
		foreach ( $schema['required'] as $required ) {
			$schema['properties'][ $required ]['required'] = true;
		}

		return array(
			'methods' => array( 'GET' => true ),
			'args'    => rest_get_endpoint_args_for_schema( $schema, WP_REST_Server::CREATABLE ),
		);
	}

	/** Return a minimal endpoint. */
	private function endpoint() {
		return array( 'methods' => array( 'GET' => true ), 'args' => array() );
	}

	/** Return a route fixture discoverable through an API-owned callback. */
	private function registered_route_fixture( $route, $ability ) {
		return array(
			$route => array(
				array(
					'methods'                => array( 'GET' => true ),
					'callback'               => 'extrachill_api_events_upcoming_counts_handler',
					'args'                   => array(),
					'_extrachill_abilities' => array( $ability ),
				),
			),
		);
	}

	/** Return a default adapter contract. */
	private function contract( $slug ) {
		return array(
			'route'                => '/extrachill/v1/parity-' . $slug,
			'ability'              => 'extrachill/parity-' . $slug,
			'methods'              => array( 'GET' ),
			'additionalProperties' => false,
		);
	}

	/** Flatten finding code and path for exact assertions. */
	private function finding_codes( array $findings ) {
		return array_map( static fn( $finding ) => $finding['code'] . ':' . $finding['path'], $findings );
	}
}
