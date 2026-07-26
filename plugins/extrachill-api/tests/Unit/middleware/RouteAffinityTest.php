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

	/** @var array<int, array<string, mixed>> */
	private $delivery_outcomes = array();

	/** @var string */
	private $loopback_error_bytes = '';

	/** @var bool */
	private $handoff_failure = false;

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
		$this->delivery_outcomes   = array();
		$this->loopback_error_bytes = '';
		$this->handoff_failure      = false;
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_COOKIE'], $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_X_EC_INTERNAL_USER'], $_SERVER['HTTP_X_EC_INTERNAL_TIMESTAMP'], $_SERVER['HTTP_X_EC_INTERNAL_SIGNATURE'] );

		add_filter( 'pre_http_request', array( $this, 'intercept_loopback' ), 10, 3 );
		if ( ! wp_has_ability_category( 'extrachill-api-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'extrachill-api-tests',
				array(
					'label'       => 'Extra Chill API Tests',
					'description' => 'Controlled affinity contracts.',
				)
			);
		}
		$test = $this;
		WP_Abilities_Registry::get_instance()->register(
			'extrachill/record-booking-attachment-delivery',
			array(
				'label'               => 'Record test delivery',
				'description'         => 'Controlled affinity delivery callback.',
				'category'            => 'extrachill-api-tests',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) use ( $test ) {
					$test->delivery_outcomes[] = $input;
					return array( 'recorded' => true );
				},
			)
		);
	}

	/**
	 * Restore global request state and filters.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept_loopback' ), 10 );
		wp_unregister_ability( 'extrachill/record-booking-attachment-delivery' );
		remove_all_filters( 'extrachill_api_private_affinity_spool_path' );
		remove_all_filters( 'extrachill_api_private_affinity_spool_protected' );

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
		$request->set_header( 'X-EC-Affinity-Verified', '1' );

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

	/** A protected stream range is forwarded only when bound to the signature. */
	public function test_private_stream_range_is_signed_and_tamper_proof() {
		$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/12/attachments/34/download' );
		$request->set_header( 'Range', 'bytes=2-5' );
		$this->downstream_response = $this->raw_http_response(
			206,
			'vate',
			array(
				'Content-Length'            => '4',
				'Content-Type'              => 'text/plain',
				'Content-Disposition'       => 'attachment; filename="rider.txt"',
				'Content-Range'             => 'bytes 2-5/10',
			)
		);
		$response = $this->dispatch_affinity( $request );

		$this->assertSame( 206, $response->get_status() );
		$this->assertNull( $response->get_data() );
		$this->assertArrayNotHasKey( 'X-EC-Download-Correlation', $response->get_headers() );
		$this->assertArrayNotHasKey( 'X-EC-Affinity-Delivery', $response->get_headers() );
		$this->assertSame( 'bytes=2-5', $this->last_http_args['headers']['range'] );
		$this->assertTrue( $this->last_http_args['stream'] );
		$this->assertSame( extrachill_api_booking_attachment_max_bytes() + 1, $this->last_http_args['limit_response_size'] );
		$spool = $this->last_http_args['filename'];
		$this->assertFileExists( $spool );

		$reentry = $this->reentry_request( $request );
		$reentry->set_header( 'Range', 'bytes=3-6' );
		$this->assert_reentry_rejected( $reentry );

		ob_start();
		$this->assertTrue( extrachill_api_serve_private_stream( false, $response, $request, rest_get_server() ) );
		$this->assertSame( 'vate', ob_get_clean() );
		$this->assertFileDoesNotExist( $spool );
	}

	/** Downstream private errors are generic and their spooled bodies are deleted. */
	public function test_private_stream_error_does_not_relay_internal_references() {
		wp_set_current_user( self::factory()->user->create() );
		$this->downstream_response = $this->raw_http_response(
			403,
			'{"storage_reference":"secret","path":"/private/root"}',
			array( 'Content-Type' => 'application/json' )
		);
		$response = $this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/12/attachments/34/download' ) );
		$spool    = $this->last_http_args['filename'];

		$this->assertWPError( $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $response->get_error_data() ) );
		$this->assertFileDoesNotExist( $spool );
	}

	/** A post-consumption loopback error still finalizes the preissued correlation. */
	public function test_private_stream_wp_error_finalizes_preissued_delivery() {
		wp_set_current_user( self::factory()->user->create() );
		$this->downstream_response = new WP_Error( 'http_request_failed', 'Stream reset after consumption.', array( 'status' => 502 ) );
		$response = $this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/12/attachments/34/download' ) );
		$spool    = $this->last_http_args['filename'];

		$this->assertWPError( $response );
		$this->assertSame( 502, $response->get_error_data()['status'] );
		$this->assertCount( 1, $this->delivery_outcomes );
		$this->assertSame( '11111111-1111-4111-8111-111111111111', $this->delivery_outcomes[0]['correlation_id'] );
		$this->assertSame( 'failed', $this->delivery_outcomes[0]['outcome'] );
		$this->assertSame( 0, $this->delivery_outcomes[0]['bytes_sent'] );
		$this->assertFileDoesNotExist( $spool );
	}

	/** A nonempty failed loopback records the exact bounded partial byte count. */
	public function test_private_stream_wp_error_records_partial_spool_bytes() {
		wp_set_current_user( self::factory()->user->create() );
		$this->loopback_error_bytes = 'part';
		$this->downstream_response  = new WP_Error( 'http_request_failed', 'Stream reset after partial body.', array( 'status' => 502 ) );
		$response = $this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/12/attachments/34/download' ) );

		$this->assertWPError( $response );
		$this->assertCount( 1, $this->delivery_outcomes );
		$this->assertSame( 'partial', $this->delivery_outcomes[0]['outcome'] );
		$this->assertSame( 4, $this->delivery_outcomes[0]['bytes_sent'] );
		$this->assertFileDoesNotExist( $this->last_http_args['filename'] );
	}

	/** Exceptions converge on the same finalizer and invoke it exactly once. */
	public function test_private_stream_exception_finalizes_once() {
		wp_set_current_user( self::factory()->user->create() );
		$throw = static function () {
			throw new RuntimeException( 'post-preflight failure' );
		};
		add_filter( 'extrachill_api_route_affinity_file_forward', $throw, PHP_INT_MAX );
		$response = $this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/12/attachments/34/download' ) );
		remove_filter( 'extrachill_api_route_affinity_file_forward', $throw, PHP_INT_MAX );

		$this->assertWPError( $response );
		$this->assertCount( 1, $this->delivery_outcomes );
		$this->assertSame( 'failed', $this->delivery_outcomes[0]['outcome'] );
		$this->assertSame( 0, $this->delivery_outcomes[0]['bytes_sent'] );
	}

	/** Spool setup fails before descriptor issuance, leaving no correlation behind. */
	public function test_private_stream_spool_setup_failures_do_not_issue_handoff() {
		wp_set_current_user( self::factory()->user->create() );
		$path_failure = static function ( $path ) {
			if ( is_string( $path ) && file_exists( $path ) ) {
				unlink( $path );
			}
			return false;
		};
		add_filter( 'extrachill_api_private_affinity_spool_path', $path_failure );
		$missing = $this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/12/attachments/34/download' ) );
		remove_filter( 'extrachill_api_private_affinity_spool_path', $path_failure );

		$protect_failure = '__return_false';
		add_filter( 'extrachill_api_private_affinity_spool_protected', $protect_failure );
		$unprotected = $this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/12/attachments/34/download' ) );
		remove_filter( 'extrachill_api_private_affinity_spool_protected', $protect_failure );

		$this->assertWPError( $missing );
		$this->assertWPError( $unprotected );
		$this->assertSame( 503, $missing->get_error_data()['status'] );
		$this->assertSame( 503, $unprotected->get_error_data()['status'] );
		$this->assertSame( 0, $this->request_count, 'Descriptor preflight must not run until spool setup succeeds.' );
		$this->assertSame( array(), $this->delivery_outcomes );
	}

	/** Repeated preflight failures delete each prepared spool without callback. */
	public function test_private_stream_preflight_failures_do_not_leak_spools() {
		wp_set_current_user( self::factory()->user->create() );
		$this->handoff_failure = true;
		$paths = array();
		$capture = static function ( $path ) use ( &$paths ) {
			$paths[] = $path;
			return $path;
		};
		add_filter( 'extrachill_api_private_affinity_spool_path', $capture );
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$response = $this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/12/attachments/34/download' ) );
			$this->assertWPError( $response );
			$this->assertSame( 502, $response->get_error_data()['status'] );
		}
		remove_filter( 'extrachill_api_private_affinity_spool_path', $capture );

		$this->assertCount( 3, $paths );
		foreach ( $paths as $path ) {
			$this->assertFileDoesNotExist( $path );
		}
		$this->assertSame( array(), $this->delivery_outcomes );
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
		$this->assertSame( '203.0.113.10', extrachill_api_route_affinity_context( $reentry )['remote_addr'] );
		$this->assertSame( '', (string) $reentry->get_header( 'X-EC-Affinity-Verified' ) );
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

	/** Caller-supplied affinity identity is removed and replaced from the socket. */
	public function test_affinity_client_spoof_is_overwritten_before_signing() {
		$request = $this->json_request(
			'POST',
			'/extrachill/v1/artists',
			array(
				'name'                     => 'Signed',
				'_ec_affinity_client'      => str_repeat( 'a', 64 ),
				'_ec_affinity_remote_addr' => '192.0.2.200',
			)
		);
		$request->set_header( 'X-EC-Affinity-Client', str_repeat( 'b', 64 ) );
		$request->set_header( 'X-EC-Affinity-Remote-Addr', '192.0.2.201' );
		$request->set_header( 'X-EC-Affinity-Verified', '1' );

		$this->dispatch_affinity( $request );
		$body = json_decode( $this->last_http_args['body'], true );

		$this->assertArrayNotHasKey( '_ec_affinity_client', $body );
		$this->assertArrayNotHasKey( '_ec_affinity_remote_addr', $body );
		$this->assertSame( '203.0.113.10', $this->last_http_args['headers']['x-ec-affinity-remote-addr'] );
		$this->assertSame( hash_hmac( 'sha256', '203.0.113.10', wp_salt( 'nonce' ) ), $this->last_http_args['headers']['x-ec-affinity-client'] );
	}

	/** Multipart forwarding uses the same signed canonical user transport as JSON. */
	public function test_multipart_forwarding_preserves_signed_canonical_user() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$file = wp_tempnam( 'booking-affinity-auth' );
		file_put_contents( $file, 'press notes' );
		add_filter( 'extrachill_api_allow_test_booking_file', '__return_true' );
		try {
			$request = new WP_REST_Request( 'POST', '/extrachill/v1/venues/42/booking-inquiries' );
			$request->set_body_params(
				array(
					'venue'               => 42,
					'idempotency_key'     => 'multipart-auth',
					'intake'              => wp_json_encode( array( 'message' => 'Hello' ) ),
					'attachment_purposes' => wp_json_encode( array( 'press_release' ) ),
					'turnstile_response'  => 'test',
				)
			);
			$request->set_file_params(
				array(
					'attachments' => array(
						'name'     => 'press.txt',
						'type'     => 'text/plain',
						'tmp_name' => $file,
						'error'    => UPLOAD_ERR_OK,
						'size'     => filesize( $file ),
					),
				)
			);

			$this->dispatch_affinity( $request );
			$headers = $this->last_http_args['headers'];
			$this->assertSame( (string) $user_id, $headers['X-EC-Internal-User'] );
			$this->assertTrue( ec_cross_site_verify_signature( $user_id, (int) $headers['X-EC-Internal-Timestamp'], $headers['X-EC-Internal-Signature'] ) );
			$this->assertStringStartsWith( 'multipart/form-data; boundary=', $headers['Content-Type'] );
			$this->assertStringContainsString( 'press notes', $this->last_http_args['body'] );
		} finally {
			remove_filter( 'extrachill_api_allow_test_booking_file', '__return_true' );
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	/** JSON booking transport failures use the stable booking error contract. */
	public function test_json_booking_transport_failure_does_not_leak_network_error() {
		$this->downstream_response = new WP_Error( 'http_request_failed', 'cURL error 28: private upstream timed out.', array( 'status' => 502 ) );
		$response = $this->dispatch_affinity( $this->json_request( 'POST', '/extrachill/v1/venues/42/booking-inquiries', array( 'venue' => 42 ) ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'booking_inquiry_unavailable', $response->get_data()['code'] );
		$this->assertStringNotContainsString( 'cURL', wp_json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( 'http_request_failed', wp_json_encode( $response->get_data() ) );
	}

	/** Multipart booking transport failures use the same stable contract. */
	public function test_multipart_booking_transport_failure_does_not_leak_network_error() {
		$file = wp_tempnam( 'booking-affinity-failure' );
		file_put_contents( $file, 'press notes' );
		add_filter( 'extrachill_api_allow_test_booking_file', '__return_true' );
		try {
			$request = new WP_REST_Request( 'POST', '/extrachill/v1/venues/42/booking-inquiries' );
			$request->set_body_params( array( 'venue' => 42, 'attachment_purposes' => array( 'press_release' ) ) );
			$request->set_file_params(
				array(
					'attachments' => array(
						'name'     => 'press.txt',
						'type'     => 'text/plain',
						'tmp_name' => $file,
						'error'    => UPLOAD_ERR_OK,
						'size'     => filesize( $file ),
					),
				)
			);
			$this->downstream_response = new WP_Error( 'http_request_failed', 'cURL error 7: private host refused.', array( 'status' => 502 ) );
			$response = $this->dispatch_affinity( $request );

			$this->assertSame( 503, $response->get_status() );
			$this->assertSame( 'booking_inquiry_unavailable', $response->get_data()['code'] );
			$this->assertStringNotContainsString( 'cURL', wp_json_encode( $response->get_data() ) );
			$this->assertStringNotContainsString( 'http_request_failed', wp_json_encode( $response->get_data() ) );
		} finally {
			remove_filter( 'extrachill_api_allow_test_booking_file', '__return_true' );
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	/** Generic affinity routes retain their existing transport error envelope. */
	public function test_generic_transport_failure_preserves_original_error() {
		$this->downstream_response = new WP_Error( 'transport_failed', 'Loopback failed.', array( 'status' => 502 ) );
		$response = $this->dispatch_affinity( new WP_REST_Request( 'GET', '/extrachill/v1/artists/42' ) );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'ec_cross_site_request_failed', $response->get_data()['code'] );
		$this->assertStringContainsString( 'Loopback failed.', $response->get_data()['message'] );
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
		if ( false !== strpos( $url, '/events/internal/booking-attachment-handoff' ) ) {
			if ( $this->handoff_failure ) {
				return new WP_Error( 'http_request_failed', 'Descriptor preflight failed.' );
			}
			return $this->http_response(
				200,
				array(
					'stream_token'   => str_repeat( 'a', 64 ),
					'correlation_id' => '11111111-1111-4111-8111-111111111111',
					'expires_at'     => '2026-07-26 20:00:00',
					'filename'       => 'rider.txt',
					'mime_type'      => 'text/plain',
				)
			);
		}
		if ( ! empty( $args['stream'] ) && is_wp_error( $this->downstream_response ) && '' !== $this->loopback_error_bytes ) {
			file_put_contents( $args['filename'], $this->loopback_error_bytes );
		}
		if ( ! empty( $args['stream'] ) && is_array( $this->downstream_response ) ) {
			$nonce = (string) ( $args['headers']['X-EC-Affinity-Nonce'] ?? '' );
			$status = (int) ( $this->downstream_response['response']['code'] ?? 0 );
			if ( in_array( $status, array( 200, 206 ), true ) ) {
				$delivery_headers = extrachill_api_booking_attachment_affinity_delivery_headers(
					array(
						'booking_id'     => 12,
						'attachment_id'  => 34,
						'correlation_id' => '11111111-1111-4111-8111-111111111111',
					),
					array( 'nonce' => $nonce )
				);
				$this->downstream_response['headers'] = array_merge( $this->downstream_response['headers'], $delivery_headers );
			}
			file_put_contents( $args['filename'], $this->downstream_response['body'] );
			$this->downstream_response['body']     = '';
			$this->downstream_response['filename'] = $args['filename'];
		}

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
			if ( 0 === strpos( strtolower( $name ), 'x-ec-affinity-' ) ) {
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

	/** Build a raw byte HTTP response fixture. */
	private function raw_http_response( $status, $body, $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
