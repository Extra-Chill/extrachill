<?php
/**
 * Booking hold, conflict, and hold-aware lifecycle tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\VenueBookingHoldAbilities;
use ExtraChillEvents\Abilities\VenueBookingAbilities;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingHoldRepository;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;
use ExtraChillEvents\Core\VenueAuthorization;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class BookingHoldTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'       => 7,
			'stack'         => array(),
			'uuid'          => 0,
			'options'       => array(),
			'dbdelta'       => array(),
			'abilities'     => array(),
			'actions'       => array(),
			'fired_actions' => array(),
			'scheduled'     => array(),
			'cache_deletes' => array(),
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
							'enabled'          => true,
							'hold_ttl_minutes' => 30,
							'spaces'           => array(
								array(
									'key'        => 'main-room',
									'name'       => 'Main Room',
									'is_default' => true,
								),
								array(
									'key'  => 'patio',
									'name' => 'Patio',
								),
							),
						),
					),
				),
			),
			'posts'         => array(),
			'post_meta'     => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
	}

	private function booking( string $start = '2030-08-01 20:00:00', string $end = '2030-08-01 23:00:00', string $space = 'main-room', array $overrides = array() ) {
		$booking = ( new BookingRepository() )->create(
			array_merge(
				array(
					'venue_term_id'        => 55,
					'artist_name'          => 'Test Band',
					'intake'               => array(),
					'deal'                 => array(
						'version'                    => 1,
						'type'                       => 'guarantee',
						'guarantee_cents'            => 100000,
						'revenue_share_basis_points' => 0,
						'revenue_share_basis'        => 'gross_ticket_sales',
						'currency'                   => 'USD',
						'capacity'                   => null,
						'advance_ticket_price_cents' => null,
						'door_ticket_price_cents'    => null,
						'ticket_fee_cents'           => null,
						'tickets_on_sale_at'         => null,
						'ticket_url'                  => null,
						'additional_terms'           => null,
					),
					'space_key'            => $space,
					'performance_start_at' => $start,
					'performance_end_at'   => $end,
				),
				$overrides
			)
		);
		if ( is_array( $booking ) ) {
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = $overrides['status'] ?? 'negotiating';
			$booking['status'] = $overrides['status'] ?? 'negotiating';
		}
		return $booking;
	}

	private function holds(): BookingHoldRepository {
		return new BookingHoldRepository( null, null, new BookingTestAuthorization() );
	}

	private function seed_held_venue( int $count, bool $elapsed ): array {
		$holds     = $this->holds();
		$lifecycle = new BookingLifecycle( null, null, null, null, $holds );
		$booking   = $this->booking( '2032-01-01 20:00:00', '2032-01-01 23:00:00' );
		$hold      = $holds->create( $booking['id'], 1, 12 );
		$lifecycle->transition( $booking['id'], 'held', 2, 12 );
		$booking_row = $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ];
		$hold_row    = $GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $hold['hold']['id'] ];
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] = array();
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ]    = array();
		for ( $id = 1; $id <= $count; ++$id ) {
			$booking_row['id']        = $id;
			$booking_row['public_id'] = sprintf( '123e4567-e89b-42d3-a456-%012d', $id );
			$hold_row['id']           = $id;
			$hold_row['booking_id']   = $id;
			$hold_row['expires_at']   = $elapsed ? gmdate( 'Y-m-d H:i:s' ) : '2035-01-01 00:00:00';
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $id ] = $booking_row;
			$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $id ]    = $hold_row;
		}
		return array( $holds, $lifecycle );
	}

	public function test_combined_schema_installs_site_scoped_holds_contract(): void {
		$this->assertSame( '7', BookingSchema::SCHEMA_VERSION );
		$this->assertTrue( BookingSchema::install() );
		$table = BookingSchema::holds_table();
		$this->assertSame( 'wp_7_ec_booking_holds', $table );
		$this->assertSame( 'InnoDB', $GLOBALS['wpdb']->engines[ $table ] );
		$this->assertSame( array( 'venue_term_id', 'space_key', 'status', 'start_at', 'end_at' ), $GLOBALS['wpdb']->schemas[ $table ]['indexes']['venue_space_overlap']['columns'] );
		$this->assertTrue( BookingSchema::health() );
	}

	public function test_ranges_are_strict_and_half_open_boundaries_do_not_conflict(): void {
		$this->assertSame( 'invalid_booking_date_range', $this->booking( '2030-08-01 20:00:00', '2030-08-01 20:00:00' )->get_error_code() );
		$holds = $this->holds();
		$first = $this->booking();
		$this->assertSame( 2, $holds->create( $first['id'], 1, 12 )['booking_version'] );
		$adjacent = $this->booking( '2030-08-01 23:00:00', '2030-08-02 01:00:00' );
		$this->assertSame( 'active', $holds->create( $adjacent['id'], 1, 12 )['hold']['status'] );
		$patio = $this->booking( '2030-08-01 23:00:00', '2030-08-02 01:00:00', 'patio' );
		$this->assertSame( 'active', $holds->create( $patio['id'], 1, 12 )['hold']['status'] );
		$this->assertSame( BookingHoldRepository::venue_lock_name( 55 ), $GLOBALS['wpdb']->lock_names[0][1], 'Booking writers must acquire the venue lock first.' );
		$this->assertSame( $GLOBALS['wpdb']->lock_names[0][1], $GLOBALS['wpdb']->lock_names[4][1], 'The same venue must produce the same outer lock name.' );
		$this->assertSame( $GLOBALS['wpdb']->lock_names[1][1], $GLOBALS['wpdb']->lock_names[5][1], 'The same venue-space must produce the same inner lock name.' );
		$this->assertNotSame( $GLOBALS['wpdb']->lock_names[1][1], $GLOBALS['wpdb']->lock_names[9][1], 'Different spaces must not share an inner lock.' );
		$this->assertLessThanOrEqual( 64, strlen( $GLOBALS['wpdb']->lock_names[0][1] ) );
		$first_lock = $GLOBALS['wpdb']->lock_names[0][1];
		$GLOBALS['wpdb']->prefix = 'wp_8_';
		$other_site = $this->booking( '2030-10-01 20:00:00', '2030-10-01 23:00:00' );
		$holds->create( $other_site['id'], 1, 12 );
		$this->assertNotSame( $first_lock, $GLOBALS['wpdb']->lock_names[12][1], 'Different site tables must not share advisory locks.' );
	}

	public function test_same_space_conflicts_different_space_succeeds_and_elapsed_never_blocks(): void {
		$holds   = $this->holds();
		$first   = $this->booking();
		$created = $holds->create( $first['id'], 1, 12 );
		$overlap = $this->booking( '2030-08-01 22:00:00', '2030-08-02 00:00:00' );
		$this->assertSame( 'booking_time_conflict', $holds->create( $overlap['id'], 1, 12 )->get_error_code() );
		$different = $this->booking( '2030-08-01 22:00:00', '2030-08-02 00:00:00', 'patio' );
		$this->assertSame( 'active', $holds->create( $different['id'], 1, 12 )['hold']['status'] );

		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $created['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$retry = $holds->create( $overlap['id'], 1, 12 );
		$this->assertSame( 'active', $retry['hold']['status'], 'An exactly elapsed hold must not block even before cleanup.' );
	}

	public function test_duplicate_exact_active_hold_is_rejected_without_booking_mutation(): void {
		$holds   = $this->holds();
		$booking = $this->booking();
		$holds->create( $booking['id'], 1, 12 );
		$duplicate = $holds->create( $booking['id'], 2, 12 );
		$this->assertSame( 'booking_hold_already_active', $duplicate->get_error_code() );
		$this->assertSame( 409, $duplicate->get_error_data()['status'] );
		$this->assertSame( 2, ( new BookingRepository() )->get( $booking['id'] )['version'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ] );
	}

	public function test_release_is_optimistic_audited_and_does_not_increment_booking_version(): void {
		$holds   = $this->holds();
		$booking = $this->booking();
		$created = $holds->create( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_hold_version_conflict', $holds->release( $created['hold']['id'], 2, 12, 'changed plans' )->get_error_code() );
		$released = $holds->release( $created['hold']['id'], 1, 12, 'changed plans' );
		$this->assertSame( 'released', $released['status'] );
		$this->assertSame( 'changed plans', $released['release_reason'] );
		$this->assertSame( 2, ( new BookingRepository() )->get( $booking['id'] )['version'], 'Release is hold-local and intentionally leaves the booking version unchanged.' );
		$this->assertSame( 'hold_released', ( new BookingActivityRepository() )->list_for_booking( $booking['id'] )[0]['kind'] );
		$empty      = $this->booking( '2030-09-01 20:00:00', '2030-09-01 23:00:00' );
		$empty_hold = $holds->create( $empty['id'], 1, 12 );
		$this->assertSame( 'booking_hold_release_reason_required', $holds->release( $empty_hold['hold']['id'], 1, 12, '<b></b>' )->get_error_code() );
	}

	public function test_hold_creation_is_restricted_to_operational_statuses(): void {
		$holds = $this->holds();
		foreach ( array( 'submitted', 'needs_info', 'under_review', 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed' ) as $status ) {
			$booking = $this->booking( '2031-01-01 20:00:00', '2031-01-01 23:00:00', 'main-room', array( 'status' => $status ) );
			$result  = $holds->create( $booking['id'], 1, 12 );
			$this->assertSame( 'booking_hold_status_forbidden', $result->get_error_code(), $status );
			$this->assertSame( 409, $result->get_error_data()['status'] );
		}
	}

	public function test_scheduler_cleanup_is_idempotent_and_exact_expiry_is_elapsed(): void {
		$holds   = $this->holds();
		$booking = $this->booking();
		$created = $holds->create( $booking['id'], 1, 12 );
		$this->assertCount( 1, $GLOBALS['ec_artist_test']['scheduled'] );
		$this->assertSame( BookingHoldRepository::EXPIRY_HOOK, $GLOBALS['ec_artist_test']['scheduled'][0]['hook'] );
		$this->assertSame( array( $created['hold']['id'], 0 ), $GLOBALS['ec_artist_test']['scheduled'][0]['args'] );
		$id = $created['hold']['id'];
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $id ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$this->assertSame( 'expired', $holds->expire( $id )['status'] );
		$this->assertSame( 'expired', $holds->expire( $id )['status'] );
		$this->assertSame( 2, $holds->get( $id )['version'] );
	}

	public function test_hold_lifetime_and_effective_expiry_use_database_time(): void {
		$GLOBALS['wpdb']->database_now = '2034-01-01 00:00:00';

		$holds   = $this->holds();
		$booking = $this->booking( '2034-08-01 20:00:00', '2034-08-01 23:00:00' );
		$created = $holds->create( $booking['id'], 1, 12 );
		$this->assertSame( '2034-01-01 00:00:00', $created['hold']['created_at'] );
		$this->assertSame( '2034-01-01 00:30:00', $created['hold']['expires_at'] );

		$GLOBALS['wpdb']->database_now = '2034-01-01 00:30:00';
		$this->assertSame( 'expired', $holds->get( $created['hold']['id'] )['status'] );
		$this->assertSame( 'booking_hold_not_active', $holds->release( $created['hold']['id'], 1, 12, 'database expiry' )->get_error_code() );
	}

	public function test_hold_creation_returns_database_clock_errors(): void {
		$holds   = $this->holds();
		$booking = $this->booking( '2034-09-01 20:00:00', '2034-09-01 23:00:00' );
		$GLOBALS['wpdb']->fail_clock_reads = true;

		$result = $holds->create( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_hold_clock_read_failed', $result->get_error_code() );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );
	}

	public function test_elapsed_holds_are_effectively_expired_in_get_list_and_release(): void {
		$holds   = $this->holds();
		$booking = $this->booking();
		$created = $holds->create( $booking['id'], 1, 12 );
		$id      = $created['hold']['id'];
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $id ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$this->assertSame( 'expired', $holds->get( $id )['status'] );
		$this->assertSame( $holds->get( $id )['expires_at'], $holds->get( $id )['expired_at'] );
		$this->assertCount(
			0,
			$holds->list(
				array(
					'venue_term_id' => 55,
					'status'        => 'active',
				),
				12
			)
		);
		$expired = $holds->list(
			array(
				'venue_term_id' => 55,
				'status'        => 'expired',
			),
			12
		);
		$this->assertSame( array( 'expired' ), array_column( $expired, 'status' ) );
		$this->assertSame( 'expired', $holds->list( array( 'venue_term_id' => 55 ), 12 )[0]['status'] );
		$this->assertSame( 'booking_hold_not_active', $holds->release( $id, 1, 12, 'too late' )->get_error_code() );
		$this->assertSame( 'expired', $GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $id ]['status'], 'Release must persist elapsed state before returning not-active.' );
	}

	public function test_expiring_selected_hold_reopens_held_booking_but_alternative_expiry_does_not(): void {
		$holds     = $this->holds();
		$lifecycle = new BookingLifecycle( null, null, null, null, $holds );
		$booking   = $this->booking();
		$selected  = $holds->create( $booking['id'], 1, 12 );
		$lifecycle->transition( $booking['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $selected['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		BookingHoldRepository::expire_scheduled( $selected['hold']['id'] );
		$this->assertSame( 'expired', $holds->get( $selected['hold']['id'] )['status'] );
		$reopened = ( new BookingRepository() )->get( $booking['id'] );
		$this->assertSame( 'negotiating', $reopened['status'] );
		$this->assertSame( 4, $reopened['version'] );
		$activity = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$this->assertSame( array( 'status_changed', 'hold_expired', 'status_changed', 'hold_created' ), array_column( $activity, 'kind' ) );

		$replacement = $holds->create( $booking['id'], 4, 12 );
		$lifecycle->transition( $booking['id'], 'held', 5, 12 );
		$alternative               = $replacement['hold'];
		$alternative['id']         = 3;
		$alternative['space_key']  = 'patio';
		$alternative['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][3] = $alternative;
		$holds->expire( 3 );
		$this->assertSame( 'held', ( new BookingRepository() )->get( $booking['id'] )['status'] );
	}

	public function test_create_reconciles_elapsed_held_booking_before_replacement(): void {
		$holds     = $this->holds();
		$lifecycle = new BookingLifecycle( null, null, null, null, $holds );
		$booking   = $this->booking();
		$selected  = $holds->create( $booking['id'], 1, 12 );
		$lifecycle->transition( $booking['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $selected['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$stale = $holds->create( $booking['id'], 3, 12 );
		$this->assertSame( 'booking_version_conflict', $stale->get_error_code() );
		$this->assertSame( 4, $stale->get_error_data()['current_version'] );
		$this->assertSame( 'negotiating', ( new BookingRepository() )->get( $booking['id'] )['status'] );
		$replacement = $holds->create( $booking['id'], 4, 12 );
		$this->assertSame( 'active', $replacement['hold']['status'] );
		$this->assertSame( 5, $replacement['booking_version'] );
	}

	public function test_transition_reconciles_elapsed_held_booking_before_validation(): void {
		$holds     = $this->holds();
		$lifecycle = new BookingLifecycle( null, null, null, null, $holds );
		$booking   = $this->booking();
		$selected  = $holds->create( $booking['id'], 1, 12 );
		$lifecycle->transition( $booking['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $selected['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$result = $lifecycle->transition( $booking['id'], 'confirmed', 3, 12 );
		$this->assertSame( 'booking_version_conflict', $result->get_error_code() );
		$this->assertSame( 4, $result->get_error_data()['current_version'] );
		$this->assertSame( 'negotiating', ( new BookingRepository() )->get( $booking['id'] )['status'] );
	}

	public function test_authoritative_booking_get_and_list_reconcile_elapsed_held_state(): void {
		$holds         = $this->holds();
		$lifecycle     = new BookingLifecycle( null, null, null, null, $holds );
		$authorization = new BookingTestAuthorization();
		$abilities     = new VenueBookingAbilities( new BookingRepository(), $lifecycle, $authorization, $holds );

		$first      = $this->booking();
		$first_hold = $holds->create( $first['id'], 1, 12 );
		$lifecycle->transition( $first['id'], 'held', 2, 12 );
		$unchanged = $holds->reconcile_booking( ( new BookingRepository() )->get( $first['id'] ) );
		$this->assertSame( 'held', $unchanged['status'] );
		$this->assertSame( 3, $unchanged['version'] );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $first_hold['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$read = $abilities->get_booking( array( 'booking_id' => $first['id'] ) );
		$this->assertSame( 'negotiating', $read['status'] );
		$this->assertSame( 4, $read['version'] );

		$second      = $this->booking( '2030-09-01 20:00:00', '2030-09-01 23:00:00' );
		$second_hold = $holds->create( $second['id'], 1, 12 );
		$lifecycle->transition( $second['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $second_hold['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$list  = $abilities->list_bookings( array( 'venue_term_id' => 55 ) );
		$by_id = array_column( $list, null, 'id' );
		$this->assertSame( 'negotiating', $by_id[ $second['id'] ]['status'] );
		$this->assertSame( 4, $by_id[ $second['id'] ]['version'] );
	}

	public function test_booking_list_reconciles_before_status_filter_and_pagination(): void {
		$holds         = $this->holds();
		$lifecycle     = new BookingLifecycle( null, null, null, null, $holds );
		$authorization = new BookingTestAuthorization();
		$abilities     = new VenueBookingAbilities( new BookingRepository(), $lifecycle, $authorization, $holds );
		$first         = $this->booking( '2031-02-01 20:00:00', '2031-02-01 23:00:00' );
		$first_hold    = $holds->create( $first['id'], 1, 12 );
		$lifecycle->transition( $first['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $first_hold['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$second      = $this->booking( '2031-03-01 20:00:00', '2031-03-01 23:00:00' );
		$second_hold = $holds->create( $second['id'], 1, 12 );
		$lifecycle->transition( $second['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $second_hold['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );

		$page_one = $abilities->list_bookings(
			array(
				'venue_term_id' => 55,
				'status'        => 'negotiating',
				'limit'         => 1,
				'offset'        => 0,
			)
		);
		$page_two = $abilities->list_bookings(
			array(
				'venue_term_id' => 55,
				'status'        => 'negotiating',
				'limit'         => 1,
				'offset'        => 1,
			)
		);
		$this->assertSame( array( $second['id'] ), array_column( $page_one, 'id' ) );
		$this->assertSame( array( $first['id'] ), array_column( $page_two, 'id' ) );
		$this->assertSame(
			array(),
			$abilities->list_bookings(
				array(
					'venue_term_id' => 55,
					'status'        => 'held',
				)
			)
		);
	}

	public function test_more_than_five_hundred_valid_held_bookings_do_not_block_listing(): void {
		list( $holds, $lifecycle ) = $this->seed_held_venue( 501, false );
		$abilities                 = new VenueBookingAbilities( new BookingRepository(), $lifecycle, new BookingTestAuthorization(), $holds );
		$result                    = $abilities->list_bookings(
			array(
				'venue_term_id' => 55,
				'status'        => 'held',
				'limit'         => 1,
			)
		);
		$this->assertCount( 1, $result );
		$this->assertSame( 'held', $result[0]['status'] );
	}

	public function test_stale_held_batches_make_forward_progress_before_filters(): void {
		list( $holds, $lifecycle ) = $this->seed_held_venue( 101, true );
		$abilities                 = new VenueBookingAbilities( new BookingRepository(), $lifecycle, new BookingTestAuthorization(), $holds );
		$first_page                = $abilities->list_bookings(
			array(
				'venue_term_id' => 55,
				'status'        => 'negotiating',
				'limit'         => 100,
				'offset'        => 0,
			)
		);
		$second_page               = $abilities->list_bookings(
			array(
				'venue_term_id' => 55,
				'status'        => 'negotiating',
				'limit'         => 100,
				'offset'        => 100,
			)
		);
		$this->assertCount( 100, $first_page );
		$this->assertCount( 1, $second_page );
		$this->assertSame(
			array(),
			$abilities->list_bookings(
				array(
					'venue_term_id' => 55,
					'status'        => 'held',
				)
			)
		);
		$this->assertCount(
			101,
			array_filter(
				$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ],
				static function ( $booking ) {
					return 'negotiating' === $booking['status'];
				}
			)
		);
	}

	public function test_exact_reconciliation_safety_cap_succeeds_when_no_stale_rows_remain(): void {
		list( $holds ) = $this->seed_held_venue( 1000, true );
		$this->assertTrue( $holds->reconcile_venue( 55 ) );
		$this->assertCount(
			1000,
			array_filter(
				$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ],
				static function ( $booking ) {
					return 'negotiating' === $booking['status'];
				}
			)
		);
	}

	public function test_over_reconciliation_safety_cap_is_retryable_only_when_stale_remains(): void {
		list( $holds ) = $this->seed_held_venue( 1001, true );
		$result        = $holds->reconcile_venue( 55 );
		$this->assertSame( 'booking_hold_venue_reconciliation_limit', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( 1000, $result->get_error_data()['processed'] );
		$this->assertCount(
			1,
			array_filter(
				$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ],
				static function ( $booking ) {
					return 'held' === $booking['status'];
				}
			)
		);
	}

	public function test_assign_and_bind_reconcile_before_expected_version_checks(): void {
		$holds         = $this->holds();
		$lifecycle     = new BookingLifecycle( null, null, null, null, $holds );
		$assigned      = $this->booking( '2031-04-01 20:00:00', '2031-04-01 23:00:00' );
		$assigned_hold = $holds->create( $assigned['id'], 1, 12 );
		$lifecycle->transition( $assigned['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $assigned_hold['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$assign_result = $lifecycle->assign( $assigned['id'], null, 3, 12 );
		$this->assertSame( 'booking_version_conflict', $assign_result->get_error_code() );
		$this->assertSame( 4, $assign_result->get_error_data()['current_version'] );

		$bound      = $this->booking( '2031-05-01 20:00:00', '2031-05-01 23:00:00' );
		$bound_hold = $holds->create( $bound['id'], 1, 12 );
		$lifecycle->transition( $bound['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $bound_hold['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$bind_result = $lifecycle->bind_artist( $bound['id'], null, null, 3, 12 );
		$this->assertSame( 'booking_version_conflict', $bind_result->get_error_code() );
		$this->assertSame( 4, $bind_result->get_error_data()['current_version'] );
	}

	public function test_elapsed_selected_release_reconciles_before_and_after_lock(): void {
		$holds     = $this->holds();
		$lifecycle = new BookingLifecycle( null, null, null, null, $holds );
		$pre       = $this->booking( '2031-06-01 20:00:00', '2031-06-01 23:00:00' );
		$pre_hold  = $holds->create( $pre['id'], 1, 12 );
		$lifecycle->transition( $pre['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $pre_hold['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$this->assertSame( 'booking_hold_not_active', $holds->release( $pre_hold['hold']['id'], 1, 12, 'elapsed' )->get_error_code() );
		$this->assertSame( 'negotiating', ( new BookingRepository() )->get( $pre['id'] )['status'] );

		$raced      = $this->booking( '2031-07-01 20:00:00', '2031-07-01 23:00:00' );
		$raced_hold = $holds->create( $raced['id'], 1, 12 );
		$lifecycle->transition( $raced['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->elapse_hold_after_membership_lock = $raced_hold['hold']['id'];
		$this->assertSame( 'booking_hold_not_active', $holds->release( $raced_hold['hold']['id'], 1, 12, 'elapsed during lock' )->get_error_code() );
		$this->assertSame( 'negotiating', ( new BookingRepository() )->get( $raced['id'] )['status'] );

		$write_race      = $this->booking( '2031-07-02 20:00:00', '2031-07-02 23:00:00' );
		$write_race_hold = $holds->create( $write_race['id'], 1, 12 );
		$GLOBALS['wpdb']->elapse_hold_before_release_update = $write_race_hold['hold']['id'];
		$this->assertSame( 'booking_hold_not_active', $holds->release( $write_race_hold['hold']['id'], 1, 12, 'elapsed at write' )->get_error_code() );
		$this->assertSame( 'expired', $GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $write_race_hold['hold']['id'] ]['status'] );
		$this->assertSame( 'negotiating', ( new BookingRepository() )->get( $write_race['id'] )['status'] );
	}

	public function test_booking_get_and_list_reauthorize_under_membership_lock(): void {
		$authorization                          = new BookingTestAuthorization();
		$holds                                  = new BookingHoldRepository( null, null, $authorization );
		$lifecycle                              = new BookingLifecycle( null, null, $authorization, null, $holds );
		$abilities                              = new VenueBookingAbilities( new BookingRepository(), $lifecycle, $authorization, $holds );
		$booking                                = $this->booking( '2031-09-01 20:00:00', '2031-09-01 23:00:00' );
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['12:55'] );
		};
		$get                                    = $abilities->get_booking( array( 'booking_id' => $booking['id'] ) );
		$this->assertSame( 'venue_action_forbidden', $get->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $get );

		$authorization->allowed['12:55']        = true;
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['12:55'] );
		};
		$list                                   = $abilities->list_bookings( array( 'venue_term_id' => 55 ) );
		$this->assertSame( 'venue_action_forbidden', $list->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $list );
	}

	public function test_booking_get_and_list_deny_before_reconciliation_mutation(): void {
		$authorization = new BookingTestAuthorization();
		$holds         = new BookingHoldRepository( null, null, $authorization );
		$lifecycle     = new BookingLifecycle( null, null, $authorization, null, $holds );
		$abilities     = new VenueBookingAbilities( new BookingRepository(), $lifecycle, $authorization, $holds );
		$booking       = $this->booking( '2031-10-01 20:00:00', '2031-10-01 23:00:00' );
		$created       = $holds->create( $booking['id'], 1, 12 );
		$lifecycle->transition( $booking['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $created['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		unset( $authorization->allowed['12:55'] );

		$get = $abilities->get_booking( array( 'booking_id' => $booking['id'] ) );
		$this->assertSame( 'venue_action_forbidden', $get->get_error_code() );
		$this->assertSame( 'held', ( new BookingRepository() )->get( $booking['id'] )['status'] );
		$this->assertSame( 'active', $GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $created['hold']['id'] ]['status'] );

		$list = $abilities->list_bookings( array( 'venue_term_id' => 55 ) );
		$this->assertSame( 'venue_action_forbidden', $list->get_error_code() );
		$this->assertSame( 'held', ( new BookingRepository() )->get( $booking['id'] )['status'] );
		$this->assertSame( 'active', $GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $created['hold']['id'] ]['status'] );
	}

	public function test_selected_hold_cannot_be_explicitly_released_while_held(): void {
		$holds     = $this->holds();
		$lifecycle = new BookingLifecycle( null, null, null, null, $holds );
		$booking   = $this->booking();
		$created   = $holds->create( $booking['id'], 1, 12 );
		$lifecycle->transition( $booking['id'], 'held', 2, 12 );
		$result = $holds->release( $created['hold']['id'], 1, 12, 'direct release' );
		$this->assertSame( 'booking_held_hold_release_forbidden', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 'held', ( new BookingRepository() )->get( $booking['id'] )['status'] );
	}

	public function test_advisory_lock_failures_release_and_transaction_rollback_are_explicit(): void {
		$holds                            = $this->holds();
		$booking                          = $this->booking();
		$GLOBALS['wpdb']->get_lock_result = 0;
		$this->assertSame( 'booking_hold_lock_not_acquired', $holds->create( $booking['id'], 1, 12 )->get_error_code() );
		$this->assertArrayNotHasKey( BookingSchema::holds_table(), $GLOBALS['wpdb']->rows );

		$GLOBALS['wpdb']->get_lock_result       = 1;
		$GLOBALS['wpdb']->fail_activity_inserts = true;
		$this->assertSame( 'booking_activity_write_failed', $holds->create( $booking['id'], 1, 12 )->get_error_code() );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );
		$this->assertSame( 'release', end( $GLOBALS['wpdb']->lock_names )[0] );

		$GLOBALS['wpdb']->fail_activity_inserts = false;
		$GLOBALS['wpdb']->release_lock_result   = 0;
		$this->assertSame( 'booking_hold_lock_release_failed', $holds->create( $booking['id'], 1, 12 )->get_error_code() );
	}

	public function test_uncertain_commit_is_not_followed_by_a_false_rollback_claim(): void {
		$holds                                    = $this->holds();
		$booking                                  = $this->booking();
		$GLOBALS['wpdb']->fail_transaction_commit = true;
		$rollbacks                                = $GLOBALS['wpdb']->rollback_queries;
		$result                                   = $holds->create( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_hold_transaction_commit_uncertain', $result->get_error_code() );
		$this->assertSame( $rollbacks, $GLOBALS['wpdb']->rollback_queries );
		$this->assertSame( 'release', end( $GLOBALS['wpdb']->lock_names )[0] );
	}

	public function test_scheduler_failure_after_commit_does_not_fail_or_rollback_create(): void {
		$holds                                        = $this->holds();
		$booking                                      = $this->booking();
		$GLOBALS['ec_artist_test']['throw_scheduler'] = true;
		$rollbacks                                    = $GLOBALS['wpdb']->rollback_queries;
		$result                                       = $holds->create( $booking['id'], 1, 12 );
		$this->assertSame( 'active', $result['hold']['status'] );
		$this->assertSame( 2, $result['booking_version'] );
		$this->assertSame( $rollbacks, $GLOBALS['wpdb']->rollback_queries );
		$this->assertCount( 1, $GLOBALS['ec_artist_test']['fired_actions']['extrachill_events_booking_hold_schedule_failed'] );
	}

	public function test_scheduler_zero_return_is_diagnostic_but_create_remains_committed(): void {
		$holds                                       = $this->holds();
		$booking                                     = $this->booking();
		$GLOBALS['ec_artist_test']['scheduler_zero'] = true;
		$result                                      = $holds->create( $booking['id'], 1, 12 );
		$this->assertSame( 'active', $result['hold']['status'] );
		$this->assertSame( 2, ( new BookingRepository() )->get( $booking['id'] )['version'] );
		$this->assertCount( 1, $GLOBALS['ec_artist_test']['fired_actions']['extrachill_events_booking_hold_schedule_failed'] );
	}

	public function test_failed_scheduled_expiry_retries_and_throws_visible_failure(): void {
		$holds                                  = $this->holds();
		$booking                                = $this->booking();
		$created                                = $holds->create( $booking['id'], 1, 12 );
		$GLOBALS['ec_artist_test']['scheduled'] = array();
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $created['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$GLOBALS['wpdb']->get_lock_result = 0;
		try {
			BookingHoldRepository::expire_scheduled( $created['hold']['id'], 0 );
			$this->fail( 'A failed scheduled expiry must throw.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Booking hold expiration failed.', $exception->getMessage() );
		}
		$this->assertCount( 1, $GLOBALS['ec_artist_test']['scheduled'] );
		$this->assertSame( array( $created['hold']['id'], 1 ), $GLOBALS['ec_artist_test']['scheduled'][0]['args'] );
	}

	public function test_failed_expiry_retry_zero_return_fires_diagnostic_and_throws(): void {
		$holds                                  = $this->holds();
		$booking                                = $this->booking( '2031-08-01 20:00:00', '2031-08-01 23:00:00' );
		$created                                = $holds->create( $booking['id'], 1, 12 );
		$GLOBALS['ec_artist_test']['scheduled'] = array();
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $created['hold']['id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$GLOBALS['wpdb']->get_lock_result            = 0;
		$GLOBALS['ec_artist_test']['scheduler_zero'] = true;
		try {
			BookingHoldRepository::expire_scheduled( $created['hold']['id'], 0 );
			$this->fail( 'A failed expiry with an unscheduled retry must throw.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Booking hold expiration failed.', $exception->getMessage() );
		}
		$this->assertCount( 1, $GLOBALS['ec_artist_test']['fired_actions']['extrachill_events_booking_hold_schedule_failed'] );
		$this->assertSame( array(), $GLOBALS['ec_artist_test']['scheduled'] );
	}

	public function test_authority_is_rechecked_inside_lock(): void {
		$authorization                          = new BookingTestAuthorization();
		$holds                                  = new BookingHoldRepository( null, null, $authorization );
		$booking                                = $this->booking();
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['12:55'] );
		};
		$this->assertSame( 'venue_action_forbidden', $holds->create( $booking['id'], 1, 12 )->get_error_code() );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );
	}

	public function test_mutations_deny_lock_current_revoked_membership(): void {
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = BookingSchema::SCHEMA_VERSION;
		$authorization = new BookingTestAuthorization();
		$authorization->require_locked_membership = true;
		$holds         = new BookingHoldRepository( null, null, $authorization );
		$lifecycle     = new BookingLifecycle( null, null, $authorization, null, $holds );
		$booking       = $this->booking( '2031-11-01 20:00:00', '2031-11-01 23:00:00' );
		$membership_table = BookingSchema::memberships_table();
		$GLOBALS['wpdb']->rows[ $membership_table ][1] = array(
			'id'                 => 1,
			'venue_term_id'      => 55,
			'user_id'            => 12,
			'is_owner'           => 1,
			'status'             => VenueAuthorization::STATUS_ACTIVE,
			'version'            => 1,
			'created_by_user_id' => 12,
			'created_at'         => '2030-01-01 00:00:00',
			'updated_at'         => '2030-01-01 00:00:00',
			'revoked_at'         => null,
		);
		$revoke = static function () use ( $membership_table ) {
			$GLOBALS['wpdb']->rows[ $membership_table ][1]['status'] = VenueAuthorization::STATUS_REVOKED;
		};
		$GLOBALS['wpdb']->after_membership_lock = $revoke;
		$this->assertSame( 'venue_action_forbidden', $holds->create( $booking['id'], 1, 12 )->get_error_code() );

		$GLOBALS['wpdb']->rows[ $membership_table ][1]['status'] = VenueAuthorization::STATUS_ACTIVE;
		$created = $holds->create( $booking['id'], 1, 12 );
		$GLOBALS['wpdb']->after_membership_lock = $revoke;
		$this->assertSame( 'venue_action_forbidden', $holds->release( $created['hold']['id'], 1, 12, 'revoked' )->get_error_code() );

		$GLOBALS['wpdb']->rows[ $membership_table ][1]['status'] = VenueAuthorization::STATUS_ACTIVE;
		$GLOBALS['wpdb']->after_membership_lock = $revoke;
		$this->assertSame( 'venue_action_forbidden', $lifecycle->transition( $booking['id'], 'held', 2, 12 )->get_error_code() );

		$GLOBALS['wpdb']->rows[ $membership_table ][1]['status'] = VenueAuthorization::STATUS_ACTIVE;
		$lifecycle->transition( $booking['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->after_membership_lock = $revoke;
		$this->assertSame( 'venue_action_forbidden', $lifecycle->transition( $booking['id'], 'confirmed', 3, 12 )->get_error_code() );
	}

	public function test_list_reauthorizes_after_lock_and_rolls_back_on_revocation(): void {
		$authorization = new BookingTestAuthorization();
		$holds         = new BookingHoldRepository( null, null, $authorization );
		$booking       = $this->booking();
		$holds->create( $booking['id'], 1, 12 );
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['12:55'] );
		};
		$before                                 = $GLOBALS['wpdb']->rollback_queries;
		$result                                 = $holds->list( array( 'venue_term_id' => 55 ), 12 );
		$this->assertSame( 'venue_action_forbidden', $result->get_error_code() );
		$this->assertSame( $before + 1, $GLOBALS['wpdb']->rollback_queries );
	}

	public function test_confirmed_booking_and_canonical_event_conflicts_use_half_open_local_time(): void {
		$holds     = $this->holds();
		$confirmed = $this->booking();
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $confirmed['id'] ]['status'] = 'confirmed';
		$overlap = $this->booking( '2030-08-01 22:00:00', '2030-08-02 00:00:00' );
		$this->assertSame( 'confirmed_booking', $holds->create( $overlap['id'], 1, 12 )->get_error_data()['conflict']['conflict_type'] );

		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $confirmed['id'] ]['status'] = 'cancelled';
		$GLOBALS['wpdb']->event_dates[] = array(
			'post_id'        => 900,
			'venue_term_id'  => 55,
			'post_status'    => 'publish',
			'start_datetime' => '2030-08-01 18:30:00',
			'end_datetime'   => '2030-08-01 19:30:00',
		);
		$this->assertSame( 'canonical_event', $holds->create( $overlap['id'], 1, 12 )->get_error_data()['conflict']['conflict_type'], '22:00 UTC converts to 18:00 EDT.' );
		$GLOBALS['wpdb']->event_dates[0]['post_status'] = 'draft';
		$this->assertSame( 'active', $holds->create( $overlap['id'], 1, 12 )['hold']['status'] );
		$GLOBALS['wpdb']->event_dates = array(
			array(
				'post_id'        => 902,
				'venue_term_id'  => 55,
				'post_status'    => 'publish',
				'start_datetime' => '2030-11-03 01:05:00',
				'end_datetime'   => null,
			),
		);
		$dst                          = $this->booking( '2030-11-03 06:00:00', '2030-11-03 07:00:00' );
		$this->assertSame( 'canonical_event', $holds->create( $dst['id'], 1, 12 )->get_error_data()['conflict']['conflict_type'], '06:00 UTC converts to the repeated 01:00 hour after the DST fallback.' );
		$crossing_fold = $this->booking( '2030-11-03 05:45:00', '2030-11-03 06:15:00' );
		$this->assertSame( 'canonical_event', $holds->create( $crossing_fold['id'], 1, 12 )->get_error_data()['conflict']['conflict_type'], 'The local window must include the early segment of the repeated fold.' );

		$GLOBALS['ec_artist_test']['meta'][7][55]['_venue_timezone'] = 'Not/AZone';
		$next = $this->booking( '2030-09-01 20:00:00', '2030-09-01 22:00:00' );
		$this->assertSame( 'booking_venue_timezone_invalid', $holds->create( $next['id'], 1, 12 )->get_error_code() );
	}

	public function test_lifecycle_requires_exact_hold_converts_selected_and_releases_alternatives(): void {
		$holds     = $this->holds();
		$lifecycle = new BookingLifecycle( null, null, null, null, $holds );
		$booking   = $this->booking();
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = 'negotiating';
		$this->assertSame( 'booking_matching_hold_required', $lifecycle->transition( $booking['id'], 'held', 1, 12 )->get_error_code() );
		$created                   = $holds->create( $booking['id'], 1, 12 );
		$alternative               = $created['hold'];
		$alternative['id']         = 2;
		$alternative['space_key']  = 'patio';
		$alternative['version']    = 1;
		$alternative['expires_at'] = gmdate( 'Y-m-d H:i:s' );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][2] = $alternative;
		$held = $lifecycle->transition( $booking['id'], 'held', 2, 12 );
		$this->assertSame( 'held', $held['status'] );
		$confirmed = $lifecycle->transition( $booking['id'], 'confirmed', 3, 12 );
		$this->assertSame( 'confirmed', $confirmed['status'] );
		$this->assertSame( 'converted', $holds->get( $created['hold']['id'] )['status'] );
		$this->assertSame( 'expired', $holds->get( 2 )['status'] );

		$other = $this->booking();
		$this->assertSame( 'booking_time_conflict', $holds->create( $other['id'], 1, 12 )->get_error_code(), 'Converted booking remains protected by confirmed-booking overlap.' );
	}

	public function test_confirmation_rolls_back_when_hold_expires_at_conversion_boundary(): void {
		$holds     = $this->holds();
		$lifecycle = new BookingLifecycle( null, null, null, null, $holds );
		$booking   = $this->booking( '2031-12-01 20:00:00', '2031-12-01 23:00:00' );
		$created   = $holds->create( $booking['id'], 1, 12 );
		$lifecycle->transition( $booking['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->elapse_hold_before_conversion_update = $created['hold']['id'];

		$result = $lifecycle->transition( $booking['id'], 'confirmed', 3, 12 );
		$this->assertSame( 'booking_hold_expired_during_confirmation', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 'held', ( new BookingRepository() )->get( $booking['id'] )['status'] );
		$this->assertSame( 3, ( new BookingRepository() )->get( $booking['id'] )['version'] );
		$this->assertSame( 'active', $GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $created['hold']['id'] ]['status'] );
	}

	public function test_confirmation_propagates_conversion_diagnostic_read_errors(): void {
		$holds     = $this->holds();
		$lifecycle = new BookingLifecycle( null, null, null, null, $holds );
		$booking   = $this->booking( '2032-12-01 20:00:00', '2032-12-01 23:00:00' );
		$created   = $holds->create( $booking['id'], 1, 12 );
		$lifecycle->transition( $booking['id'], 'held', 2, 12 );
		$GLOBALS['wpdb']->elapse_hold_before_conversion_update = $created['hold']['id'];
		$GLOBALS['wpdb']->fail_read_after_conversion_update    = true;

		$result = $lifecycle->transition( $booking['id'], 'confirmed', 3, 12 );
		$this->assertSame( 'booking_hold_read_failed', $result->get_error_code() );
		$GLOBALS['wpdb']->reads_before_failure = null;
		$this->assertSame( 'held', ( new BookingRepository() )->get( $booking['id'] )['status'] );
		$this->assertSame( 3, ( new BookingRepository() )->get( $booking['id'] )['version'] );
	}

	public function test_leaving_held_releases_remaining_active_holds(): void {
		$holds     = $this->holds();
		$lifecycle = new BookingLifecycle( null, null, null, null, $holds );
		$booking   = $this->booking();
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = 'negotiating';
		$created = $holds->create( $booking['id'], 1, 12 );
		$lifecycle->transition( $booking['id'], 'held', 2, 12 );
		$result = $lifecycle->transition( $booking['id'], 'negotiating', 3, 12 );
		$this->assertSame( 'negotiating', $result['status'] );
		$this->assertSame( 'released', $holds->get( $created['hold']['id'] )['status'] );
	}

	public function test_list_is_venue_bounded_and_uses_half_open_range_filters(): void {
		$holds = $this->holds();
		$first = $this->booking();
		$holds->create( $first['id'], 1, 12 );
		$second = $this->booking( '2030-09-01 20:00:00', '2030-09-01 23:00:00' );
		$holds->create( $second['id'], 1, 12 );
		$list = $holds->list(
			array(
				'venue_term_id' => 55,
				'range_start'   => '2030-08-01 23:00:00',
				'range_end'     => '2030-09-02 00:00:00',
				'limit'         => 1,
			),
			12
		);
		$this->assertCount( 1, $list );
		$this->assertSame( $second['id'], $list[0]['booking_id'] );
		$this->assertSame(
			'invalid_booking_hold_status',
			$holds->list(
				array(
					'venue_term_id' => 55,
					'status'        => 'deleted',
				),
				12
			)->get_error_code()
		);
	}

	public function test_abilities_are_strict_rest_visible_and_do_not_enumerate_missing_records(): void {
		$authorization = new BookingTestAuthorization();
		$abilities     = new VenueBookingHoldAbilities( null, new BookingRepository(), $authorization );
		$abilities->register();
		$registered = $GLOBALS['ec_artist_test']['abilities'];
		$this->assertSame( array( 'extrachill/create-booking-hold', 'extrachill/release-booking-hold', 'extrachill/list-booking-holds' ), array_keys( $registered ) );
		foreach ( $registered as $definition ) {
			$this->assertTrue( $definition['meta']['show_in_rest'] );
			$this->assertFalse( $definition['input_schema']['additionalProperties'] );
		}
		$this->assertSame( BookingHoldRepository::STATUSES, array_slice( $registered['extrachill/list-booking-holds']['input_schema']['properties']['status']['enum'], 0, 4 ) );
		$this->assertSame( 'venue_action_forbidden', $abilities->can_access_hold( array( 'hold_id' => 999 ) )->get_error_code() );
		$this->assertSame( array(), $authorization->calls );
	}
}
