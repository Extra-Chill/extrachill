<?php
/**
 * Tests for the keyed partner events endpoint (Extra-Chill/extrachill#1802).
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the transport contract through a controlled ability double. */
class Partner_EventsTest extends WP_UnitTestCase {

	private const CATEGORY = 'partner-events-tests';

	/** @var array<int, array<string, mixed>> */
	public $ability_inputs = array();

	/** @var mixed */
	public $ability_result;

	/** @var array<string, int> */
	private $rate_counts = array();

	/** Reset controlled state and install the deterministic rate-limit store. */
	public function set_up() {
		parent::set_up();
		$this->ability_inputs   = array();
		$this->rate_counts      = array();
		$this->ability_result   = array(
			'venue'        => array(
				'id'                => 1524,
				'name'              => 'Lo-Fi Brewing',
				'slug'              => 'lo-fi-brewing',
				'url'               => 'https://events.extrachill.com/venue/lo-fi-brewing/',
				'booking_embed_url' => null,
			),
			'events'       => array(),
			'generated_at' => '2026-12-01T12:00:00+00:00',
			'cache_ttl'    => 300,
		);
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int( 1, 250 );
		add_filter( 'extrachill_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );
	}

	/** Remove controlled fixtures. */
	public function tear_down() {
		remove_filter( 'extrachill_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );
		if ( wp_has_ability( EXTRACHILL_API_PARTNER_EVENTS_ABILITY ) ) {
			wp_unregister_ability( EXTRACHILL_API_PARTNER_EVENTS_ABILITY );
		}
		if ( wp_has_ability_category( self::CATEGORY ) ) {
			wp_unregister_ability_category( self::CATEGORY );
		}
		parent::tear_down();
	}

	/** Public GET route, 1-50 limit defaulting to 20, events-site affinity. */
	public function test_route_registration_and_events_affinity() {
		do_action( 'rest_api_init' );
		$route = rest_get_server()->get_routes()['/extrachill/v1/partner/events'][0];

		$this->assertSame( '__return_true', $route['permission_callback'] );
		$this->assertArrayHasKey( 'GET', $route['methods'] );
		$this->assertSame( 1, $route['args']['limit']['minimum'] );
		$this->assertSame( 50, $route['args']['limit']['maximum'] );
		$this->assertSame( 20, $route['args']['limit']['default'] );
		$this->assertSame( array( EXTRACHILL_API_PARTNER_EVENTS_ABILITY ), $route['_extrachill_abilities'] );
		$this->assertSame( 'events', ec_get_route_site_affinity( '/extrachill/v1/partner/events' ) );
	}

	/** The header key and limit reach the ability; the body is the ability document. */
	public function test_success_passes_header_key_and_sets_private_cache() {
		$this->register_ability();
		$request = $this->request( 'ecpk_1524_' . str_repeat( 'a', 64 ), 7 );

		$response = extrachill_api_handle_partner_events( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->ability_result, $response->get_data() );
		$this->assertSame(
			array(
				'partner_key' => 'ecpk_1524_' . str_repeat( 'a', 64 ),
				'limit'       => 7,
			),
			$this->ability_inputs[0]
		);
		$headers = $response->get_headers();
		$this->assertSame( 'private, max-age=300', $headers['Cache-Control'] );
		$this->assertSame( 'X-Extrachill-Partner-Key', $headers['Vary'] );
	}

	/** Missing header never reaches the ability and is the contract 401. */
	public function test_missing_key_is_401() {
		$this->register_ability();
		$response = extrachill_api_handle_partner_events( $this->request( '', 20 ) );

		$this->assert_invalid_key( $response );
		$this->assertEmpty( $this->ability_inputs );
	}

	/** Every key failure from the domain collapses to the one 401 shape. */
	public function test_ability_key_error_is_401() {
		$this->ability_result = new WP_Error( 'extrachill_partner_key_invalid', 'Invalid partner key.', array( 'status' => 401 ) );
		$this->register_ability();

		$this->assert_invalid_key( extrachill_api_handle_partner_events( $this->request( 'revoked', 20 ) ) );
	}

	/** A key in the query string is not a credential. */
	public function test_query_string_key_is_ignored() {
		$this->register_ability();
		$request = $this->request( '', 20 );
		$request->set_query_params( array( 'partner_key' => 'ecpk_1524_' . str_repeat( 'a', 64 ) ) );

		$this->assert_invalid_key( extrachill_api_handle_partner_events( $request ) );
	}

	/** The route schema rejects an out-of-range limit before the handler runs. */
	public function test_invalid_limit_fails_route_schema() {
		do_action( 'rest_api_init' );
		$schema = rest_get_server()->get_routes()['/extrachill/v1/partner/events'][0]['args']['limit'];

		foreach ( array( 0, 51, 'abc' ) as $limit ) {
			$this->assertWPError( rest_validate_value_from_schema( $limit, $schema, 'limit' ), (string) $limit );
		}
		$this->assertTrue( rest_validate_value_from_schema( 50, $schema, 'limit' ) );
	}

	/** A missing domain ability is a 503, not a 401 or a fatal. */
	public function test_missing_ability_is_503() {
		$response = extrachill_api_handle_partner_events( $this->request( 'ecpk_1524_' . str_repeat( 'a', 64 ), 20 ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 503, $response->get_error_data()['status'] );
	}

	/** The partner key survives the signed affinity hop to the events site. */
	public function test_partner_key_is_forwarded_and_signed_across_affinity() {
		$request = $this->request( 'ecpk_1524_' . str_repeat( 'b', 64 ), 20 );
		$headers = extrachill_api_route_affinity_forwarded_headers( $request, true );
		$this->assertSame( 'ecpk_1524_' . str_repeat( 'b', 64 ), $headers['x-extrachill-partner-key'] );

		$other = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
		$other->set_header( 'X-Extrachill-Partner-Key', 'leak' );
		$this->assertArrayNotHasKey( 'x-extrachill-partner-key', extrachill_api_route_affinity_forwarded_headers( $other, true ) );
	}

	/** Assert the single 401 contract. */
	private function assert_invalid_key( $response ) {
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'extrachill_partner_key_invalid', $response->get_error_code() );
		$this->assertSame( 401, $response->get_error_data()['status'] );
	}

	/** Build a GET request with an optional key header. */
	private function request( $key, $limit ) {
		$request = new WP_REST_Request( 'GET', '/extrachill/v1/partner/events' );
		if ( '' !== $key ) {
			$request->set_header( 'X-Extrachill-Partner-Key', $key );
		}
		$request->set_param( 'limit', $limit );
		return $request;
	}

	/** Register a controlled double of extrachill/get-partner-venue-events. */
	private function register_ability() {
		if ( wp_has_ability( EXTRACHILL_API_PARTNER_EVENTS_ABILITY ) ) {
			wp_unregister_ability( EXTRACHILL_API_PARTNER_EVENTS_ABILITY );
		}
		if ( ! wp_has_ability_category( self::CATEGORY ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				self::CATEGORY,
				array(
					'label'       => 'Partner events tests',
					'description' => 'Controlled partner events contract.',
				)
			);
		}
		$test = $this;
		WP_Abilities_Registry::get_instance()->register(
			EXTRACHILL_API_PARTNER_EVENTS_ABILITY,
			array(
				'label'               => 'Get partner venue events',
				'description'         => 'Controlled partner events double.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'partner_key' => array( 'type' => 'string' ),
						'limit'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
					'required'             => array( 'partner_key' ),
					'additionalProperties' => false,
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) use ( $test ) {
					$test->ability_inputs[] = $input;
					return $test->ability_result;
				},
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	/** Return the deterministic store callback. */
	public function use_test_rate_limit_store() {
		return array( $this, 'increment_test_rate_limit' );
	}

	/** Increment one deterministic counter. */
	public function increment_test_rate_limit( $key ) {
		$this->rate_counts[ $key ] = ( $this->rate_counts[ $key ] ?? 0 ) + 1;
		return $this->rate_counts[ $key ];
	}
}
