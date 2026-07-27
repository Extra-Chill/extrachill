<?php
/**
 * Protected booking attachment download transport tests.
 *
 * @package ExtraChill\API\Tests
 */

namespace ExtraChillEvents\Core {
	/** Controlled Events service contract used by the API transport tests. */
	class BookingAttachmentService {
		/** @var array<string, bool> */
		public static $consumed = array();

		/** Consume one test handoff and return private bytes. */
		public function open_download_stream( int $booking_id, int $attachment_id, string $token, int $actor_id, string $correlation_id ) {
			unset( $attachment_id, $actor_id );
			if ( ! wp_is_uuid( $correlation_id, 4 ) ) {
				return new \WP_Error( 'private_stream_unavailable', 'Invalid delivery correlation.', array( 'status' => 403 ) );
			}
			if ( in_array( $booking_id, array( 4, 5, 6 ), true ) || isset( self::$consumed[ $token ] ) ) {
				return new \WP_Error( 'private_stream_unavailable', 'Internal reference /private/object/leak', array( 'status' => 403 ) );
			}
			self::$consumed[ $token ] = true;
			$stream                   = fopen( 'php://temp', 'w+b' );
			fwrite( $stream, 'private booking bytes' );
			rewind( $stream );
			return $stream;
		}
	}
}

namespace {
	/** Exercises the real REST adapter around controlled Events contracts. */
	class Booking_Attachment_DownloadTest extends WP_UnitTestCase {

		/** @var string[] */
		private $registered_abilities = array();

		/** @var array<int, array<string, int>> */
		private $audit = array();

		/** @var array<int, array<string, int|string>> */
		private $delivery_outcomes = array();

		/** @var array<string, int> */
		private $rate_counts = array();

		/** Register a controlled hidden Events ability. */
		public function set_up() {
			parent::set_up();
			\ExtraChillEvents\Core\BookingAttachmentService::$consumed = array();
			$GLOBALS['extrachill_api_private_streams']                 = array();
			$this->delivery_outcomes                                   = array();
			$this->rate_counts = array();
			wp_set_current_user( 0 );
			remove_filter( 'rest_pre_dispatch', 'extrachill_api_route_affinity_dispatch', 10 );
			add_filter( 'extrachill_api_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );

			$categories = WP_Ability_Categories_Registry::get_instance();
			if ( ! wp_has_ability_category( 'extrachill-api-tests' ) ) {
				$categories->register(
					'extrachill-api-tests',
					array(
						'label'       => 'Extra Chill API Tests',
						'description' => 'Controlled private transport contracts.',
					)
				);
			}

			$this->register_ability();
			do_action( 'rest_api_init' );
		}

		/** Remove controlled state and any unserved streams. */
		public function tear_down() {
			extrachill_api_cleanup_private_streams();
			remove_filter( 'extrachill_api_rate_limit_store', array( $this, 'use_test_rate_limit_store' ) );
			remove_all_filters( 'extrachill_api_private_affinity_stream' );
			remove_all_filters( 'extrachill_api_booking_attachment_max_bytes' );
			foreach ( $this->registered_abilities as $name ) {
				wp_unregister_ability( $name );
			}
			wp_set_current_user( 0 );
			add_filter( 'rest_pre_dispatch', 'extrachill_api_route_affinity_dispatch', 10, 3 );
			parent::tear_down();
		}

		/** Anonymous requests fail before Events is called and remain non-cacheable. */
		public function test_anonymous_request_fails_closed_without_audit() {
			$request  = new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/1/attachments/1/download' );
			$response = rest_do_request( $request );
			$response = extrachill_api_protect_booking_attachment_download_response( $response, rest_get_server(), $request );

			$this->assertSame( 401, $response->get_status() );
			$this->assertSame( 'booking_attachment_download_unavailable', $response->get_data()['code'] );
			$this->assertStringContainsString( 'no-store', $this->response_header( $response, 'cache-control' ) );
			$this->assertSame( array(), $this->audit );
		}

		/** Cross-venue and revoked callers receive the same generic response. */
		public function test_cross_venue_and_revoked_access_are_indistinguishable() {
			wp_set_current_user( self::factory()->user->create() );
			foreach ( array( 2, 3 ) as $booking_id ) {
				$response = $this->dispatch( $booking_id, 1 );
				$this->assertSame( 404, $response->get_status() );
				$this->assertSame( 'booking_attachment_download_unavailable', $response->get_data()['code'] );
				$this->assertStringNotContainsString( 'venue', wp_json_encode( $response->get_data() ) );
			}
			$this->assertSame( array(), $this->audit );
		}

		/** Expired, replayed, and tampered handoffs fail after Events reauthorization. */
		public function test_expired_replayed_and_tampered_handoffs_fail_generically() {
			wp_set_current_user( self::factory()->user->create() );
			foreach ( array( 4, 5, 6 ) as $booking_id ) {
				$response = $this->dispatch( $booking_id, 1 );
				$this->assertSame( 404, $response->get_status() );
				$this->assertStringNotContainsString( 'private', wp_json_encode( $response->get_data() ) );
			}

			$first = $this->dispatch( 7, 1 );
			$this->assertSame( 200, $first->get_status() );
			$this->serve( $first );
			$replay = $this->dispatch( 7, 1 );
			$this->assertSame( 404, $replay->get_status() );
		}

		/** Success exposes only safe headers and manually serves the private bytes. */
		public function test_success_is_no_store_auditable_and_reference_free() {
			$user_id = self::factory()->user->create();
			wp_set_current_user( $user_id );
			$response = $this->dispatch( 1, 9 );
			$headers  = $response->get_headers();

			$this->assertSame( 200, $response->get_status() );
			$this->assertNull( $response->get_data() );
			$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
			$this->assertSame( 'application/pdf', $headers['Content-Type'] );
			$this->assertStringContainsString( 'safe-rider.pdf', $headers['Content-Disposition'] );
			$this->assertArrayNotHasKey( 'X-EC-Download-Correlation', $headers );
			$this->assertArrayNotHasKey( 'X-EC-Affinity-Delivery', $headers );
			$this->assertSame(
				array(
					array(
						'booking_id'    => 1,
						'attachment_id' => 9,
						'actor_id'      => $user_id,
					),
				),
				$this->audit
			);
			$correlation_id = '11111111-1111-4111-8111-000000000001';
			$this->assertSame( 'private booking bytes', $this->serve( $response ) );
			$this->assertSame(
				array(
					'booking_id'     => 1,
					'attachment_id'  => 9,
					'correlation_id' => $correlation_id,
					'outcome'        => 'completed',
					'bytes_sent'     => 21,
				),
				$this->delivery_outcomes[0]
			);
			$this->assertSame( array(), $GLOBALS['extrachill_api_private_streams'] );
		}

		/** Filename and MIME metadata are defensively normalized for HTTP headers. */
		public function test_filename_injection_and_invalid_mime_are_neutralized() {
			$stream = fopen( 'php://temp', 'w+b' );
			fwrite( $stream, 'x' );
			rewind( $stream );
			$request  = new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/1/attachments/1/download' );
			$response = extrachill_api_create_private_stream_response( $stream, "../bad\r\nX-Leak: yes.pdf", "text/plain\r\nX-Leak: yes", $request );
			$headers  = $response->get_headers();

			$this->assertStringNotContainsString( "\r", $headers['Content-Disposition'] );
			$this->assertStringNotContainsString( "\n", $headers['Content-Disposition'] );
			$this->assertStringNotContainsString( '..', $headers['Content-Disposition'] );
			$this->assertSame( 'application/octet-stream', $headers['Content-Type'] );
			$this->serve( $response );
		}

		/** Single ranges are bounded while multiple and unsatisfiable ranges fail. */
		public function test_range_and_size_limits_are_enforced() {
			$this->assertSame(
				array(
					'offset' => 2,
					'length' => 4,
					'status' => 206,
				),
				extrachill_api_booking_attachment_range( 'bytes=2-5', 10 )
			);
			$this->assertSame(
				array(
					'offset' => 7,
					'length' => 3,
					'status' => 206,
				),
				extrachill_api_booking_attachment_range( 'bytes=-3', 10 )
			);
			$this->assertWPError( extrachill_api_booking_attachment_range( 'bytes=0-1,4-5', 10 ) );
			$unsatisfied = extrachill_api_booking_attachment_range( 'bytes=20-', 10 );
			$this->assertWPError( $unsatisfied );
			$this->assertSame( 'bytes */10', $unsatisfied->get_error_data()['headers']['Content-Range'] );

			add_filter( 'extrachill_api_booking_attachment_max_bytes', array( $this, 'one_byte_limit' ) );
			$stream = fopen( 'php://temp', 'w+b' );
			fwrite( $stream, 'too large' );
			rewind( $stream );
			$result = extrachill_api_create_private_stream_response( $stream, 'file.txt', 'text/plain', new WP_REST_Request( 'GET', '/' ) );
			remove_filter( 'extrachill_api_booking_attachment_max_bytes', array( $this, 'one_byte_limit' ) );
			$this->assertWPError( $result );
			$this->assertSame( 413, $result->get_error_data()['status'] );
		}

		/** Cleanup removes a partially served affinity spool and closes its stream. */
		public function test_manual_stream_cleanup_unlinks_affinity_spool() {
			$path = wp_tempnam( 'booking-download-test' );
			file_put_contents( $path, 'abcdef' );
			chmod( $path, 0600 );
			$stream   = fopen( $path, 'rb' );
			$response = extrachill_api_register_private_stream( $stream, 3, 200, extrachill_api_private_stream_headers( 'file.txt', 'text/plain', 3 ), $path );

			$this->assertSame( 'abc', $this->serve( $response ) );
			$this->assertFileDoesNotExist( $path );
			$this->assertFalse( is_resource( $stream ) );
		}

		/** Partial reads and unserved shutdown cleanup report exact terminal outcomes. */
		public function test_partial_and_unserved_streams_record_terminal_outcomes() {
			wp_set_current_user( self::factory()->user->create() );
			$partial_delivery = array(
				'booking_id'      => 10,
				'attachment_id'   => 2,
				'correlation_id'  => '11111111-1111-4111-8111-111111111110',
				'success_outcome' => 'partial',
			);
			$stream           = fopen( 'php://temp', 'w+b' );
			fwrite( $stream, 'abc' );
			rewind( $stream );
			$response = extrachill_api_register_private_stream( $stream, 5, 200, array(), null, $partial_delivery );
			$this->assertSame( 'abc', $this->serve( $response ) );
			$this->assertSame( 'partial', $this->delivery_outcomes[0]['outcome'] );
			$this->assertSame( 3, $this->delivery_outcomes[0]['bytes_sent'] );

			$interrupted_delivery = array(
				'booking_id'     => 11,
				'attachment_id'  => 3,
				'correlation_id' => '11111111-1111-4111-8111-111111111111',
			);
			$unserved             = fopen( 'php://temp', 'w+b' );
			fwrite( $unserved, 'pending' );
			rewind( $unserved );
			extrachill_api_register_private_stream( $unserved, 7, 200, array(), null, $interrupted_delivery );
			extrachill_api_cleanup_private_streams();

			$this->assertSame( 'interrupted', $this->delivery_outcomes[1]['outcome'] );
			$this->assertSame( 0, $this->delivery_outcomes[1]['bytes_sent'] );
			$this->assertFalse( is_resource( $unserved ) );
		}

		/** Rate limiting occurs before a second handoff can be issued. */
		public function test_rate_limit_precedes_handoff_issuance() {
			wp_set_current_user( self::factory()->user->create() );
			add_filter( 'extrachill_api_booking_attachment_download_rate_limit', array( $this, 'one_byte_limit' ) );
			$first = $this->dispatch( 8, 1 );
			$this->assertSame( 200, $first->get_status() );
			$this->serve( $first );
			$second = $this->dispatch( 9, 1 );
			remove_filter( 'extrachill_api_booking_attachment_download_rate_limit', array( $this, 'one_byte_limit' ) );

			$this->assertSame( 429, $second->get_status() );
			$this->assertGreaterThanOrEqual( 1, (int) $this->response_header( $second, 'retry-after' ) );
			$this->assertLessThanOrEqual( 60, (int) $this->response_header( $second, 'retry-after' ) );
			$this->assertCount( 1, $this->audit );
		}

		/** Affinity spools preserve private bytes without trusting unsafe headers. */
		public function test_affinity_spool_is_bounded_sanitized_and_cleaned() {
			$nonce = wp_generate_uuid4();
			$path  = wp_tempnam( 'booking-affinity-test' );
			file_put_contents( $path, 'part' );
			chmod( $path, 0600 );
			$delivery = array(
				'booking_id'      => 10,
				'attachment_id'   => 2,
				'correlation_id'  => '11111111-1111-4111-8111-111111111110',
				'success_outcome' => 'partial',
			);
			$response = extrachill_api_private_stream_from_affinity_response(
				array(
					'headers'  => array_merge(
						array(
							'Content-Length'      => '4',
							'Content-Type'        => 'text/plain',
							'Content-Disposition' => 'attachment; filename="../rider.txt"',
							'Content-Range'       => 'bytes 2-5/10',
						),
						extrachill_api_booking_attachment_affinity_delivery_headers( $delivery, array( 'nonce' => $nonce ) )
					),
					'body'     => '',
					'response' => array( 'code' => 206 ),
				),
				$path,
				$nonce
			);

			$this->assertSame( 206, $response->get_status() );
			$this->assertStringNotContainsString( '..', $response->get_headers()['Content-Disposition'] );
			$this->assertArrayNotHasKey( 'X-EC-Affinity-Delivery', $response->get_headers() );
			$this->assertSame( 'part', $this->serve( $response ) );
			$this->assertSame( 'partial', $this->delivery_outcomes[0]['outcome'] );
			$this->assertSame( 4, $this->delivery_outcomes[0]['bytes_sent'] );
			$this->assertFileDoesNotExist( $path );

			$bad_path = wp_tempnam( 'booking-affinity-test' );
			file_put_contents( $bad_path, 'part' );
			$bad = extrachill_api_private_stream_from_affinity_response(
				array(
					'headers'  => array(
						'Content-Length' => '4',
						'Content-Range'  => 'bytes 2-8/10',
					),
					'body'     => '',
					'response' => array( 'code' => 206 ),
				),
				$bad_path,
				$nonce
			);
			$this->assertWPError( $bad );
			$this->assertFileDoesNotExist( $bad_path );

			foreach ( array( 'bytes 0-3/0', 'bytes 4-7/4', 'bytes 6-9/8' ) as $content_range ) {
				$invalid_path = wp_tempnam( 'booking-affinity-range' );
				file_put_contents( $invalid_path, 'part' );
				$invalid = extrachill_api_private_stream_from_affinity_response(
					array(
						'headers'  => array_merge(
							array(
								'Content-Length' => '4',
								'Content-Range'  => $content_range,
							),
							extrachill_api_booking_attachment_affinity_delivery_headers( $delivery, array( 'nonce' => $nonce ) )
						),
						'body'     => '',
						'response' => array( 'code' => 206 ),
					),
					$invalid_path,
					$nonce
				);
				$this->assertWPError( $invalid, $content_range );
				$this->assertFileDoesNotExist( $invalid_path );
			}
		}

		/** Every post-consumption spool failure records one terminal failed outcome. */
		public function test_affinity_spool_failures_finalize_consumed_handoff() {
			$nonce            = wp_generate_uuid4();
			$delivery         = array(
				'booking_id'      => 10,
				'attachment_id'   => 2,
				'correlation_id'  => '11111111-1111-4111-8111-111111111110',
				'success_outcome' => 'completed',
			);
			$delivery_headers = extrachill_api_booking_attachment_affinity_delivery_headers( $delivery, array( 'nonce' => $nonce ) );
			$cases            = array(
				'missing spool'            => array( '/tmp/missing-booking-spool-' . wp_generate_uuid4(), array( 'Content-Length' => '4' ), null ),
				'mismatched length'        => array( null, array( 'Content-Length' => '5' ), null ),
				'malformed content range'  => array(
					null,
					array(
						'Content-Length' => '4',
						'Content-Range'  => 'private-range',
					),
					null,
				),
				'inconsistent range total' => array(
					null,
					array(
						'Content-Length' => '4',
						'Content-Range'  => 'bytes 6-9/8',
					),
					null,
				),
				'spool open failure'       => array( null, array( 'Content-Length' => '4' ), 'fail_open' ),
			);

			foreach ( $cases as $label => $case ) {
				$path = $case[0] ?: wp_tempnam( 'booking-affinity-failure' );
				if ( null === $case[0] ) {
					file_put_contents( $path, 'part' );
				}
				if ( 'fail_open' === $case[2] ) {
					add_filter(
						'extrachill_api_private_affinity_stream',
						static function ( $stream ) {
							if ( is_resource( $stream ) ) {
								fclose( $stream );
							}
							return false;
						}
					);
				}
				$before = count( $this->delivery_outcomes );
				$result = extrachill_api_private_stream_from_affinity_response(
					array(
						'headers'  => array_merge( $case[1], $delivery_headers ),
						'body'     => '',
						'response' => array( 'code' => isset( $case[1]['Content-Range'] ) ? 206 : 200 ),
					),
					$path,
					$nonce
				);
				remove_all_filters( 'extrachill_api_private_affinity_stream' );

				$this->assertWPError( $result, $label );
				$this->assertSame( 502, $result->get_error_data()['status'], $label );
				$this->assertCount( $before + 1, $this->delivery_outcomes, $label );
				$expected_bytes = 'missing spool' === $label ? 0 : 4;
				$this->assertSame( $expected_bytes > 0 ? 'partial' : 'failed', $this->delivery_outcomes[ $before ]['outcome'], $label );
				$this->assertSame( $expected_bytes, $this->delivery_outcomes[ $before ]['bytes_sent'], $label );
				$this->assertFileDoesNotExist( $path, $label );
			}

			$oversized = wp_tempnam( 'booking-affinity-oversized' );
			file_put_contents( $oversized, 'part' );
			add_filter( 'extrachill_api_booking_attachment_max_bytes', array( $this, 'one_byte_limit' ) );
			$before = count( $this->delivery_outcomes );
			$result = extrachill_api_private_stream_from_affinity_response(
				array(
					'headers'  => array_merge( array( 'Content-Length' => '4' ), $delivery_headers ),
					'body'     => '',
					'response' => array( 'code' => 200 ),
				),
				$oversized,
				$nonce
			);
			remove_filter( 'extrachill_api_booking_attachment_max_bytes', array( $this, 'one_byte_limit' ) );

			$this->assertWPError( $result, 'oversized spool' );
			$this->assertCount( $before, $this->delivery_outcomes, 'An impossible spool count must remain reconcilable instead of sending false accounting.' );
			$this->assertFileDoesNotExist( $oversized );
		}

		/** Affinity target spooling is non-terminal; only outer client serving completes delivery. */
		public function test_affinity_target_does_not_complete_delivery_before_outer_stream() {
			wp_set_current_user( self::factory()->user->create() );
			$nonce   = wp_generate_uuid4();
			$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/1/attachments/9/download' );
			$request->set_param( 'booking_id', 1 );
			$request->set_param( 'attachment_id', 9 );
			extrachill_api_set_route_affinity_context( $request, array( 'nonce' => $nonce ) );

			$target = extrachill_api_download_booking_attachment( $request );
			$this->assertInstanceOf( WP_REST_Response::class, $target );
			$this->assertArrayHasKey( 'X-EC-Affinity-Delivery', $target->get_headers() );
			$bytes = $this->serve( $target );
			$this->assertSame( 'private booking bytes', $bytes );
			$this->assertSame( array(), $this->delivery_outcomes, 'Loopback spool completion must not be terminal.' );

			$path = wp_tempnam( 'booking-affinity-outer' );
			file_put_contents( $path, $bytes );
			chmod( $path, 0600 );
			$outer = extrachill_api_private_stream_from_affinity_response(
				array(
					'headers'  => $target->get_headers(),
					'body'     => '',
					'response' => array( 'code' => 200 ),
				),
				$path,
				$nonce
			);
			$this->assertArrayNotHasKey( 'X-EC-Affinity-Delivery', $outer->get_headers() );
			$this->assertSame( $bytes, $this->serve( $outer ) );
			$this->assertCount( 1, $this->delivery_outcomes );
			$this->assertSame( 'completed', $this->delivery_outcomes[0]['outcome'] );
			$this->assertSame( strlen( $bytes ), $this->delivery_outcomes[0]['bytes_sent'] );
		}

		/** HEAD and REST envelope requests cannot consume a private handoff. */
		public function test_head_and_envelope_requests_do_not_reach_events() {
			wp_set_current_user( self::factory()->user->create() );
			$head = new WP_REST_Request( 'HEAD', '/extrachill/v1/events/bookings/1/attachments/1/download' );
			$this->assertSame( 405, rest_do_request( $head )->get_status() );

			$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/bookings/1/attachments/1/download' );
			$request->set_query_params( array( '_envelope' => '1' ) );
			$this->assertSame( 400, rest_do_request( $request )->get_status() );
			$this->assertSame( array(), $this->audit );
		}

		/** Return a one-byte transport limit. */
		public function one_byte_limit() {
			return 1;
		}

		/** Return the deterministic test atomic store. */
		public function use_test_rate_limit_store() {
			return array( $this, 'increment_test_rate_limit' );
		}

		/** Increment one test rate counter. */
		public function increment_test_rate_limit( $key ) {
			$this->rate_counts[ $key ] = ( $this->rate_counts[ $key ] ?? 0 ) + 1;
			return $this->rate_counts[ $key ];
		}

		/** Register the hidden Events descriptor contract. */
		private function register_ability() {
			$name    = 'extrachill/download-booking-attachment';
			$ability = WP_Abilities_Registry::get_instance()->register(
				$name,
				array(
					'label'               => 'Download booking attachment',
					'description'         => 'Controlled Events handoff contract.',
					'category'            => 'extrachill-api-tests',
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'booking_id'    => array( 'type' => 'integer' ),
							'attachment_id' => array( 'type' => 'integer' ),
						),
						'required'             => array( 'booking_id', 'attachment_id' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => function ( array $input ) {
						return in_array( $input['booking_id'], array( 2, 3 ), true )
							? new WP_Error( 'venue_action_forbidden', 'Internal venue policy.', array( 'status' => 403 ) )
							: true;
					},
					'execute_callback'    => function ( array $input ) {
						$this->audit[] = array(
							'booking_id'    => $input['booking_id'],
							'attachment_id' => $input['attachment_id'],
							'actor_id'      => get_current_user_id(),
						);
						return array(
							'stream_token'   => str_repeat( dechex( min( 15, $input['booking_id'] ) ), 64 ),
							'correlation_id' => '11111111-1111-4111-8111-' . str_pad( (string) $input['booking_id'], 12, '0', STR_PAD_LEFT ),
							'expires_at'     => gmdate( 'c', time() + 300 ),
							'filename'       => 'safe-rider.pdf',
							'mime_type'      => 'application/pdf',
						);
					},
				)
			);
			$this->assertInstanceOf( WP_Ability::class, $ability );
			$this->registered_abilities[] = $name;

			$name    = 'extrachill/record-booking-attachment-delivery';
			$ability = WP_Abilities_Registry::get_instance()->register(
				$name,
				array(
					'label'               => 'Record booking attachment delivery',
					'description'         => 'Controlled terminal delivery contract.',
					'category'            => 'extrachill-api-tests',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => '__return_true',
					'execute_callback'    => function ( array $input ) {
						$this->delivery_outcomes[] = $input;
						return array( 'recorded' => true );
					},
				)
			);
			$this->assertInstanceOf( WP_Ability::class, $ability );
			$this->registered_abilities[] = $name;
		}

		/** Dispatch one request through the real REST server. */
		private function dispatch( $booking_id, $attachment_id ) {
			$request  = new WP_REST_Request(
				'GET',
				sprintf( '/extrachill/v1/events/bookings/%d/attachments/%d/download', $booking_id, $attachment_id )
			);
			$response = rest_do_request( $request );

			return extrachill_api_protect_booking_attachment_download_response( $response, rest_get_server(), $request );
		}

		/** Capture manual byte serving for one response. */
		private function serve( WP_REST_Response $response ) {
			ob_start();
			$served = extrachill_api_serve_private_stream( false, $response, new WP_REST_Request(), rest_get_server() );
			$bytes  = ob_get_clean();
			$this->assertTrue( $served );
			return $bytes;
		}

		/** Return a response header without relying on retained key casing. */
		private function response_header( WP_REST_Response $response, $name ) {
			foreach ( $response->get_headers() as $key => $value ) {
				if ( strtolower( $key ) === strtolower( $name ) ) {
					return (string) $value;
				}
			}
			return '';
		}
	}
}
