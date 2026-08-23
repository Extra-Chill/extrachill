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
use ExtraChillEvents\Core\LocalSupportWorkspace;
use ExtraChillEvents\Core\VenueAuthorization;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class LocalSupportDomainTest extends BookingTestCase {
	private $authorization_tokens = array();

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
		$GLOBALS['ec_artist_test']['user_caps'][1]['manage_options'] = true;
		$this->assertSame( 'local_support_forbidden', $authorization->authorize_organizer( $artist_request, 1 )->get_error_code(), 'Administrative capability must not manufacture an artist organizer identity.' );
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

	public function test_mutations_reauthorize_under_lock_after_request_row_lock(): void {
		$request = $this->open_request();
		$this->authorization->locked_organizer_allowed = false;
		$error = $this->service->transition_request( $request['id'], 'paused', 1, 'lock-denied', 12 );
		$this->assertSame( 'local_support_forbidden', $error->get_error_code() );
		$this->assertSame( array( 'request:1', 'organizer-authority' ), array_slice( $this->authorization->lock_sequence, -2 ) );
		$this->assertSame( 1, $this->repository->requests[1]['version'] );

		$this->authorization->locked_organizer_allowed = true;
		$this->authorization->locked_artist_allowed    = false;
		$error = $this->service->express_interest( $request['id'], 202, 'artist-lock-denied', 20 );
		$this->assertSame( 'local_support_forbidden', $error->get_error_code() );
		$this->assertSame( array( 'request:1', 'artist-authority' ), array_slice( $this->authorization->lock_sequence, -2 ) );
		$this->assertCount( 0, $this->repository->interests );
		$this->assertSame( 0, $GLOBALS['wpdb']->nested_transaction_starts );
	}

	public function test_locked_venue_authority_uses_only_the_exact_actor_row_without_a_cap(): void {
		$this->configure_real_authority_fixture();
		$GLOBALS['ec_artist_test']['options'][\ExtraChillEvents\Core\BookingSchema::VERSION_OPTION] = \ExtraChillEvents\Core\BookingSchema::SCHEMA_VERSION;
		$wpdb = new LocalSupportAuthorityWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->venue_rows = array( $this->venue_membership_row( 1, 55, 12 ) );
		$authorization = new LocalSupportAuthorization();
		$request = array( 'event_id' => 900, 'venue_term_id' => 55, 'organizer_type' => 'venue', 'organizer_id' => 55 );
		$scope = $this->open_authorization_scope( $authorization );

		$this->assertTrue( $authorization->authorize_organizer_locked( $request, 12, $scope ) );
		$this->assertStringContainsString( 'object_id = 900', $wpdb->queries[0] );
		$this->assertStringContainsString( 'venue_term_id = 55 AND user_id = 12 FOR UPDATE', $wpdb->row_queries[0] );
		$this->assertStringNotContainsString( 'LIMIT', $wpdb->row_queries[0] );
		for ( $index = 0; $index < 150; ++$index ) {
			$wpdb->venue_rows[] = $this->venue_membership_row( 10 + $index, 55, 1000 + $index );
		}
		$this->assertTrue( $authorization->authorize_organizer_locked( $request, 12, $scope ) );
		$wpdb->venue_rows = array( $this->venue_membership_row( 2, 56, 12 ) );
		$this->assertSame( 'venue_action_forbidden', $authorization->authorize_organizer_locked( $request, 12, $scope )->get_error_code() );

		$wpdb->fail_results = true;
		$this->assertSame( 'local_support_event_venue_lock_failed', $authorization->authorize_organizer_locked( $request, 12, $scope )->get_error_code() );
		$wpdb->fail_results = false;
		$wpdb->fail_row = true;
		$this->assertSame( 'venue_membership_read_failed', $authorization->authorize_organizer_locked( $request, 12, $scope )->get_error_code() );
		$authorization->close_transaction_scope( $scope );
	}

	public function test_locked_artist_authority_uses_reciprocal_exact_rows_and_restores_context(): void {
		$this->configure_real_authority_fixture();
		$wpdb = new LocalSupportAuthorityWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->artist_rows = array( array( 'meta_id' => 1, 'meta_value' => serialize( array( 30 ) ) ) );
		$wpdb->user_rows   = array( array( 'umeta_id' => 2, 'meta_value' => serialize( array( 501 ) ) ) );
		$authorization = new LocalSupportAuthorization();
		$scope = $this->open_authorization_scope( $authorization );

		$this->assertTrue( $authorization->authorize_artist_locked( 101, 30, $scope ) );
		$this->assertSame( 7, get_current_blog_id() );
		$this->assertSame( array( 'membership-advisory', 'profile-binding', 'term-binding', 'artist', 'user' ), $wpdb->lock_sequence );
		$this->assertStringContainsString( 'post_id = 501', $wpdb->queries[0] );
		$this->assertStringContainsString( 'term_id = 101', $wpdb->queries[1] );
		$this->assertStringContainsString( 'user_id = 30', $wpdb->queries[3] );
		$authorization->close_transaction_scope( $scope );
		$this->assertSame( 'membership-release', end( $wpdb->lock_sequence ) );

		$wpdb->lock_sequence = array();
		$wpdb->user_rows = array( array( 'umeta_id' => 2, 'meta_value' => serialize( array( 999 ) ) ) );
		$scope = $this->open_authorization_scope( $authorization );
		$this->assertSame( 'local_support_forbidden', $authorization->authorize_artist_locked( 101, 30, $scope )->get_error_code() );
		$authorization->close_transaction_scope( $scope );
		$wpdb->user_rows = array_fill( 0, 2, array( 'umeta_id' => 2, 'meta_value' => serialize( array( 501 ) ) ) );
		$scope = $this->open_authorization_scope( $authorization );
		$this->assertSame( 'local_support_artist_authority_rows_corrupt', $authorization->authorize_artist_locked( 101, 30, $scope )->get_error_code() );
		$authorization->close_transaction_scope( $scope );
	}

	public function test_locked_artist_authority_fails_on_database_error_and_oversized_values(): void {
		$this->configure_real_authority_fixture();
		$wpdb = new LocalSupportAuthorityWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->artist_rows = array( array( 'meta_id' => 1, 'meta_value' => serialize( array( 30 ) ) ) );
		$wpdb->user_rows   = array( array( 'umeta_id' => 2, 'meta_value' => serialize( range( 1, 101 ) ) ) );
		$authorization = new LocalSupportAuthorization();
		$scope = $this->open_authorization_scope( $authorization );
		$this->assertSame( 'local_support_artist_authority_corrupt', $authorization->authorize_artist_locked( 101, 30, $scope )->get_error_code() );
		$authorization->close_transaction_scope( $scope );

		$wpdb->fail_results = true;
		$scope = $this->open_authorization_scope( $authorization );
		$this->assertSame( 'local_support_artist_authority_read_failed', $authorization->authorize_artist_locked( 101, 30, $scope )->get_error_code() );
		$this->assertSame( 7, get_current_blog_id() );
		$authorization->close_transaction_scope( $scope );
	}

	public function test_locked_event_relationship_and_artist_binding_changes_fail_closed(): void {
		$this->configure_real_authority_fixture();
		$wpdb = new LocalSupportAuthorityWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->venue_rows = array( $this->venue_membership_row( 1, 55, 12 ) );
		$wpdb->artist_rows = array( array( 'meta_id' => 1, 'meta_value' => serialize( array( 30 ) ) ) );
		$wpdb->user_rows = array( array( 'umeta_id' => 2, 'meta_value' => serialize( array( 501 ) ) ) );
		$authorization = new LocalSupportAuthorization();
		$scope = $this->open_authorization_scope( $authorization );
		$venue_request = array( 'event_id' => 900, 'venue_term_id' => 55, 'organizer_type' => 'venue', 'organizer_id' => 55 );
		$artist_request = array( 'event_id' => 900, 'venue_term_id' => 55, 'organizer_type' => 'artist', 'organizer_id' => 101 );

		$wpdb->event_venue_rows = array( array( 'term_id' => 56, 'term_taxonomy_id' => 556 ) );
		$this->assertSame( 'invalid_local_support_event_venue', $authorization->authorize_organizer_locked( $venue_request, 12, $scope )->get_error_code() );
		$wpdb->event_venue_rows = array( array( 'term_id' => 55, 'term_taxonomy_id' => 555 ) );
		$wpdb->mapping_rows[0]['meta_value'] = '999';
		$this->assertSame( 'local_support_artist_mapping_changed', $authorization->authorize_organizer_locked( $artist_request, 30, $scope )->get_error_code() );
		$authorization->close_transaction_scope( $scope );
		$scope = $this->open_authorization_scope( $authorization );
		$wpdb->mapping_rows[0]['meta_value'] = '1001';
		$GLOBALS['ec_artist_test']['artist_mappings'][102] = 1001;
		$this->assertSame( 'local_support_artist_mapping_claims_invalid', $authorization->authorize_organizer_locked( $artist_request, 30, $scope )->get_error_code() );
		$authorization->close_transaction_scope( $scope );
		$scope = $this->open_authorization_scope( $authorization );
		unset( $GLOBALS['ec_artist_test']['artist_mappings'][102] );
		$wpdb->event_artist_rows = array();
		$this->assertSame( 'local_support_forbidden', $authorization->authorize_organizer_locked( $artist_request, 30, $scope )->get_error_code() );
		$authorization->close_transaction_scope( $scope );
		$scope = $this->open_authorization_scope( $authorization );
		$wpdb->event_artist_rows = array( array( 'term_id' => 1001, 'term_taxonomy_id' => 10001 ) );
		$wpdb->profile_binding_rows[0]['meta_value'] = '999';
		$this->assertSame( 'invalid_local_support_artist', $authorization->authorize_organizer_locked( $artist_request, 30, $scope )->get_error_code() );
		$this->assertSame( array( 'membership-advisory', 'profile-binding', 'term-binding' ), array_slice( $wpdb->lock_sequence, -3 ) );
		$authorization->close_transaction_scope( $scope );
		$this->assertSame( array( 'membership-release', 'mapping-release' ), array_slice( $wpdb->lock_sequence, -2 ) );
	}

	public function test_mapping_release_failure_is_distinct_and_retains_tracking(): void {
		$this->configure_real_authority_fixture();
		$wpdb = new LocalSupportAuthorityWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->artist_rows = array( array( 'meta_id' => 1, 'meta_value' => serialize( array( 30 ) ) ) );
		$wpdb->user_rows = array( array( 'umeta_id' => 2, 'meta_value' => serialize( array( 501 ) ) ) );
		$authorization = new LocalSupportAuthorization();
		$scope = $this->open_authorization_scope( $authorization );
		$request = array( 'event_id' => 900, 'venue_term_id' => 55, 'organizer_type' => 'artist', 'organizer_id' => 101 );
		$this->assertTrue( $authorization->authorize_organizer_locked( $request, 30, $scope ) );

		$wpdb->mapping_release_result = 0;
		$this->assertSame( 'events_artist_mapping_release_failed', $authorization->close_transaction_scope( $scope )->get_error_code() );
		$wpdb->mapping_release_result = 1;
		$this->assertTrue( $authorization->close_transaction_scope( $scope ) );
		$this->assertSame( array( 'membership-release', 'mapping-release', 'mapping-release' ), array_slice( $wpdb->lock_sequence, -3 ) );
	}

	public function test_artist_binding_rows_are_exact_and_release_failure_retains_tracking(): void {
		$this->configure_real_authority_fixture();
		$wpdb = new LocalSupportAuthorityWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->artist_rows = array( array( 'meta_id' => 1, 'meta_value' => serialize( array( 30 ) ) ) );
		$wpdb->user_rows = array( array( 'umeta_id' => 2, 'meta_value' => serialize( array( 501 ) ) ) );
		$wpdb->after_profile_binding_lock = static function () use ( $wpdb ): void {
			$wpdb->binding_change_waited = true;
		};
		$authorization = new LocalSupportAuthorization();
		$scope = $this->open_authorization_scope( $authorization );
		$this->assertTrue( $authorization->authorize_artist_locked( 101, 30, $scope ) );
		$this->assertTrue( $wpdb->binding_change_waited, 'A binding writer arriving after the profile lock must wait behind authorization.' );

		$wpdb->membership_release_result = 0;
		$this->assertSame( 'local_support_artist_authority_release_failed', $authorization->close_transaction_scope( $scope )->get_error_code() );
		$wpdb->membership_release_result = 1;
		$this->assertTrue( $authorization->close_transaction_scope( $scope ) );
		$this->assertSame( array( 'membership-release', 'membership-release' ), array_slice( $wpdb->lock_sequence, -2 ) );

		$scope = $this->open_authorization_scope( $authorization );
		$wpdb->profile_binding_rows = array();
		$this->assertSame( 'local_support_artist_binding_corrupt', $authorization->authorize_artist_locked( 101, 30, $scope )->get_error_code() );
		$authorization->close_transaction_scope( $scope );
		$scope = $this->open_authorization_scope( $authorization );
		$wpdb->profile_binding_rows = array_fill( 0, 2, array( 'meta_id' => 10, 'meta_value' => '101' ) );
		$this->assertSame( 'local_support_artist_binding_corrupt', $authorization->authorize_artist_locked( 101, 30, $scope )->get_error_code() );
		$authorization->close_transaction_scope( $scope );
	}

	public function test_lock_current_scope_and_mysql_advisory_support_fail_closed(): void {
		$this->configure_real_authority_fixture();
		$wpdb = new LocalSupportAuthorityWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->transaction_active = false;
		$authorization = new LocalSupportAuthorization();
		$owner = new stdClass();
		$this->assertTrue( $authorization->claim_transaction_owner( $owner ) );
		$this->assertSame( 'local_support_transaction_scope_required', $authorization->open_transaction_scope( new stdClass() )->get_error_code() );
		$scope = $authorization->open_transaction_scope( $owner );
		$this->assertIsObject( $scope );
		$authorization->close_transaction_scope( $scope );

		$wpdb = new LocalSupportSqliteAuthorityWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$authorization = new LocalSupportAuthorization();
		$scope = $this->open_authorization_scope( $authorization );
		$this->assertSame( 'local_support_artist_advisory_locks_unsupported', $authorization->authorize_artist_locked( 101, 30, $scope )->get_error_code() );
		$authorization->close_transaction_scope( $scope );
	}

	public function test_repeatable_read_boundary_succeeds_and_restores_error_suppression(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->suppress_errors = true;
		$this->assertIsArray( $this->open_request() );
		$this->assertSame( array( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ), $wpdb->transaction_boundary_queries );
		$this->assertTrue( $wpdb->suppress_errors );
	}

	public function test_repeatable_read_boundary_failure_and_throw_fail_before_start(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->fail_transaction_boundary = true;
		$error = $this->service->open_request( array( 'event_id' => 900, 'organizer_type' => 'venue', 'organizer_id' => 55, 'idempotency_key' => 'boundary-failure' ), 12 );
		$this->assertSame( 'local_support_transaction_boundary_forbidden', $error->get_error_code() );
		$this->assertSame( array(), $wpdb->transaction_start_reference_lock_counts );
		$this->assertFalse( $wpdb->suppress_errors );

		$wpdb->fail_transaction_boundary = false;
		$wpdb->throw_transaction_boundary = true;
		$error = $this->service->open_request( array( 'event_id' => 900, 'organizer_type' => 'venue', 'organizer_id' => 55, 'idempotency_key' => 'boundary-throw' ), 12 );
		$this->assertSame( 'local_support_transaction_boundary_forbidden', $error->get_error_code() );
		$this->assertSame( array(), $wpdb->transaction_start_reference_lock_counts );
	}

	public function test_throwable_rolls_back_and_closes_scope_without_nested_transaction(): void {
		$request = $this->open_request();
		$this->repository->throw_on_update = true;
		try {
			$this->service->transition_request( $request['id'], 'paused', 1, 'throw-update', 12 );
			$this->fail( 'Expected the repository exception.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'simulated update throwable', $exception->getMessage() );
		}
		$this->assertSame( 1, $GLOBALS['wpdb']->rollback_queries );
		$this->assertSame( 2, $this->authorization->scope_closes );
		$this->assertFalse( $GLOBALS['wpdb']->transaction_active );

		$GLOBALS['wpdb']->transaction_active = true;
		$error = $this->service->transition_request( $request['id'], 'paused', 1, 'nested', 12 );
		$this->assertSame( 'local_support_transaction_boundary_forbidden', $error->get_error_code() );
		$this->assertSame( 0, $GLOBALS['wpdb']->nested_transaction_starts );
		$GLOBALS['wpdb']->transaction_active = false;
	}

	public function test_committed_mutation_keeps_success_semantics_when_scope_release_is_recorded(): void {
		$request = $this->open_request();
		$this->authorization->close_failure = true;
		$paused = $this->service->transition_request( $request['id'], 'paused', 1, 'release-failure', 12 );
		$this->assertSame( 'paused', $paused['status'] );
		$events = $GLOBALS['ec_artist_test']['fired_actions']['extrachill_events_local_support_authority_release_failed'];
		$this->assertSame( 'local_support_artist_authority_release_failed', $events[0][0]['code'] );
		$this->assertTrue( $events[0][0]['committed'] );
	}

	public function test_commit_failure_quarantines_without_rollback_and_releases_advisory_scope(): void {
		$request = $this->open_request();
		$this->authorization->use_advisory_scope = true;
		$GLOBALS['wpdb']->fail_transaction_commit = true;

		$error = $this->service->transition_request( $request['id'], 'paused', 1, 'commit-false', 12 );

		$this->assertSame( 'local_support_transaction_commit_uncertain', $error->get_error_code() );
		$this->assertFalse( $GLOBALS['wpdb']->transaction_active );
		$this->assertSame( 0, $GLOBALS['wpdb']->rollback_queries );
		$this->assertSame( 1, $GLOBALS['wpdb']->close_calls );
		$this->assertSame( array(), $GLOBALS['wpdb']->reference_locks );
	}

	public function test_commit_throw_quarantines_without_rollback_and_releases_advisory_scope(): void {
		$request = $this->open_request();
		$this->authorization->use_advisory_scope = true;
		$GLOBALS['wpdb']->throw_transaction_commit = true;

		$error = $this->service->transition_request( $request['id'], 'paused', 1, 'commit-throw', 12 );

		$this->assertSame( 'local_support_transaction_commit_uncertain', $error->get_error_code() );
		$this->assertFalse( $GLOBALS['wpdb']->transaction_active );
		$this->assertSame( 0, $GLOBALS['wpdb']->rollback_queries );
		$this->assertSame( 1, $GLOBALS['wpdb']->close_calls );
		$this->assertSame( array(), $GLOBALS['wpdb']->reference_locks );
	}

	public function test_post_commit_throw_does_not_issue_unsafe_rollback(): void {
		$request = $this->open_request();
		$this->authorization->use_advisory_scope = true;
		$GLOBALS['wpdb']->throw_transaction_commit_after_success = true;

		$error = $this->service->transition_request( $request['id'], 'paused', 1, 'commit-post-throw', 12 );

		$this->assertSame( 'local_support_transaction_commit_uncertain', $error->get_error_code() );
		$this->assertFalse( $GLOBALS['wpdb']->transaction_active );
		$this->assertSame( 0, $GLOBALS['wpdb']->rollback_queries );
		$this->assertSame( 1, $GLOBALS['wpdb']->close_calls );
		$this->assertSame( array(), $GLOBALS['wpdb']->reference_locks );
	}

	public function test_rollback_failure_quarantines_connection_and_clears_owned_state(): void {
		$request = $this->open_request();
		$this->authorization->use_advisory_scope = true;
		$this->authorization->locked_organizer_allowed = false;
		$GLOBALS['wpdb']->fail_transaction_rollback = true;

		$error = $this->service->transition_request( $request['id'], 'paused', 1, 'rollback-false', 12 );

		$this->assertSame( 'local_support_transaction_rollback_failed', $error->get_error_code() );
		$this->assertTrue( $error->get_error_data()['connection_quarantined'] );
		$this->assertFalse( $GLOBALS['wpdb']->transaction_active );
		$this->assertSame( 1, $GLOBALS['wpdb']->close_calls );
		$this->assertSame( array(), $GLOBALS['wpdb']->reference_locks );
	}

	public function test_rollback_throw_quarantines_connection_and_clears_owned_state(): void {
		$request = $this->open_request();
		$this->authorization->use_advisory_scope = true;
		$this->authorization->locked_organizer_allowed = false;
		$GLOBALS['wpdb']->throw_transaction_rollback = true;

		$error = $this->service->transition_request( $request['id'], 'paused', 1, 'rollback-throw', 12 );

		$this->assertSame( 'local_support_transaction_rollback_failed', $error->get_error_code() );
		$this->assertTrue( $error->get_error_data()['connection_quarantined'] );
		$this->assertFalse( $GLOBALS['wpdb']->transaction_active );
		$this->assertSame( 1, $GLOBALS['wpdb']->close_calls );
		$this->assertSame( array(), $GLOBALS['wpdb']->reference_locks );
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

	public function test_workspace_reauthorizes_and_reveals_contact_only_during_active_consent(): void {
		$request   = $this->open_request();
		$interest  = $this->service->express_interest( $request['id'], 202, 'interest-workspace', 20 );
		$workspace = new LocalSupportWorkspace( $this->repository, $this->authorization, $this->service );

		$model = $workspace->read( $request['id'], 0, 12 );
		$this->assertSame( 'organizer', $model['role'] );
		$this->assertCount( 1, $model['interests'] );
		$this->assertNull( $model['interests'][0]['contact'] );

		$this->service->set_contact_consent( $interest['id'], true, array( 'email' => 'artist@example.com' ), array( 'email' ), 1, 'workspace-consent', 20 );
		$model = $workspace->read( $request['id'], 0, 12 );
		$this->assertSame( array( 'email' => 'artist@example.com' ), $model['interests'][0]['contact'] );

		$this->service->set_contact_consent( $interest['id'], false, array(), array(), 2, 'workspace-revoke', 20 );
		$model = $workspace->read( $request['id'], 0, 12 );
		$this->assertNull( $model['interests'][0]['contact'] );

		$this->authorization->organizer_allowed = false;
		$denied = $workspace->read( $request['id'], 0, 12 );
		$this->assertSame( 'local_support_forbidden', $denied->get_error_code() );
	}

	public function test_withdrawal_atomically_revokes_active_contact_consent(): void {
		$request  = $this->open_request();
		$interest = $this->service->express_interest( $request['id'], 202, 'interest-with-contact', 20 );
		$granted  = $this->service->set_contact_consent( $interest['id'], true, array( 'email' => 'artist@example.com' ), array( 'email' ), 1, 'withdraw-consent', 20 );

		$withdrawn = $this->service->transition_interest( $interest['id'], 'withdrawn', $granted['version'], 'withdraw-contact', 20 );
		$this->assertSame( 'withdrawn', $withdrawn['status'] );
		$this->assertNull( $withdrawn['contact'] );
		$this->assertSame( 2, $withdrawn['consent_version'] );
		$this->assertSame( 20, $withdrawn['revoked_by_user_id'] );
		$this->assertTrue( end( $this->repository->activity )['payload']['contact_consent_revoked'] );
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

	private function open_authorization_scope( LocalSupportAuthorization $authorization ): object {
		$id = spl_object_id( $authorization );
		if ( ! isset( $this->authorization_tokens[ $id ] ) ) {
			$this->authorization_tokens[ $id ] = new stdClass();
			$this->assertTrue( $authorization->claim_transaction_owner( $this->authorization_tokens[ $id ] ) );
		}
		$scope = $authorization->open_transaction_scope( $this->authorization_tokens[ $id ] );
		$this->assertIsObject( $scope );
		return $scope;
	}

	private function configure_real_authority_fixture(): void {
		$GLOBALS['ec_artist_test'] = array_merge(
			$GLOBALS['ec_artist_test'],
			array(
				'blog_id'         => 7,
				'stack'           => array(),
				'terms'           => array(
					1 => array( 101 => (object) array( 'term_id' => 101, 'taxonomy' => 'artist' ) ),
					7 => array( 55 => (object) array( 'term_id' => 55, 'taxonomy' => 'venue' ) ),
				),
				'meta'            => array( 1 => array( 101 => array( '_artist_profile_id' => 501 ) ) ),
				'posts'           => array(
					4 => array( 501 => (object) array( 'ID' => 501, 'post_type' => 'artist_profile', 'post_status' => 'publish' ) ),
					7 => array( 900 => (object) array( 'ID' => 900, 'post_type' => 'data_machine_events', 'post_status' => 'publish' ) ),
				),
				'post_meta'       => array( 4 => array( 501 => array( '_artist_term_id' => 101 ) ) ),
				'event_venues'    => array( 7 => array( 900 => array( 55 ) ) ),
				'event_artists'   => array( 7 => array( 900 => array( 1001 ) ) ),
				'artist_mappings' => array( 101 => 1001 ),
				'user_caps'       => array( 12 => array( VenueAuthorization::ACCESS_CAPABILITY => true ) ),
			)
		);
	}

	private function venue_membership_row( int $id, int $venue_id, int $user_id ): array {
		return array(
			'id'                 => $id,
			'venue_term_id'      => $venue_id,
			'user_id'            => $user_id,
			'is_owner'           => '0',
			'status'             => VenueAuthorization::STATUS_ACTIVE,
			'version'            => 1,
			'created_by_user_id' => 12,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'revoked_at'         => null,
		);
	}
}

/** In-memory persistence double that exercises service behavior without SQL parsing. */
final class LocalSupportMemoryRepository extends LocalSupportRepository {
	public $requests  = array();
	public $interests = array();
	public $activity  = array();
	public $throw_on_update = false;

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
		if ( $for_update && isset( $GLOBALS['local_support_test_authorization'] ) ) {
			$GLOBALS['local_support_test_authorization']->lock_sequence[] = 'request:' . $id;
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
		if ( $this->throw_on_update ) {
			throw new RuntimeException( 'simulated update throwable' );
		}
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
	public $locked_organizer_allowed = true;
	public $locked_artist_allowed    = true;
	public $attached_artists  = array( 101 );
	public $lock_sequence     = array();
	public $scope_closes      = 0;
	public $close_failure     = false;
	public $use_advisory_scope = false;
	private $advisory_scopes = array();

	public function __construct() {
		parent::__construct();
		$GLOBALS['local_support_test_authorization'] = $this;
	}

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

	public function authorize_organizer_locked( array $request, int $user_id, object $scope ) {
		unset( $scope );
		$this->lock_sequence[] = 'organizer-authority';
		return $this->locked_organizer_allowed ? $this->authorize_organizer( $request, $user_id ) : new WP_Error( 'local_support_forbidden' );
	}

	public function authorize_artist_locked( int $artist_term_id, int $user_id, object $scope ) {
		unset( $scope );
		$this->lock_sequence[] = 'artist-authority';
		return $this->locked_artist_allowed ? $this->authorize_artist( $artist_term_id, $user_id ) : new WP_Error( 'local_support_forbidden' );
	}

	public function artist_attached_to_event( int $event_id, int $artist_term_id ) {
		return 900 === $event_id && in_array( $artist_term_id, $this->attached_artists, true );
	}

	public function artist_attached_to_event_locked( int $event_id, int $artist_term_id, object $scope ) {
		unset( $scope );
		return $this->artist_attached_to_event( $event_id, $artist_term_id );
	}

	public function open_transaction_scope( object $owner ) {
		$scope = parent::open_transaction_scope( $owner );
		if ( ! is_wp_error( $scope ) && $this->use_advisory_scope ) {
			global $wpdb;
			$lock_name = 'local_support_test_scope_' . spl_object_id( $scope );
			$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) );
			$this->advisory_scopes[ spl_object_id( $scope ) ] = $lock_name;
		}
		return $scope;
	}

	public function close_transaction_scope( object $scope ) {
		++$this->scope_closes;
		$release_failed = false;
		$scope_id       = spl_object_id( $scope );
		if ( isset( $this->advisory_scopes[ $scope_id ] ) ) {
			global $wpdb;
			$released       = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->advisory_scopes[ $scope_id ] ) );
			$release_failed = '1' !== (string) $released;
			unset( $this->advisory_scopes[ $scope_id ] );
		}
		$result = parent::close_transaction_scope( $scope );
		return $this->close_failure || $release_failed ? new WP_Error( 'local_support_artist_authority_release_failed' ) : $result;
	}
}

/** Minimal lock-aware database double for current organizer authority. */
class LocalSupportAuthorityWpdb {
	public $prefix = 'wp_7_';
	public $last_error = '';
	public $usermeta = 'wp_usermeta';
	public $postmeta = 'wp_4_postmeta';
	public $termmeta = 'wp_termmeta';
	public $term_relationships = 'wp_7_term_relationships';
	public $term_taxonomy = 'wp_7_term_taxonomy';
	public $venue_rows = array();
	public $event_venue_rows = array( array( 'term_id' => 55, 'term_taxonomy_id' => 555 ) );
	public $event_artist_rows = array( array( 'term_id' => 1001, 'term_taxonomy_id' => 10001 ) );
	public $mapping_rows = array( array( 'meta_id' => 9, 'meta_value' => '1001' ) );
	public $profile_binding_rows = array( array( 'meta_id' => 10, 'meta_value' => '101' ) );
	public $term_binding_rows = array( array( 'meta_id' => 11, 'meta_value' => '501' ) );
	public $artist_rows = array();
	public $user_rows = array();
	public $queries = array();
	public $row_queries = array();
	public $lock_sequence = array();
	public $fail_results = false;
	public $fail_row = false;
	public $transaction_active = true;
	public $suppress_errors = false;
	public $mapping_release_result = 1;
	public $membership_release_result = 1;
	public $after_profile_binding_lock;
	public $binding_change_waited = false;

	public function flush(): void {
		$this->last_error = '';
	}

	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', static function ( $match ) use ( &$args, &$i ) {
			$value = $args[ $i++ ];
			return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
		}, $query );
	}

	public function suppress_errors( $suppress = true ) {
		$previous              = $this->suppress_errors;
		$this->suppress_errors = (bool) $suppress;
		return $previous;
	}

	public function get_var( $query ) {
		$this->last_error = '';
		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			$this->lock_sequence[] = false !== strpos( $query, 'ec_events_artist_mapping_' ) ? 'mapping-advisory' : 'membership-advisory';
			return 1;
		}
		if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			$is_mapping = false !== strpos( $query, 'ec_events_artist_mapping_' );
			$this->lock_sequence[] = $is_mapping ? 'mapping-release' : 'membership-release';
			return $is_mapping ? $this->mapping_release_result : $this->membership_release_result;
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		unset( $output );
		$this->queries[] = $query;
		$this->last_error = $this->fail_results ? 'simulated authority read failure' : '';
		if ( $this->fail_results ) {
			return null;
		}
		if ( false !== strpos( $query, "tt.taxonomy = 'venue'" ) ) {
			return $this->event_venue_rows;
		}
		if ( false !== strpos( $query, "tt.taxonomy = 'artist'" ) ) {
			return $this->event_artist_rows;
		}
		if ( false !== strpos( $query, '_extrachill_events_artist_term_id' ) ) {
			$this->lock_sequence[] = 'artist-mapping';
			return $this->mapping_rows;
		}
		if ( false !== strpos( $query, '_artist_term_id' ) ) {
			$this->lock_sequence[] = 'profile-binding';
			if ( is_callable( $this->after_profile_binding_lock ) ) {
				$callback = $this->after_profile_binding_lock;
				$this->after_profile_binding_lock = null;
				$callback();
			}
			return $this->profile_binding_rows;
		}
		if ( false !== strpos( $query, '_artist_profile_id' ) && false !== strpos( $query, 'term_id' ) ) {
			$this->lock_sequence[] = 'term-binding';
			return $this->term_binding_rows;
		}
		if ( false !== strpos( $query, '_artist_member_ids' ) ) {
			$this->lock_sequence[] = 'artist';
			return $this->artist_rows;
		}
		$this->lock_sequence[] = 'user';
		return $this->user_rows;
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		$this->row_queries[] = $query;
		$this->last_error = $this->fail_row ? 'simulated authority row failure' : '';
		if ( $this->fail_row ) {
			return null;
		}
		preg_match( '/venue_term_id = (\d+) AND user_id = (\d+)/', $query, $match );
		foreach ( $this->venue_rows as $row ) {
			if ( (int) $row['venue_term_id'] === (int) $match[1] && (int) $row['user_id'] === (int) $match[2] ) {
				return $row;
			}
		}
		return null;
	}
}

/** Non-MySQL runtime double for explicit advisory-lock incompatibility. */
final class LocalSupportSqliteAuthorityWpdb extends LocalSupportAuthorityWpdb {
}

/** Exact venue policy double used while exercising the real support authorization. */
final class LocalSupportVenueAuthorization extends VenueAuthorization {
	/** Accept only the fixture's exact venue member relationship. */
	public function authorize( int $user_id, int $venue_term_id, string $action ) {
		return 12 === $user_id && 55 === $venue_term_id && VenueAuthorization::ACTION_ACCESS_VENUE === $action ? true : new WP_Error( 'venue_action_forbidden' );
	}
}
