<?php
/**
 * Venue booking persistence foundation tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;
use ExtraChillEvents\Abilities\VenueBookingAbilities;
use ExtraChillEvents\Core\VenueAuthorization;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class BookingFoundationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'       => 7,
			'stack'         => array(),
			'uuid'          => 0,
			'options'       => array(),
			'dbdelta'       => array(),
			'abilities'     => array(),
			'actions'       => array(),
			'cache_deletes' => array(),
			'terms'         => array(
				1 => array(
					101 => (object) array(
						'term_id'  => 101,
						'taxonomy' => 'artist',
						'name'     => 'Canonical Artist',
					),
				),
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
					56 => (object) array(
						'term_id'  => 56,
						'taxonomy' => 'artist',
						'name'     => 'Wrong Type',
					),
				),
			),
			'meta'          => array(
				1 => array( 101 => array( '_artist_profile_id' => 501 ) ),
				7 => array(
					55 => array(
						'_extrachill_booking_config' => array( 'enabled' => true ),
						'_venue_timezone'            => 'America/New_York',
					),
				),
			),
			'posts'         => array(
				4 => array(
					501 => (object) array(
						'ID'          => 501,
						'post_type'   => 'artist_profile',
						'post_status' => 'publish',
						'post_title'  => 'Canonical Artist',
					),
					502 => (object) array(
						'ID'          => 502,
						'post_type'   => 'artist_profile',
						'post_status' => 'publish',
						'post_title'  => 'Unbound Artist',
					),
				),
				7 => array(
					900 => (object) array(
						'ID'          => 900,
						'post_type'   => 'data_machine_events',
						'post_status' => 'publish',
					),
					901 => (object) array(
						'ID'          => 901,
						'post_type'   => 'post',
						'post_status' => 'publish',
					),
				),
			),
			'post_meta'     => array( 4 => array( 501 => array( '_artist_term_id' => 101 ) ) ),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
	}

	private function create_booking( array $overrides = array() ) {
		return ( new BookingRepository() )->create(
			array_merge(
				array(
					'venue_term_id' => 55,
					'artist_name'   => 'New Band',
					'intake'        => array( 'draw' => 100 ),
				),
				$overrides
			)
		);
	}

	public function test_schema_health_validates_attributes_and_site_scope(): void {
		$this->assertTrue( BookingSchema::install() );
		$this->assertSame( BookingSchema::SCHEMA_VERSION, get_option( BookingSchema::VERSION_OPTION ) );
		$this->assertTrue( BookingSchema::health() );
		$this->assertArrayHasKey( 'is_owner', $GLOBALS['wpdb']->schemas['wp_7_ec_venue_members']['columns'] );
		$this->assertArrayNotHasKey( 'role', $GLOBALS['wpdb']->schemas['wp_7_ec_venue_members']['columns'] );

		$columns                   =& $GLOBALS['wpdb']->schemas['wp_7_ec_bookings']['columns'];
		$columns['status']['Type'] = 'text';
		$this->assertSame( 'type', BookingSchema::health()->get_error_data()['attribute'] );
		$columns['status']['Type'] = 'varchar(32)';
		$columns['status']['Null'] = 'YES';
		$this->assertSame( 'nullable', BookingSchema::health()->get_error_data()['attribute'] );
		$columns['status']['Null']    = 'NO';
		$columns['status']['Default'] = 'pending';
		$this->assertSame( 'default', BookingSchema::health()->get_error_data()['attribute'] );
		$columns['status']['Default']  = 'submitted';
		$columns['version']['Default'] = '2';
		$this->assertSame( 'default', BookingSchema::health()->get_error_data()['attribute'] );
		$columns['version']['Default'] = '1';
		$columns['id']['Extra']        = '';
		$this->assertSame( 'extra', BookingSchema::health()->get_error_data()['attribute'] );
		$columns['id']['Extra'] = 'auto_increment';
		$this->assertTrue( BookingSchema::health() );

		$GLOBALS['wpdb']->prefix = 'wp_12_';
		$this->assertSame( 'wp_12_ec_bookings', BookingSchema::bookings_table() );
		$this->assertSame( 'wp_12_ec_venue_members', BookingSchema::memberships_table() );
		$this->assertSame( 'booking_schema_table_missing', BookingSchema::health()->get_error_code() );
	}

	public function test_role_schema_migrates_only_structural_ownership(): void {
		$this->assertTrue( BookingSchema::install() );
		$table = BookingSchema::memberships_table();
		$GLOBALS['wpdb']->schemas[ $table ]['columns']['role']                 = array(
			'Type'    => 'varchar(32)',
			'Null'    => 'NO',
			'Default' => null,
			'Extra'   => '',
		);
		$GLOBALS['wpdb']->schemas[ $table ]['indexes']['venue_status_role']    = array(
			'unique'  => false,
			'columns' => array( 'venue_term_id', 'status', 'role' ),
		);
		$GLOBALS['wpdb']->rows[ $table ]                                       = array(
			1 => array(
				'role'     => 'owner',
				'is_owner' => 0,
			),
			2 => array(
				'role'     => 'marketing',
				'is_owner' => 1,
			),
		);
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '2';

		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( 1, $GLOBALS['wpdb']->rows[ $table ][1]['is_owner'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->rows[ $table ][2]['is_owner'] );
		$this->assertArrayNotHasKey( 'role', $GLOBALS['wpdb']->rows[ $table ][1] );
		$this->assertArrayNotHasKey( 'role', $GLOBALS['wpdb']->schemas[ $table ]['columns'] );
		$this->assertArrayNotHasKey( 'venue_status_role', $GLOBALS['wpdb']->schemas[ $table ]['indexes'] );
		$this->assertSame( BookingSchema::SCHEMA_VERSION, get_option( BookingSchema::VERSION_OPTION ) );
	}

	public function test_concurrent_completed_role_migration_is_treated_as_success(): void {
		$this->assertTrue( BookingSchema::install() );
		$table = BookingSchema::memberships_table();
		$GLOBALS['wpdb']->schemas[ $table ]['columns']['role']                 = array(
			'Type'    => 'varchar(32)',
			'Null'    => 'NO',
			'Default' => null,
			'Extra'   => '',
		);
		$GLOBALS['wpdb']->schemas[ $table ]['indexes']['venue_status_role']    = array(
			'unique'  => false,
			'columns' => array( 'venue_term_id', 'status', 'role' ),
		);
		$GLOBALS['wpdb']->rows[ $table ]                                       = array(
			1 => array(
				'role'     => 'owner',
				'is_owner' => 0,
			),
		);
		$GLOBALS['wpdb']->concurrent_role_migration                            = true;
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '2';

		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( 1, $GLOBALS['wpdb']->rows[ $table ][1]['is_owner'] );
		$this->assertSame( BookingSchema::SCHEMA_VERSION, get_option( BookingSchema::VERSION_OPTION ) );
		$this->assertFalse( get_option( BookingSchema::FAILURE_OPTION, false ) );
	}

	public function test_unrepairable_column_attributes_are_not_stamped(): void {
		$this->assertTrue( BookingSchema::install() );
		$GLOBALS['wpdb']->schemas['wp_7_ec_bookings']['columns']['status']['Null'] = 'YES';
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ]     = '';
		$result = BookingSchema::maybe_install();
		$this->assertSame( 'booking_schema_column_invalid', $result->get_error_code() );
		$this->assertSame( 'nullable', $result->get_error_data()['attribute'] );
		$this->assertSame( '', get_option( BookingSchema::VERSION_OPTION, '' ) );
	}

	public function test_partial_schema_is_not_stamped_and_repeat_install_repairs_it(): void {
		$GLOBALS['wpdb']->schema_omit['wp_7_ec_bookings']['columns'] = array( 'event_id' );
		$result = BookingSchema::install();
		$this->assertSame( 'booking_schema_columns_missing', $result->get_error_code() );
		$this->assertSame( '', get_option( BookingSchema::VERSION_OPTION, '' ) );
		$this->assertSame( 'booking_schema_columns_missing', get_option( BookingSchema::FAILURE_OPTION )['code'] );
		$GLOBALS['wpdb']->schema_omit = array();
		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertTrue( BookingSchema::health() );
		$this->assertSame( BookingSchema::SCHEMA_VERSION, get_option( BookingSchema::VERSION_OPTION ) );
	}

	public function test_malformed_required_indexes_are_dropped_and_repaired(): void {
		$this->assertTrue( BookingSchema::install() );
		$bookings = 'wp_7_ec_bookings';
		$activity = 'wp_7_ec_booking_activity';
		$members  = 'wp_7_ec_venue_members';
		$GLOBALS['wpdb']->schemas[ $bookings ]['indexes']['PRIMARY']['columns']             = array( 'id', 'public_id' );
		$GLOBALS['wpdb']->schemas[ $bookings ]['indexes']['public_id']['unique']            = false;
		$GLOBALS['wpdb']->schemas[ $activity ]['indexes']['booking_idempotency']['columns'] = array( 'idempotency_key' );
		$GLOBALS['wpdb']->schemas[ $members ]['indexes']['venue_user']['unique']            = false;
		$GLOBALS['wpdb']->schemas[ $activity ]['indexes']['operator_extra']                 = array(
			'unique'  => false,
			'columns' => array( 'created_at' ),
		);
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ]              = '';
		$GLOBALS['wpdb']->dropped_indexes = array();

		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertTrue( BookingSchema::health() );
		$this->assertSame( array( 'id' ), $GLOBALS['wpdb']->schemas[ $bookings ]['indexes']['PRIMARY']['columns'] );
		$this->assertTrue( $GLOBALS['wpdb']->schemas[ $bookings ]['indexes']['public_id']['unique'] );
		$this->assertSame( array( 'booking_id', 'idempotency_key' ), $GLOBALS['wpdb']->schemas[ $activity ]['indexes']['booking_idempotency']['columns'] );
		$this->assertTrue( $GLOBALS['wpdb']->schemas[ $members ]['indexes']['venue_user']['unique'] );
		$this->assertArrayHasKey( 'operator_extra', $GLOBALS['wpdb']->schemas[ $activity ]['indexes'] );
		$this->assertSame(
			array(
				array(
					'table' => $bookings,
					'index' => 'PRIMARY',
				),
				array(
					'table' => $bookings,
					'index' => 'public_id',
				),
				array(
					'table' => $activity,
					'index' => 'booking_idempotency',
				),
				array(
					'table' => $members,
					'index' => 'venue_user',
				),
			),
			$GLOBALS['wpdb']->dropped_indexes
		);
	}

	public function test_current_version_maybe_install_performs_no_schema_queries(): void {
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = BookingSchema::SCHEMA_VERSION;
		$GLOBALS['wpdb']->schema_queries                                       = 0;
		$GLOBALS['ec_artist_test']['dbdelta']                                  = array();
		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( 0, $GLOBALS['wpdb']->schema_queries );
		$this->assertSame( array(), $GLOBALS['ec_artist_test']['dbdelta'] );
	}

	public function test_membership_table_engine_is_repaired_before_version_stamp(): void {
		$this->assertTrue( BookingSchema::install() );
		$table                              = BookingSchema::memberships_table();
		$GLOBALS['wpdb']->engines[ $table ] = 'MyISAM';
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '';
		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( 'INNODB', strtoupper( $GLOBALS['wpdb']->engines[ $table ] ) );

		$GLOBALS['wpdb']->engines[ $table ]                                    = 'MyISAM';
		$GLOBALS['wpdb']->fail_engine_repair                                   = true;
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '';
		$result = BookingSchema::maybe_install();
		$this->assertSame( 'booking_schema_engine_repair_failed', $result->get_error_code() );
		$this->assertSame( '', get_option( BookingSchema::VERSION_OPTION, '' ) );
	}

	public function test_aggregate_table_engines_are_repaired_and_failures_are_not_stamped(): void {
		$this->assertTrue( BookingSchema::install() );
		$bookings                              = BookingSchema::bookings_table();
		$activity                              = BookingSchema::activity_table();
		$GLOBALS['wpdb']->engines[ $bookings ] = 'MyISAM';
		$GLOBALS['wpdb']->engines[ $activity ] = 'MyISAM';
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '';
		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( 'INNODB', strtoupper( $GLOBALS['wpdb']->engines[ $bookings ] ) );
		$this->assertSame( 'INNODB', strtoupper( $GLOBALS['wpdb']->engines[ $activity ] ) );

		$GLOBALS['wpdb']->engines[ $bookings ]                                 = 'MyISAM';
		$GLOBALS['wpdb']->fail_engine_repair                                   = true;
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '';
		$this->assertSame( 'booking_schema_engine_repair_failed', BookingSchema::maybe_install()->get_error_code() );
		$this->assertSame( '', get_option( BookingSchema::VERSION_OPTION, '' ) );
	}

	public function test_missing_unique_index_and_schema_read_errors_remain_retryable(): void {
		$GLOBALS['wpdb']->schema_omit['wp_7_ec_booking_activity']['indexes'] = array( 'booking_idempotency' );
		$this->assertSame( 'booking_schema_index_missing', BookingSchema::install()->get_error_code() );
		$this->assertSame( '', get_option( BookingSchema::VERSION_OPTION, '' ) );
		$GLOBALS['wpdb']->fail_reads = true;
		$this->assertSame( 'booking_schema_db_error', BookingSchema::install()->get_error_code() );
	}

	public function test_json_encoding_failure_never_writes_or_increments(): void {
		$recursive         = array();
		$recursive['self'] =& $recursive;
		$result            = $this->create_booking( array( 'intake' => $recursive ) );
		$this->assertSame( 'booking_payload_encode_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows );

		$booking = $this->create_booking();
		$result  = ( new BookingRepository() )->update( $booking['id'], array( 'deal' => $recursive ), 1 );
		$this->assertSame( 'empty_booking_update', $result->get_error_code(), 'Generic repository updates cannot bypass deal policy.' );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );
		$result = ( new BookingActivityRepository() )->append(
			array(
				'booking_id' => $booking['id'],
				'kind'       => 'note',
				'payload'    => $recursive,
			)
		);
		$this->assertSame( 'booking_activity_payload_encode_failed', $result->get_error_code() );
	}

	public function test_corrupt_and_unsupported_json_are_explicit_read_errors(): void {
		$booking = $this->create_booking();
		$table   = BookingSchema::bookings_table();
		$GLOBALS['wpdb']->rows[ $table ][ $booking['id'] ]['intake_payload'] = '{bad';
		$this->assertSame( 'booking_payload_invalid_json', ( new BookingRepository() )->get( $booking['id'] )->get_error_code() );
		$GLOBALS['wpdb']->rows[ $table ][ $booking['id'] ]['intake_payload'] = '{"version":2,"data":{}}';
		$this->assertSame( 'booking_payload_version_unsupported', ( new BookingRepository() )->get( $booking['id'] )->get_error_code() );
		$GLOBALS['wpdb']->rows[ $table ][ $booking['id'] ]['intake_payload'] = '{"version":"1junk","data":{}}';
		$this->assertSame( 'booking_payload_version_unsupported', ( new BookingRepository() )->get( $booking['id'] )->get_error_code() );

		$activity = array(
			'id'         => 1,
			'booking_id' => 1,
			'actor_id'   => null,
			'payload'    => '{bad',
		);
		$this->assertSame( 'booking_activity_payload_invalid_json', ( new BookingActivityRepository() )->hydrate( $activity )->get_error_code() );
		$activity['payload'] = '{"version":9,"data":{}}';
		$this->assertSame( 'booking_activity_payload_version_unsupported', ( new BookingActivityRepository() )->hydrate( $activity )->get_error_code() );
		$activity['payload'] = '{"version":"1junk","data":{}}';
		$this->assertSame( 'booking_activity_payload_version_unsupported', ( new BookingActivityRepository() )->hydrate( $activity )->get_error_code() );
	}

	public function test_artist_identity_states_and_profile_only_resolution(): void {
		$unresolved = $this->create_booking();
		$this->assertNull( $unresolved['artist_term_id'] );
		$this->assertNull( $unresolved['artist_profile_id'] );
		$canonical = $this->create_booking(
			array(
				'artist_term_id' => 101,
				'artist_name'    => '',
			)
		);
		$this->assertSame( 101, $canonical['artist_term_id'] );
		$profile = $this->create_booking(
			array(
				'artist_profile_id' => 501,
				'artist_name'       => '',
			)
		);
		$this->assertSame( 101, $profile['artist_term_id'] );
		$this->assertSame( 501, $profile['artist_profile_id'] );
		$unbound = $this->create_booking(
			array(
				'artist_profile_id' => 502,
				'artist_name'       => '',
			)
		);
		$this->assertNull( $unbound['artist_term_id'] );
		$this->assertSame( 502, $unbound['artist_profile_id'] );
	}

	public function test_profile_must_be_published_and_bindings_must_be_bidirectional(): void {
		$GLOBALS['ec_artist_test']['posts'][4][501]->post_status = 'draft';
		$this->assertSame( 'invalid_booking_artist_profile', $this->create_booking( array( 'artist_profile_id' => 501 ) )->get_error_code() );
		$GLOBALS['ec_artist_test']['posts'][4][501]->post_status = 'trash';
		$this->assertSame( 'invalid_booking_artist_profile', $this->create_booking( array( 'artist_profile_id' => 501 ) )->get_error_code() );
		$GLOBALS['ec_artist_test']['posts'][4][501]->post_status = 'publish';
		unset( $GLOBALS['ec_artist_test']['post_meta'][4][501]['_artist_term_id'] );
		$this->assertSame(
			'booking_artist_identity_mismatch',
			$this->create_booking(
				array(
					'artist_term_id'    => 101,
					'artist_profile_id' => 501,
				)
			)->get_error_code()
		);
		$GLOBALS['ec_artist_test']['post_meta'][4][501]['_artist_term_id'] = 999;
		$this->assertSame(
			'booking_artist_identity_mismatch',
			$this->create_booking(
				array(
					'artist_term_id'    => 101,
					'artist_profile_id' => 501,
				)
			)->get_error_code()
		);
		$GLOBALS['ec_artist_test']['post_meta'][4][501]['_artist_term_id'] = 101;
		$GLOBALS['ec_artist_test']['meta'][1][101]['_artist_profile_id']   = 999;
		$this->assertSame(
			'booking_artist_identity_mismatch',
			$this->create_booking(
				array(
					'artist_term_id'    => 101,
					'artist_profile_id' => 501,
				)
			)->get_error_code()
		);
	}

	public function test_artist_blog_switches_are_restored_on_success_and_exception(): void {
		$this->create_booking( array( 'artist_profile_id' => 501 ) );
		$this->assertSame( 7, get_current_blog_id() );
		$GLOBALS['ec_artist_test']['throw_get_post'] = true;
		try {
			$this->create_booking( array( 'artist_profile_id' => 501 ) );
			$this->fail( 'Expected profile read exception.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'post read failed', $exception->getMessage() );
		}
		$this->assertSame( 7, get_current_blog_id() );
	}

	public function test_event_handoff_is_null_only_validated_and_idempotent(): void {
		$repository = new BookingRepository();
		$booking    = $this->create_booking();
		$claimed    = $repository->claim_event( $booking['id'], 900, 1 );
		$this->assertSame( 900, $claimed['event_id'] );
		$this->assertSame( 2, $claimed['version'] );
		$this->assertSame( 900, $repository->claim_event( $booking['id'], 900, 1 )['event_id'] );
		$this->assertSame( 'booking_event_already_linked', $repository->claim_event( $booking['id'], 902, 2 )->get_error_code() );
		$unclaimed = $this->create_booking();
		$this->assertSame( 'invalid_booking_event', $repository->claim_event( $unclaimed['id'], 999, 1 )->get_error_code() );
		$this->assertSame( 'invalid_booking_event', $repository->claim_event( $unclaimed['id'], 901, 1 )->get_error_code() );
		$this->assertSame( 'empty_booking_update', $repository->update( $booking['id'], array( 'event_id' => 900 ), 2 )->get_error_code() );
	}

	public function test_event_claim_distinguishes_stale_version_and_missing_booking(): void {
		$repository = new BookingRepository();
		$booking    = $this->create_booking();
		$updated    = $repository->update( $booking['id'], array( 'artist_name' => 'Updated' ), 1 );
		$this->assertSame( 2, $updated['version'] );
		$this->assertSame( 'booking_version_conflict', $repository->claim_event( $booking['id'], 900, 1 )->get_error_code() );
		$GLOBALS['wpdb']->race_event_read_fail = true;
		$this->assertSame( 'booking_read_failed', $repository->claim_event( $booking['id'], 900, 1 )->get_error_code() );
		$GLOBALS['wpdb']->fail_reads           = false;
		$GLOBALS['wpdb']->reads_before_failure = null;
		$this->assertSame( 'booking_not_found', $repository->claim_event( 999, 900, 1 )->get_error_code() );
	}

	public function test_activity_idempotency_is_booking_scoped_and_orphans_are_rejected(): void {
		$activity = new BookingActivityRepository();
		$one      = $this->create_booking();
		$two      = $this->create_booking();
		$input    = array(
			'booking_id'      => $one['id'],
			'kind'            => 'inquiry_received',
			'idempotency_key' => 'intake:request-1',
			'payload'         => array( 'source' => 'form' ),
		);
		$first    = $activity->append( $input );
		$retry    = $activity->append( $input );
		$this->assertSame( $first['id'], $retry['id'] );
		$other = $activity->append( array_merge( $input, array( 'booking_id' => $two['id'] ) ) );
		$this->assertNotSame( $first['id'], $other['id'] );
		$this->assertSame( 'booking_activity_orphan', $activity->append( array_merge( $input, array( 'booking_id' => 999 ) ) )->get_error_code() );
	}

	public function test_activity_duplicate_insert_race_returns_winner_or_read_error(): void {
		$booking                               = $this->create_booking();
		$activity                              = new BookingActivityRepository();
		$GLOBALS['wpdb']->race_activity_insert = true;
		$result                                = $activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'note',
				'idempotency_key' => 'note:race-1',
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'note:race-1', $result['idempotency_key'] );

		$GLOBALS['wpdb']->race_activity_insert    = true;
		$GLOBALS['wpdb']->race_activity_read_fail = true;
		$result                                   = $activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'note',
				'idempotency_key' => 'note:race-2',
			)
		);
		$this->assertSame( 'booking_activity_read_failed', $result->get_error_code() );
	}

	public function test_activity_reports_read_and_write_failures_distinctly(): void {
		$booking                              = $this->create_booking();
		$activity                             = new BookingActivityRepository();
		$GLOBALS['wpdb']->fail_activity_reads = true;
		$result                               = $activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'note',
				'idempotency_key' => 'note:1',
			)
		);
		$this->assertSame( 'booking_activity_read_failed', $result->get_error_code() );
		$GLOBALS['wpdb']->fail_activity_reads = false;
		$GLOBALS['wpdb']->fail_inserts        = true;
		$result                               = $activity->append(
			array(
				'booking_id' => $booking['id'],
				'kind'       => 'note',
			)
		);
		$this->assertSame( 'booking_activity_write_failed', $result->get_error_code() );
		$GLOBALS['wpdb']->fail_inserts = false;
		$GLOBALS['wpdb']->fail_reads   = true;
		$result                        = $activity->append(
			array(
				'booking_id' => $booking['id'],
				'kind'       => 'note',
			)
		);
		$this->assertSame( 'booking_activity_booking_read_failed', $result->get_error_code() );
	}

	public function test_config_handles_unchanged_values_and_rejects_wrong_term_or_versions(): void {
		$service = new VenueBookingConfig();
		$config  = $service->normalize( array( 'enabled' => true ) );
		$this->assertIsArray( $config );
		$this->assertSame( $config, $service->normalize( $config ) );
		$this->assertSame( 'invalid_booking_config_venue', $service->get( 56 )->get_error_code() );
		$this->assertSame( 'booking_config_version_unsupported', $service->normalize( array( 'version' => 2 ) )->get_error_code() );
		$this->assertSame( 'booking_config_version_unsupported', $service->normalize( array( 'version' => '1junk' ) )->get_error_code() );
		$this->assertSame( 'booking_config_section_version_unsupported', $service->normalize( array( 'intake' => array( 'version' => 2 ) ) )->get_error_code() );
		$GLOBALS['ec_artist_test']['meta'][7][55][ VenueBookingConfig::META_KEY ] = array( 'version' => 99 );
		$this->assertSame( 'booking_config_version_unsupported', $service->get( 55 )->get_error_code() );
	}

	public function test_config_detects_truncated_collisions_and_validates_channels_currency(): void {
		$service = new VenueBookingConfig();
		$prefix  = str_repeat( 'a', 64 );
		$result  = $service->normalize(
			array(
				'spaces' => array(
					array(
						'key'  => $prefix . 'x',
						'name' => 'One',
					),
					array(
						'key'  => $prefix . 'y',
						'name' => 'Two',
					),
				),
			)
		);
		$this->assertSame( 'invalid_booking_space', $result->get_error_code() );
		$result = $service->normalize( array( 'intake' => array( 'fields' => array( array( 'key' => $prefix . 'x' ), array( 'key' => $prefix . 'y' ) ) ) ) );
		$this->assertSame( 'invalid_booking_intake_field', $result->get_error_code() );
		$this->assertSame(
			'invalid_booking_intake_field',
			$service->normalize(
				array(
					'intake' => array(
						'fields' => array(
							array(
								'key'   => 'bio',
								'label' => '<b></b>',
							),
						),
					),
				)
			)->get_error_code()
		);
		$this->assertSame( 'invalid_booking_marketing_channels', $service->normalize( array( 'marketing_channels' => array_fill( 0, 21, 'email' ) ) )->get_error_code() );
		$this->assertSame( 'invalid_booking_marketing_channel', $service->normalize( array( 'marketing_channels' => array( $prefix . 'x', $prefix . 'y' ) ) )->get_error_code() );
		$this->assertSame( 'invalid_booking_currency', $service->normalize( array( 'default_deal' => array( 'currency' => 'US1' ) ) )->get_error_code() );
	}

	public function test_repository_rejects_invalid_ids_dates_filters_and_normalizes_updates(): void {
		$repository = new BookingRepository();
		$this->assertSame( 'invalid_booking_id', $this->create_booking( array( 'venue_term_id' => -55 ) )->get_error_code() );
		$this->assertSame( 'invalid_booking_id', $this->create_booking( array( 'assignee_user_id' => -2 ) )->get_error_code() );
		$this->assertSame(
			'invalid_booking_date_range',
			$this->create_booking(
				array(
					'requested_start_at' => '2026-08-02 00:00:00',
					'requested_end_at'   => '2026-08-01 00:00:00',
				)
			)->get_error_code()
		);
		$this->assertSame(
			'invalid_booking_datetime',
			$repository->list(
				array(
					'venue_term_id'      => 55,
					'requested_start_at' => 'tomorrow',
				)
			)->get_error_code()
		);
		$booking = $this->create_booking();
		$updated = $repository->update(
			$booking['id'],
			array(
				'artist_name' => str_repeat( 'x', 300 ),
				'space_key'   => str_repeat( 'y', 80 ),
				'intake'      => array(
					'one' => 1,
					'two' => 2,
				),
			),
			1
		);
		$this->assertSame( 255, strlen( $updated['artist_name'] ) );
		$this->assertNull( $updated['space_key'], 'Generic updates cannot bypass scheduling policy.' );
		$this->assertSame( 'submitted', $updated['status'] );
		$this->assertSame(
			array( 'draw' => 100 ),
			$updated['intake']['data']
		);
	}

	public function test_repository_list_applies_filters_order_and_bounds(): void {
		$repository = new BookingRepository();
		$first      = $this->create_booking(
			array(
				'status'             => 'submitted',
				'requested_start_at' => '2026-08-01 00:00:00',
				'requested_end_at'   => '2026-08-02 00:00:00',
			)
		);
		$confirmed  = $this->create_booking(
			array(
				'requested_start_at' => '2026-09-01 00:00:00',
				'requested_end_at'   => '2026-09-02 00:00:00',
			)
		);
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $confirmed['id'] ]['status'] = 'confirmed';
		$latest = $this->create_booking(
			array(
				'status'             => 'submitted',
				'requested_start_at' => '2026-10-01 00:00:00',
				'requested_end_at'   => '2026-10-02 00:00:00',
				'artist_term_id'     => 101,
			)
		);
		$rows   = $repository->list(
			array(
				'venue_term_id' => 55,
				'status'        => 'submitted',
				'limit'         => 1,
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( $latest['id'], $rows[0]['id'] );
		$rows = $repository->list(
			array(
				'venue_term_id'      => 55,
				'artist_term_id'     => 101,
				'requested_start_at' => '2026-09-30 00:00:00',
			)
		);
		$this->assertSame( array( $latest['id'] ), array_column( $rows, 'id' ) );
		$rows = $repository->list(
			array(
				'venue_term_id'    => 55,
				'requested_end_at' => '2026-08-31 00:00:00',
			)
		);
		$this->assertSame( array( $first['id'] ), array_column( $rows, 'id' ) );
	}

	public function test_repository_distinguishes_not_found_conflict_and_read_failure(): void {
		$repository = new BookingRepository();
		$this->assertSame( 'booking_not_found', $repository->update( 999, array( 'contact_name' => 'Reviewing' ), 1 )->get_error_code() );
		$booking = $this->create_booking();
		$repository->update( $booking['id'], array( 'artist_name' => 'Reviewing' ), 1 );
		$this->assertSame( 'booking_version_conflict', $repository->update( $booking['id'], array( 'artist_name' => 'Accepted' ), 1 )->get_error_code() );
		$GLOBALS['wpdb']->fail_reads = true;
		$this->assertSame( 'booking_read_failed', $repository->get( $booking['id'] )->get_error_code() );
		$this->assertSame( 'booking_list_failed', $repository->list( array( 'venue_term_id' => 55 ) )->get_error_code() );
	}

	public function test_lifecycle_statuses_and_every_transition_edge_are_explicit(): void {
		$this->assertSame(
			array( 'submitted', 'needs_info', 'under_review', 'negotiating', 'held', 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed' ),
			BookingLifecycle::STATUSES
		);
		$this->assertSame( BookingRepository::STATUSES, BookingLifecycle::STATUSES );
		$allowed   = array(
			'submitted'    => array( 'needs_info', 'under_review', 'declined', 'withdrawn' ),
			'needs_info'   => array( 'submitted', 'under_review', 'declined', 'withdrawn' ),
			'under_review' => array( 'needs_info', 'negotiating', 'declined', 'withdrawn' ),
			'negotiating'  => array( 'needs_info', 'under_review', 'held', 'confirmed', 'declined', 'withdrawn' ),
			'held'         => array( 'negotiating', 'confirmed', 'declined', 'withdrawn', 'cancelled' ),
			'confirmed'    => array( 'cancelled', 'completed' ),
			'declined'     => array(),
			'withdrawn'    => array(),
			'cancelled'    => array(),
			'completed'    => array(),
		);
		$lifecycle = new BookingLifecycle();
		foreach ( BookingLifecycle::STATUSES as $from ) {
			foreach ( BookingLifecycle::STATUSES as $to ) {
				$result = $lifecycle->validate_transition(
					array(
						'status'               => $from,
						'performance_start_at' => '2026-08-01 20:00:00',
						'performance_end_at'   => '2026-08-01 23:00:00',
						'space_key'            => 'main-room',
						'deal'                 => array(
							'version' => 1,
							'data'    => array(
								'version'                 => 1,
								'type'                    => 'guarantee',
								'guarantee_cents'         => 0,
								'revenue_share_basis_points' => 0,
								'revenue_share_basis'     => 'gross_ticket_sales',
								'currency'                => 'USD',
								'capacity'                => null,
								'advance_ticket_price_cents' => null,
								'door_ticket_price_cents' => null,
								'ticket_fee_cents'        => null,
								'tickets_on_sale_at'      => null,
								'ticket_url'               => null,
								'additional_terms'        => null,
							),
						),
					),
					$to
				);
				if ( in_array( $to, $allowed[ $from ], true ) ) {
					$this->assertNotSame( 'booking_transition_forbidden', is_wp_error( $result ) ? $result->get_error_code() : null, "Expected {$from} -> {$to} to be explicit." );
				} else {
					$this->assertSame( 'booking_transition_forbidden', $result->get_error_code(), "Expected {$from} -> {$to} to be forbidden." );
				}
			}
		}
	}

	public function test_missing_hold_and_conflict_substrates_fail_closed(): void {
		$lifecycle = new BookingLifecycle();
		$booking   = array(
			'status'               => 'negotiating',
			'performance_start_at' => null,
			'performance_end_at'   => null,
			'space_key'            => null,
			'deal'                 => null,
		);
		$this->assertSame( 'booking_hold_selection_required', $lifecycle->validate_transition( $booking, 'held' )->get_error_code() );
		$this->assertSame( 'booking_confirmation_selection_required', $lifecycle->validate_transition( $booking, 'confirmed' )->get_error_code() );
		$booking['performance_start_at'] = '2026-08-01 20:00:00';
		$booking['performance_end_at']   = '2026-08-01 23:00:00';
		$booking['space_key']            = 'main-room';
		$this->assertSame( 'booking_confirmation_deal_required', $lifecycle->validate_transition( $booking, 'confirmed' )->get_error_code() );
		$booking['deal'] = array(
			'version' => 1,
			'data'    => array(
				'version'                    => 1,
				'type'                       => 'guarantee',
				'guarantee_cents'            => 0,
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
		);
		$this->assertTrue( $lifecycle->validate_transition( $booking, 'confirmed' ) );
	}

	public function test_inquiry_creation_is_atomic_anonymous_and_race_idempotent(): void {
		$lifecycle = new BookingLifecycle();
		$input     = array(
			'idempotency_key' => 'request-298',
			'venue_term_id'   => 55,
			'artist_name'     => 'New Band',
			'intake'          => array( 'draw' => 100 ),
		);
		$first     = $lifecycle->create_inquiry( $input );
		$this->assertSame( 'submitted', $first['status'] );
		$this->assertNull( $first['submitter_user_id'] );
		$this->assertSame( $first['id'], $lifecycle->create_inquiry( $input )['id'] );
		$reordered = array(
			'intake'          => array( 'draw' => 100 ),
			'artist_name'     => 'New Band',
			'venue_term_id'   => 55,
			'idempotency_key' => 'request-298',
		);
		$this->assertSame( $first['id'], $lifecycle->create_inquiry( $reordered )['id'] );
		$conflict = $lifecycle->create_inquiry( array_merge( $input, array( 'artist_name' => 'Different Band' ) ) );
		$this->assertSame( 'booking_idempotency_conflict', $conflict->get_error_code() );
		$this->assertSame( array( 'status' => 409 ), $conflict->get_error_data() );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] );

		$GLOBALS['wpdb']->race_booking_insert = true;
		$race                                 = $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'request-race' ) ) );
		$this->assertIsArray( $race );
		$this->assertSame( 0, $GLOBALS['wpdb']->natural_key_reads_in_transaction, 'The loser must resolve its winner only after rollback.' );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] );
		$this->assertSame(
			'booking_idempotency_conflict',
			$lifecycle->create_inquiry(
				array_merge(
					$input,
					array(
						'idempotency_key' => 'request-race',
						'contact_email'   => 'other@example.com',
					)
				)
			)->get_error_code()
		);
		$GLOBALS['wpdb']->race_booking_insert = true;
		$GLOBALS['wpdb']->race_booking_hash   = str_repeat( '0', 64 );
		$race_conflict                        = $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'request-race-mismatch' ) ) );
		$this->assertSame( 'booking_idempotency_conflict', $race_conflict->get_error_code() );
		$this->assertSame( array( 'status' => 409 ), $race_conflict->get_error_data() );
		$this->assertSame( 'booking_idempotency_conflict', $lifecycle->create_inquiry( $input, 12 )->get_error_code(), 'Authenticated actor identity must be part of the fingerprint.' );

		$GLOBALS['wpdb']->fail_activity_inserts = true;
		$result                                 = $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'request-fails' ) ) );
		$this->assertSame( 'booking_activity_write_failed', $result->get_error_code() );
		$this->assertCount( 3, $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
	}

	public function test_failed_idempotent_insert_without_winner_preserves_database_error(): void {
		$lifecycle                     = new BookingLifecycle();
		$GLOBALS['wpdb']->fail_inserts = true;
		$result                        = $lifecycle->create_inquiry(
			array(
				'idempotency_key' => 'no-winner',
				'venue_term_id'   => 55,
				'intake'          => array(),
				'artist_name'     => 'Band',
			)
		);
		$this->assertSame( 'booking_create_failed', $result->get_error_code() );
		$this->assertSame( array( 'database_error' => 'simulated insert failure' ), $result->get_error_data() );
		$this->assertSame( 0, $GLOBALS['wpdb']->natural_key_reads_in_transaction );
	}

	public function test_inquiry_admission_requires_enabled_config_but_retry_survives_disable(): void {
		$lifecycle = new BookingLifecycle();
		$input     = array(
			'idempotency_key' => 'enabled-request',
			'venue_term_id'   => 55,
			'intake'          => array(),
			'artist_name'     => 'Band',
		);
		$created   = $lifecycle->create_inquiry( $input );
		$this->assertIsArray( $created );
		$this->assertSame( 1, $GLOBALS['wpdb']->venue_lock_queries );
		$this->assertContains( array( 55, 'term_meta' ), $GLOBALS['ec_artist_test']['cache_deletes'] );
		$GLOBALS['ec_artist_test']['meta'][7][55]['_extrachill_booking_config'] = array( 'enabled' => false );
		$this->assertSame( $created['id'], $lifecycle->create_inquiry( $input )['id'] );
		$this->assertSame( 1, $GLOBALS['wpdb']->venue_lock_queries, 'Matching retries must resolve before admission locking.' );
		$this->assertSame( 'booking_inquiry_admission_disabled', $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'disabled-request' ) ) )->get_error_code() );

		$GLOBALS['ec_artist_test']['meta'][7][55]['_extrachill_booking_config'] = array( 'enabled' => true );
		$GLOBALS['wpdb']->after_venue_lock                                      = static function () {
			$GLOBALS['ec_artist_test']['meta'][7][55]['_extrachill_booking_config'] = array( 'enabled' => false );
		};
		$this->assertSame( 'booking_inquiry_admission_disabled', $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'disabled-during-lock' ) ) )->get_error_code() );

		$GLOBALS['wpdb']->fail_venue_lock = true;
		$this->assertSame( 'booking_inquiry_venue_lock_failed', $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'lock-failure' ) ) )->get_error_code() );
	}

	public function test_inquiry_config_read_failure_rolls_back_after_venue_lock(): void {
		$lifecycle = new BookingLifecycle( null, null, null, new BookingTestConfig() );
		$result    = $lifecycle->create_inquiry(
			array(
				'idempotency_key' => 'read-failure',
				'venue_term_id'   => 55,
				'intake'          => array(),
				'artist_name'     => 'Band',
			)
		);
		$this->assertSame( 'booking_inquiry_config_read_failed', $result->get_error_code() );
		$this->assertSame( 1, $GLOBALS['wpdb']->venue_lock_queries );
		$this->assertSame( 1, $GLOBALS['wpdb']->rollback_queries );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows );
	}

	public function test_transition_and_assignment_are_atomic_and_optimistic(): void {
		$authorization = new BookingTestAuthorization( array( '20:55' => true ) );
		$lifecycle     = new BookingLifecycle( null, null, $authorization );
		$booking       = $this->create_booking();
		$reviewing     = $lifecycle->transition( $booking['id'], 'under_review', 1, 12, 'Review started' );
		$this->assertSame( 'under_review', $reviewing['status'] );
		$this->assertSame( 2, $reviewing['version'] );
		$this->assertSame( 'booking_version_conflict', $lifecycle->transition( $booking['id'], 'needs_info', 1, 12 )->get_error_code() );

		$assigned = $lifecycle->assign( $booking['id'], 20, 2, 12 );
		$this->assertSame( 20, $assigned['assignee_user_id'] );
		$this->assertSame( 3, $assigned['version'] );

		$GLOBALS['wpdb']->fail_activity_inserts = true;
		$result                                 = $lifecycle->transition( $booking['id'], 'negotiating', 3, 12 );
		$this->assertSame( 'booking_activity_write_failed', $result->get_error_code() );
		$current = ( new BookingRepository() )->get( $booking['id'] );
		$this->assertSame( 'under_review', $current['status'] );
		$this->assertSame( 3, $current['version'] );
	}

	public function test_assignment_requires_target_access_to_the_exact_booking_venue(): void {
		$authorization = new BookingTestAuthorization(
			array(
				'20:55' => true,
				'21:56' => true,
			)
		);
		$lifecycle     = new BookingLifecycle( null, null, $authorization );
		$booking       = $this->create_booking();

		$this->assertSame( 'invalid_booking_assignee', $lifecycle->assign( $booking['id'], 21, 1, 12 )->get_error_code(), 'Access to a different venue must not permit assignment.' );
		$this->assertSame( 'invalid_booking_assignee', $lifecycle->assign( $booking['id'], 22, 1, 12 )->get_error_code(), 'An unauthorized target must not permit assignment.' );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );

		$assigned = $lifecycle->assign( $booking['id'], 20, 1, 12 );
		$this->assertSame( 20, $assigned['assignee_user_id'] );
		$this->assertSame( 2, $assigned['version'] );
		$unassigned = $lifecycle->assign( $booking['id'], null, 2, 12 );
		$this->assertNull( $unassigned['assignee_user_id'] );
		$this->assertSame( 3, $unassigned['version'] );
		$this->assertSame(
			array(
				array( 21, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
				array( 22, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
				array( 20, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
				array( 12, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
				array( 20, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
				array( 12, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
			),
			$authorization->calls,
			'Unassignment must not attempt target authorization.'
		);
	}

	public function test_transaction_lock_reauthorizes_actor_and_atomic_artist_binding(): void {
		$authorization                          = new BookingTestAuthorization();
		$lifecycle                              = new BookingLifecycle( null, null, $authorization );
		$booking                                = $this->create_booking();
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['12:55'] );
		};
		$denied                                 = $lifecycle->transition( $booking['id'], 'under_review', 1, 12 );
		$this->assertSame( 'venue_action_forbidden', $denied->get_error_code() );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );

		$authorization->allowed['12:55'] = true;
		$bound                           = $lifecycle->bind_artist( $booking['id'], 101, 501, 1, 12 );
		$this->assertSame( 101, $bound['artist_term_id'] );
		$this->assertSame( 501, $bound['artist_profile_id'] );
		$this->assertSame( 'Canonical Artist', $bound['artist_name'] );
		$this->assertSame( 2, $bound['version'] );
		$this->assertSame( 'booking_artist_already_bound', $lifecycle->bind_artist( $booking['id'], null, 502, 2, 12 )->get_error_code() );
		$activities = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$this->assertSame( 'artist_bound', $activities[0]['kind'] );

		$term_only = $this->create_booking( array( 'artist_term_id' => 101 ) );
		$completed = $lifecycle->bind_artist( $term_only['id'], null, 501, 1, 12 );
		$this->assertSame( 101, $completed['artist_term_id'] );
		$this->assertSame( 501, $completed['artist_profile_id'] );
	}

	public function test_assignment_target_is_reauthorized_after_venue_lock(): void {
		$authorization                          = new BookingTestAuthorization( array( '20:55' => true ) );
		$lifecycle                              = new BookingLifecycle( null, null, $authorization );
		$booking                                = $this->create_booking();
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['20:55'] );
		};
		$result                                 = $lifecycle->assign( $booking['id'], 20, 1, 12 );
		$this->assertSame( 'invalid_booking_assignee', $result->get_error_code() );
		$current = ( new BookingRepository() )->get( $booking['id'] );
		$this->assertNull( $current['assignee_user_id'] );
		$this->assertSame( 1, $current['version'] );
	}

	public function test_transaction_control_failures_are_explicit(): void {
		$lifecycle                               = new BookingLifecycle();
		$input                                   = array(
			'idempotency_key' => 'transaction-test',
			'venue_term_id'   => 55,
			'artist_name'     => 'New Band',
			'intake'          => array(),
		);
		$GLOBALS['wpdb']->fail_transaction_start = true;
		$this->assertSame( 'booking_transaction_start_failed', $lifecycle->create_inquiry( $input )->get_error_code() );

		$GLOBALS['wpdb']->fail_transaction_start  = false;
		$GLOBALS['wpdb']->fail_transaction_commit = true;
		$rollbacks_before                         = $GLOBALS['wpdb']->rollback_queries;
		$this->assertSame( 'booking_transaction_commit_uncertain', $lifecycle->create_inquiry( $input )->get_error_code() );
		$this->assertSame( $rollbacks_before, $GLOBALS['wpdb']->rollback_queries );

		$GLOBALS['wpdb']->fail_transaction_commit   = false;
		$GLOBALS['wpdb']->fail_activity_inserts     = true;
		$GLOBALS['wpdb']->fail_transaction_rollback = true;
		$this->assertSame( 'booking_transaction_rollback_failed', $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'rollback-test' ) ) )->get_error_code() );
	}

	public function test_ability_contracts_are_strict_hidden_publicly_and_exactly_venue_scoped(): void {
		$authorization = new BookingTestAuthorization();
		$abilities     = new VenueBookingAbilities( new BookingRepository(), new BookingLifecycle(), $authorization );
		$abilities->register();
		$registered = $GLOBALS['ec_artist_test']['abilities'];
		$this->assertSame(
			array(
				'extrachill/create-booking-inquiry',
				'extrachill/list-venue-bookings',
				'extrachill/get-venue-booking',
				'extrachill/assign-venue-booking',
				'extrachill/transition-venue-booking',
				'extrachill/bind-venue-booking-artist',
			),
			array_keys( $registered )
		);
		$this->assertFalse( $registered['extrachill/create-booking-inquiry']['meta']['show_in_rest'] );
		$this->assertTrue( $registered['extrachill/list-venue-bookings']['meta']['show_in_rest'] );
		foreach ( array( 'extrachill/list-venue-bookings', 'extrachill/get-venue-booking' ) as $reconciling_ability ) {
			$this->assertFalse( $registered[ $reconciling_ability ]['meta']['annotations']['readonly'] );
			$this->assertTrue( $registered[ $reconciling_ability ]['meta']['annotations']['idempotent'] );
			$this->assertFalse( $registered[ $reconciling_ability ]['meta']['annotations']['destructive'] );
		}
		foreach ( $registered as $definition ) {
			$this->assertFalse( $definition['input_schema']['additionalProperties'] );
			$this->assertFalse( $definition['output_schema']['additionalProperties'] ?? false );
		}
		$this->assertSame( BookingLifecycle::STATUSES, $registered['extrachill/transition-venue-booking']['input_schema']['properties']['to_status']['enum'] );
		$this->assertSame( array( 'idempotency_key', 'venue_term_id', 'intake' ), $registered['extrachill/create-booking-inquiry']['input_schema']['required'] );
		$this->assertSame( array( 'venue_term_id' ), $registered['extrachill/list-venue-bookings']['input_schema']['required'] );
		$this->assertSame( array( 'booking_id', 'to_status', 'expected_version' ), $registered['extrachill/transition-venue-booking']['input_schema']['required'] );
		$this->assertSame( array( 'booking_id', 'expected_version' ), $registered['extrachill/bind-venue-booking-artist']['input_schema']['required'] );
		$this->assertSame( array( 'public_id', 'venue_term_id', 'submitted_at' ), $registered['extrachill/create-booking-inquiry']['output_schema']['required'] );
		$receipt_input = array(
			'idempotency_key' => 'public-receipt',
			'venue_term_id'   => 55,
			'intake'          => array(),
			'artist_name'     => 'Private Band',
			'contact_email'   => 'private@example.com',
		);
		$receipt       = call_user_func( $registered['extrachill/create-booking-inquiry']['execute_callback'], $receipt_input );
		$this->assertSame( array( 'public_id', 'venue_term_id', 'submitted_at' ), array_keys( $receipt ) );
		$this->assertArrayNotHasKey( 'id', $receipt );
		$this->assertArrayNotHasKey( 'status', $receipt );
		$this->assertArrayNotHasKey( 'contact_email', $receipt );
		$stored = reset( $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $stored['id'] ]['status']        = 'under_review';
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $stored['id'] ]['contact_email'] = 'changed@example.com';
		$this->assertSame( $receipt, call_user_func( $registered['extrachill/create-booking-inquiry']['execute_callback'], $receipt_input ) );

		$booking = $this->create_booking();
		$this->assertTrue( $abilities->can_access_booking( array( 'booking_id' => $booking['id'] ) ) );
		$this->assertSame( array( array( 12, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) ), $authorization->calls );
		$this->assertSame( 'venue_action_forbidden', $abilities->can_access_booking( array( 'booking_id' => 999 ) )->get_error_code() );
		$this->assertSame( array( array( 12, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) ), $authorization->calls, 'Missing bookings must not reach authorization with a guessed venue.' );
	}
}
