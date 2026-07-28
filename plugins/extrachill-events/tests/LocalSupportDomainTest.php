<?php
/**
 * Event-scoped local support domain tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\LocalSupportAbilities;
use ExtraChillEvents\Core\LocalSupportAuthorization;
use ExtraChillEvents\Core\LocalSupportRepository;
use ExtraChillEvents\Core\LocalSupportSchema;
use ExtraChillEvents\Core\LocalSupportService;
use ExtraChillEvents\Core\VenueAuthorization;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class LocalSupportDomainTest extends BookingTestCase {

	/** @var LocalSupportMemoryRepository */
	private $repository;

	/** @var LocalSupportTestAuthorization */
	private $authorization;

	/** @var LocalSupportService */
	private $service;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'       => 7,
			'uuid'          => 0,
			'options'       => array(),
			'dbdelta'       => array(),
			'abilities'     => array(),
			'actions'       => array(),
			'fired_actions' => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
		$this->repository          = new LocalSupportMemoryRepository();
		$this->authorization       = new LocalSupportTestAuthorization();
		$this->service             = new LocalSupportService( $this->repository, $this->authorization );
	}

	public function test_schema_owns_separate_unique_transactional_tables(): void {
		$this->assertTrue( LocalSupportSchema::install() );
		$this->assertTrue( LocalSupportSchema::health() );
		$this->assertSame( 'wp_7_ec_local_support_requests', LocalSupportSchema::requests_table() );
		$this->assertSame( array( 'event_id' ), $GLOBALS['wpdb']->schemas[ LocalSupportSchema::requests_table() ]['indexes']['event_id']['columns'] );
		$this->assertTrue( $GLOBALS['wpdb']->schemas[ LocalSupportSchema::requests_table() ]['indexes']['event_id']['unique'] );
		$this->assertSame( array( 'request_id', 'artist_term_id' ), $GLOBALS['wpdb']->schemas[ LocalSupportSchema::interests_table() ]['indexes']['request_artist']['columns'] );
		$this->assertTrue( $GLOBALS['wpdb']->schemas[ LocalSupportSchema::activity_table() ]['indexes']['request_idempotency']['unique'] );
	}

	public function test_request_and_interest_lifecycles_are_separate_and_explicit(): void {
		$this->assertTrue( LocalSupportService::can_transition_request( 'open', 'paused' ) );
		$this->assertTrue( LocalSupportService::can_transition_request( 'open', 'filled' ) );
		$this->assertFalse( LocalSupportService::can_transition_request( 'closed', 'open' ) );
		$this->assertTrue( LocalSupportService::can_transition_interest( 'interested', 'shortlisted' ) );
		$this->assertTrue( LocalSupportService::can_transition_interest( 'shortlisted', 'selected' ) );
		$this->assertFalse( LocalSupportService::can_transition_interest( 'declined', 'interested' ) );
	}

	/** Prove exact venue and bound canonical artist organizer authorization. */
	public function test_real_authorization_requires_exact_event_bindings_and_current_authority(): void {
		$GLOBALS['ec_artist_test'] = array_merge(
			$GLOBALS['ec_artist_test'],
			array(
				'blog_id'          => 7,
				'stack'            => array(),
				'terms'            => array(
					1 => array( 101 => (object) array( 'term_id' => 101, 'taxonomy' => 'artist' ) ),
					7 => array( 55 => (object) array( 'term_id' => 55, 'taxonomy' => 'venue' ) ),
				),
				'meta'             => array( 1 => array( 101 => array( '_artist_profile_id' => 501 ) ) ),
				'posts'            => array(
					4 => array( 501 => (object) array( 'ID' => 501, 'post_type' => 'artist_profile', 'post_status' => 'publish' ) ),
					7 => array( 900 => (object) array( 'ID' => 900, 'post_type' => 'data_machine_events', 'post_status' => 'publish' ) ),
				),
				'post_meta'        => array( 4 => array( 501 => array( '_artist_term_id' => 101 ) ) ),
				'event_venues'     => array( 7 => array( 900 => array( 55 ) ) ),
				'event_artists'    => array( 7 => array( 900 => array( 1001 ) ) ),
				'artist_mappings'  => array( 101 => 1001 ),
				'artist_managers'  => array( 501 => array( 30 => true ) ),
			)
		);
		$authorization = new LocalSupportAuthorization( new LocalSupportVenueAuthorization() );
		$venue_request = array( 'event_id' => 900, 'venue_term_id' => 55, 'organizer_type' => 'venue', 'organizer_id' => 55 );
		$this->assertTrue( $authorization->authorize_organizer( $venue_request, 12 ) );
		$venue_request['organizer_id'] = 56;
		$this->assertSame( 'local_support_forbidden', $authorization->authorize_organizer( $venue_request, 12 )->get_error_code() );

		$artist_request = array( 'event_id' => 900, 'venue_term_id' => 55, 'organizer_type' => 'artist', 'organizer_id' => 101 );
		$this->assertTrue( $authorization->authorize_organizer( $artist_request, 30 ) );
		$this->assertSame( 'local_support_forbidden', $authorization->authorize_organizer( $artist_request, 31 )->get_error_code() );
		$GLOBALS['ec_artist_test']['event_artists'][7][900] = array();
		$this->assertSame( 'local_support_forbidden', $authorization->authorize_organizer( $artist_request, 30 )->get_error_code() );
	}

	public function test_one_request_and_interest_are_idempotent_and_hash_bound(): void {
		$request = $this->open_request();
		$retry   = $this->open_request();
		$this->assertSame( $request['id'], $retry['id'] );
		$this->assertCount( 1, $this->repository->requests );

		$conflict = $this->service->open_request(
			array(
				'event_id'        => 900,
				'organizer_type'  => 'artist',
				'organizer_id'    => 101,
				'idempotency_key' => 'open-900',
			),
			12
		);
		$this->assertSame( 'local_support_idempotency_conflict', $conflict->get_error_code() );

		$interest = $this->service->express_interest( $request['id'], 202, 'interest-202', 20 );
		$retry    = $this->service->express_interest( $request['id'], 202, 'interest-202', 20 );
		$this->assertSame( $interest['id'], $retry['id'] );
		$this->assertCount( 1, $this->repository->interests );
		$this->assertNull( $interest['contact'] );
	}

	public function test_optimistic_conflicts_and_authority_fail_closed(): void {
		$request = $this->open_request();
		$paused  = $this->service->transition_request( $request['id'], 'paused', 1, 'pause-1', 12 );
		$this->assertSame( 2, $paused['version'] );
		$this->assertSame( 'local_support_version_conflict', $this->service->transition_request( $request['id'], 'closed', 1, 'close-stale', 12 )->get_error_code() );

		$this->authorization->organizer_allowed = false;
		$this->assertSame( 'local_support_forbidden', $this->service->get_request( $request['id'], 12 )->get_error_code() );
		$this->authorization->organizer_allowed = true;
		$this->service->transition_request( $request['id'], 'open', 2, 'resume-1', 12 );
		$this->authorization->artist_allowed    = false;
		$this->assertSame( 'local_support_forbidden', $this->service->express_interest( $request['id'], 202, 'denied-interest', 20 )->get_error_code() );
	}

	public function test_contact_is_absent_until_explicit_consent_and_revocation_is_audited(): void {
		$request  = $this->open_request();
		$interest = $this->service->express_interest( $request['id'], 202, 'interest-202', 20 );
		$this->assertNull( $interest['contact'] );

		$granted = $this->service->set_contact_consent(
			$interest['id'],
			true,
			array(
				'name'  => 'Artist Manager',
				'email' => 'artist@example.com',
				'phone' => 'not disclosed',
			),
			array( 'name', 'email' ),
			1,
			'consent-1',
			20
		);
		$this->assertSame(
			array(
				'name'  => 'Artist Manager',
				'email' => 'artist@example.com',
			),
			$granted['contact']
		);
		$this->assertSame( array( 'name', 'email' ), $granted['consent_fields'] );
		$this->assertSame( 1, $granted['consent_version'] );
		$this->assertNull( $granted['revoked_at'] );

		$revoked = $this->service->set_contact_consent( $interest['id'], false, array(), array(), 2, 'revoke-1', 20 );
		$this->assertNull( $revoked['contact'] );
		$this->assertNull( $revoked['consent_fields'] );
		$this->assertSame( 2, $revoked['consent_version'] );
		$this->assertSame( 20, $revoked['revoked_by_user_id'] );
		$this->assertSame( array( 'contact_consent_granted', 'contact_consent_revoked' ), array_slice( array_column( $this->repository->activity, 'kind' ), -2 ) );
		$this->assertStringNotContainsString( 'artist@example.com', wp_json_encode( $this->repository->activity ) );
	}

	public function test_only_organizer_can_shortlist_and_private_abilities_are_not_rest_exposed(): void {
		$request  = $this->open_request();
		$interest = $this->service->express_interest( $request['id'], 202, 'interest-202', 20 );
		$selected = $this->service->transition_interest( $interest['id'], 'shortlisted', 1, 'shortlist-202', 12 );
		$this->assertSame( 'shortlisted', $selected['status'] );
		$this->assertSame( array( 202 ), array_column( $this->service->list_interests( $request['id'], 12 ), 'artist_term_id' ) );

		$abilities = new LocalSupportAbilities( $this->service );
		$abilities->register();
		$this->assertArrayHasKey( 'extrachill-events/open-local-support-request', $GLOBALS['ec_artist_test']['abilities'] );
		foreach ( $GLOBALS['ec_artist_test']['abilities'] as $name => $definition ) {
			if ( 0 === strpos( $name, 'extrachill-events/' ) && false !== strpos( $name, 'local-support' ) ) {
				$this->assertFalse( $definition['meta']['show_in_rest'], $name );
			}
		}
	}

	private function open_request(): array {
		return $this->service->open_request(
			array(
				'event_id'        => 900,
				'organizer_type'  => 'venue',
				'organizer_id'    => 55,
				'idempotency_key' => 'open-900',
			),
			12
		);
	}
}

/** In-memory persistence double that exercises service behavior without SQL parsing. */
final class LocalSupportMemoryRepository extends LocalSupportRepository {
	public $requests  = array();
	public $interests = array();
	public $activity  = array();

	public function create_request( array $data ) {
		$id                    = count( $this->requests ) + 1;
		$this->requests[ $id ] = array_merge(
			$data,
			array(
				'id'                 => $id,
				'public_id'          => 'request-' . $id,
				'status'             => 'open',
				'version'            => 1,
				'created_by_user_id' => $data['actor_id'],
				'created_at'         => '2026-07-27 00:00:00',
				'updated_at'         => '2026-07-27 00:00:00',
			)
		);
		unset( $this->requests[ $id ]['actor_id'] );
		return $this->requests[ $id ];
	}

	public function get_request( int $id, bool $for_update = false ) {
		unset( $for_update );
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

	public function create_interest( int $request_id, int $artist_term_id, int $actor_id ) {
		$id                     = count( $this->interests ) + 1;
		$row                    = array(
			'id'                   => $id,
			'public_id'            => 'interest-' . $id,
			'request_id'           => $request_id,
			'artist_term_id'       => $artist_term_id,
			'status'               => 'interested',
			'version'              => 1,
			'contact_payload'      => null,
			'consent_fields'       => null,
			'consent_version'      => 0,
			'consented_by_user_id' => null,
			'consented_at'         => null,
			'revoked_by_user_id'   => null,
			'revoked_at'           => null,
			'created_by_user_id'   => $actor_id,
			'created_at'           => '2026-07-27 00:00:00',
			'updated_at'           => '2026-07-27 00:00:00',
		);
		$this->interests[ $id ] = $row;
		return $this->hydrate_interest( $row );
	}

	public function get_interest( int $id, bool $for_update = false ) {
		unset( $for_update );
		return isset( $this->interests[ $id ] ) ? $this->hydrate_interest( $this->interests[ $id ] ) : null;
	}

	public function get_interest_for_artist( int $request_id, int $artist_term_id ) {
		foreach ( $this->interests as $row ) {
			if ( $row['request_id'] === $request_id && $row['artist_term_id'] === $artist_term_id ) {
				return $this->hydrate_interest( $row );
			}
		}
		return null;
	}

	public function list_interests( int $request_id, int $limit = 100 ): array {
		$rows = array_filter(
			$this->interests,
			static function ( $row ) use ( $request_id ) {
				return $row['request_id'] === $request_id;
			}
		);
		return array_map( array( $this, 'hydrate_interest' ), array_slice( array_values( $rows ), 0, $limit ) );
	}

	public function update_request( int $id, int $expected_version, array $changes ) {
		if ( ! isset( $this->requests[ $id ] ) || $this->requests[ $id ]['version'] !== $expected_version ) {
			return new WP_Error( 'local_support_version_conflict' );
		}
		$this->requests[ $id ] = array_merge( $this->requests[ $id ], $changes, array( 'version' => $expected_version + 1 ) );
		return $this->requests[ $id ];
	}

	public function update_interest( int $id, int $expected_version, array $changes ) {
		if ( ! isset( $this->interests[ $id ] ) || $this->interests[ $id ]['version'] !== $expected_version ) {
			return new WP_Error( 'local_support_version_conflict' );
		}
		$this->interests[ $id ] = array_merge( $this->interests[ $id ], $changes, array( 'version' => $expected_version + 1 ) );
		return $this->hydrate_interest( $this->interests[ $id ] );
	}

	public function append_activity( array $data ) {
		$data['id']       = count( $this->activity ) + 1;
		$this->activity[] = $data;
		return $data;
	}

	public function find_activity( int $request_id, string $idempotency_key ) {
		foreach ( $this->activity as $activity ) {
			if ( $activity['request_id'] === $request_id && $activity['idempotency_key'] === $idempotency_key ) {
				return $activity;
			}
		}
		return null;
	}
}

/** Authorization double with explicit fail-closed controls. */
final class LocalSupportTestAuthorization extends LocalSupportAuthorization {
	public $organizer_allowed = true;
	public $artist_allowed    = true;
	public $attached_artists  = array( 101 );

	public function event_context( int $event_id ) {
		return 900 === $event_id ? array(
			'event_id'      => 900,
			'venue_term_id' => 55,
		) : new WP_Error( 'invalid_local_support_event' );
	}

	public function authorize_organizer( array $request, int $user_id ) {
		unset( $user_id );
		if ( ! $this->organizer_allowed || 900 !== $request['event_id'] || 55 !== $request['venue_term_id'] || ( 'venue' === $request['organizer_type'] && 55 !== $request['organizer_id'] ) || ( 'artist' === $request['organizer_type'] && ! in_array( $request['organizer_id'], $this->attached_artists, true ) ) ) {
			return new WP_Error( 'local_support_forbidden' );
		}
		return true;
	}

	public function authorize_artist( int $artist_term_id, int $user_id ) {
		unset( $artist_term_id, $user_id );
		return $this->artist_allowed ? true : new WP_Error( 'local_support_forbidden' );
	}

	public function artist_attached_to_event( int $event_id, int $artist_term_id ) {
		return 900 === $event_id && in_array( $artist_term_id, $this->attached_artists, true );
	}
}

/** Exact venue policy double used while exercising the real support authorization. */
final class LocalSupportVenueAuthorization extends VenueAuthorization {
	/** Accept only the fixture's exact venue member relationship. */
	public function authorize( int $user_id, int $venue_term_id, string $action ) {
		return 12 === $user_id && 55 === $venue_term_id && VenueAuthorization::ACTION_ACCESS_VENUE === $action ? true : new WP_Error( 'venue_action_forbidden' );
	}
}
