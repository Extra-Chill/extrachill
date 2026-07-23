#!/usr/bin/env php
<?php
/**
 * Two-session canonical-event/booking advisory-lock regression.
 *
 * Environment:
 * - EC_EVENTS_MYSQL_TEST_DSN=mysql:host=127.0.0.1;port=3306;dbname=events_test
 * - EC_EVENTS_MYSQL_TEST_USER
 * - EC_EVENTS_MYSQL_TEST_PASSWORD
 *
 * @package ExtraChillEvents\Tests\Integration
 */

$dsn = trim( (string) getenv( 'EC_EVENTS_MYSQL_TEST_DSN' ) );
if ( '' === $dsn ) {
	fwrite( STDOUT, "SKIP: EC_EVENTS_MYSQL_TEST_DSN is unavailable; no MySQL test endpoint was contacted.\n" );
	exit( 0 );
}
if ( ! extension_loaded( 'mysqli' ) ) {
	fwrite( STDERR, "FAIL: EC_EVENTS_MYSQL_TEST_DSN is configured but mysqli is unavailable.\n" );
	exit( 1 );
}

$settings = array();
foreach ( explode( ';', preg_replace( '/^mysql:/i', '', $dsn ) ) as $part ) {
	if ( false === strpos( $part, '=' ) ) {
		continue;
	}
	list( $key, $value ) = explode( '=', $part, 2 );
	$settings[ strtolower( trim( $key ) ) ] = trim( $value );
}
$database = $settings['dbname'] ?? '';
if ( '' === $database || false === strpos( strtolower( $database ), 'test' ) ) {
	fwrite( STDERR, "FAIL: EC_EVENTS_MYSQL_TEST_DSN must name an explicit database containing 'test'; refusing a possible production endpoint.\n" );
	exit( 1 );
}

$user       = (string) getenv( 'EC_EVENTS_MYSQL_TEST_USER' );
$password   = (string) getenv( 'EC_EVENTS_MYSQL_TEST_PASSWORD' );
$host       = $settings['host'] ?? '127.0.0.1';
$port       = (int) ( $settings['port'] ?? 3306 );
$socket     = $settings['unix_socket'] ?? null;
$suffix     = bin2hex( random_bytes( 6 ) );
$wpdb       = (object) array( 'prefix' => 'ec_events_lock_' . $suffix . '_' );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingSchema.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingHoldRepository.php';

$holds      = \ExtraChillEvents\Core\BookingSchema::holds_table();
$bookings   = \ExtraChillEvents\Core\BookingSchema::bookings_table();
$events     = 'ec_events_lock_events_' . $suffix;
$venue_id   = 55;
$space_keys = array( 'main-room', 'patio' );
$event      = null;
$booking    = null;
$exit_code  = 0;

$connect = static function () use ( $host, $user, $password, $database, $port, $socket ): mysqli {
	$connection = mysqli_init();
	$connection->real_connect( $host, $user, $password, $database, $port, $socket );
	$connection->set_charset( 'utf8mb4' );
	return $connection;
};
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$lock = static function ( mysqli $connection, string $name, int $timeout ): int {
	$statement = $connection->prepare( 'SELECT GET_LOCK(?, ?)' );
	$statement->bind_param( 'si', $name, $timeout );
	$statement->execute();
	return (int) $statement->get_result()->fetch_row()[0];
};
$lock_async = static function ( mysqli $connection, string $name, int $timeout ): void {
	$name = $connection->real_escape_string( $name );
	$connection->query( "SELECT GET_LOCK('{$name}', {$timeout})", MYSQLI_ASYNC );
};
$reap_lock = static function ( mysqli $connection ): int {
	$result = $connection->reap_async_query();
	if ( ! $result instanceof mysqli_result ) {
		throw new RuntimeException( 'Asynchronous GET_LOCK query failed: ' . $connection->error );
	}
	return (int) $result->fetch_row()[0];
};
$release = static function ( mysqli $connection, string $name ): int {
	$statement = $connection->prepare( 'SELECT RELEASE_LOCK(?)' );
	$statement->bind_param( 's', $name );
	$statement->execute();
	return (int) $statement->get_result()->fetch_row()[0];
};
$venue_lock = \ExtraChillEvents\Core\BookingHoldRepository::venue_lock_name( $venue_id );
$space_locks = array_map(
	static function ( string $space_key ) use ( $venue_id ): string {
		return \ExtraChillEvents\Core\BookingHoldRepository::venue_space_lock_name( $venue_id, $space_key );
	},
	$space_keys
);
$all_locks = array_merge( array( $venue_lock ), $space_locks );

try {
	$event   = $connect();
	$booking = $connect();
	foreach ( array( $event, $booking ) as $connection ) {
		$connection->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" );
	}
	$event->query( "CREATE TABLE `{$holds}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_id BIGINT UNSIGNED NOT NULL, venue_term_id BIGINT UNSIGNED NOT NULL, space_key VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, expires_at DATETIME NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NOT NULL) ENGINE=InnoDB" );
	$event->query( "CREATE TABLE `{$bookings}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, venue_term_id BIGINT UNSIGNED NOT NULL, space_key VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, performance_start_at DATETIME NOT NULL, performance_end_at DATETIME NOT NULL) ENGINE=InnoDB" );
	$event->query( "CREATE TABLE `{$events}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, venue_term_id BIGINT UNSIGNED NOT NULL, post_status VARCHAR(20) NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NOT NULL) ENGINE=InnoDB" );

	// Ordering one: publication owns the venue domain, then booking waits before taking its narrower space lock.
	$assert( 1 === $lock( $event, $venue_lock, 0 ), 'Publication session did not acquire the venue lock.' );
	$started = microtime( true );
	$lock_async( $booking, $venue_lock, 3 );
	usleep( 250000 );
	$event->query( "INSERT INTO `{$events}` (venue_term_id, post_status, start_at, end_at) VALUES (55, 'publish', '2030-08-01 20:00:00', '2030-08-01 23:00:00')" );
	$assert( 1 === $release( $event, $venue_lock ), 'Publication session did not release the venue lock.' );
	$assert( 1 === $reap_lock( $booking ), 'Booking writer did not acquire the contended venue lock after publication completed.' );
	$assert( microtime( true ) - $started >= 0.2, 'Booking writer did not actually wait behind publication.' );
	$assert( 1 === $lock( $booking, $space_locks[0], 0 ), 'Booking writer did not acquire its space lock after the venue lock.' );
	$result = $booking->query( "SELECT id FROM `{$events}` WHERE venue_term_id = 55 AND post_status = 'publish' AND start_at < '2030-08-01 22:00:00' AND end_at > '2030-08-01 21:00:00' LIMIT 1" );
	$assert( 1 === $result->num_rows, 'Booking writer did not see the event committed while it waited.' );
	$assert( 1 === $release( $booking, $space_locks[0] ), 'Booking writer did not release its space lock.' );
	$assert( 1 === $release( $booking, $venue_lock ), 'Booking writer did not release its venue lock.' );

	// Ordering two: booking owns venue then space, and publication waits on the venue domain before checking holds.
	$assert( 1 === $lock( $booking, $venue_lock, 0 ), 'Booking session did not acquire the venue lock first.' );
	$assert( 1 === $lock( $booking, $space_locks[0], 0 ), 'Booking session did not acquire its space lock second.' );
	$started = microtime( true );
	$lock_async( $event, $venue_lock, 3 );
	usleep( 250000 );
	$booking->query( "INSERT INTO `{$holds}` (booking_id, venue_term_id, space_key, status, expires_at, start_at, end_at) VALUES (99, 55, 'main-room', 'active', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), '2030-08-01 20:00:00', '2030-08-01 23:00:00')" );
	$assert( 1 === $release( $booking, $space_locks[0] ), 'Booking session did not release its space lock first.' );
	$assert( 1 === $release( $booking, $venue_lock ), 'Booking session did not release its venue lock second.' );
	$assert( 1 === $reap_lock( $event ), 'Publication did not acquire the contended venue lock after booking completed.' );
	$assert( microtime( true ) - $started >= 0.2, 'Publication did not actually wait behind the booking writer.' );
	$result = $event->query( "SELECT id FROM `{$holds}` WHERE venue_term_id = 55 AND status = 'active' AND expires_at > UTC_TIMESTAMP() AND start_at < '2030-08-01 22:00:00' AND end_at > '2030-08-01 21:00:00' LIMIT 1" );
	$assert( 1 === $result->num_rows, 'Publication did not see the booking hold committed while it waited.' );
	$assert( 1 === $release( $event, $venue_lock ), 'Publication did not release the second-order venue lock.' );

	fwrite( STDOUT, "PASS: two MySQL sessions proved canonical publication and booking writers serialize and recheck conflicts in both orderings.\n" );
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'FAIL: ' . $throwable->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	foreach ( array( $event, $booking ) as $connection ) {
		if ( ! $connection instanceof mysqli ) {
			continue;
		}
		foreach ( $all_locks as $name ) {
			try {
				$release( $connection, $name );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
			}
		}
	}
	if ( $event instanceof mysqli ) {
		foreach ( array( $events, $bookings, $holds ) as $table ) {
			$event->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}
	foreach ( array( $event, $booking ) as $connection ) {
		if ( $connection instanceof mysqli ) {
			$connection->close();
		}
	}
}

exit( $exit_code );
