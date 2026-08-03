<?php
/**
 * Event vendor request domain tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\VendorRequestAbilities;
use ExtraChillEvents\Core\VendorRequestAuthorization;
use ExtraChillEvents\Core\VendorRequestNotificationService;
use ExtraChillEvents\Core\VendorRequestRepository;
use ExtraChillEvents\Core\VendorRequestService;

require_once __DIR__ . '/Support/BookingTestHarness.php';

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ) {
		return filter_var( (string) $value, FILTER_VALIDATE_URL ) ? (string) $value : '';
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $value ) {
		return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $value ) {
		return '<p>' . $value . '</p>';
	}
}
if ( ! function_exists( 'get_home_url' ) ) {
	function get_home_url( $blog_id = null, $path = '' ) {
		unset( $blog_id );
		return 'https://events.example' . $path;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/VendorRequestSchema.php';
require_once dirname( __DIR__ ) . '/inc/Core/VendorRequestAuthorization.php';
require_once dirname( __DIR__ ) . '/inc/Core/VendorRequestRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/VendorRequestService.php';
require_once dirname( __DIR__ ) . '/inc/Core/VendorRequestNotificationService.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/VendorRequestAbilities.php';

/** Proves vendor policy remains event-scoped and private. */
final class VendorRequestDomainTest extends BookingTestCase {

	/** @var VendorRequestMemoryRepository */
	private $repository;
	/** @var VendorRequestTestAuthorization */
	private $authorization;
	/** @var VendorRequestService */
	private $service;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'       => 7,
			'uuid'          => 0,
			'options'       => array(),
			'abilities'     => array(),
			'actions'       => array(),
			'fired_actions' => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
		$this->repository          = new VendorRequestMemoryRepository();
		$this->authorization       = new VendorRequestTestAuthorization();
		$this->service             = new VendorRequestService( $this->repository, $this->authorization );
	}

	public function test_exact_coordinator_controls_window_while_current_organizer_may_review(): void {
		$request = $this->open_request();
		$this->assertSame( 12, $request['coordinator_user_id'] );
		$this->assertSame( 'vendor_request_forbidden', $this->service->set_request_open( $request['id'], false, 1, 'close-other', 13 )->get_error_code() );
		$this->assertSame( 'closed', $this->service->set_request_open( $request['id'], false, 1, 'close-coordinator', 12 )['status'] );
		$this->assertSame( 'open', $this->service->set_request_open( $request['id'], true, 2, 'reopen-coordinator', 12 )['status'] );

		$application = $this->apply();
		$rows        = $this->service->list_applications( $request['id'], 13 );
		$this->assertSame( $application['public_id'], $rows[0]['public_id'] );
		$this->authorization->organizer_venues[13] = array( 56 );
		$this->assertSame( 'vendor_request_forbidden', $this->service->list_applications( $request['id'], 13 )->get_error_code() );
	}

	public function test_public_projection_and_event_type_relationship_do_not_leak_contacts(): void {
		$request = $this->open_request();
		$public  = $this->service->public_request_for_event( 900 );
		$this->assertSame( array( 'public_id', 'event_id', 'policy', 'status' ), array_keys( $public ) );
		$this->assertStringNotContainsString( 'coordinator', wp_json_encode( $public ) );
		$this->assertStringNotContainsString( 'email', wp_json_encode( $public ) );

		$this->service->set_request_open( $request['id'], false, 1, 'close-public', 12 );
		$this->assertNull( $this->service->public_request_for_event( 900 ) );
		$this->assertStringContainsString( "'eventType'", file_get_contents( dirname( __DIR__ ) . '/inc/Core/BookingEventConversionService.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source contract fixture.
		$this->assertFileDoesNotExist( dirname( __DIR__ ) . '/inc/Core/VendorTaxonomy.php' );
	}

	public function test_application_is_private_consent_bound_and_idempotent(): void {
		$this->open_request();
		$input = $this->application_input();
		$first = $this->service->apply( $input );
		$retry = $this->service->apply( $input );
		$this->assertSame( $first['public_id'], $retry['public_id'] );
		$this->assertSame( $first['access_token'], $retry['access_token'] );
		$this->assertCount( 1, $this->repository->applications );
		$this->assertArrayHasKey( 'access_token', $first );

		$private = $this->service->list_applications( 1, 12 )[0];
		$this->assertSame( 'vendor@example.com', $private['contact']['email'] );
		$this->assertSame( '555-0100', $private['contact']['phone'] );
		$this->assertArrayNotHasKey( 'contact', $first );
		$this->assertStringNotContainsString( 'vendor@example.com', wp_json_encode( $this->repository->activity ) );

		$changed                  = $input;
		$changed['business_name'] = 'Different Vendor';
		$this->assertSame( 'vendor_request_idempotency_conflict', $this->service->apply( $changed )->get_error_code() );
		$input['idempotency_key'] = 'missing-consent';
		$input['contact_consent'] = false;
		$this->assertSame( 'vendor_application_consent_required', $this->service->apply( $input )->get_error_code() );
	}

	public function test_close_race_rechecks_locked_request_and_preserves_zero_new_rows(): void {
		$this->open_request();
		$this->repository->close_on_lock = true;
		$result                          = $this->service->apply( $this->application_input() );
		$this->assertSame( 'vendor_request_not_open', $result->get_error_code() );
		$this->assertCount( 0, $this->repository->applications );
	}

	public function test_opaque_withdrawal_revokes_contact_and_blocks_correspondence(): void {
		$this->open_request();
		$receipt = $this->apply();
		$result  = $this->service->withdraw( $receipt['public_id'], $receipt['access_token'], 1, 'withdraw-1' );
		$this->assertSame( 'withdrawn', $result['status'] );
		$private = $this->service->list_applications( 1, 12 )[0];
		$this->assertNull( $private['contact'] );
		$this->assertSame( 2, $private['consent_version'] );
		$this->assertNotNull( $private['revoked_at'] );
		$this->assertSame( 'vendor_application_contact_unavailable', $this->service->contact_applicant( 1, 'Hello', 'Message', 'contact-withdrawn', 12, '__return_true' )->get_error_code() );
	}

	public function test_managed_correspondence_has_product_identity_footer_and_receipt(): void {
		$this->open_request();
		$this->apply();
		$queued = array();
		$result = $this->service->contact_applicant(
			1,
			'Market details',
			'Please confirm your arrival time.',
			'contact-1',
			12,
			static function ( array $input ) use ( &$queued ): array {
				$queued = $input;
				return array(
					'success'   => true,
					'action_id' => 77,
				);
			}
		);
		$this->assertSame(
			array(
				'status'    => 'queued',
				'action_id' => 77,
			),
			$result
		);
		$this->assertSame( 'Extra Chill Events', $queued['from_name'] );
		$this->assertSame( '', $queued['cc'] );
		$this->assertStringContainsString( 'Powered by Extra Chill', $queued['body'] );
		$this->assertStringNotContainsString( 'Extra Chill Bot', $queued['body'] );
		$this->assertStringNotContainsString( 'Chris', $queued['body'] );
		$this->assertSame( 'vendor@example.com', $queued['to'] );
		$this->assertSame(
			$result,
			$this->service->contact_applicant(
				1,
				'Market details',
				'Please confirm your arrival time.',
				'contact-1',
				12,
				static function (): void {
					throw new RuntimeException( 'Must not queue twice.' );
				}
			)
		);
	}

	/** Coordinator notification uses a deterministic, contact-free Users receipt. */
	public function test_application_notification_receipt_contains_no_applicant_contact(): void {
		$this->open_request();
		$captured = array();
		$service  = new VendorRequestNotificationService(
			$this->repository,
			$this->authorization,
			static function ( array $recipients, array $payload ) use ( &$captured ): array {
				$captured = compact( 'recipients', 'payload' );
				return array( 'recipients' => array( 12 => array( 'status' => 'inserted' ) ) );
			},
			static function (): int {
				return 99;
			}
		);
		$receipt = $service->notify_change( array( 'kind' => 'application_submitted', 'request_id' => 1, 'application_id' => 4, 'version' => 1 ) );
		$this->assertSame( array( 12 ), $captured['recipients'] );
		$this->assertSame( VendorRequestNotificationService::PRODUCER, $captured['payload']['producer'] );
		$this->assertStringNotContainsString( 'email', wp_json_encode( $captured ) );
		$this->assertSame( 'inserted', $receipt['payload']['status'] );
	}

	public function test_abilities_are_hidden_and_ui_is_accessible_and_mobile_bounded(): void {
		$abilities = new VendorRequestAbilities( $this->service );
		$abilities->register();
		$this->assertArrayHasKey( 'extrachill-events/apply-to-vendor-request', $GLOBALS['ec_artist_test']['abilities'] );
		foreach ( $GLOBALS['ec_artist_test']['abilities'] as $name => $definition ) {
			if ( false !== strpos( $name, 'vendor' ) ) {
				$this->assertFalse( $definition['meta']['show_in_rest'], $name );
			}
		}
		$ui  = file_get_contents( dirname( __DIR__ ) . '/inc/core/vendor-request-workspace.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source contract fixture.
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/vendor-request.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source contract fixture.
		$this->assertStringContainsString( 'Apply as a vendor', $ui );
		$this->assertStringContainsString( 'aria-live="polite"', $ui );
		$this->assertStringContainsString( 'role="alert"', $ui );
		$this->assertStringContainsString( '@media (max-width: 600px)', $css );
		$this->assertStringContainsString( 'min-height: 44px', $css );
	}

	private function open_request(): array {
		return $this->service->open_request(
			900,
			array(
				'categories'     => array( 'Food', 'Art' ),
				'power_required' => true,
			),
			'open-900',
			12
		);
	}

	private function apply(): array {
		return $this->service->apply( $this->application_input() );
	}

	private function application_input(): array {
		return array(
			'event_id'        => 900,
			'idempotency_key' => 'apply-1',
			'business_name'   => 'Lowcountry Goods',
			'contact_name'    => 'Vendor Person',
			'contact_email'   => 'vendor@example.com',
			'contact_phone'   => '555-0100',
			'category'        => 'Art',
			'website_url'     => 'https://vendor.example',
			'footprint'       => '10 x 10 feet',
			'power_needs'     => 'One 20 amp circuit',
			'insurance_notes' => 'Certificate available',
			'message'         => 'We sell handmade goods.',
			'contact_consent' => true,
		);
	}
}

/** In-memory persistence double preserving private fields and receipts. */
final class VendorRequestMemoryRepository extends VendorRequestRepository {
	public $requests      = array();
	public $applications  = array();
	public $activity      = array();
	public $close_on_lock = false;

	public function create_request( array $data ) {
		$id                    = count( $this->requests ) + 1;
		$this->requests[ $id ] = array_merge(
			$data,
			array(
				'id'         => $id,
				'public_id'  => '123e4567-e89b-42d3-a456-000000000001',
				'status'     => 'open',
				'version'    => 1,
				'created_at' => '2026-08-02 20:00:00',
				'updated_at' => '2026-08-02 20:00:00',
			)
		);
		return $this->requests[ $id ];
	}

	public function get_request( int $id, bool $for_update = false ) {
		if ( $for_update && $this->close_on_lock && isset( $this->requests[ $id ] ) ) {
			$this->requests[ $id ]['status'] = 'closed';
		}
		return $this->requests[ $id ] ?? null;
	}

	public function get_request_by_event( int $event_id ) {
		foreach ( $this->requests as $request ) {
			if ( $request['event_id'] === $event_id ) {
				return $request;
			}
		}
		return null;
	}

	public function get_request_by_public_id( string $public_id ) {
		foreach ( $this->requests as $request ) {
			if ( $request['public_id'] === $public_id ) {
				return $request;
			}
		}
		return null;
	}

	public function update_request( int $id, int $expected_version, array $changes ) {
		if ( ! isset( $this->requests[ $id ] ) || $this->requests[ $id ]['version'] !== $expected_version ) {
			return new WP_Error( 'vendor_request_version_conflict' );
		}
		$this->requests[ $id ] = array_merge( $this->requests[ $id ], $changes, array( 'version' => $expected_version + 1 ) );
		return $this->requests[ $id ];
	}

	public function create_application( array $data ) {
		$id                        = count( $this->applications ) + 1;
		$this->applications[ $id ] = array_merge(
			$data,
			array(
				'id'              => $id,
				'public_id'       => '223e4567-e89b-42d3-a456-' . str_pad( (string) $id, 12, '0', STR_PAD_LEFT ),
				'status'          => 'submitted',
				'version'         => 1,
				'consent_version' => 1,
				'consented_at'    => '2026-08-02 20:00:00',
				'revoked_at'      => null,
				'private_notes'   => null,
				'created_at'      => '2026-08-02 20:00:00',
				'updated_at'      => '2026-08-02 20:00:00',
			)
		);
		return $this->public_application( $this->applications[ $id ] );
	}

	public function get_application( int $id, bool $for_update = false ) {
		unset( $for_update );
		return isset( $this->applications[ $id ] ) ? $this->public_application( $this->applications[ $id ] ) : null;
	}

	public function get_application_by_public_id( string $public_id, bool $for_update = false ) {
		unset( $for_update );
		foreach ( $this->applications as $application ) {
			if ( $application['public_id'] === $public_id ) {
				return $this->public_application( $application );
			}
		}
		return null;
	}

	public function find_application_retry( int $request_id, string $key ) {
		foreach ( $this->applications as $application ) {
			if ( $application['request_id'] === $request_id && $application['idempotency_key'] === $key ) {
				$row                 = $this->public_application( $application );
				$row['_replay_hash'] = $application['request_hash'];
				return $row;
			}
		}
		return null;
	}

	public function list_applications( int $request_id, int $limit = 100 ) {
		$rows = array_filter(
			$this->applications,
			static function ( $application ) use ( $request_id ): bool {
				return $application['request_id'] === $request_id;
			}
		);
		return array_map( array( $this, 'public_application' ), array_slice( array_values( $rows ), 0, $limit ) );
	}

	public function update_application( int $id, int $expected_version, array $changes ) {
		if ( ! isset( $this->applications[ $id ] ) || $this->applications[ $id ]['version'] !== $expected_version ) {
			return new WP_Error( 'vendor_request_version_conflict' );
		}
		if ( isset( $changes['contact_payload'] ) ) {
			$changes['contact'] = json_decode( $changes['contact_payload'], true );
			unset( $changes['contact_payload'] );
		}
		$this->applications[ $id ] = array_merge( $this->applications[ $id ], $changes, array( 'version' => $expected_version + 1 ) );
		return $this->public_application( $this->applications[ $id ] );
	}

	public function append_activity( array $data ) {
		$data['id']       = count( $this->activity ) + 1;
		$this->activity[] = $data;
		return $data;
	}

	public function find_activity( int $request_id, string $key ) {
		foreach ( $this->activity as $activity ) {
			if ( $activity['request_id'] === $request_id && $activity['idempotency_key'] === $key ) {
				return $activity;
			}
		}
		return null;
	}

	public function verify_application_token( string $public_id, string $token ): bool {
		foreach ( $this->applications as $application ) {
			if ( $application['public_id'] === $public_id ) {
				return hash_equals( $application['access_token_hash'], hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ) );
			}
		}
		return false;
	}

	public function public_application( array $application ): array {
		$application['contact'] = null === $application['revoked_at'] ? $application['contact'] : null;
		unset( $application['access_token_hash'], $application['idempotency_key'], $application['request_hash'] );
		return $application;
	}
}

/** Authorization double with exact event and venue membership policy. */
final class VendorRequestTestAuthorization extends VendorRequestAuthorization {
	public $organizer_venues = array(
		12 => array( 55 ),
		13 => array( 55 ),
	);

	public function event_context( int $event_id ) {
		return 900 === $event_id ? array(
			'event_id'      => 900,
			'venue_term_id' => 55,
		) : new WP_Error( 'invalid_vendor_request_event' );
	}

	public function authorize_organizer( array $request, int $user_id ) {
		return 900 === $request['event_id'] && 55 === $request['venue_term_id'] && in_array( 55, $this->organizer_venues[ $user_id ] ?? array(), true ) ? true : new WP_Error( 'vendor_request_forbidden' );
	}

	public function authorize_coordinator( array $request, int $user_id ) {
		return (int) $request['coordinator_user_id'] === $user_id ? $this->authorize_organizer( $request, $user_id ) : new WP_Error( 'vendor_request_forbidden' );
	}
}
