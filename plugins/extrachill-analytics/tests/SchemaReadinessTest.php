<?php
/**
 * Network schema lifecycle coverage.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/schema-readiness-fixtures.php';

/**
 * Verify activation, upgrades, and the public-write readiness guard.
 */
final class SchemaReadinessTest extends TestCase {
	/**
	 * Install a minimal database fixture.
	 */
	protected function setUp(): void {
		$GLOBALS['extrachill_analytics_test_site_options'] = array();
		$GLOBALS['extrachill_analytics_test_dbdelta']      = array();
		$GLOBALS['extrachill_analytics_test_migrations']   = array();
		$GLOBALS['wpdb']                                   = new class() {
			/**
			 * Network table prefix.
			 *
			 * @var string
			 */
			public $base_prefix = 'wp_';

			/**
			 * Site table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Last database error.
			 *
			 * @var string
			 */
			public $last_error = '';

			/**
			 * Return a deterministic charset clause.
			 *
			 * @return string Charset clause.
			 */
			public function get_charset_collate() {
				return 'DEFAULT CHARACTER SET utf8mb4';
			}
		};
	}

	/**
	 * Fresh network activation creates every table without an admin request.
	 */
	public function test_fresh_activation_prepares_frontend_event_write_without_admin_init(): void {
		extrachill_analytics_activate( true );

		$this->assertTrue( extrachill_analytics_network_schema_is_ready() );
		$this->assertSame( array( 'events', 'php-errors' ), $GLOBALS['extrachill_analytics_test_migrations'] );
		$this->assertCount( 1, $GLOBALS['extrachill_analytics_test_dbdelta'] );
		$this->assertSame(
			EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION,
			get_site_option( EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION )
		);
		$this->assertArrayNotHasKey( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION, $GLOBALS['extrachill_analytics_test_site_options'] );
	}

	/**
	 * Current schemas avoid dbDelta during lifecycle and write readiness checks.
	 */
	public function test_current_schema_short_circuits_without_migration(): void {
		$GLOBALS['extrachill_analytics_test_site_options'] = array(
			EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION  => EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION,
			EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION_OPTION => EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION,
			EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION => EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION,
		);

		$this->assertTrue( extrachill_analytics_install_network_schema() );
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_migrations'] );
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_dbdelta'] );
	}

	/**
	 * A concurrent migration blocks duplicate schema work and event writes.
	 */
	public function test_active_migration_lock_prevents_concurrent_dbdelta(): void {
		$GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION ] = time();

		$this->assertFalse( extrachill_analytics_install_network_schema() );
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_migrations'] );
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_dbdelta'] );
	}

	/**
	 * The public writer checks readiness before resolving or inserting its table.
	 */
	public function test_public_write_guard_precedes_insert(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/events.php' );
		$guard  = strpos( $source, 'extrachill_analytics_events_ensure_ready()' );
		$table  = strpos( $source, '$table_name = extrachill_analytics_events_table()' );
		$insert = strpos( $source, '$wpdb->insert(' );

		$this->assertNotFalse( $guard );
		$this->assertNotFalse( $table );
		$this->assertNotFalse( $insert );
		$this->assertLessThan( $table, $guard );
		$this->assertLessThan( $insert, $guard );
	}

	/**
	 * The real events migration remains version-gated and admin-independent.
	 */
	public function test_events_migration_contract_is_idempotent_and_not_admin_gated(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/database/events-db.php' );

		$this->assertStringContainsString( 'EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION === $current_db_version', $source );
		$this->assertStringContainsString( 'function extrachill_analytics_events_ensure_ready()', $source );
		$this->assertStringNotContainsString( "add_action( 'admin_init'", $source );
	}
}
