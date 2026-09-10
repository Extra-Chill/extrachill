<?php
/**
 * Tests for the Ticketmaster-compliance ticket redirect endpoint.
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises transport, the three-tier bot gate, and rate limiting through a controlled ability double. */
class Ticket_RedirectTest extends WP_UnitTestCase {

	/** @var array<int, array<string, mixed>> */
	private $ability_inputs = array();

	/** @var mixed */
	private $ability_result = array(
		'url'          => 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7JFo-',
		'is_affiliate' => true,
	);

	/** @var array<string, int> */
	private $rate_counts = array();

	/** Reset controlled state and install the deterministic rate-limit store. */
	public function set_up() {
		parent::set_up();
		$this->ability_inputs   = array();
		$this->rate_counts      = array();
		$this->ability_result   = array(
			'url'          => 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7JFo-',
			'is_affiliate' => true,
		);
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int( 1, 250 );
		add_filter( 'extrachill_api_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );
	}

	/** Remove controlled fixtures and global state. */
	public function tear_down() {
		remove_all_filters( 'extrachill_api_ticket_redirect_rate_limit' );
		remove_filter( 'extrachill_api_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );
		if ( wp_has_ability( EXTRACHILL_API_TICKET_REDIRECT_ABILITY ) ) {
			wp_unregister_ability( EXTRACHILL_API_TICKET_REDIRECT_ABILITY );
		}
		if ( wp_has_ability_category( 'ticket-redirect-tests' ) ) {
			wp_unregister_ability_category( 'ticket-redirect-tests' );
		}
		$this->clear_ambiguous_counters();
		parent::tear_down();
	}

	/** The public GET route registers with __return_true and the shared events affinity. */
	public function test_route_registration_and_events_affinity() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$route  = $routes['/extrachill/v1/events/tickets/(?P<id>\d+)/go'][0];

		$this->assertSame( '__return_true', $route['permission_callback'] );
		$this->assertContains( WP_REST_Server::READABLE, $this->normalize_methods( $route['methods'] ) );
		$this->assertSame( 'events', ec_get_route_site_affinity( '/extrachill/v1/events/tickets/42/go' ) );
	}

	/** same-origin and same-site are admitted by the primary Sec-Fetch-Site gate. */
	public function test_sec_fetch_site_same_origin_and_same_site_redirect() {
		$this->register_ability( false );

		foreach ( array( 'same-origin', 'same-site' ) as $value ) {
			$request = $this->valid_request();
			$request->set_header( 'Sec-Fetch-Site', $value );
			$response = extrachill_api_handle_ticket_redirect( $request );

			$this->assert_successful_redirect( $response );
		}
	}

	/** cross-site and none are rejected outright by the primary gate, Referer notwithstanding. */
	public function test_sec_fetch_site_cross_site_and_none_are_blocked() {
		$this->register_ability( false );

		foreach ( array( 'cross-site', 'none' ) as $value ) {
			$request = $this->valid_request();
			$request->set_header( 'Sec-Fetch-Site', $value );
			$request->set_header( 'Referer', 'https://events.extrachill.com/events/some-show/' );
			$response = extrachill_api_handle_ticket_redirect( $request );

			$this->assert_not_found( $response );
		}
		$this->assertEmpty( $this->ability_inputs );
	}

	/** When Sec-Fetch-Site is entirely absent, an on-network Referer is admitted. */
	public function test_missing_sec_fetch_site_with_network_referer_redirects() {
		$this->register_ability( false );
		$request = $this->valid_request();
		$request->set_header( 'Referer', 'https://community.extrachill.com/t/some-thread/' );

		$response = extrachill_api_handle_ticket_redirect( $request );

		$this->assert_successful_redirect( $response );
	}

	/** When Sec-Fetch-Site is absent, an off-network Referer is rejected (not the ambiguous bucket). */
	public function test_missing_sec_fetch_site_with_off_network_referer_is_blocked() {
		$this->register_ability( false );
		$request = $this->valid_request();
		$request->set_header( 'Referer', 'https://scraper.example.com/' );

		$response = extrachill_api_handle_ticket_redirect( $request );

		$this->assert_not_found( $response );
		$this->assertEmpty( $this->ability_inputs );
		$this->assertSame( 0, $this->ambiguous_count() );
	}

	/** No Sec-Fetch-Site and no Referer is admitted (allow-and-measure), and tallied separately. */
	public function test_missing_both_signals_redirects_and_is_counted_as_ambiguous() {
		$this->register_ability( false );
		$request = $this->valid_request();

		$response = extrachill_api_handle_ticket_redirect( $request );

		$this->assert_successful_redirect( $response );
		$this->assertSame( 1, $this->ambiguous_count() );

		$response = extrachill_api_handle_ticket_redirect( $this->valid_request() );
		$this->assert_successful_redirect( $response );
		$this->assertSame( 2, $this->ambiguous_count() );
	}

	/** A successful resolution sends the exact required headers alongside the 302. */
	public function test_successful_redirect_headers() {
		$this->register_ability( false );
		$request = $this->valid_request();
		$request->set_header( 'Sec-Fetch-Site', 'same-origin' );

		$response = extrachill_api_handle_ticket_redirect( $request );

		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( $this->ability_result['url'], $response->get_headers()['Location'] );
		$this->assertSame( 'noindex, nofollow', $response->get_headers()['X-Robots-Tag'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( array( 'event_id' => 42 ), $this->ability_inputs[0] );
	}

	/** Non-existent, ticketless, and non-event IDs all converge on the one 404. */
	public function test_unresolvable_events_are_not_found() {
		$this->register_ability( false );

		$this->ability_result = new WP_Error( 'ticket_not_found', 'No ticket URL for this event.' );
		$this->assert_not_found( extrachill_api_handle_ticket_redirect( $this->valid_request() ) );

		$this->ability_result = array(
			'url'          => '',
			'is_affiliate' => false,
		);
		$this->assert_not_found( extrachill_api_handle_ticket_redirect( $this->valid_request() ) );

		$this->ability_result = 'not-an-array';
		$this->assert_not_found( extrachill_api_handle_ticket_redirect( $this->valid_request() ) );
	}

	/** A zero event ID is rejected before the ability is ever called. */
	public function test_zero_id_is_not_found_without_calling_the_ability() {
		$this->register_ability( false );
		$request = $this->valid_request();
		$request->set_url_params( array( 'id' => 0 ) );
		$request->set_param( 'id', 0 );

		$this->assert_not_found( extrachill_api_handle_ticket_redirect( $request ) );
		$this->assertEmpty( $this->ability_inputs );
	}

	/** An unregistered ability (sibling PR not yet merged) 404s cleanly instead of fataling. */
	public function test_missing_ability_is_not_found() {
		$this->assertFalse( wp_has_ability( EXTRACHILL_API_TICKET_REDIRECT_ABILITY ) );

		$this->assert_not_found( extrachill_api_handle_ticket_redirect( $this->valid_request() ) );
	}

	/** An ability accidentally left REST-visible is treated as absent, never invoked. */
	public function test_rest_visible_ability_is_not_found() {
		$this->register_ability( true );

		$this->assert_not_found( extrachill_api_handle_ticket_redirect( $this->valid_request() ) );
		$this->assertEmpty( $this->ability_inputs );
	}

	/** The shared public-read limiter returns a stable 429 once the budget is exhausted. */
	public function test_rate_limit_returns_stable_429() {
		$this->register_ability( false );
		add_filter(
			'extrachill_api_ticket_redirect_rate_limit',
			static function () {
				return 1;
			}
		);

		$first  = extrachill_api_handle_ticket_redirect( $this->valid_request() );
		$second = extrachill_api_handle_ticket_redirect( $this->valid_request() );

		$this->assert_successful_redirect( $first );
		$this->assertWPError( $second );
		$this->assertSame( 'public_read_rate_limited', $second->get_error_code() );
		$this->assertSame( 429, $second->get_error_data()['status'] );
		$this->assertArrayHasKey( 'Retry-After', $second->get_error_data()['headers'] );
	}

	/** The bot gate blocks before the rate limit is ever consumed. */
	public function test_blocked_requests_never_consume_rate_limit_budget() {
		$this->register_ability( false );
		add_filter(
			'extrachill_api_ticket_redirect_rate_limit',
			static function () {
				return 1;
			}
		);

		$blocked = $this->valid_request();
		$blocked->set_header( 'Sec-Fetch-Site', 'cross-site' );
		$this->assert_not_found( extrachill_api_handle_ticket_redirect( $blocked ) );

		$admitted = $this->valid_request();
		$admitted->set_header( 'Sec-Fetch-Site', 'same-origin' );
		$this->assert_successful_redirect( extrachill_api_handle_ticket_redirect( $admitted ) );
	}

	/** Register the controlled hidden ability contract used by data-machine-events#816. */
	private function register_ability( $show_in_rest ) {
		if ( wp_has_ability( EXTRACHILL_API_TICKET_REDIRECT_ABILITY ) ) {
			wp_unregister_ability( EXTRACHILL_API_TICKET_REDIRECT_ABILITY );
		}
		if ( ! wp_has_ability_category( 'ticket-redirect-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'ticket-redirect-tests',
				array(
					'label'       => 'Ticket redirect tests',
					'description' => 'Controlled hidden ticket-destination contract.',
				)
			);
		}
		$test = $this;
		WP_Abilities_Registry::get_instance()->register(
			EXTRACHILL_API_TICKET_REDIRECT_ABILITY,
			array(
				'label'               => 'Resolve ticket destination',
				'description'         => 'Controlled ticket destination resolver.',
				'category'            => 'ticket-redirect-tests',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'event_id' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'event_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) use ( $test ) {
					$test->ability_inputs[] = $input;
					return $test->ability_result;
				},
				'meta'                => array( 'show_in_rest' => (bool) $show_in_rest ),
			)
		);
	}

	/** Build a canonical GET request for event 42. */
	private function valid_request() {
		$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/tickets/42/go' );
		$request->set_url_params( array( 'id' => '42' ) );
		$request->set_param( 'id', 42 );
		return $request;
	}

	/** Assert a redirect matches the fixed contract for the controlled ability result. */
	private function assert_successful_redirect( $response ) {
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( $this->ability_result['url'], $response->get_headers()['Location'] );
	}

	/** Assert the exact fixed public not-found contract. */
	private function assert_not_found( $response ) {
		$this->assertWPError( $response );
		$this->assertSame( 'ticket_redirect_not_found', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	/** Normalize a WP_REST_Server methods declaration to a list of method names. */
	private function normalize_methods( $methods ) {
		if ( is_string( $methods ) ) {
			return explode( ',', $methods );
		}
		return array_keys( array_filter( (array) $methods ) );
	}

	/** Read today's ambiguous-bucket counter. */
	private function ambiguous_count() {
		return (int) get_transient( 'extrachill_api_ticket_redirect_ambiguous_' . gmdate( 'Y-m-d' ) );
	}

	/** Remove any ambiguous-bucket counter this test may have written. */
	private function clear_ambiguous_counters() {
		delete_transient( 'extrachill_api_ticket_redirect_ambiguous_' . gmdate( 'Y-m-d' ) );
	}

	/** Return the deterministic test rate-limit store. */
	public function use_test_rate_limit_store() {
		return array( $this, 'increment_test_rate_limit' );
	}

	/** Increment one deterministic counter. */
	public function increment_test_rate_limit( $key ) {
		$this->rate_counts[ $key ] = ( $this->rate_counts[ $key ] ?? 0 ) + 1;
		return $this->rate_counts[ $key ];
	}
}
