<?php
/**
 * Network Database Schema Lifecycle
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION', 'extrachill_analytics_schema_migration_lock' );
define( 'EXTRACHILL_ANALYTICS_SCHEMA_LOCK_TTL', 300 );

/**
 * Check whether every network-scoped analytics table is current.
 *
 * @return bool Whether all network schemas are ready.
 */
function extrachill_analytics_network_schema_is_ready() {
	return EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION === get_site_option( EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION )
		&& EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION === get_site_option( EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION_OPTION )
		&& EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION === get_site_option( EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION );
}

/**
 * Atomically claim the network schema migration, recovering abandoned locks.
 *
 * @return bool Whether this request owns the migration lock.
 */
function extrachill_analytics_claim_schema_lock() {
	$now = time();

	if ( add_site_option( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION, $now ) ) {
		return true;
	}

	$locked_at = (int) get_site_option( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION, 0 );
	if ( $locked_at > $now - EXTRACHILL_ANALYTICS_SCHEMA_LOCK_TTL ) {
		return false;
	}

	delete_site_option( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION );
	return add_site_option( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION, $now );
}

/**
 * Install or upgrade all network-scoped analytics tables.
 *
 * Version checks make the normal request path cheap. The atomic network option
 * serializes dbDelta calls when multiple frontend requests arrive during an
 * upgrade or immediately after a restore.
 *
 * @return bool Whether every network schema is ready.
 */
function extrachill_analytics_install_network_schema() {
	if ( extrachill_analytics_network_schema_is_ready() ) {
		return true;
	}

	if ( ! extrachill_analytics_claim_schema_lock() ) {
		return extrachill_analytics_network_schema_is_ready();
	}

	try {
		extrachill_analytics_events_create_table();
		extrachill_analytics_php_error_create_table();
		extrachill_analytics_revenue_create_table();
	} finally {
		delete_site_option( EXTRACHILL_ANALYTICS_SCHEMA_LOCK_OPTION );
	}

	return extrachill_analytics_network_schema_is_ready();
}

/**
 * Install the network schema during explicit plugin activation.
 *
 * @param bool $network_wide Whether WordPress activated the plugin network-wide.
 */
function extrachill_analytics_activate( $network_wide = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	extrachill_analytics_install_network_schema();
}

add_action( 'plugins_loaded', 'extrachill_analytics_install_network_schema' );
