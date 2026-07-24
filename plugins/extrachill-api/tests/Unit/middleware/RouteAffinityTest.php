<?php
/**
 * Tests for multisite route-affinity transport behavior.
 *
 * @package ExtraChill\API\Tests
 */

/**
 * Exercises forwarding trust, matching, identity, and response fidelity.
 */
class Route_AffinityTest extends WP_UnitTestCase {

	/**
	 * Number of intercepted loopback requests.
	 *
	 * @var int
	 */
	private $request_count = 0;

	/**
	 * Mock downstream response.
	 *
	 * @var array|WP_Error
	 */
	private $downstream_response;

	/**
	 * Last HTTP arguments produced by the real cross-site helper.
	 *
	 * @var array
	 */
	private $last_http_args = array();

	/**
	 * Last URL produced by the real cross-site helper.
	 *
	 * @var string
	 */
	private $last_http_url = '';

	/**
	 * Original server values changed by tests.
	 *
	 * @var array
	 */
	private $original_server = array();

	/**
	 * Install a controlled loopback transport around the real helper.
	 */
	public function set_up() {
		parent::set_up();

		$this->assertTrue( function_exists( 'ec_cross_site_rest_request' ), 'The extrachill-network validation dependency must load the real cross-site helper.' );
		$this->assertTrue( function_exists( 'ec_get_route_site_affinity' ), 'The real route-affinity resolver must be available.' );

		foreach ( array( 'REMOTE_ADDR', 'HTTP_HOST', 'HTTP_COOKIE', 'HTTP_X_WP_NONCE', 'HTTP_X_EC_INTERNAL_USER', 'HTTP_X_EC_INTERNAL_TIMESTAMP', 'HTTP_X_EC_INTERNAL_SIGNATURE' ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Preserve exact server state for teardown.
			$this->original_server[ $key ] = $_SERVER[ $key ] ?? null;
		}

		$_SERVER['REMOTE_ADDR']    = '203.0.113.10';
		$this->downstream_response = $this->http_response( 200, array( 'ok' => true ) );
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_COOKIE'], $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_X_EC_INTERNAL_USER'], $_SERVER['HTTP_X_EC_INTERNAL_TIMESTAMP'], $_SERVER['HTTP_X_EC_INTERNAL_SIGNATURE'] );

		add_filter( 'pre_http_request', array( $this, 'intercept_loopback' ), 10, 3 );
	}

	/**
	 * Restore global request state and filters.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept_loopback' ), 10 );

		foreach ( $this->original_server as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}

		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The collection is exact and item affinity stops at the slash boundary.
	 */
	public function test_artist_affinity_uses_exact_collection_and_item_boundaries() {
		foreach ( array( '/extrachill/v1/artists', '/extrachill/v1/artists/42' ) as $route ) {
			$response = $this->dispatch_affinity( new WP_REST_Request( 'GET', $route ) );

			$this->assertInstanceOf( WP_REST_Response::class, $response );
		}

		$this->assertSame( 2, $this->request_count );

		foreach ( array( '/extrachill/v1/artistsfoo', '/extrachill/v1/artists-search' ) as $route ) {
			$this->assertNull( $this->dispatch_affinity( new WP_REST_Request( 'GET', $route ) ) );
		}

		$this->assertSame( 2, $this->request_count );
	}

	/**
	 * External callers cannot forge either legacy or new affinity headers.
	 */
	public function test_external_affinity_headers_never_bypass_forwarding() {
		$request = new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' );
		$request->set_header( 'X-EC-Forwarded', '1' );
		$request->set_header( 'X-EC-Affinity-Timestamp', (string) time() );
		$request->set_header( 'X-EC-Affinity-Signature', str_repeat( 'a', 64 ) );
		$request->set_header( 'X-EC-Affinity-Target', 'artist.example.com' );
		$request->set_header( 'X-EC-Affinity-Nonce', wp_generate_uuid4() );

		$this->assertInstanceOf( WP_REST_Response::class, $this->dispatch_affinity( $request ) );
		$this->assertSame( 1, $this->request_count );
	}

	/**
	 * Invalid and stale localhost signatures do not count as trusted re-entry.
	 */
	public function test_invalid_and_stale_localhost_signatures_are_rejected() {
		$request                = new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$_SERVER['HTTP_HOST']   = 'artist.example.com';

		$request->set_header( 'X-EC-Affinity-Timestamp', (string) time() );
		$request->set_header( 'X-EC-Affinity-Signature', str_repeat( 'b', 64 ) );
		$request->set_header( 'X-EC-Affinity-Target', 'artist.example.com' );
		$request->set_header( 'X-EC-Affinity-Nonce', wp_generate_uuid4() );
		$this->assert_reentry_rejected( $request );

		$request->set_header( 'X-EC-Affinity-Timestamp', (string) ( time() - 301 ) );
		$this->assert_reentry_rejected( $request );
	}

	/**
	 * A partial local token without a target host is safely rejected.
	 */
	public function test_local_reentry_without_target_host_is_rejected() {
		$request                = new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$_SERVER['HTTP_HOST']   = 'artist.example.com';

		$request->set_header( 'X-EC-Affinity-Timestamp', (string) time() );
		$request->set_header( 'X-EC-Affinity-Signature', str_repeat( 'b', 64 ) );
		$request->set_header( 'X-EC-Affinity-Nonce', wp_generate_uuid4() );

		$this->assertNull( $request->get_header( 'X-EC-Affinity-Target' ) );
		$this->assert_reentry_rejected( $request );
	}

	/**
	 * Signed tokens cannot be reused with altered query or body data.
	 */
	public function test_signature_rejects_altered_query_and_body() {
		$query_request = new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' );
		$query_request->set_query_params(
			array(
				'context' => 'edit',
				'page'    => 2,
			)
		);
		$this->dispatch_affinity( $query_request );

		$reentry = $this->reentry_request( $query_request );
		$reentry->set_query_params(
			array(
				'context' => 'edit',
				'page'    => 3,
			)
		);
		$this->assert_reentry_rejected( $reentry );

		$body_request = $this->json_request( 'POST', '/extrachill/v1/artists', array( 'name' => 'Original' ) );
		$this->dispatch_affinity( $body_request );

		$reentry = $this->reentry_request( $body_request );
		$reentry->set_body( wp_json_encode( array( 'name' => 'Altered' ) ) );
		$this->assert_reentry_rejected( $reentry );
	}

	/**
	 * Typed query input signs the exact helper wire representation.
	 *
	 * @dataProvider typed_query_provider
	 * @param array $source_query Query input supplied to the helper.
	 * @param array $target_query Query shape parsed on the target.
	 */
	public function test_typed_query_matches_real_helper_wire_shape( $source_query, $target_query ) {
		$source = new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' );
		$source->set_query_params( $source_query );
		$this->dispatch_affinity( $source );

		$reentry = $this->reentry_request( $source );
		$this->assertSame( $target_query, $reentry->get_query_params() );
		$this->assertNull( $this->dispatch_affinity( $reentry ) );
		$this->assertSame( 1, $this->request_count );
	}

	/**
	 * A valid token remains bound to both target header and HTTP host.
	 */
	public function test_valid_token_rejects_altered_target_host_binding() {
		$source = new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' );
		$this->dispatch_affinity( $source );
		$reentry       = $this->reentry_request( $source );
		$original_host = $this->last_http_args['headers']['Host'];

		$_SERVER['HTTP_HOST'] = 'wrong.example.com';
		$this->assert_reentry_rejected( $reentry );

		$_SERVER['HTTP_HOST'] = $original_host;
		$reentry->set_header( 'X-EC-Affinity-Target', 'wrong.example.com' );
		$this->assert_reentry_rejected( $reentry );
		$this->assertSame( 1, $this->request_count );
	}

	/**
	 * A valid signed hop is accepted once and prevents recursive forwarding.
	 */
	public function test_valid_reentry_is_single_use_and_prevents_recursion() {
		$source = $this->json_request( 'POST', '/extrachill/v1/artists', array( 'name' => 'Signed' ) );
		$source->set_query_params( array( 'context' => 'edit' ) );
		$this->dispatch_affinity( $source );

		$reentry = $this->reentry_request( $source );
		$this->assertNull( $this->dispatch_affinity( $reentry ) );
		$this->assert_reentry_rejected( $reentry );
		$this->assertSame( 1, $this->request_count );
	}

	/**
	 * Anonymous forwarding does not gain an internal user identity.
	 */
	public function test_anonymous_caller_remains_anonymous() {
		$this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' ) );

		$this->assertArrayNotHasKey( 'X-EC-Internal-User', $this->last_http_args['headers'] );
		$this->assertArrayNotHasKey( 'Cookie', $this->last_http_args['headers'] );
		$this->assertArrayNotHasKey( 'X-WP-Nonce', $this->last_http_args['headers'] );
		$this->assertNull( ec_cross_site_authenticate_internal_request( null ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Authenticated identity is signed by the real cross-site helper.
	 */
	public function test_authenticated_caller_identity_survives_loopback() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_SERVER['HTTP_COOKIE']     = 'wordpress_test_cookie=1';
		$_SERVER['HTTP_X_WP_NONCE'] = 'test-nonce';

		$this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' ) );

		$headers = $this->last_http_args['headers'];
		$this->assertSame( (string) $user_id, $headers['X-EC-Internal-User'] );
		$this->assertSame( 'wordpress_test_cookie=1', $headers['Cookie'] );
		$this->assertSame( 'test-nonce', $headers['X-WP-Nonce'] );
		$this->assertTrue(
			ec_cross_site_verify_signature(
				$user_id,
				(int) $headers['X-EC-Internal-Timestamp'],
				$headers['X-EC-Internal-Signature']
			)
		);

		$_SERVER['REMOTE_ADDR']                  = '127.0.0.1';
		$_SERVER['HTTP_X_EC_INTERNAL_USER']      = $headers['X-EC-Internal-User'];
		$_SERVER['HTTP_X_EC_INTERNAL_TIMESTAMP'] = $headers['X-EC-Internal-Timestamp'];
		$_SERVER['HTTP_X_EC_INTERNAL_SIGNATURE'] = $headers['X-EC-Internal-Signature'];
		wp_set_current_user( 0 );

		$this->assertTrue( ec_cross_site_authenticate_internal_request( null ) );
		$this->assertSame( $user_id, get_current_user_id() );
	}

	/**
	 * Successful downstream status and headers are preserved.
	 *
	 * @dataProvider success_response_provider
	 * @param int $status Downstream status.
	 */
	public function test_success_response_preserves_status_body_and_headers( $status ) {
		$this->downstream_response = $this->http_response(
			$status,
			array( 'artist_id' => 42 ),
			array( 'X-EC-Result' => 'forwarded' )
		);

		$response = $this->dispatch_affinity( $this->json_request( 'POST', '/extrachill/v1/artists', array( 'name' => 'Artist' ) ) );

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( array( 'artist_id' => 42 ), $response->get_data() );
		$this->assertSame( 'forwarded', $response->get_headers()['X-EC-Result'] );
	}

	/**
	 * Error responses retain their full downstream envelope and headers.
	 */
	public function test_error_response_preserves_status_body_and_headers() {
		$body = array(
			'code'    => 'artist_conflict',
			'message' => 'Artist already exists.',
			'data'    => array( 'status' => 409 ),
			'details' => array( 'artist_id' => 42 ),
		);

		$this->downstream_response = $this->http_response( 409, $body, array( 'X-EC-Error' => 'conflict' ) );
		$response                  = $this->dispatch_affinity( $this->json_request( 'POST', '/extrachill/v1/artists', array( 'name' => 'Artist' ) ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $body, $response->get_data() );
		$this->assertSame( 'conflict', $response->get_headers()['X-EC-Error'] );
	}

	/**
	 * Request-global transport filters are removed after success and failure.
	 */
	public function test_temporary_filters_are_cleaned_up_on_success_and_error() {
		$hooks    = array( 'ec_cross_site_use_http_loopback', 'pre_http_request', 'http_response' );
		$baseline = array_map( array( $this, 'filter_callback_count' ), $hooks );

		$this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' ) );
		$this->assertSame( $baseline, array_map( array( $this, 'filter_callback_count' ), $hooks ) );

		$this->downstream_response = new WP_Error( 'transport_failed', 'Loopback failed.', array( 'status' => 502 ) );
		$this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' ) );
		$this->assertSame( $baseline, array_map( array( $this, 'filter_callback_count' ), $hooks ) );
	}

	/**
	 * Successful non-200 statuses.
	 *
	 * @return array<string, array{int}>
	 */
	public function success_response_provider() {
		return array(
			'created'  => array( 201 ),
			'accepted' => array( 202 ),
		);
	}

	/**
	 * Typed query values and their post-wire target forms.
	 *
	 * @return array<string, array{array, array}>
	 */
	public function typed_query_provider() {
		return array(
			'false becomes zero'  => array(
				array( 'value' => false ),
				array( 'value' => '0' ),
			),
			'null is omitted'     => array(
				array( 'value' => null ),
				array(),
			),
			'empty array omitted' => array(
				array( 'value' => array() ),
				array(),
			),
			'nested values'       => array(
				array(
					'value' => array(
						'false' => false,
						'null'  => null,
						'empty' => array(),
						'zero'  => 0,
						'true'  => true,
						'text'  => 'x',
					),
				),
				array(
					'value' => array(
						'false' => '0',
						'zero'  => '0',
						'true'  => '1',
						'text'  => 'x',
					),
				),
			),
			'ordinary scalars'    => array(
				array(
					'string'  => 'one',
					'integer' => 2,
					'float'   => 2.5,
					'true'    => true,
				),
				array(
					'string'  => 'one',
					'integer' => '2',
					'float'   => '2.5',
					'true'    => '1',
				),
			),
		);
	}

	/**
	 * Return the configured response instead of making a loopback request.
	 *
	 * @param false|array|WP_Error $pre  Preemptive HTTP response.
	 * @param array                $args HTTP arguments.
	 * @param string               $url  Request URL.
	 * @return false|array|WP_Error
	 */
	public function intercept_loopback( $pre, $args, $url ) {
		if ( false === strpos( $url, '127.0.0.1' ) ) {
			return $pre;
		}

		++$this->request_count;
		$this->last_http_args = $args;
		$this->last_http_url  = $url;

		return $this->downstream_response;
	}

	/**
	 * Count callbacks currently attached to a hook.
	 *
	 * @param string $hook_name Hook name.
	 * @return int
	 */
	public function filter_callback_count( $hook_name ) {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook_name ] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $hook_name ]->callbacks as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
	}

	/**
	 * Dispatch the middleware directly.
	 *
	 * @param WP_REST_Request $request Request to dispatch.
	 * @return mixed
	 */
	private function dispatch_affinity( WP_REST_Request $request ) {
		return extrachill_api_route_affinity_dispatch( null, rest_get_server(), $request );
	}

	/**
	 * Assert that a local token failure is rejected before route execution.
	 *
	 * @param WP_REST_Request $request Request carrying an invalid token.
	 */
	private function assert_reentry_rejected( WP_REST_Request $request ) {
		$result = $this->dispatch_affinity( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'route_affinity_reentry_invalid', $result->get_error_code() );
	}

	/**
	 * Build a JSON request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @param array  $body   JSON body.
	 * @return WP_REST_Request
	 */
	private function json_request( $method, $route, $body ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return $request;
	}

	/**
	 * Rebuild the downstream request using headers emitted by the real helper.
	 *
	 * @param WP_REST_Request $source Source request.
	 * @return WP_REST_Request
	 */
	private function reentry_request( WP_REST_Request $source ) {
		$request = new WP_REST_Request( $source->get_method(), $source->get_route() );

		$query = wp_parse_url( $this->last_http_url, PHP_URL_QUERY );
		if ( $query ) {
			parse_str( $query, $query_params );
			$request->set_query_params( $query_params );
		}

		if ( ! empty( $this->last_http_args['body'] ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $this->last_http_args['body'] );
		}

		foreach ( $this->last_http_args['headers'] as $name => $value ) {
			if ( 0 === strpos( $name, 'X-EC-Affinity-' ) ) {
				$request->set_header( $name, $value );
			}
		}

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$_SERVER['HTTP_HOST']   = $this->last_http_args['headers']['Host'];

		return $request;
	}

	/**
	 * Build a WordPress HTTP API response fixture.
	 *
	 * @param int   $status  HTTP status.
	 * @param mixed $body    JSON body.
	 * @param array $headers Response headers.
	 * @return array
	 */
	private function http_response( $status, $body, $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
