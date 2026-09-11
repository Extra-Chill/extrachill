<?php
/**
 * Network schema lifecycle coverage.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify activation, upgrades, and the public-write readiness guard.
 */
final class SchemaReadinessTest extends Extrachill_Analytics_TestCase {
	/**
	 * Fresh network activation creates every table without an admin request.
	 */
	public function test_fresh_activation_prepares_frontend_event_write_without_admin_init(): void {
		delete_site_option( EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION );
		delete_site_option( EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION_OPTION );
		delete_site_option( EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION );
		delete_site_option( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION );

		delete_site_option( EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION );
		delete_site_option( EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION_OPTION );
		delete_site_option( EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION );
		delete_site_option( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION );

		extrachill_analytics_activate( true );

		$this->assertTrue( extrachill_analytics_network_schema_is_ready() );
		$this->assertSame(
			EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION,
			get_site_option( EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION )
		);
		$this->assertSame( extrachill_analytics_events_table(), $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', extrachill_analytics_events_table() ) ) );
		$this->assertFalse( get_site_option( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION ) );
	}

	/**
	 * Current schemas avoid dbDelta during lifecycle and write readiness checks.
	 */
	public function test_current_schema_short_circuits_without_migration(): void {
		$captured = $this->capture_queries();
		$result   = extrachill_analytics_install_network_schema();
		$queries  = $captured->queries;

		$this->assertTrue( $result );
		$created_tables = preg_grep( '/CREATE TABLE/', $queries );
		$this->assertEmpty( $created_tables );
	}

	/**
	 * A concurrent migration blocks duplicate schema work and event writes.
	 */
	public function test_active_migration_lock_prevents_concurrent_dbdelta(): void {
		delete_site_option( EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION );
		delete_site_option( EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION_OPTION );
		delete_site_option( EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION );
		update_site_option( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION, time() );

		$this->assertFalse( extrachill_analytics_install_network_schema() );
		$this->assertNotFalse( get_site_option( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION ), 'The failed claim must leave the lock intact.' );

		delete_site_option( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION );
		$this->assertTrue( extrachill_analytics_install_network_schema() );
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
