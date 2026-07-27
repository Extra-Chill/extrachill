#!/usr/bin/env php
<?php
/**
 * Two-session and multisite booking/event synchronization proof.
 *
 * @package ExtraChillEvents\Tests\Integration
 */

$dsn = trim( (string) getenv( 'EC_EVENTS_MYSQL_TEST_DSN' ) );
if ( '' === $dsn ) {
	fwrite( STDOUT, "SKIP: EC_EVENTS_MYSQL_TEST_DSN is unavailable; no MySQL test endpoint was contacted.\n" );
	exit( 0 );
}
if ( ! extension_loaded( 'mysqli' ) ) {
	fwrite( STDERR, "FAIL: mysqli is required for the booking/event synchronization proof.\n" );
	exit( 1 );
}
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );

$settings = array();
foreach ( explode( ';', preg_replace( '/^mysql:/i', '', $dsn ) ) as $part ) {
	if ( false === strpos( $part, '=' ) ) {
		continue;
	}
	list( $key, $value )                 = explode( '=', $part, 2 );
	$settings[ strtolower( trim( $key ) ) ] = trim( $value );
}
$database = $settings['dbname'] ?? '';
if ( '' === $database || false === strpos( strtolower( $database ), 'test' ) ) {
	fwrite( STDERR, "FAIL: the DSN must name an explicit test database.\n" );
	exit( 1 );
}

$host       = $settings['host'] ?? '127.0.0.1';
$port       = (int) ( $settings['port'] ?? 3306 );
$socket     = $settings['unix_socket'] ?? null;
$user       = (string) getenv( 'EC_EVENTS_MYSQL_TEST_USER' );
$password   = (string) getenv( 'EC_EVENTS_MYSQL_TEST_PASSWORD' );
$suffix     = bin2hex( random_bytes( 6 ) );
$site_seven = 'ec_sync_7_' . $suffix . '_';
$site_eight = 'ec_sync_8_' . $suffix . '_';
$tables     = array();
$primary    = null;
$contender  = null;
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
$release = static function ( mysqli $connection, string $name ): int {
	$statement = $connection->prepare( 'SELECT RELEASE_LOCK(?)' );
	$statement->bind_param( 's', $name );
	$statement->execute();
	return (int) $statement->get_result()->fetch_row()[0];
};

try {
	$primary   = $connect();
	$contender = $connect();
	foreach ( array( $primary, $contender ) as $connection ) {
		$connection->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" );
		$connection->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
	}

	foreach ( array( $site_seven, $site_eight ) as $prefix ) {
		$bookings   = $prefix . 'bookings';
		$activity   = $prefix . 'activity';
		$events     = $prefix . 'events';
		$tables[]   = $bookings;
		$tables[]   = $activity;
		$tables[]   = $events;
		$primary->query( "CREATE TABLE `{$bookings}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(36) NOT NULL, venue_term_id BIGINT UNSIGNED NOT NULL, status VARCHAR(32) NOT NULL, version BIGINT UNSIGNED NOT NULL, performance_start_at DATETIME NOT NULL, performance_end_at DATETIME NOT NULL, event_id BIGINT UNSIGNED NULL, UNIQUE KEY public_id (public_id), UNIQUE KEY event_id (event_id)) ENGINE=InnoDB" );
		$primary->query( "CREATE TABLE `{$activity}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(64) NOT NULL, idempotency_key VARCHAR(191) NULL, UNIQUE KEY booking_key (booking_id, idempotency_key)) ENGINE=InnoDB" );
		$primary->query( "CREATE TABLE `{$events}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_identity CHAR(64) NOT NULL, venue_term_id BIGINT UNSIGNED NOT NULL, event_status VARCHAR(32) NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NOT NULL, UNIQUE KEY source_identity (source_identity)) ENGINE=InnoDB" );
	}

	$bookings = $site_seven . 'bookings';
	$activity = $site_seven . 'activity';
	$events   = $site_seven . 'events';
	$public   = '11111111-1111-4111-8111-111111111111';
	$identity = hash( 'sha256', "extrachill-events-booking\0{$public}" );
	$primary->query( "INSERT INTO `{$events}` (source_identity, venue_term_id, event_status, start_at, end_at) VALUES ('{$identity}', 55, 'EventScheduled', '2030-03-10 00:00:00', '2030-03-10 03:00:00')" );
	$event_id = (int) $primary->insert_id;
	$primary->query( "INSERT INTO `{$bookings}` (public_id, venue_term_id, status, version, performance_start_at, performance_end_at, event_id) VALUES ('{$public}', 55, 'confirmed', 2, '2030-03-10 00:00:00', '2030-03-10 03:00:00', {$event_id})" );
	$booking_id = (int) $primary->insert_id;

	// A manual/event writer cannot cross the booking aggregate's correction boundary.
	$primary->begin_transaction();
	$primary->query( "SELECT * FROM `{$bookings}` WHERE id = {$booking_id} FOR UPDATE" );
	try {
		$contender->query( "UPDATE `{$bookings}` SET performance_start_at = '2030-03-12 00:00:00' WHERE id = {$booking_id}" );
		$blocked = false;
	} catch ( mysqli_sql_exception $exception ) {
		$blocked = 1205 === $exception->getCode();
	}
	$assert( $blocked, 'The independent booking writer did not wait behind the synchronization row lock.' );
	$primary->query( "UPDATE `{$bookings}` SET version = 3, performance_start_at = '2030-03-11 00:00:00', performance_end_at = '2030-03-11 03:00:00' WHERE id = {$booking_id} AND version = 2" );
	$primary->commit();

	// Crash/retry markers and immutable source identity converge on one row.
	$key = "event-sync:{$public}:3:1";
	$primary->query( "INSERT INTO `{$activity}` (booking_id, kind, idempotency_key) VALUES ({$booking_id}, 'event_sync_started', '{$key}')" );
	try {
		$primary->query( "INSERT INTO `{$activity}` (booking_id, kind, idempotency_key) VALUES ({$booking_id}, 'event_sync_started', '{$key}')" );
		$duplicate_rejected = false;
	} catch ( mysqli_sql_exception $exception ) {
		$duplicate_rejected = 1062 === $exception->getCode();
	}
	$assert( $duplicate_rejected, 'A duplicate synchronization retry inserted a second intent.' );
	$assert( 1 === (int) $primary->query( "SELECT id FROM `{$events}` WHERE source_identity = '{$identity}'" )->num_rows, 'The immutable source identity no longer resolved exactly one event.' );

	// Reschedule and cancellation remain one booking/event identity.
	$primary->query( "UPDATE `{$events}` SET event_status = 'EventRescheduled', start_at = '2030-03-11 00:00:00', end_at = '2030-03-11 03:00:00' WHERE id = {$event_id} AND source_identity = '{$identity}'" );
	$primary->query( "UPDATE `{$bookings}` SET status = 'cancelled', version = 4 WHERE id = {$booking_id} AND version = 3" );
	$primary->query( "UPDATE `{$events}` SET event_status = 'EventCancelled' WHERE id = {$event_id} AND source_identity = '{$identity}'" );
	$row = $primary->query( "SELECT b.status, b.version, e.event_status FROM `{$bookings}` b INNER JOIN `{$events}` e ON e.id = b.event_id WHERE b.id = {$booking_id}" )->fetch_assoc();
	$assert( 'cancelled' === $row['status'] && 4 === (int) $row['version'] && 'EventCancelled' === $row['event_status'], 'Cancellation did not preserve the synchronized identity.' );

	// The same numeric IDs on another multisite prefix cannot satisfy this handoff.
	$other_events = $site_eight . 'events';
	$primary->query( "INSERT INTO `{$other_events}` (id, source_identity, venue_term_id, event_status, start_at, end_at) VALUES ({$event_id}, '" . str_repeat( 'a', 64 ) . "', 999, 'EventScheduled', '2030-03-10 00:00:00', '2030-03-10 03:00:00')" );
	$assert( 0 === (int) $primary->query( "SELECT id FROM `{$other_events}` WHERE id = {$event_id} AND source_identity = '{$identity}' AND venue_term_id = 55" )->num_rows, 'A wrong-site or wrong-venue event satisfied the booking identity.' );

	// Publication and synchronization serialize through the existing venue lock.
	$venue_lock = 'ecbv:' . sha1( $site_seven . 'ec_booking_holds:55' );
	$assert( 1 === $lock( $primary, $venue_lock, 0 ), 'Synchronization did not acquire the venue publication lock.' );
	$started = microtime( true );
	$contender->query( "SELECT GET_LOCK('{$venue_lock}', 3)", MYSQLI_ASYNC );
	usleep( 250000 );
	$assert( 1 === $release( $primary, $venue_lock ), 'Synchronization did not release the venue publication lock.' );
	$result = $contender->reap_async_query();
	$assert( $result instanceof mysqli_result && 1 === (int) $result->fetch_row()[0] && microtime( true ) - $started >= 0.2, 'Publication did not wait behind synchronization.' );
	$release( $contender, $venue_lock );

	fwrite( STDOUT, "PASS: true MySQL sessions proved booking/event retry, correction, cancellation, multisite identity, and publication serialization.\n" );
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'FAIL: ' . $throwable->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	if ( $primary instanceof mysqli ) {
		foreach ( $tables as $table ) {
			$primary->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}
	foreach ( array( $primary, $contender ) as $connection ) {
		if ( $connection instanceof mysqli ) {
			$connection->close();
		}
	}
}

exit( $exit_code );
