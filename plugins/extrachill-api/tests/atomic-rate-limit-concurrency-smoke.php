<?php
// phpcs:disable
/** Standalone process-concurrency evidence for the shared inquiry/download cap. */

if ( ! function_exists( 'pcntl_fork' ) ) {
	fwrite( STDERR, "pcntl is required\n" );
	exit( 2 );
}

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public function __construct( $code = '', $message = '', $data = array() ) {}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function __( $message ) {
	return $message;
}

function apply_filters( $hook, $value ) {
	return 'extrachill_api_rate_limit_store' === $hook ? 'extrachill_api_concurrency_file_increment' : $value;
}

function wp_cache_add() {
	return false;
}

function wp_cache_incr() {
	return false;
}

$GLOBALS['extrachill_api_concurrency_dir'] = sys_get_temp_dir() . '/ec-api-rate-' . bin2hex( random_bytes( 8 ) );
mkdir( $GLOBALS['extrachill_api_concurrency_dir'], 0700 );

function extrachill_api_concurrency_file_increment( $key ) {
	$path = $GLOBALS['extrachill_api_concurrency_dir'] . '/counter-' . hash( 'sha256', $key );
	$file = fopen( $path, 'c+' );
	if ( false === $file || ! flock( $file, LOCK_EX ) ) {
		return new WP_Error( 'store_failed' );
	}
	$raw = stream_get_contents( $file );
	$count = (int) $raw + 1;
	rewind( $file );
	ftruncate( $file, 0 );
	fwrite( $file, (string) $count );
	fflush( $file );
	flock( $file, LOCK_UN );
	fclose( $file );
	return $count;
}

require dirname( __DIR__ ) . '/inc/middleware/public-write-admission.php';

function extrachill_api_run_concurrent_cap( $scope, $limit, $workers ) {
	$pids = array();
	for ( $index = 0; $index < $workers; ++$index ) {
		$pid = pcntl_fork();
		if ( 0 === $pid ) {
			$admitted = extrachill_api_atomic_rate_limit_admit( $scope, $limit, 60 );
			file_put_contents(
				$GLOBALS['extrachill_api_concurrency_dir'] . '/result-' . $scope . '-' . getmypid(),
				true === $admitted ? '1' : '0'
			);
			exit( 0 );
		}
		if ( $pid < 0 ) {
			return array( 'error' => 'fork_failed' );
		}
		$pids[] = $pid;
	}
	foreach ( $pids as $pid ) {
		pcntl_waitpid( $pid, $status );
		if ( ! pcntl_wifexited( $status ) || 0 !== pcntl_wexitstatus( $status ) ) {
			return array( 'error' => 'worker_failed' );
		}
	}
	$allowed = 0;
	foreach ( glob( $GLOBALS['extrachill_api_concurrency_dir'] . '/result-' . $scope . '-*' ) as $result ) {
		$allowed += (int) file_get_contents( $result );
	}
	return array(
		'workers'  => $workers,
		'limit'    => $limit,
		'allowed'  => $allowed,
		'rejected' => $workers - $allowed,
		'passed'   => $limit === $allowed,
	);
}

$evidence = array(
	'schema'   => 'extrachill-api/atomic-rate-limit-concurrency/v1',
	'inquiry'  => extrachill_api_run_concurrent_cap( 'inquiry', 7, 24 ),
	'download' => extrachill_api_run_concurrent_cap( 'download', 5, 24 ),
);
$passed = true === ( $evidence['inquiry']['passed'] ?? false ) && true === ( $evidence['download']['passed'] ?? false );
$evidence['passed'] = $passed;
fwrite( STDOUT, json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

foreach ( glob( $GLOBALS['extrachill_api_concurrency_dir'] . '/*' ) as $path ) {
	unlink( $path );
}
rmdir( $GLOBALS['extrachill_api_concurrency_dir'] );
exit( $passed ? 0 : 1 );
