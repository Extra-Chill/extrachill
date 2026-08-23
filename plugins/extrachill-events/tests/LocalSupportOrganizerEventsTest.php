<?php
/**
 * Local Support organizer event query regression tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingSchema;

// phpcs:disable -- Plain-PHP integration harness intentionally declares WordPress stubs.

if ( ! defined( 'EXTRACHILL_EVENTS_LOCAL_SUPPORT_SKIP_HOOKS' ) ) {
	define( 'EXTRACHILL_EVENTS_LOCAL_SUPPORT_SKIP_HOOKS', true );
}

require_once __DIR__ . '/Support/BookingTestHarness.php';

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_id;
		public $taxonomy;
		public $name;
		public $slug;

		public function __construct( int $term_id, string $taxonomy, string $name ) {
			$this->term_id  = $term_id;
			$this->taxonomy = $taxonomy;
			$this->name     = $name;
			$this->slug     = strtolower( str_replace( ' ', '-', $name ) );
		}
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		if ( 'mysql' !== $type ) {
			return 0;
		}
		++$GLOBALS['ec_artist_test']['current_time_calls'];
		$times = $GLOBALS['ec_artist_test']['current_times'] ?? array();
		return empty( $times ) ? '2030-01-01 00:00:00' : (string) $times[ min( count( $times ) - 1, $GLOBALS['ec_artist_test']['current_time_calls'] - 1 ) ];
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://events.example' . $path;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value = null, $url = '' ) {
		$args = is_array( $key ) ? $key : array( $key => $value );
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
	}
}

require_once dirname( __DIR__ ) . '/inc/core/local-support-workspace.php';

/** Exercises server-resolved organizer scopes against the actual workspace query. */
final class LocalSupportOrganizerEventsTest extends BookingTestCase {

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'          => 7,
			'stack'            => array(),
			'uuid'             => 0,
			'options'          => array( BookingSchema::VERSION_OPTION => BookingSchema::SCHEMA_VERSION ),
			'terms'            => array( 1 => array(), 4 => array(), 7 => array() ),
			'meta'             => array( 1 => array(), 4 => array(), 7 => array() ),
			'posts'            => array( 1 => array(), 4 => array(), 7 => array() ),
			'post_meta'        => array( 1 => array(), 4 => array(), 7 => array() ),
			'event_venues'     => array( 7 => array() ),
			'event_artists'    => array( 7 => array() ),
			'artist_managers'  => array(),
			'artist_mappings'  => array(),
			'artist_mapping_claims' => array(),
			'user_caps'        => array(),
			'feature_available' => true,
			'current_time_calls' => 0,
			'permalinks'       => array( 7 => array() ),
			'abilities'        => array(),
			'actions'          => array(),
			'fired_actions'    => array(),
		);
		$GLOBALS['wpdb'] = new BookingWpdb();
	}

	/** More than the former global cap cannot hide a later authorized event. */
	public function test_authorized_event_remains_visible_after_more_than_one_hundred_unrelated_events(): void {
		$this->grant_venue( 12, 55 );
		for ( $i = 1; $i <= 101; ++$i ) {
			$this->candidate( $i, 900 + $i, 99 );
		}
		$this->candidate( 200, 2000, 55 );

		$events = extrachill_events_local_support_organizer_events( 12 );

		$this->assertSame( array( 200 ), array_column( $events, 'id' ) );
		$this->assertStringContainsString( "scope_tt.taxonomy = 'venue' AND scope_tt.term_id IN (55)", $GLOBALS['wpdb']->local_support_candidate_query );
	}

	/** Venue and canonical artist authority include only their exact event scopes. */
	public function test_direct_venue_and_artist_scopes_are_isolated(): void {
		$this->grant_venue( 12, 55 );
		$this->grant_artist( 12, 402, 202, 302 );
		$this->candidate( 10, 2010, 55 );
		$this->candidate( 11, 2011, 56, array( 302 ) );
		$this->candidate( 12, 2012, 56, array( 999 ) );

		$events = extrachill_events_local_support_organizer_events( 12 );

		$this->assertSame( array( 10, 11 ), array_column( $events, 'id' ) );
		$options = extrachill_events_local_support_organizer_options( 11, 12 );
		$this->assertSame( array( array( 'type' => 'artist', 'id' => 202, 'label' => 'Managed Artist' ) ), $options );
	}

	/** Taxonomy assignment without reciprocal roster authority grants nothing. */
	public function test_artist_taxonomy_assignment_alone_does_not_grant_authority(): void {
		$this->candidate( 20, 2020, 56, array( 302 ) );

		$this->assertSame( array(), extrachill_events_local_support_organizer_events( 12 ) );
	}

	/** A requested venue is intersected with direct membership, not trusted. */
	public function test_client_venue_scope_requires_direct_membership(): void {
		$this->grant_venue( 12, 55 );
		$this->candidate( 30, 2030, 56 );

		$this->assertSame( array(), extrachill_events_local_support_organizer_events( 12, 56 ) );
	}

	/** Candidate output has stable date/ID ordering and a hard 100-row bound. */
	public function test_results_are_deterministically_ordered_and_bounded(): void {
		$this->grant_venue( 12, 55 );
		for ( $i = 120; $i >= 1; --$i ) {
			$this->candidate( $i, 2100, 55 );
		}

		$events = extrachill_events_local_support_organizer_events( 12 );

		$this->assertCount( 100, $events );
		$this->assertSame( range( 1, 100 ), array_column( $events, 'id' ) );
		$this->assertStringContainsString( 'ORDER BY dates.start_datetime ASC, p.ID ASC LIMIT 101', $GLOBALS['wpdb']->local_support_candidate_query );
	}

	/** Venue memberships without current feature access cannot precede artist scope. */
	public function test_inactive_feature_venue_candidates_do_not_hide_artist_event(): void {
		$this->grant_venue( 12, 55 );
		$this->grant_artist( 12, 402, 202, 302 );
		$GLOBALS['ec_artist_test']['feature_available'] = false;
		for ( $event_id = 1; $event_id <= 100; ++$event_id ) {
			$this->candidate( $event_id, 1000 + $event_id, 55 );
		}
		$this->candidate( 200, 2000, 56, array( 302 ) );

		$events = extrachill_events_local_support_organizer_events( 12 );

		$this->assertSame( array( 200 ), array_column( $events, 'id' ) );
		$this->assertStringNotContainsString( "scope_tt.taxonomy = 'venue'", $GLOBALS['wpdb']->local_support_candidate_query );
	}

	/** Reverse mapping ambiguity and taxonomy failures fail the complete scope. */
	public function test_artist_mapping_claim_ambiguity_and_errors_fail_closed(): void {
		$this->grant_artist( 12, 402, 202, 302 );
		$GLOBALS['ec_artist_test']['artist_mapping_claims'][302] = array( 202, 203 );
		$scope = extrachill_events_local_support_organizer_scope( 12 );
		$this->assertSame( 'local_support_artist_mapping_claims_invalid', $scope->get_error_code() );

		$GLOBALS['ec_artist_test']['artist_mapping_claims_error'] = true;
		$scope = extrachill_events_local_support_organizer_scope( 12 );
		$this->assertSame( 'artist_mapping_claims_read_failed', $scope->get_error_code() );

		$GLOBALS['ec_artist_test']['artist_mapping_claims_error']    = false;
		$GLOBALS['ec_artist_test']['artist_mapping_claims_db_error'] = true;
		$scope = extrachill_events_local_support_organizer_scope( 12 );
		$this->assertSame( 'artist_mapping_claims_query_failed', $scope->get_error_code() );
	}

	/** Artist term database errors abort scope resolution before venue results. */
	public function test_artist_term_database_error_aborts_complete_organizer_scope(): void {
		$this->grant_venue( 12, 55 );
		$this->grant_artist( 12, 402, 202, 302 );
		$this->candidate( 1, 1000, 55 );
		$GLOBALS['ec_artist_test']['artist_mapping_errors'][202] = new WP_Error( 'artist_term_query_failed', 'simulated artist term database failure', array( 'status' => 503 ) );

		$scope = extrachill_events_local_support_organizer_scope( 12 );
		$this->assertSame( 'artist_term_query_failed', $scope->get_error_code() );
		$this->assertSame( 503, $scope->get_error_data()['status'] );
		$this->assertSame( array(), extrachill_events_local_support_organizer_events( 12 ) );
	}

	/** An operational taxonomy error after a valid row cannot return partial data. */
	public function test_per_event_operational_error_after_success_fails_complete_list(): void {
		$this->grant_venue( 12, 55 );
		$this->candidate( 1, 1000, 55 );
		$this->candidate( 2, 1001, 55 );
		$GLOBALS['ec_artist_test']['event_term_errors']['venue'][2] = true;

		$this->assertSame( array(), extrachill_events_local_support_organizer_events( 12 ) );
	}

	/** Empty object-term results with wpdb errors cannot return prior successes. */
	public function test_hidden_object_term_database_error_after_success_fails_complete_list(): void {
		$this->grant_venue( 12, 55 );
		$this->candidate( 1, 1000, 55 );
		$this->candidate( 2, 1001, 55 );
		$GLOBALS['ec_artist_test']['event_term_db_errors']['artist'][2] = true;

		$this->assertSame( array(), extrachill_events_local_support_organizer_events( 12 ) );
	}

	/** Malformed scoped rows are skipped across bounded deterministic pages. */
	public function test_one_hundred_malformed_candidates_do_not_hide_valid_event(): void {
		$this->grant_venue( 12, 55 );
		for ( $event_id = 1; $event_id <= 100; ++$event_id ) {
			$this->candidate( $event_id, 1000 + $event_id, 55 );
			$GLOBALS['ec_artist_test']['event_venues'][7][ $event_id ] = array( 55, 56 );
		}
		$this->candidate( 200, 2000, 55 );

		$this->assertSame( array( 200 ), array_column( extrachill_events_local_support_organizer_events( 12 ), 'id' ) );
	}

	/** Every keyset page retains the same lower-bound time snapshot. */
	public function test_pagination_freezes_cutoff_when_clock_changes_between_pages(): void {
		$this->grant_venue( 12, 55 );
		$GLOBALS['ec_artist_test']['current_times'] = array( '2030-01-01 00:00:00', '2030-01-03 00:00:00' );
		for ( $event_id = 1; $event_id <= 101; ++$event_id ) {
			$this->candidate( $event_id, 1000 + $event_id, 55 );
			$GLOBALS['ec_artist_test']['event_venues'][7][ $event_id ] = array( 55, 56 );
		}
		$this->candidate( 200, 2000, 55 );

		$this->assertSame( array( 200 ), array_column( extrachill_events_local_support_organizer_events( 12 ), 'id' ) );
		$this->assertCount( 2, $GLOBALS['wpdb']->local_support_candidate_queries );
		$this->assertSame( 1, $GLOBALS['ec_artist_test']['current_time_calls'] );
		$this->assertStringContainsString( "dates.start_datetime >= '2030-01-01 00:00:00'", $GLOBALS['wpdb']->local_support_candidate_queries[1] );
	}

	/** Candidate scans beyond 500 fail closed with an explicit overflow code. */
	public function test_malformed_candidate_scan_has_hard_overflow_bound(): void {
		$this->grant_venue( 12, 55 );
		for ( $event_id = 1; $event_id <= 501; ++$event_id ) {
			$this->candidate( $event_id, 1000 + $event_id, 55 );
			$GLOBALS['ec_artist_test']['event_venues'][7][ $event_id ] = array( 55, 56 );
		}

		$this->assertSame( array(), extrachill_events_local_support_organizer_events( 12 ) );
		$logs = $GLOBALS['ec_artist_test']['fired_actions']['datamachine_log'] ?? array();
		$this->assertSame( 'local_support_candidate_scan_overflow', $logs[0][2]['code'] ?? '' );
		$this->assertCount( 5, $GLOBALS['wpdb']->local_support_candidate_queries );
	}

	/** Oversized or corrupt authority state returns explicit scope errors. */
	public function test_authority_scope_bounds_and_corrupt_bindings_fail_explicitly(): void {
		for ( $venue_id = 1; $venue_id <= 101; ++$venue_id ) {
			$this->grant_venue( 12, $venue_id );
		}
		$scope = extrachill_events_local_support_organizer_scope( 12 );
		$this->assertSame( 'local_support_venue_scope_overflow', $scope->get_error_code() );

		$GLOBALS['wpdb']->rows[ $GLOBALS['wpdb']->prefix . 'ec_venue_members' ] = array();
		$GLOBALS['ec_artist_test']['artist_managers'][402][12] = true;
		$GLOBALS['ec_artist_test']['posts'][4][402] = (object) array( 'ID' => 402, 'post_type' => 'artist_profile', 'post_status' => 'publish' );
		$scope = extrachill_events_local_support_organizer_scope( 12 );
		$this->assertSame( 'local_support_artist_scope_corrupt', $scope->get_error_code() );

		$GLOBALS['ec_artist_test']['artist_managers'] = array();
		for ( $profile_id = 1; $profile_id <= 101; ++$profile_id ) {
			$GLOBALS['ec_artist_test']['artist_managers'][ $profile_id ][12] = true;
		}
		$scope = extrachill_events_local_support_organizer_scope( 12 );
		$this->assertSame( 'local_support_artist_scope_overflow', $scope->get_error_code() );
	}

	/** Scope, candidate, and per-event request database failures fail closed. */
	public function test_database_errors_fail_the_complete_list_closed(): void {
		$this->grant_venue( 12, 55 );
		$this->candidate( 40, 2040, 55 );

		$GLOBALS['wpdb']->fail_reads = true;
		$this->assertSame( array(), extrachill_events_local_support_organizer_events( 12 ) );

		$GLOBALS['wpdb']->fail_reads                          = false;
		$GLOBALS['wpdb']->fail_local_support_candidate_reads = true;
		$this->assertSame( array(), extrachill_events_local_support_organizer_events( 12 ) );

		$GLOBALS['wpdb']->fail_local_support_candidate_reads = false;
		$GLOBALS['wpdb']->fail_local_support_request_reads   = true;
		$this->assertSame( array(), extrachill_events_local_support_organizer_events( 12 ) );
	}

	private function grant_venue( int $user_id, int $venue_id ): void {
		$GLOBALS['ec_artist_test']['terms'][7][ $venue_id ] = new WP_Term( $venue_id, 'venue', 'Venue ' . $venue_id );
		$GLOBALS['ec_artist_test']['user_caps'][ $user_id ]['access_events_admin'] = true;
		$GLOBALS['wpdb']->rows[ $GLOBALS['wpdb']->prefix . 'ec_venue_members' ][] = array(
			'id'                 => count( $GLOBALS['wpdb']->rows[ $GLOBALS['wpdb']->prefix . 'ec_venue_members' ] ?? array() ) + 1,
			'venue_term_id'      => $venue_id,
			'user_id'            => $user_id,
			'is_owner'           => 0,
			'status'             => 'active',
			'version'            => 1,
			'created_by_user_id' => $user_id,
			'created_at'         => '2029-01-01 00:00:00',
			'updated_at'         => '2029-01-01 00:00:00',
			'revoked_at'         => null,
		);
	}

	private function grant_artist( int $user_id, int $profile_id, int $canonical_id, int $local_id ): void {
		$GLOBALS['ec_artist_test']['artist_managers'][ $profile_id ][ $user_id ] = true;
		$GLOBALS['ec_artist_test']['posts'][4][ $profile_id ] = (object) array( 'ID' => $profile_id, 'post_type' => 'artist_profile', 'post_status' => 'publish' );
		$GLOBALS['ec_artist_test']['post_meta'][4][ $profile_id ]['_artist_term_id'] = $canonical_id;
		$GLOBALS['ec_artist_test']['terms'][1][ $canonical_id ] = new WP_Term( $canonical_id, 'artist', 'Managed Artist' );
		$GLOBALS['ec_artist_test']['meta'][1][ $canonical_id ]['_artist_profile_id'] = $profile_id;
		$GLOBALS['ec_artist_test']['terms'][7][ $local_id ] = new WP_Term( $local_id, 'artist', 'Managed Artist' );
		$GLOBALS['ec_artist_test']['artist_mappings'][ $canonical_id ] = $local_id;
		$GLOBALS['ec_artist_test']['artist_mapping_claims'][ $local_id ] = array( $canonical_id );
	}

	private function candidate( int $event_id, int $minute, int $venue_id, array $artist_ids = array() ): void {
		$datetime = sprintf( '2030-01-%02d %02d:%02d:00', 1 + intdiv( $minute, 1440 ), intdiv( $minute % 1440, 60 ), $minute % 60 );
		$GLOBALS['wpdb']->local_support_candidate_rows[] = array(
			'ID'              => $event_id,
			'post_title'      => 'Event ' . $event_id,
			'start_datetime'  => $datetime,
			'venue_term_id'   => $venue_id,
			'artist_term_ids' => $artist_ids,
		);
		$GLOBALS['ec_artist_test']['posts'][7][ $event_id ] = (object) array( 'ID' => $event_id, 'post_type' => 'data_machine_events', 'post_status' => 'publish' );
		$GLOBALS['ec_artist_test']['event_venues'][7][ $event_id ]  = array( $venue_id );
		$GLOBALS['ec_artist_test']['event_artists'][7][ $event_id ] = $artist_ids;
		if ( ! isset( $GLOBALS['ec_artist_test']['terms'][7][ $venue_id ] ) ) {
			$GLOBALS['ec_artist_test']['terms'][7][ $venue_id ] = new WP_Term( $venue_id, 'venue', 'Venue ' . $venue_id );
		}
	}
}
