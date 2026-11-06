<?php
/**
 * Schema install serialization: LifecycleProvider::maybe_install_schema().
 *
 * Regression for extrachill#1160. Two concurrent first requests after a
 * deploy both ran RsvpPassesTable::create_table(), and the second logged
 * "Duplicate column name" errors from dbDelta. The provider now installs
 * behind a non-blocking MySQL advisory lock and re-checks stored versions
 * (with fresh option caches) after taking it.
 *
 * Pure-PHP unit test: the real RsvpPassesTable / GuestListPartiesTable /
 * OwnerGuestListTable installers run against a fake $wpdb that models
 * GET_LOCK/RELEASE_LOCK and an option store with a request-local cache, so
 * a stale cached version read is reproducible. dbDelta comes from
 * tests/fixtures/wp-admin/includes/upgrade.php and records each call.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File pairs WP option/cache shims and a fake $wpdb with the test case.

use ExtraChillEvents\Core\RsvpPassesTable;
use ExtraChillEvents\Providers\LifecycleProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/GuestListPartiesTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/OwnerGuestListTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/RsvpPassesTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/Providers/LifecycleProvider.php';

/*
 * Option store: `db` is the shared database, `cache` is this request's
 * option cache. get_option() fills the cache on first read, exactly like
 * WordPress, so a value written by another request stays invisible until
 * the cache entry is deleted.
 */
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default_value = false ) {
		$state = &$GLOBALS['schema_lock_test'];
		if ( ! array_key_exists( $name, $state['cache'] ) ) {
			$state['cache'][ $name ] = $state['db'][ $name ] ?? $default_value;
		}
		return $state['cache'][ $name ];
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( $name, $default_value = false ) {
		return get_option( $name, $default_value );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['schema_lock_test']['db'][ $name ]    = $value;
		$GLOBALS['schema_lock_test']['cache'][ $name ] = $value;
		$GLOBALS['schema_lock_test']['writes'][]       = $name;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		if ( 'options' === $group ) {
			unset( $GLOBALS['schema_lock_test']['cache'][ $key ] );
		}
		return true;
	}
}

if ( ! function_exists( 'get_current_network_id' ) ) {
	function get_current_network_id() {
		return 1;
	}
}

/** Fake $wpdb modelling MySQL advisory locks and schema queries. */
final class SchemaLockTestFakeWpdb {

	public $prefix = 'wp_7_';

	public $base_prefix = 'wp_';

	/** Whether another connection currently holds the install lock. */
	public $held_elsewhere = false;

	/** Name of the lock this connection holds, if any. */
	public $held_lock = null;

	/** @var array<int, string> Every lock query, in order. */
	public $lock_log = array();

	/** @var array<int, string> Non-lock queries. */
	public $queries = array();

	/** @var callable|null Runs right after this connection takes the lock. */
	public $on_acquire = null;

	/** @var \Throwable|null Thrown from the next dbDelta call. */
	public $throw_on_schema = null;

	public function get_charset_collate() {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			static function ( $matched ) use ( $args, &$i ) {
				$value = $args[ $i++ ];
				return '%d' === $matched[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function get_var( $query ) {
		if ( preg_match( "/^SELECT GET_LOCK\\('([^']+)', (\\d+)\\)$/", $query, $m ) ) {
			$this->lock_log[] = 'GET_LOCK:' . $m[2];
			if ( $this->held_elsewhere ) {
				return '0';
			}
			$this->held_lock = $m[1];
			if ( is_callable( $this->on_acquire ) ) {
				( $this->on_acquire )();
			}
			return '1';
		}
		if ( preg_match( "/^SELECT RELEASE_LOCK\\('([^']+)'\\)$/", $query, $m ) ) {
			$this->lock_log[] = 'RELEASE_LOCK';
			$released         = $m[1] === $this->held_lock ? '1' : '0';
			$this->held_lock  = null;
			return $released;
		}
		throw new \LogicException( 'Unexpected get_var: ' . $query ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double diagnostic, never rendered.
	}

	public function query( $query ) {
		$this->queries[] = $query;
		return 0;
	}

	/** Hook called by the dbDelta fixture. */
	public function apply_schema( $sql ) {
		unset( $sql );
		if ( null !== $this->throw_on_schema ) {
			throw $this->throw_on_schema;
		}
	}
}

final class LifecycleProviderSchemaLockTest extends TestCase {

	/** @var SchemaLockTestFakeWpdb */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb                         = new SchemaLockTestFakeWpdb();
		$GLOBALS['wpdb']                    = $this->wpdb;
		$GLOBALS['schema_lock_test']        = array(
			'db'     => array(),
			'cache'  => array(),
			'writes' => array(),
		);
		$GLOBALS['ec_artist_test']          = array( 'dbdelta' => array() );
		$GLOBALS['ec_test_filters']         = array();
		$GLOBALS['schema_lock_test_action'] = 0;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['schema_lock_test'], $GLOBALS['ec_artist_test'], $GLOBALS['schema_lock_test_action'] );
		$GLOBALS['ec_test_filters'] = array();
		parent::tearDown();
	}

	private function dbdelta_calls(): int {
		return count( $GLOBALS['ec_artist_test']['dbdelta'] );
	}

	private function stored_rsvp_version() {
		return $GLOBALS['schema_lock_test']['db'][ RsvpPassesTable::VERSION_OPTION ] ?? null;
	}

	public function test_current_schema_skips_the_lock_entirely(): void {
		$GLOBALS['schema_lock_test']['db'][ RsvpPassesTable::VERSION_OPTION ] = RsvpPassesTable::SCHEMA_VERSION;

		LifecycleProvider::maybe_install_schema();

		$this->assertSame( array(), $this->wpdb->lock_log, 'Steady-state requests must not touch the advisory lock.' );
		$this->assertSame( 0, $this->dbdelta_calls() );
	}

	public function test_acquired_lock_installs_once_and_releases(): void {
		$GLOBALS['schema_lock_test']['db'][ RsvpPassesTable::VERSION_OPTION ] = '5';

		LifecycleProvider::maybe_install_schema();

		$this->assertSame( array( 'GET_LOCK:0', 'RELEASE_LOCK' ), $this->wpdb->lock_log, 'Lock is taken without waiting and released.' );
		$this->assertNull( $this->wpdb->held_lock );
		// Passes table, owner list + check-ins, parties table.
		$this->assertSame( 4, $this->dbdelta_calls() );
		$this->assertSame( RsvpPassesTable::SCHEMA_VERSION, $this->stored_rsvp_version() );
		$this->assertSame( array( RsvpPassesTable::VERSION_OPTION ), $GLOBALS['schema_lock_test']['writes'] );

		// The same request (or the next) is now a lock-free no-op.
		LifecycleProvider::maybe_install_schema();
		$this->assertSame( array( 'GET_LOCK:0', 'RELEASE_LOCK' ), $this->wpdb->lock_log );
		$this->assertSame( 4, $this->dbdelta_calls() );
	}

	public function test_lock_held_by_another_request_skips_install(): void {
		$GLOBALS['schema_lock_test']['db'][ RsvpPassesTable::VERSION_OPTION ] = '5';
		$this->wpdb->held_elsewhere = true;

		LifecycleProvider::maybe_install_schema();

		$this->assertSame( array( 'GET_LOCK:0' ), $this->wpdb->lock_log, 'A losing request must not release a lock it never held.' );
		$this->assertSame( 0, $this->dbdelta_calls(), 'A losing request must not run dbDelta.' );
		$this->assertSame( array(), $this->wpdb->queries, 'A losing request must not run manual ALTERs.' );
		$this->assertSame( '5', $this->stored_rsvp_version(), 'A losing request must not bump the version.' );
		$this->assertSame( array(), $GLOBALS['schema_lock_test']['writes'] );
	}

	public function test_skipped_request_lets_the_next_request_retry(): void {
		$GLOBALS['schema_lock_test']['db'][ RsvpPassesTable::VERSION_OPTION ] = '5';
		$this->wpdb->held_elsewhere = true;
		LifecycleProvider::maybe_install_schema();

		$this->wpdb->held_elsewhere = false;
		LifecycleProvider::maybe_install_schema();

		$this->assertSame( 4, $this->dbdelta_calls() );
		$this->assertSame( RsvpPassesTable::SCHEMA_VERSION, $this->stored_rsvp_version() );
	}

	public function test_version_current_after_acquiring_lock_skips_install(): void {
		$GLOBALS['schema_lock_test']['db'][ RsvpPassesTable::VERSION_OPTION ] = '5';
		// A concurrent request finishes the install between this request's
		// lock-free version read (now cached as stale) and its GET_LOCK.
		$this->wpdb->on_acquire = static function () {
			$GLOBALS['schema_lock_test']['db'][ RsvpPassesTable::VERSION_OPTION ] = RsvpPassesTable::SCHEMA_VERSION;
		};

		LifecycleProvider::maybe_install_schema();

		$this->assertSame( array( 'GET_LOCK:0', 'RELEASE_LOCK' ), $this->wpdb->lock_log );
		$this->assertSame( 0, $this->dbdelta_calls(), 'The re-check must read the fresh version, not the cached stale one.' );
		$this->assertSame( array(), $this->wpdb->queries );
		$this->assertSame( array(), $GLOBALS['schema_lock_test']['writes'] );
	}

	public function test_exception_during_install_releases_lock(): void {
		$GLOBALS['schema_lock_test']['db'][ RsvpPassesTable::VERSION_OPTION ] = '5';
		$this->wpdb->throw_on_schema = new \RuntimeException( 'dbDelta exploded' );

		try {
			LifecycleProvider::maybe_install_schema();
			$this->fail( 'The install failure must propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'dbDelta exploded', $e->getMessage() );
		}

		$this->assertSame( array( 'GET_LOCK:0', 'RELEASE_LOCK' ), $this->wpdb->lock_log );
		$this->assertNull( $this->wpdb->held_lock, 'The lock must be released after a failed install.' );
		$this->assertSame( '5', $this->stored_rsvp_version(), 'A failed install must not bump the version.' );
	}

	public function test_lock_name_is_network_scoped_and_fits_mysql_limit(): void {
		$GLOBALS['schema_lock_test']['db'][ RsvpPassesTable::VERSION_OPTION ] = '5';
		$this->wpdb->on_acquire = function () {
			$this->assertMatchesRegularExpression( '/^ec_events_schema_[0-9a-f]{40}$/', (string) $this->wpdb->held_lock );
			$this->assertLessThanOrEqual( 64, strlen( (string) $this->wpdb->held_lock ) );
		};
		LifecycleProvider::maybe_install_schema();
		$first = $this->wpdb->lock_log;

		// Another subsite (different prefix, same base prefix) contends for the same lock.
		$this->setUp();
		$this->wpdb->prefix = 'wp_9_';
		$GLOBALS['schema_lock_test']['db'][ RsvpPassesTable::VERSION_OPTION ] = '5';
		$names                  = array();
		$this->wpdb->on_acquire = function () use ( &$names ) {
			$names[] = $this->wpdb->held_lock;
		};
		LifecycleProvider::maybe_install_schema();

		$this->assertSame( array( 'GET_LOCK:0', 'RELEASE_LOCK' ), $first );
		$this->assertCount( 1, $names );
		$expected = 'ec_events_schema_' . substr( hash( 'sha256', "\0wp_" ), 0, 40 );
		$this->assertSame( $expected, $names[0] );
	}

	public function test_stale_integration_schema_opens_the_guarded_install_path(): void {
		$GLOBALS['schema_lock_test']['db'][ RsvpPassesTable::VERSION_OPTION ] = RsvpPassesTable::SCHEMA_VERSION;
		add_filter(
			'extrachill_events_schema_versions',
			static function ( $versions ) {
				$versions['integration_schema_version'] = array(
					'version' => '3',
					'network' => false,
				);
				return $versions;
			}
		);

		LifecycleProvider::maybe_install_schema();

		$this->assertSame( array( 'GET_LOCK:0', 'RELEASE_LOCK' ), $this->wpdb->lock_log, 'A stale filtered schema must take the lock.' );
		$this->assertSame( 0, $this->dbdelta_calls(), 'Current Events schemas still skip their own installers.' );
	}
}
