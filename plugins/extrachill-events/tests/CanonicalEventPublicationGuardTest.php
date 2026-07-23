<?php
/**
 * Canonical publication/booking serialization tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingEventConversionService;
use ExtraChillEvents\Core\BookingHoldRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\CanonicalEventPublicationGuard;
use ExtraChillEvents\Core\VenueBookingConfig;
use ExtraChillEvents\Abilities\VenueBookingEventAbilities;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class CanonicalEventPublicationGuardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ec_test_filters'] = array();
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'       => 7,
			'stack'         => array(),
			'uuid'          => 0,
			'options'       => array(),
			'abilities'     => array(),
			'actions'       => array(),
			'fired_actions' => array(),
			'scheduled'     => array(),
			'cache_deletes' => array(),
			'posts'         => array(),
			'post_meta'     => array(),
			'event_venues'  => array( 7 => array() ),
			'parsed_blocks' => array(),
			'tms'           => array(),
			'terms'         => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
				),
			),
			'meta'          => array(
				7 => array(
					55 => array(
						'_venue_timezone'            => 'America/New_York',
						VenueBookingConfig::META_KEY => array(
							'enabled' => true,
							'spaces'  => array(
								array( 'key' => 'patio', 'name' => 'Patio' ),
								array( 'key' => 'main-room', 'name' => 'Main Room', 'is_default' => true ),
							),
						),
					),
				),
			),
		);
		$GLOBALS['wpdb'] = new BookingWpdb();
	}

	public function test_acquires_and_releases_one_stable_venue_lock(): void {
		$guard = new CanonicalEventPublicationGuard();

		$this->assertTrue( $guard->acquire_for_publication( 55, '2030-08-01 20:00:00', '2030-08-01 23:00:00' ) );
		$guard->release();

		$venue = BookingHoldRepository::venue_lock_name( 55 );
		$this->assertSame(
			array(
				array( 'get', $venue ),
				array( 'release', $venue ),
			),
			$GLOBALS['wpdb']->lock_names
		);
	}

	public function test_active_hold_conflict_releases_immediately(): void {
		$this->seed_hold( 'main-room', '2030-08-01 20:00:00', '2030-08-01 23:00:00' );
		$guard  = new CanonicalEventPublicationGuard();
		$result = $guard->acquire_for_publication( 55, '2030-08-01 21:00:00', '2030-08-02 00:00:00' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'canonical_event_booking_conflict', $result->get_error_code() );
		$this->assertSame( 'hold', $result->get_error_data()['conflict']['conflict_type'] );
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_confirmed_booking_conflict_is_venue_wide(): void {
		$this->seed_booking( 'patio', '2030-08-01 20:00:00', '2030-08-01 23:00:00', 'confirmed' );
		$result = ( new CanonicalEventPublicationGuard() )->acquire_for_publication( 55, '2030-08-01 20:30:00', '2030-08-01 21:30:00' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'confirmed_booking', $result->get_error_data()['conflict']['conflict_type'] );
		$this->assertSame( 'venue_wide', $result->get_error_data()['canonical_event_space_policy'] );
	}

	public function test_disabled_empty_booking_config_does_not_skip_durable_conflicts(): void {
		$GLOBALS['ec_artist_test']['meta'][7][55][ VenueBookingConfig::META_KEY ] = array( 'enabled' => false, 'spaces' => array() );
		$this->seed_booking( 'retired-room', '2030-08-01 20:00:00', '2030-08-01 23:00:00', 'confirmed' );

		$result = ( new CanonicalEventPublicationGuard() )->acquire_for_publication( 55, '2030-08-01 20:30:00', '2030-08-01 21:30:00' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'canonical_event_booking_conflict', $result->get_error_code() );
	}

	public function test_non_overlapping_publication_retains_locks_until_completion(): void {
		$this->seed_hold( 'main-room', '2030-08-02 20:00:00', '2030-08-02 23:00:00' );
		$guard = new CanonicalEventPublicationGuard();

		$this->assertTrue( $guard->acquire_for_publication( 55, '2030-08-01 20:00:00', '2030-08-01 23:00:00' ) );
		$this->assertSame( 0, $this->release_count() );
		$guard->release();
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_dme_conflict_denies_before_write_and_is_audited(): void {
		$this->seed_hold( 'main-room', '2030-08-01 20:00:00', '2030-08-01 23:00:00' );
		$guard = new CanonicalEventPublicationGuard();

		$this->assertInstanceOf( WP_Error::class, $this->preflight_dme( $guard, $this->dme_input() ) );
		$this->assertArrayHasKey( 'extrachill_events_canonical_event_publication_denied', $GLOBALS['ec_artist_test']['fired_actions'] );
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_dme_missing_end_uses_conservative_three_hour_window(): void {
		$this->seed_hold( 'main-room', '2030-08-01 22:00:00', '2030-08-01 23:00:00' );
		$input = $this->dme_input();
		unset( $input['event']['endTime'] );

		$this->assertInstanceOf( WP_Error::class, $this->preflight_dme( new CanonicalEventPublicationGuard(), $input ) );
	}

	public function test_dme_locks_remain_until_taxonomy_completion(): void {
		$guard = new CanonicalEventPublicationGuard();

		$context = $this->dme_context( $this->dme_input() );
		$this->assertTrue( $guard->preflight_dme_persistence( true, $context ) );
		$this->assertSame( 0, $this->release_count() );
		$guard->bind_dme_post_id( 901 );
		$guard->complete_dme_persistence( $context, 901, true );
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_second_dme_preflight_rejects_without_releasing_outer_context(): void {
		$guard = new CanonicalEventPublicationGuard();
		$context = $this->dme_context( $this->dme_input() );
		$this->assertTrue( $guard->preflight_dme_persistence( true, $context ) );

		$nested = $context;
		$nested['invocation_id'] = 'nested-invocation';
		$this->assertInstanceOf( WP_Error::class, $guard->preflight_dme_persistence( true, $nested ) );
		$this->assertSame( 0, $this->release_count() );
		$this->assertSame( 'canonical_event_publication_reentrant', $GLOBALS['ec_artist_test']['fired_actions']['extrachill_events_canonical_event_publication_denied'][0][1]->get_error_code() );
		$guard->complete_dme_persistence( $nested, 0, new WP_Error( 'nested_denied' ) );
		$this->assertSame( 0, $this->release_count(), 'Nested completion must not release the outer invocation.' );
		$guard->complete_dme_persistence( $context, 0, new WP_Error( 'outer_complete' ) );
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_sourceless_new_dme_write_consumes_only_first_zero_id_boundary(): void {
		$guard   = new CanonicalEventPublicationGuard();
		$context = $this->dme_context( $this->dme_input() );
		$this->assertTrue( $guard->preflight_dme_persistence( true, $context ) );
		$postarr = array( 'post_type' => 'data_machine_events', 'post_status' => 'publish' );

		$this->assertFalse( $guard->preflight_direct_post_insert( false, $postarr ) );
		$this->assertTrue( $guard->preflight_direct_post_insert( false, $postarr ) );
		$this->assertSame( 0, $this->release_count() );
		$guard->complete_dme_persistence( $context, 901, true );
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_dme_fence_and_completion_require_bound_post_identity(): void {
		$guard = new CanonicalEventPublicationGuard();
		$context = $this->dme_context( $this->dme_input() );
		$this->assertTrue( $guard->preflight_dme_persistence( true, $context ) );
		$guard->bind_dme_post_id( 901 );
		$postarr = array(
			'ID'           => 902,
			'post_type'    => 'data_machine_events',
			'post_status'  => 'publish',
			'post_title'   => 'Wrong Event',
			'post_content' => '',
			'post_excerpt' => '',
		);

		$this->assertTrue( $guard->preflight_direct_post_insert( false, $postarr ) );
		$this->assertSame( 0, $this->release_count() );
		$this->assertTrue( $guard->preflight_direct_post_insert( false, array_merge( $postarr, array( 'ID' => 901 ) ) ), 'A poisoned identity must fail closed.' );
		$guard->complete_dme_persistence( $context, 0, new WP_Error( 'identity_mismatch' ) );
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_dme_guard_does_not_widen_existing_authorization(): void {
		$guard = new CanonicalEventPublicationGuard();

		$this->assertFalse( $guard->preflight_dme_persistence( false, $this->dme_context( $this->dme_input() ) ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->lock_names );
	}

	public function test_spoofed_booking_conversion_source_does_not_exempt_confirmed_booking(): void {
		$booking_id = $this->seed_booking( 'main-room', '2030-08-01 20:00:00', '2030-08-01 23:00:00', 'confirmed' );
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking_id ]['public_id'] = 'booking-source';
		$input              = $this->dme_input();
		$input['source']    = BookingEventConversionService::SOURCE;
		$input['source_id'] = 'booking-source';
		$guard              = new CanonicalEventPublicationGuard();

		$this->assertInstanceOf( WP_Error::class, $this->preflight_dme( $guard, $input ) );
		$this->assertSame( 'canonical_event_booking_conflict', $GLOBALS['ec_artist_test']['fired_actions']['extrachill_events_canonical_event_publication_denied'][0][1]->get_error_code() );
	}

	public function test_dst_fallback_window_covers_both_repeated_wall_time_occurrences(): void {
		$this->seed_hold( 'main-room', '2030-11-03 06:10:00', '2030-11-03 06:20:00' );
		$input                       = $this->dme_input();
		$input['event']['startDate'] = '2030-11-03';
		$input['event']['startTime'] = '01:30';
		$input['event']['endTime']   = '01:45';

		$this->assertInstanceOf( WP_Error::class, $this->preflight_dme( new CanonicalEventPublicationGuard(), $input ) );
	}

	public function test_dst_spring_forward_gap_is_rejected(): void {
		$input                       = $this->dme_input();
		$input['event']['startDate'] = '2030-03-10';
		$input['event']['startTime'] = '02:30';
		$input['event']['endTime']   = '03:30';

		$this->assertInstanceOf( WP_Error::class, $this->preflight_dme( new CanonicalEventPublicationGuard(), $input ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->lock_names );
	}

	public function test_release_failure_is_audited_while_every_lock_is_attempted(): void {
		$guard = new CanonicalEventPublicationGuard();
		$this->assertTrue( $guard->acquire_for_publication( 55, '2030-08-01 20:00:00', '2030-08-01 23:00:00' ) );
		$GLOBALS['wpdb']->release_lock_results = array( 0 );
		$GLOBALS['wpdb']->release_lock_errors  = array( 'simulated release uncertainty' );

		$guard->release();

		$this->assertSame( 1, $this->release_count() );
		$this->assertCount( 1, $GLOBALS['ec_artist_test']['fired_actions']['extrachill_events_canonical_event_booking_lock_release_failed'] );
		$audit = $GLOBALS['ec_artist_test']['fired_actions']['extrachill_events_canonical_event_booking_lock_release_failed'][0];
		$this->assertSame( BookingHoldRepository::venue_lock_name( 55 ), $audit[0] );
		$this->assertSame( 'simulated release uncertainty', $audit[1] );
		$logs = $GLOBALS['ec_artist_test']['fired_actions']['datamachine_log'];
		$this->assertSame( 'error', $logs[0][0] );
		$this->assertSame( array( 'lock_name', 'database_error' ), array_keys( $logs[0][2] ) );
	}

	public function test_rest_conflict_returns_error_before_persistence(): void {
		$this->seed_booking( 'main-room', '2030-08-01 20:00:00', '2030-08-01 23:00:00', 'confirmed' );
		$GLOBALS['ec_artist_test']['parsed_blocks']['event-content'] = array(
			array(
				'blockName' => 'data-machine-events/event-details',
				'attrs'     => array( 'startDate' => '2030-08-01', 'startTime' => '16:00', 'endTime' => '19:00' ),
			),
		);
		$post = (object) array( 'ID' => 0, 'post_status' => 'publish', 'post_content' => 'event-content' );

		$result = ( new CanonicalEventPublicationGuard() )->preflight_rest_insert( $post, new BookingTestRestRequest( array( 'venue' => array( 55 ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'canonical_event_booking_conflict', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_artist_test']['posts'] );
	}

	public function test_rest_venue_only_update_of_published_event_is_preflighted(): void {
		$this->seed_booking( 'main-room', '2030-08-01 20:00:00', '2030-08-01 23:00:00', 'confirmed' );
		$GLOBALS['ec_artist_test']['posts'][7][901] = (object) array(
			'ID'           => 901,
			'post_type'    => 'data_machine_events',
			'post_status'  => 'publish',
			'post_title'   => 'Published Event',
			'post_content' => 'event-content',
			'post_excerpt' => '',
		);
		$GLOBALS['ec_artist_test']['parsed_blocks']['event-content'] = array(
			array(
				'blockName' => 'data-machine-events/event-details',
				'attrs'     => array( 'startDate' => '2030-08-01', 'startTime' => '16:00', 'endTime' => '19:00' ),
			),
		);
		$prepared = (object) array( 'ID' => 901 );

		$result = ( new CanonicalEventPublicationGuard() )->preflight_rest_insert( $prepared, new BookingTestRestRequest( array( 'venue' => array( 55 ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'canonical_event_booking_conflict', $result->get_error_code() );
	}

	public function test_rest_retained_venue_read_failure_fails_closed(): void {
		$GLOBALS['ec_artist_test']['posts'][7][901] = (object) array(
			'ID'           => 901,
			'post_type'    => 'data_machine_events',
			'post_status'  => 'publish',
			'post_title'   => 'Published Event',
			'post_content' => 'event-content',
			'post_excerpt' => '',
		);
		$GLOBALS['ec_artist_test']['venue_terms_error'] = true;

		$result = ( new CanonicalEventPublicationGuard() )->preflight_rest_insert( (object) array( 'ID' => 901 ), new BookingTestRestRequest( array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'canonical_event_venue_read_failed', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( 'venue_terms_read_failed', $result->get_error_data()['cause'] );
		$this->assertSame( 'simulated venue taxonomy read failure', $result->get_error_data()['database_error'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->lock_names );
	}

	public function test_rest_locks_remain_until_after_insert(): void {
		$GLOBALS['ec_artist_test']['parsed_blocks']['event-content'] = array(
			array(
				'blockName' => 'data-machine-events/event-details',
				'attrs'     => array( 'startDate' => '2030-08-01', 'startTime' => '16:00', 'endTime' => '19:00' ),
			),
		);
		$post    = (object) array( 'ID' => 0, 'post_status' => 'publish', 'post_content' => 'event-content' );
		$guard   = new CanonicalEventPublicationGuard();
		$request = new BookingTestRestRequest( array( 'venue' => array( 55 ) ) );

		$this->assertSame( $post, $guard->preflight_rest_insert( $post, $request ) );
		$this->assertSame( 0, $this->release_count() );
		$guard->complete_rest_insert( (object) array( 'ID' => 901 ), $request );
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_rest_callback_error_fallback_releases_exactly_once(): void {
		$GLOBALS['ec_artist_test']['parsed_blocks']['event-content'] = array(
			array( 'blockName' => 'data-machine-events/event-details', 'attrs' => array( 'startDate' => '2030-08-01', 'startTime' => '16:00', 'endTime' => '19:00' ) ),
		);
		$guard   = new CanonicalEventPublicationGuard();
		$post    = (object) array( 'ID' => 0, 'post_status' => 'publish', 'post_content' => 'event-content' );
		$request = new BookingTestRestRequest( array( 'venue' => array( 55 ) ) );
		$this->assertSame( $post, $guard->preflight_rest_insert( $post, $request ) );

		$error = new WP_Error( 'rest_persistence_failed', 'REST callback failed.' );
		$this->assertSame( $error, $guard->complete_rest_request( $error, null, $request ) );
		$this->assertSame( 1, $this->release_count() );
		$guard->complete_rest_insert( (object) array( 'ID' => 901 ), $request );
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_nested_rest_request_cannot_release_owning_request_lock(): void {
		$GLOBALS['ec_artist_test']['parsed_blocks']['event-content'] = array(
			array( 'blockName' => 'data-machine-events/event-details', 'attrs' => array( 'startDate' => '2030-08-01', 'startTime' => '16:00', 'endTime' => '19:00' ) ),
		);
		$guard   = new CanonicalEventPublicationGuard();
		$post    = (object) array( 'ID' => 0, 'post_status' => 'publish', 'post_content' => 'event-content' );
		$owner   = new BookingTestRestRequest( array( 'venue' => array( 55 ) ) );
		$nested  = new BookingTestRestRequest( array() );
		$this->assertSame( $post, $guard->preflight_rest_insert( $post, $owner ) );

		$response = new WP_Error( 'nested_complete', 'Nested request completed.' );
		$this->assertSame( $response, $guard->complete_rest_request( $response, null, $nested ) );
		$this->assertSame( 0, $this->release_count() );
		$guard->complete_rest_insert( (object) array( 'ID' => 901 ), $nested );
		$this->assertSame( 0, $this->release_count() );
		$guard->complete_rest_insert( (object) array( 'ID' => 901 ), $owner );
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_rest_update_excludes_the_events_own_confirmed_booking(): void {
		$booking_id = $this->seed_booking( 'main-room', '2030-08-01 20:00:00', '2030-08-01 23:00:00', 'confirmed' );
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking_id ]['event_id'] = 901;
		$GLOBALS['ec_artist_test']['event_venues'][7][901]                                  = array( 55 );
		$GLOBALS['ec_artist_test']['parsed_blocks']['event-content']                        = array(
			array(
				'blockName' => 'data-machine-events/event-details',
				'attrs'     => array( 'startDate' => '2030-08-01', 'startTime' => '16:00', 'endTime' => '19:00' ),
			),
		);
		$post    = (object) array( 'ID' => 901, 'post_status' => 'publish', 'post_content' => 'event-content' );
		$guard   = new CanonicalEventPublicationGuard();
		$request = new BookingTestRestRequest( array() );

		$this->assertSame( $post, $guard->preflight_rest_insert( $post, $request ) );
		$guard->complete_rest_insert( $post, $request );
	}

	public function test_direct_tax_input_locks_remain_through_wp_after_insert_post(): void {
		$GLOBALS['ec_artist_test']['parsed_blocks']['event-content'] = array(
			array(
				'blockName' => 'data-machine-events/event-details',
				'attrs'     => array( 'startDate' => '2030-08-01', 'startTime' => '16:00', 'endTime' => '19:00' ),
			),
		);
		$data = array(
			'post_type'    => 'data_machine_events',
			'post_status'  => 'publish',
			'post_title'   => 'Direct Event',
			'post_content' => 'event-content',
			'post_excerpt' => '',
		);
		$guard = new CanonicalEventPublicationGuard();

		$this->assertFalse( $guard->preflight_direct_post_insert( false, array_merge( $data, array( 'tax_input' => array( 'venue' => array( 55 ) ) ) ) ) );
		$this->assertSame( 0, $this->release_count() );
		$guard->complete_post_insert( 901, (object) array( 'ID' => 901, 'post_type' => 'data_machine_events' ) );
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_direct_source_less_insert_ignores_nested_non_event_completion(): void {
		$GLOBALS['ec_artist_test']['parsed_blocks']['event-content'] = array(
			array( 'blockName' => 'data-machine-events/event-details', 'attrs' => array( 'startDate' => '2030-08-01', 'startTime' => '16:00', 'endTime' => '19:00' ) ),
		);
		$data = array(
			'post_type'    => 'data_machine_events',
			'post_status'  => 'publish',
			'post_title'   => 'Direct Event',
			'post_content' => 'event-content',
			'post_excerpt' => '',
			'tax_input'    => array( 'venue' => array( 55 ) ),
		);
		$guard = new CanonicalEventPublicationGuard();
		$this->assertFalse( $guard->preflight_direct_post_insert( false, $data ) );

		$guard->complete_post_insert( 700, (object) array( 'ID' => 700, 'post_type' => 'post' ) );
		$this->assertSame( 0, $this->release_count() );
		$guard->complete_post_insert( 901, (object) array( 'ID' => 901, 'post_type' => 'data_machine_events' ) );
		$this->assertSame( 1, $this->release_count() );
	}

	public function test_direct_tax_input_conflict_aborts_before_seeded_state_changes(): void {
		$this->seed_booking( 'main-room', '2030-08-01 20:00:00', '2030-08-01 23:00:00', 'confirmed' );
		$GLOBALS['ec_artist_test']['posts'][7][901] = (object) array( 'ID' => 901, 'post_type' => 'data_machine_events', 'post_status' => 'publish', 'post_title' => 'Original', 'post_content' => 'original-content', 'post_excerpt' => '' );
		$GLOBALS['ec_artist_test']['event_venues'][7][901] = array( 77 );
		$GLOBALS['ec_artist_test']['parsed_blocks']['changed-content'] = array(
			array( 'blockName' => 'data-machine-events/event-details', 'attrs' => array( 'startDate' => '2030-08-01', 'startTime' => '16:00', 'endTime' => '19:00' ) ),
		);
		$before_post  = clone $GLOBALS['ec_artist_test']['posts'][7][901];
		$before_terms = $GLOBALS['ec_artist_test']['event_venues'][7][901];

		$aborted = ( new CanonicalEventPublicationGuard() )->preflight_direct_post_insert( false, array( 'ID' => 901, 'post_type' => 'data_machine_events', 'post_status' => 'publish', 'post_title' => 'Changed', 'post_content' => 'changed-content', 'tax_input' => array( 'venue' => array( 55 ) ) ) );

		$this->assertTrue( $aborted );
		$this->assertEquals( $before_post, $GLOBALS['ec_artist_test']['posts'][7][901] );
		$this->assertSame( $before_terms, $GLOBALS['ec_artist_test']['event_venues'][7][901] );
	}

	public function test_only_exact_originating_hold_is_exempt(): void {
		$this->seed_hold( 'main-room', '2030-08-01 20:00:00', '2030-08-01 23:00:00' );
		$guard = new CanonicalEventPublicationGuard();
		$this->assertTrue( $guard->acquire_for_publication( 55, '2030-08-01 20:00:00', '2030-08-01 23:00:00', 0, 10 ) );
		$guard->release();

		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][2] = array( 'id' => 2, 'booking_id' => 10, 'venue_term_id' => 55, 'space_key' => 'patio', 'start_at' => '2030-08-01 21:00:00', 'end_at' => '2030-08-01 22:30:00', 'expires_at' => '2035-01-01 00:00:00', 'status' => 'active' );
		$result = ( new CanonicalEventPublicationGuard() )->acquire_for_publication( 55, '2030-08-01 20:00:00', '2030-08-01 23:00:00', 0, 10 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 2, $result->get_error_data()['conflict']['id'] );
	}

	public function test_event_linked_booking_requires_exact_venue_and_candidate_interval(): void {
		$id = $this->seed_booking( 'main-room', '2030-08-01 20:00:00', '2030-08-01 23:00:00', 'confirmed' );
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $id ]['event_id'] = 901;
		$guard = new CanonicalEventPublicationGuard();
		$this->assertTrue( $guard->acquire_for_publication( 55, '2030-08-01 20:00:00', '2030-08-01 23:00:00', 901 ) );
		$guard->release();
		$this->assertInstanceOf( WP_Error::class, ( new CanonicalEventPublicationGuard() )->acquire_for_publication( 55, '2030-08-01 20:30:00', '2030-08-01 23:30:00', 901 ) );
		$this->assertInstanceOf( WP_Error::class, ( new CanonicalEventPublicationGuard() )->acquire_for_publication( 56, '2030-08-01 20:00:00', '2030-08-01 23:00:00', 901 ) );
	}

	public function test_fold_conversion_exemption_accepts_each_occurrence_but_not_altered_interval(): void {
		$reflection = new ReflectionClass( VenueBookingEventAbilities::class );
		$wrapper    = $reflection->newInstanceWithoutConstructor();
		$property   = $reflection->getProperty( 'active_conversion' );
		$property->setAccessible( true );
		$publication = array(
			'venue_id' => 55,
			'start_at' => '2030-11-03 05:30:00',
			'end_at'   => '2030-11-03 06:45:00',
			'_candidate_intervals' => array(
				array( 'start_at' => '2030-11-03 05:30:00', 'end_at' => '2030-11-03 05:45:00' ),
				array( 'start_at' => '2030-11-03 06:30:00', 'end_at' => '2030-11-03 06:45:00' ),
			),
		);
		$input = array( 'source' => BookingEventConversionService::SOURCE, 'source_id' => 'fold-booking' );
		foreach ( $publication['_candidate_intervals'] as $index => $interval ) {
			$property->setValue( $wrapper, array( 'id' => 40 + $index, 'public_id' => 'fold-booking', 'venue_term_id' => 55, 'performance_start_at' => $interval['start_at'], 'performance_end_at' => $interval['end_at'] ) );
			$this->assertSame( 40 + $index, $wrapper->excluded_booking_id_for_active_conversion( 0, $input, $publication ) );
		}
		$property->setValue( $wrapper, array( 'id' => 42, 'public_id' => 'fold-booking', 'venue_term_id' => 55, 'performance_start_at' => '2030-11-03 05:30:00', 'performance_end_at' => '2030-11-03 06:00:00' ) );
		$this->assertSame( 0, $wrapper->excluded_booking_id_for_active_conversion( 0, $input, $publication ) );
	}

	public function test_complete_event_update_uses_proposed_values_and_exact_invocation_owner(): void {
		$context = array(
			'invocation_id'      => 'owning-update',
			'post_id'            => 901,
			'post_status'        => 'publish',
			'event'              => array( 'startDate' => '2030-08-01', 'startTime' => '16:00', 'endTime' => '19:00' ),
			'next_venue_id'      => 55,
			'previous_venue_ids' => array( 77 ),
		);
		$guard = new CanonicalEventPublicationGuard();

		$this->assertTrue( $guard->preflight_event_update_persistence( true, $context ) );
		$this->assertFalse( $guard->preflight_direct_post_insert( false, array( 'ID' => 901, 'post_type' => 'data_machine_events', 'post_status' => 'publish' ) ) );
		$other = array_merge( $context, array( 'invocation_id' => 'nested-update' ) );
		$guard->complete_event_update_persistence( $other, array() );
		$this->assertSame( 0, $this->release_count() );
		$guard->complete_event_update_persistence( $context, array() );
		$this->assertSame( 1, $this->release_count() );

		$this->seed_hold( 'main-room', '2030-08-01 20:00:00', '2030-08-01 23:00:00' );
		$result = ( new CanonicalEventPublicationGuard() )->preflight_event_update_persistence( true, $context );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'canonical_event_booking_conflict', $result->get_error_code() );
	}

	private function dme_input(): array {
		return array(
			'source'      => 'test-source',
			'source_id'   => 'test-event',
			'post_status' => 'publish',
			'event'       => array(
				'venue'     => 'The Room',
				'startDate' => '2030-08-01',
				'startTime' => '16:00',
				'endTime'   => '19:00',
			),
		);
	}

	private function dme_context( array $input ): array {
		return array(
			'invocation_id'     => 'outer-invocation',
			'venue_term_id'   => 55,
			'event'           => $input['event'],
			'post_status'     => $input['post_status'] ?? 'publish',
			'existing_post_id' => 0,
			'source'          => $input['source'] ?? '',
			'source_id'       => $input['source_id'] ?? '',
			'source_identity' => hash( 'sha256', ( $input['source'] ?? '' ) . "\0" . ( $input['source_id'] ?? '' ) ),
		);
	}

	private function preflight_dme( CanonicalEventPublicationGuard $guard, array $input ) {
		return $guard->preflight_dme_persistence( true, $this->dme_context( $input ) );
	}

	private function seed_hold( string $space, string $start, string $end ): void {
		$table = BookingSchema::holds_table();
		$GLOBALS['wpdb']->rows[ $table ][1] = array(
			'id'            => 1,
			'booking_id'    => 10,
			'venue_term_id' => 55,
			'space_key'     => $space,
			'start_at'      => $start,
			'end_at'        => $end,
			'expires_at'    => '2035-01-01 00:00:00',
			'status'        => 'active',
		);
	}

	private function seed_booking( string $space, string $start, string $end, string $status ): int {
		$table = BookingSchema::bookings_table();
		$id    = count( $GLOBALS['wpdb']->rows[ $table ] ?? array() ) + 1;
		$GLOBALS['wpdb']->rows[ $table ][ $id ] = array(
			'id'                   => $id,
			'public_id'            => 'booking-' . $id,
			'venue_term_id'        => 55,
			'space_key'            => $space,
			'performance_start_at' => $start,
			'performance_end_at'   => $end,
			'status'               => $status,
		);
		return $id;
	}

	private function release_count(): int {
		return count(
			array_filter(
				$GLOBALS['wpdb']->lock_names,
				static function ( array $entry ): bool {
					return 'release' === $entry[0];
				}
			)
		);
	}
}
