<?php
/**
 * Local-support matching notification tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\LocalSupportNotificationAdapter;
use ExtraChillEvents\Core\LocalSupportNotificationService;
use ExtraChillEvents\Core\LocalSupportAuthorization;
use ExtraChillEvents\Core\LocalSupportRepository;
use ExtraChillEvents\Core\VenueMembershipRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

/** In-memory implementation of the #420 durable notification adapter contract. */
final class LocalSupportNotificationAdapterStub {
	public $intents    = array();
	public $attempts   = array();
	public $terminals  = array();
	public $organizers = array( 77 );
	public $workspace  = 'https://events.example/local-support/15';
	public $source;
	public $processed = array();

	public function notification_source( array $change ) {
		unset( $change );
		return $this->source;
	}

	public function append_notification_intent( array $intent ) {
		if ( isset( $this->intents[ $intent['idempotency_key'] ] ) ) {
			return $this->intents[ $intent['idempotency_key'] ];
		}
		$this->intents[ $intent['idempotency_key'] ] = $intent;
		return $intent;
	}
	public function pending_notification_intents( int $limit ): array {
		return array_slice( array_values( $this->intents ), 0, $limit );
	}
	public function pending_notification_sources(): array {
		return array();
	}
	public function mark_notification_source_processed( array $activity ): array {
		$this->processed[] = $activity['id'];
		return array( 'source_activity_id' => $activity['id'] );
	}
	public function notification_terminal( array $intent ) {
		$key = $intent['idempotency_key'];
		return $this->terminals[ $key ] ?? null;
	}
	public function notification_attempt_count( array $intent ): int {
		$key = $intent['idempotency_key'];
		return count( $this->attempts[ $key ] ?? array() );
	}
	public function record_notification_attempt( array $intent, int $attempt, string $due_at, string $error ): array {
		$key                      = $intent['idempotency_key'];
		$this->attempts[ $key ][] = compact( 'attempt', 'due_at', 'error' );
		return array(
			'status'  => 'retrying',
			'attempt' => $attempt,
		);
	}
	public function record_notification_terminal( array $intent, string $status, array $details ): array {
		$key                     = $intent['idempotency_key'];
		$this->terminals[ $key ] = compact( 'status', 'details' );
		return $this->terminals[ $key ];
	}
	public function organizer_recipient_ids(): array {
		return $this->organizers;
	}
	public function workspace_url(): string {
		return $this->workspace;
	}
}

/** Activity-backed repository double for the production adapter. */
final class LocalSupportNotificationRepositoryStub extends LocalSupportRepository {
	public $request;
	public $interest;
	public $activity = array();

	public function get_request( int $id, bool $for_update = false ) {
		unset( $for_update );
		return $id === (int) $this->request['id'] ? $this->request : null;
	}

	public function get_interest( int $id, bool $for_update = false ) {
		unset( $for_update );
		return $id === (int) $this->interest['id'] ? $this->interest : null;
	}

	public function find_activity_for_change( int $request_id, ?int $interest_id, string $kind, int $version ) {
		foreach ( $this->activity as $row ) {
			if ( $row['request_id'] === $request_id && $row['interest_id'] === $interest_id && $row['kind'] === $kind && $row['result_version'] === $version ) {
				return $row;
			}
		}
		return null;
	}

	public function get_activity( int $activity_id, ?int $request_id = null ) {
		foreach ( $this->activity as $row ) {
			if ( $row['id'] === $activity_id && ( null === $request_id || $row['request_id'] === $request_id ) ) {
				return $row;
			}
		}
		return null;
	}

	public function find_activity( int $request_id, string $idempotency_key ) {
		foreach ( $this->activity as $row ) {
			if ( $row['request_id'] === $request_id && $row['idempotency_key'] === $idempotency_key ) {
				return $row;
			}
		}
		return null;
	}

	public function append_activity( array $data ) {
		$data['id']         = count( $this->activity ) + 1;
		$data['created_at'] = $data['created_at'] ?? '2026-07-27 00:00:00';
		$this->activity[]   = $data;
		return $data;
	}

	public function hydrate_activity( array $row ): array {
		return $row;
	}

	public function pending_notification_intents( int $limit = 50 ): array {
		$terminal_hashes = array_column(
			array_filter(
				$this->activity,
				static function ( $row ) {
					return 'notification_terminal' === $row['kind'];
				}
			),
			'request_hash'
		);
		$rows            = array_filter(
			$this->activity,
			static function ( $row ) use ( $terminal_hashes ) {
				return 'notification_intent' === $row['kind'] && ! in_array( $row['request_hash'], $terminal_hashes, true );
			}
		);
		return array_slice( array_values( $rows ), 0, $limit );
	}

	public function pending_notification_sources( int $limit = 50 ): array {
		$processed = array_column( array_filter( $this->activity, static function ( $row ) { return 'notification_source_processed' === $row['kind']; } ), 'payload' );
		$ids       = array_map( static function ( $payload ) { return $payload['source_activity_id']; }, $processed );
		$rows      = array_filter(
			$this->activity,
			static function ( $row ) use ( $ids ) {
				return in_array( $row['kind'], array( 'request_opened', 'interest_expressed', 'interest_status_changed', 'contact_consent_granted', 'contact_consent_revoked' ), true ) && ! in_array( $row['id'], $ids, true );
			}
		);
		return array_slice( array_values( $rows ), 0, $limit );
	}

	public function notification_terminal( int $request_id, string $request_hash ) {
		foreach ( array_reverse( $this->activity ) as $row ) {
			if ( $row['request_id'] === $request_id && $row['request_hash'] === $request_hash && 'notification_terminal' === $row['kind'] ) {
				return $row;
			}
		}
		return null;
	}

	public function notification_attempt_count( int $request_id, string $request_hash ) {
		return count(
			array_filter(
				$this->activity,
				static function ( $row ) use ( $request_id, $request_hash ) {
					return $row['request_id'] === $request_id && $row['request_hash'] === $request_hash && 'notification_attempt' === $row['kind'];
				}
			)
		);
	}
}

/** Organizer authorization double for production-adapter tests. */
final class LocalSupportNotificationAuthorizationStub extends LocalSupportAuthorization {
	public function authorize_organizer( array $request, int $user_id ) {
		unset( $request );
		return 77 === $user_id ? true : new WP_Error( 'local_support_forbidden' );
	}
}

/** Active venue member resolver double for production-adapter tests. */
final class LocalSupportNotificationMembershipsStub extends VenueMembershipRepository {
	public function list_for_venue( int $venue_term_id, array $filters = array(), int $actor_user_id = 0 ) {
		unset( $venue_term_id, $filters, $actor_user_id );
		return array( array( 'user_id' => 77 ) );
	}
}

/** Covers matching, exclusions, authorization, privacy, replay, and dependency failures. */
final class LocalSupportNotificationTest extends TestCase {
	private $adapter;
	private $eligibility_calls;
	private $candidates;
	private $deliveries;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id' => 7,
			'stack'   => array(),
			'uuid'    => 0,
		);
		$this->adapter             = new LocalSupportNotificationAdapterStub();
		$this->eligibility_calls   = array();
		$this->deliveries          = array();
		$this->candidates          = array(
			array(
				'artist_profile_id' => 501,
				'artist_term_id'    => 91,
				'manager_user_ids'  => array( 41, 42 ),
				'email'             => 'must-not-leak@example.com',
			),
			array(
				'artist_profile_id' => 502,
				'artist_term_id'    => 88,
				'manager_user_ids'  => array( 43 ),
			),
		);
		$this->adapter->source     = array(
			'request'  => $this->request(),
			'interest' => array( 'artist_term_id' => 91 ),
			'activity' => array(
				'id'         => 301,
				'kind'       => 'request_opened',
				'request_id' => 15,
			),
		);
	}

	private function service( ?callable $delivery = null ): LocalSupportNotificationService {
		return new LocalSupportNotificationService(
			$this->adapter,
			function ( array $input ): array {
				$this->eligibility_calls[] = $input;
				return array( 'candidates' => $this->candidates );
			},
			static function (): array {
				return array(
					'title'              => 'Touring Band at The Room',
					'location_term_id'   => 12,
					'location_slug'      => 'charleston-sc',
					'venue_term_id'      => 55,
					'artist_term_ids'    => array( 88 ),
					'artist_profile_ids' => array( 502 ),
				);
			},
			$delivery ? $delivery : function ( array $recipients, array $payload ): array {
				$this->deliveries[] = compact( 'recipients', 'payload' );
				return array( 'recipients' => array( $recipients[0] => array( 'status' => 'inserted' ) ) );
			},
			static function (): int {
				return 99;
			}
		);
	}

	private function request(): array {
		return array(
			'id'            => 15,
			'event_id'      => 700,
			'status'        => 'open',
			'genre'         => 'Indie Rock',
			'contact_email' => 'private@example.com',
		);
	}

	public function test_open_request_matches_canonical_scene_excludes_attached_artist_and_is_idempotent(): void {
		$service  = $this->service();
		$activity = array(
			'id'         => 301,
			'kind'       => 'request_opened',
			'request_id' => 15,
		);
		$first    = $service->queue_request_opened( $this->request(), $activity );
		$second   = $service->queue_request_opened( $this->request(), $activity );

		$this->assertCount( 2, $first );
		$this->assertCount( 2, $second );
		$this->assertCount( 2, $this->adapter->intents );
		$this->assertSame( 'charleston-sc', $this->eligibility_calls[0]['scene_slug'] );
		$this->assertSame( array( 502 ), $this->eligibility_calls[0]['exclude_artist_ids'] );
		$this->assertSame( 'Indie Rock', $this->eligibility_calls[0]['genre'] );
		$this->assertSame( array( 41, 42 ), array_column( array_values( $this->adapter->intents ), 'recipient_id' ) );
	}

	public function test_candidate_delivery_revalidates_manager_and_discloses_no_private_fields(): void {
		$intent = $this->service()->queue_request_opened(
			$this->request(),
			array(
				'id'         => 301,
				'kind'       => 'request_opened',
				'request_id' => 15,
			)
		)[0];
		$result = $this->service()->reconcile_intent( $intent );

		$this->assertSame( 'delivered', $result['status'] );
		$this->assertCount( 2, $this->eligibility_calls );
		$payload = $this->deliveries[0]['payload'];
		$this->assertSame( 'extrachill-events-local-support', $payload['producer'] );
		$this->assertSame( 'https://events.example/local-support/15', $payload['link'] );
		$this->assertStringNotContainsString( 'private@example.com', wp_json_encode( $payload ) );
		$this->assertStringNotContainsString( 'must-not-leak@example.com', wp_json_encode( $payload ) );
	}

	public function test_interest_change_notifies_only_current_organizers_and_rechecks_on_delivery(): void {
		$service = $this->service();
		$queued  = $service->queue_interest_changed(
			$this->request(),
			array(
				'artist_term_id' => 91,
				'contact_phone'  => '555-0100',
			),
			array(
				'id'         => 401,
				'kind'       => 'interest_expressed',
				'request_id' => 15,
			)
		);

		$this->assertSame( array( 77 ), array_column( $queued, 'recipient_id' ) );
		$this->adapter->organizers = array();
		$result                    = $service->reconcile_intent( $queued[0] );
		$this->assertSame( 'suppressed', $result['status'] );
		$this->assertSame( 'recipient_no_longer_authorized', $result['details']['reason'] );
		$this->assertEmpty( $this->deliveries );
	}

	public function test_failed_receipts_retry_boundedly_and_replay_terminal_result(): void {
		$service = $this->service(
			static function ( array $recipients ): array {
				return array( 'recipients' => array( $recipients[0] => array( 'status' => 'failed' ) ) );
			}
		);
		$intent  = $service->queue_request_opened(
			$this->request(),
			array(
				'id'         => 301,
				'kind'       => 'request_opened',
				'request_id' => 15,
			)
		)[0];
		for ( $attempt = 1; $attempt <= LocalSupportNotificationService::MAX_ATTEMPTS; ++$attempt ) {
			$result = $service->reconcile_intent( $intent );
		}
		$replay = $service->reconcile_intent( $intent );

		$this->assertSame( 'suppressed', $result['status'] );
		$this->assertSame( 'delivery_poisoned', $result['details']['reason'] );
		$this->assertSame( LocalSupportNotificationService::MAX_ATTEMPTS, $result['details']['attempt'] );
		$this->assertSame( $result, $replay );
	}

	public function test_landed_change_hook_uses_a_production_adapter_and_maps_domain_payloads(): void {
		LocalSupportNotificationService::register();
		$callbacks = array_column( $GLOBALS['ec_test_filters'][ LocalSupportNotificationService::CHANGE_HOOK ][10], 0 );
		$this->assertContains(
			array( LocalSupportNotificationService::class, 'handle_change' ),
			$callbacks
		);
		$this->assertTrue( LocalSupportNotificationService::authorize_producer( false, LocalSupportNotificationService::PRODUCER ) );
		$this->assertFalse( LocalSupportNotificationService::authorize_producer( false, 'other-producer' ) );

		$runtime = new ReflectionMethod( LocalSupportNotificationService::class, 'runtime' );
		$runtime->setAccessible( true );
		$service = $runtime->invoke( null );
		$adapter = new ReflectionProperty( LocalSupportNotificationService::class, 'adapter' );
		$adapter->setAccessible( true );
		$this->assertInstanceOf( LocalSupportNotificationAdapter::class, $adapter->getValue( $service ) );

		$queued = $this->service()->queue_change(
			array(
				'kind'        => 'request_opened',
				'request_id'  => 15,
				'interest_id' => null,
				'version'     => 1,
			)
		);
		$this->assertCount( 2, $queued );
	}

	public function test_production_adapter_uses_domain_activity_and_workspace_fails_closed(): void {
		$repository             = new LocalSupportNotificationRepositoryStub();
		$repository->request    = array(
			'id'             => 15,
			'event_id'       => 700,
			'venue_term_id'  => 55,
			'organizer_type' => 'venue',
			'organizer_id'   => 55,
			'status'         => 'open',
			'contact_email'  => 'private@example.com',
		);
		$repository->interest   = array(
			'id'             => 8,
			'request_id'     => 15,
			'artist_term_id' => 91,
		);
		$repository->activity[] = array(
			'id'              => 1,
			'request_id'      => 15,
			'interest_id'     => null,
			'kind'            => 'request_opened',
			'actor_user_id'   => 12,
			'idempotency_key' => 'open-15',
			'request_hash'    => hash( 'sha256', 'open-15' ),
			'result_version'  => 1,
			'payload'         => array( 'status' => 'open' ),
		);
		$adapter                = new LocalSupportNotificationAdapter(
			$repository,
			new LocalSupportNotificationAuthorizationStub(),
			new LocalSupportNotificationMembershipsStub()
		);
		$service                = new LocalSupportNotificationService(
			$adapter,
			static function (): array {
				return array(
					'candidates' => array(
						array(
							'artist_profile_id' => 501,
							'artist_term_id'    => 91,
							'manager_user_ids'  => array( 41 ),
						),
					),
				);
			},
			static function (): array {
				return array(
					'title'              => 'Touring Band at The Room',
					'location_term_id'   => 12,
					'location_slug'      => 'charleston-sc',
					'venue_term_id'      => 55,
					'artist_term_ids'    => array(),
					'artist_profile_ids' => array(),
				);
			},
			null,
			static function (): int {
				return 99; }
		);
		$queued                 = $service->queue_change(
			array(
				'kind'        => 'request_opened',
				'request_id'  => 15,
				'interest_id' => null,
				'version'     => 1,
			)
		);
		$repository->activity   = array_values( array_filter( $repository->activity, static function ( $row ) { return 'notification_source_processed' !== $row['kind']; } ) );
		$summary                = $service->reconcile_pending();
		$last                   = end( $repository->activity );
		$retry                  = $last['payload'];

		$this->assertSame( array( 'request_opened', 'notification_intent', 'notification_source_processed', 'notification_attempt' ), array_column( $repository->activity, 'kind' ) );
		$this->assertSame( 1, $summary['recovered'] );
		$this->assertCount( 1, array_filter( $repository->activity, static function ( $row ) { return 'notification_intent' === $row['kind']; } ) );
		$this->assertSame( 'retrying', $retry['status'] );
		$this->assertSame( 'local_support_workspace_unavailable', $retry['error_code'] );
		$this->assertStringNotContainsString( 'private@example.com', wp_json_encode( $repository->activity[1]['payload'] ) );
		$this->assertSame( array( 77 ), $adapter->organizer_recipient_ids( 15 ) );
	}

	public function test_missing_dependency_contracts_fail_closed_with_actionable_errors(): void {
		$missing_domain = ( new LocalSupportNotificationService(
			null,
			null,
			static function (): array {
				return array(
					'title'              => 'Show',
					'location_term_id'   => 12,
					'location_slug'      => 'charleston-sc',
					'venue_term_id'      => 55,
					'artist_term_ids'    => array(),
					'artist_profile_ids' => array(),
				);
			}
		) )->queue_interest_changed(
			$this->request(),
			array( 'artist_term_id' => 91 ),
			array(
				'id'         => 401,
				'kind'       => 'interest_expressed',
				'request_id' => 15,
			)
		);
		$missing_artist = ( new LocalSupportNotificationService(
			$this->adapter,
			null,
			static function (): array {
				return array(
					'title'              => 'Show',
					'location_term_id'   => 12,
					'location_slug'      => 'charleston-sc',
					'venue_term_id'      => 55,
					'artist_term_ids'    => array(),
					'artist_profile_ids' => array(),
				);
			}
		) )->queue_request_opened(
			$this->request(),
			array(
				'id'         => 301,
				'kind'       => 'request_opened',
				'request_id' => 15,
			)
		);

		$this->assertSame( 'local_support_domain_contract_unavailable', $missing_domain->get_error_code() );
		$this->assertStringContainsString( 'organizer_recipient_ids', $missing_domain->get_error_message() );
		$this->assertSame( 'local_support_eligibility_unavailable', $missing_artist->get_error_code() );
	}
}
