<?php
/**
 * Schema readiness lifecycle fixtures.
 *
 * @package ExtraChill\Analytics
 */

if ( ! defined( 'EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION' ) ) {
	define( 'EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION', '1.2' );
	define( 'EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION', 'extrachill_analytics_events_db_version' );
}
if ( ! defined( 'EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION' ) ) {
	define( 'EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION', '1.0' );
	define( 'EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION_OPTION', 'extrachill_analytics_php_error_db_version' );
}
if ( ! function_exists( 'extrachill_analytics_events_create_table' ) ) {
	/**
	 * Record the events migration fixture.
	 *
	 * @return bool True.
	 */
	function extrachill_analytics_events_create_table() {
		$GLOBALS['extrachill_analytics_test_migrations'][] = 'events';
		update_site_option( EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION, EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION );
		return true;
	}
}
if ( ! function_exists( 'extrachill_analytics_php_error_create_table' ) ) {
	/**
	 * Record the PHP error migration fixture.
	 *
	 * @return bool True.
	 */
	function extrachill_analytics_php_error_create_table() {
		$GLOBALS['extrachill_analytics_test_migrations'][] = 'php-errors';
		update_site_option( EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION_OPTION, EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION );
		return true;
	}
}
if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Record a schema reconciliation fixture.
	 *
	 * @param string $sql Schema definition.
	 * @return array Empty dbDelta result.
	 */
	function dbDelta( $sql ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Mirrors the WordPress core API.
		$GLOBALS['extrachill_analytics_test_dbdelta'][] = $sql;
		return array();
	}
}

require_once dirname( __DIR__ ) . '/inc/database/mediavine-revenue-db.php';
require_once dirname( __DIR__ ) . '/inc/database/network-schema.php';
