<?php
/** Native two-process hold contention proof for the internal calendar alpha. */

require_once __DIR__ . '/BookingAttachmentMySQLIntegrationTest.php';

use ExtraChillEvents\Core\BookingHoldRepository;
use ExtraChillEvents\Core\BookingRepository;

/** Prove overlapping public Ability calls converge to one active hold. */
final class InternalBookingHoldConcurrencyMySQLProof extends BookingAttachmentMySQLIntegrationTest {
	/** Run two native contenders against one venue-space interval. */
	public function test_overlapping_public_hold_abilities_allow_one_winner(): void {
		$this->assertTrue( function_exists( 'pcntl_fork' ), 'The native hold proof requires pcntl_fork().' );
		$this->register_booking_abilities();
		wp_set_current_user( $this->actor_id );
		$this->assertNotFalse( update_term_meta( $this->venue_id, '_venue_timezone', 'America/New_York' ) );
		$this->assertTrue( DataMachineEvents\Core\EventDatesTable::table_exists() );

		$config            = wp_get_ability( 'extrachill/get-venue-booking-config' )->execute( array( 'venue_term_id' => $this->venue_id ) );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_code() : 'Venue config was not returned.' );
		$revision = (int) $config['revision'];
		unset( $config['revision'], $config['updated_by_user_id'], $config['updated_at'] );
		$config['enabled'] = true;
		$config['spaces']  = array(
			array(
				'key'        => 'main-room',
				'name'       => 'Main Room',
				'is_default' => true,
			),
		);
		$config = wp_get_ability( 'extrachill/update-venue-booking-config' )->execute(
			array(
				'venue_term_id'    => $this->venue_id,
				'expected_revision' => $revision,
				'config'           => $config,
			)
		);
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_code() : 'Venue config was not committed.' );

		$bookings = array(
			$this->prepare_booking( 'Native Race One' ),
			$this->prepare_booking( 'Native Race Two' ),
		);
		$blocker  = $this->connect_lock_session();
		$lock     = BookingHoldRepository::venue_lock_name( $this->venue_id );
		$escaped  = $blocker->real_escape_string( $lock );
		$this->assertSame( 1, (int) $blocker->query( "SELECT GET_LOCK('{$escaped}', 5)" )->fetch_row()[0] );

		$directory = sys_get_temp_dir() . '/ec-hold-alpha-' . wp_generate_uuid4();
		$this->assertTrue( mkdir( $directory, 0700 ) );
		$pids = array();
		foreach ( $bookings as $index => $booking ) {
			$pid = pcntl_fork();
			$this->assertGreaterThanOrEqual( 0, $pid, 'A native hold contender could not be created.' );
			if ( 0 === $pid ) {
				$this->reconnect_wordpress_database();
				wp_set_current_user( $this->actor_id );
				file_put_contents( $directory . '/ready-' . $index, '1', LOCK_EX );
				$result = wp_get_ability( 'extrachill/create-booking-hold' )->execute(
					array(
						'booking_id'              => $booking['id'],
						'expected_booking_version' => $booking['version'],
					)
				);
				file_put_contents(
					$directory . '/result-' . $index,
					wp_json_encode(
						is_wp_error( $result )
							? array( 'error' => $result->get_error_code(), 'data' => $result->get_error_data() )
							: array( 'hold' => $result )
					),
					LOCK_EX
				);
				exit( 0 );
			}
			$pids[] = $pid;
		}

		$deadline = microtime( true ) + 5;
		while ( ( ! file_exists( $directory . '/ready-0' ) || ! file_exists( $directory . '/ready-1' ) ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		$this->assertFileExists( $directory . '/ready-0' );
		$this->assertFileExists( $directory . '/ready-1' );
		$this->assertSame( 1, (int) $blocker->query( "SELECT RELEASE_LOCK('{$escaped}')" )->fetch_row()[0] );
		$blocker->close();

		foreach ( $pids as $pid ) {
			$status = 0;
			pcntl_waitpid( $pid, $status );
			$this->assertTrue( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'A native hold contender did not exit cleanly.' );
		}
		$this->assertFileExists( $directory . '/result-0' );
		$this->assertFileExists( $directory . '/result-1' );
		$results = array(
			json_decode( (string) file_get_contents( $directory . '/result-0' ), true ),
			json_decode( (string) file_get_contents( $directory . '/result-1' ), true ),
		);
		$this->assertCount( 1, array_filter( $results, static fn( array $result ): bool => isset( $result['hold'] ) ) );
		$this->assertCount( 1, array_filter( $results, static fn( array $result ): bool => 'booking_time_conflict' === ( $result['error'] ?? '' ) ) );

		wp_set_current_user( $this->actor_id );
		$holds = wp_get_ability( 'extrachill/list-booking-holds' )->execute(
			array(
				'venue_term_id' => $this->venue_id,
				'status'        => 'active',
				'range_start'   => '2031-07-01 00:00:00',
				'range_end'     => '2031-07-01 03:00:00',
			)
		);
		$this->assertIsArray( $holds, is_wp_error( $holds ) ? $holds->get_error_code() : 'Active holds were not returned.' );
		$this->assertCount( 1, $holds );

		foreach ( glob( $directory . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $directory );
	}

	/** Prepare one negotiating booking through public lifecycle Abilities. */
	private function prepare_booking( string $artist_name ): array {
		$booking = ( new BookingRepository() )->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => $artist_name,
				'intake'        => array(),
			)
		);
		$this->assertIsArray( $booking, is_wp_error( $booking ) ? $booking->get_error_code() : 'Booking was not created.' );
		foreach ( array( 'under_review', 'negotiating' ) as $status ) {
			$booking = wp_get_ability( 'extrachill/transition-venue-booking' )->execute(
				array(
					'booking_id'      => $booking['id'],
					'to_status'       => $status,
					'expected_version' => $booking['version'],
					'note'            => 'Native alpha race setup.',
				)
			);
			$this->assertIsArray( $booking, is_wp_error( $booking ) ? $booking->get_error_code() : 'Lifecycle transition failed.' );
		}
		$booking = wp_get_ability( 'extrachill/select-venue-booking-performance' )->execute(
			array(
				'booking_id'      => $booking['id'],
				'expected_version' => $booking['version'],
				'space_key'       => 'main-room',
				'start_at'        => '2031-07-01 00:00:00',
				'end_at'          => '2031-07-01 03:00:00',
			)
		);
		$this->assertIsArray( $booking, is_wp_error( $booking ) ? $booking->get_error_code() : 'Performance selection failed.' );
		return $booking;
	}

	/** Register booking definitions in Core's public Abilities registry. */
	private function register_booking_abilities(): void {
		if ( ! wp_has_ability( 'extrachill/create-booking-hold' ) ) {
			do_action( 'wp_abilities_api_init' );
		}
		foreach ( array( 'extrachill/get-venue-booking-config', 'extrachill/update-venue-booking-config', 'extrachill/transition-venue-booking', 'extrachill/select-venue-booking-performance', 'extrachill/create-booking-hold', 'extrachill/list-booking-holds' ) as $name ) {
			$this->assertInstanceOf( WP_Ability::class, wp_get_ability( $name ), $name . ' is unavailable.' );
		}
	}

	/** Open an independent MySQL session that controls the venue lock. */
	private function connect_lock_session(): mysqli {
		$connection = mysqli_init();
		$this->assertTrue( mysqli_real_connect( $connection, (string) getenv( 'DB_HOST' ), (string) getenv( 'DB_USER' ), (string) getenv( 'DB_PASSWORD' ), (string) getenv( 'DB_NAME' ), (int) getenv( 'DB_PORT' ) ), (string) mysqli_connect_error() );
		$connection->set_charset( 'utf8mb4' );
		return $connection;
	}
}
