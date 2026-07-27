<?php
/**
 * Production booking event synchronization through public abilities on MySQL.
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.RestrictedFunctions -- Independent MySQL session is the subject of this integration proof.

use ExtraChillEvents\Abilities\VenueBookingEventAbilities;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingEventConversionService;
use ExtraChillEvents\Core\BookingEventSyncService;
use ExtraChillEvents\Core\BookingHoldRepository;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipRepository;

final class BookingEventSyncMySQLIntegrationTest extends WP_UnitTestCase {
	private mysqli $contender;
	private int $actor_id;
	private int $old_venue_id;
	private int $new_venue_id;
	private bool $switched_to_events = false;

	public function set_up(): void {
		parent::set_up();
		if ( ! extension_loaded( 'mysqli' ) || ':memory:' === DB_NAME || false !== stripos( (string) DB_HOST, 'sqlite' ) ) {
			$this->markTestSkipped( 'A real MySQL WordPress test runtime is required.' );
		}
		if ( 7 !== get_current_blog_id() ) {
			switch_to_blog( 7 );
			$this->switched_to_events = true;
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			register_taxonomy( 'venue', 'data_machine_events', array( 'public' => false ) );
		}
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			register_post_type( 'data_machine_events', array( 'public' => true ) );
		}
		$this->old_venue_id = $this->create_venue( 'Old Sync Room' );
		$this->new_venue_id = $this->create_venue( 'New Sync Room' );
		$this->actor_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $this->actor_id )->add_cap( VenueAuthorization::ACCESS_CAPABILITY );
		wp_set_current_user( $this->actor_id );
		$this->assertTrue( BookingSchema::install() );
		foreach ( array( $this->old_venue_id, $this->new_venue_id ) as $venue_id ) {
			$this->assertIsArray(
				( new VenueMembershipRepository() )->create(
					array(
						'venue_term_id'      => $venue_id,
						'user_id'            => $this->actor_id,
						'is_owner'           => true,
						'status'             => VenueAuthorization::STATUS_ACTIVE,
						'created_by_user_id' => $this->actor_id,
					)
				)
			);
		}
		$this->contender = $this->connect_second_session();
		$this->contender->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$this->register_booking_event_abilities();
	}

	public function tear_down(): void {
		global $wpdb;
		remove_filter( 'datamachine_events_upsert_event_permission', '__return_true' );
		remove_all_actions( 'datamachine_events_after_event_venue_mutation' );
		if ( isset( $this->contender ) ) {
			$this->contender->close();
		}
		foreach ( array( BookingSchema::holds_table(), BookingSchema::activity_table(), BookingSchema::bookings_table(), BookingSchema::memberships_table(), BookingSchema::communication_state_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}datamachine_event_dates" );
		delete_option( BookingSchema::VERSION_OPTION );
		delete_option( BookingSchema::FAILURE_OPTION );
		if ( $this->switched_to_events ) {
			restore_current_blog();
			$this->switched_to_events = false;
		}
		parent::tear_down();
	}

	public function test_public_service_keeps_combined_update_invisible_and_multisite_scoped(): void {
		global $wpdb;
		$upsert = wp_get_ability( 'data-machine-events/upsert-event' );
		$this->assertNotNull( $upsert );
		add_filter( 'datamachine_events_upsert_event_permission', '__return_true' );
		$source_id = wp_generate_uuid4();
		$upstream  = $upsert->execute(
			array(
				'source'      => BookingEventConversionService::SOURCE,
				'source_id'   => $source_id,
				'post_status' => 'publish',
				'event'       => $this->event_payload( $this->old_venue_id, '2030-03-09', '19:00' ),
			)
		);
		$this->assertIsArray( $upstream, is_wp_error( $upstream ) ? $upstream->get_error_code() : '' );
		$event_id = (int) $upstream['event_id'];
		$booking  = ( new BookingRepository() )->create(
			array(
				'venue_term_id'        => $this->old_venue_id,
				'artist_name'          => 'MySQL Sync Band',
				'space_key'            => 'main-room',
				'performance_start_at' => '2030-03-10 00:00:00',
				'performance_end_at'   => '2030-03-10 03:00:00',
				'confirmed_deal'       => array(
					'version'                    => 1,
					'type'                       => 'guarantee',
					'guarantee_cents'            => 10000,
					'revenue_share_basis_points' => 0,
					'revenue_share_basis'        => 'gross_ticket_sales',
					'currency'                   => 'USD',
					'capacity'                   => 100,
					'advance_ticket_price_cents' => 2000,
					'door_ticket_price_cents'    => 2500,
					'ticket_fee_cents'           => 0,
					'tickets_on_sale_at'         => null,
					'ticket_url'                 => 'https://tickets.example/mysql',
					'additional_terms'           => null,
				),
			)
		);
		$this->assertIsArray( $booking, is_wp_error( $booking ) ? $booking->get_error_code() . ': ' . wp_json_encode( $booking->get_error_data() ) : '' );
		$wpdb->update( BookingSchema::bookings_table(), array( 'public_id' => $source_id, 'status' => 'confirmed', 'event_id' => $event_id ), array( 'id' => $booking['id'] ) );
		$booking = ( new BookingRepository() )->get( $booking['id'] );
		$wpdb->insert(
			BookingSchema::holds_table(),
			array(
				'booking_id' => $booking['id'], 'venue_term_id' => $this->old_venue_id, 'space_key' => 'main-room',
				'start_at' => $booking['performance_start_at'], 'end_at' => $booking['performance_end_at'], 'expires_at' => gmdate( 'Y-m-d H:i:s' ),
				'status' => 'converted', 'version' => 1, 'created_by_user_id' => $this->actor_id, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$authority = BookingEventSyncService::authority_from_event( $this->event_payload( $this->old_venue_id, '2030-03-09', '19:00' ), $this->old_venue_id );
		$activity  = new BookingActivityRepository();
		$activity->append( array( 'booking_id' => $booking['id'], 'kind' => 'event_conversion_started', 'idempotency_key' => 'mysql-conversion-start', 'payload' => array( 'attempt' => 1, 'source' => BookingEventConversionService::SOURCE, 'source_id' => $source_id, 'source_identity' => $upstream['source']['identity'], 'expected_version' => 1 ) ) );
		$activity->append( array( 'booking_id' => $booking['id'], 'kind' => 'event_converted', 'idempotency_key' => 'mysql-conversion-complete', 'external_id' => (string) $event_id, 'payload' => array( 'attempt' => 1, 'event_id' => $event_id, 'source' => BookingEventConversionService::SOURCE, 'source_id' => $source_id, 'source_identity' => $upstream['source']['identity'], 'authority' => $authority, 'fingerprint' => $upstream['fingerprint'], 'version' => 1 ) ) );

		$observed = null;
		add_action(
			'datamachine_events_after_event_venue_mutation',
			function () use ( &$observed, $event_id ): void {
				global $wpdb;
				$content = $this->contender->query( "SELECT post_content FROM {$wpdb->posts} WHERE ID = {$event_id}" )->fetch_row()[0];
				$terms   = $this->contender->query( "SELECT tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = {$event_id} AND tt.taxonomy = 'venue'" )->fetch_all( MYSQLI_NUM );
				$old_lock = BookingHoldRepository::venue_lock_name( $this->old_venue_id );
				$new_lock = BookingHoldRepository::venue_lock_name( $this->new_venue_id );
				$observed = array( $content, array_map( 'intval', array_column( $terms, 0 ) ), $this->get_lock( $old_lock ), $this->get_lock( $new_lock ) );
			},
			10,
			0
		);
		$ability = wp_get_ability( 'extrachill/reconcile-booking-event' );
		$this->assertNotNull( $ability );
		$result = $ability->execute( array( 'booking_id' => $booking['id'], 'expected_version' => 1, 'changes' => array( 'venue_term_id' => $this->new_venue_id, 'performance_start_at' => '2030-03-11 00:00:00', 'performance_end_at' => '2030-03-11 03:00:00' ) ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertStringContainsString( '"startTime":"19:00"', $observed[0] );
		$this->assertSame( array( $this->old_venue_id ), $observed[1] );
		$this->assertSame( array( 0, 0 ), array_slice( $observed, 2 ) );
		$this->assertSame( array( $this->new_venue_id ), wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) ) );

		if ( is_multisite() ) {
			$other_blog = self::factory()->blog->create();
			switch_to_blog( $other_blog );
			$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
			$wrong_site = $ability->execute( array( 'booking_id' => $booking['id'], 'expected_version' => 1, 'changes' => array() ) );
			restore_current_blog();
			$this->assertWPError( $wrong_site );
		}
	}

	/** Register only the booking event definitions under Core's required action. */
	private function register_booking_event_abilities(): void {
		foreach ( array( 'extrachill/convert-booking-to-event', 'extrachill/reconcile-booking-event' ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		$registrar = new VenueBookingEventAbilities();
		$previous  = isset( $GLOBALS['wp_filter']['wp_abilities_api_init'] ) ? clone $GLOBALS['wp_filter']['wp_abilities_api_init'] : null;
		remove_all_actions( 'wp_abilities_api_init' );
		add_action( 'wp_abilities_api_init', array( $registrar, 'register' ) );
		do_action( 'wp_abilities_api_init' );
		remove_all_actions( 'wp_abilities_api_init' );
		if ( null !== $previous ) {
			$GLOBALS['wp_filter']['wp_abilities_api_init'] = $previous;
		}
	}

	private function create_venue( string $name ): int {
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'venue', 'name' => $name . ' ' . wp_generate_uuid4() ) );
		$this->assertNotWPError( $term );
		foreach ( array( '_venue_address' => '123 Test St', '_venue_city' => 'Charleston', '_venue_state' => 'SC', '_venue_country' => 'US', '_venue_timezone' => 'America/New_York' ) as $key => $value ) {
			update_term_meta( $term->term_id, $key, $value );
		}
		return (int) $term->term_id;
	}

	private function event_payload( int $venue_id, string $date, string $time ): array {
		$term = get_term( $venue_id, 'venue' );
		return array( 'title' => 'MySQL Sync Band at ' . $term->name, 'startDate' => $date, 'startTime' => $time, 'endDate' => $date, 'endTime' => '22:00', 'performer' => 'MySQL Sync Band', 'performerType' => 'PerformingGroup', 'venue' => $term->name, 'venueAddress' => '123 Test St', 'venueCity' => 'Charleston', 'venueState' => 'SC', 'venueCountry' => 'US', 'venueTimezone' => 'America/New_York', 'ticketUrl' => 'https://tickets.example/mysql', 'eventStatus' => 'EventScheduled', 'eventType' => 'MusicEvent' );
	}

	private function connect_second_session(): mysqli {
		$host = (string) DB_HOST;
		$port = 3306;
		if ( false !== strpos( $host, ':' ) ) {
			list( $host, $port ) = explode( ':', $host, 2 );
		}
		$connection = mysqli_init();
		$connection->real_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, (int) $port );
		return $connection;
	}

	private function get_lock( string $name ): int {
		$statement = $this->contender->prepare( 'SELECT GET_LOCK(?, 0)' );
		$statement->bind_param( 's', $name );
		$statement->execute();
		return (int) $statement->get_result()->fetch_row()[0];
	}
}
