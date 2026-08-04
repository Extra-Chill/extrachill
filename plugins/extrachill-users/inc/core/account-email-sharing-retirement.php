<?php
/**
 * Retire account-derived artist and venue email-sharing rows.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_EMAIL_SHARING_RETIREMENT_VERSION = '1';

/**
 * Delete only persisted rows belonging to the retired purpose identities.
 *
 * @return int|WP_Error Number of deleted rows, or a database error.
 */
function extrachill_users_purge_retired_email_sharing_subscriptions() {
	global $wpdb;

	$table   = extrachill_users_entity_subscriptions_table_name();
	$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration against the plugin-owned table.
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE (entity_type = %s AND taxonomy = %s) OR (entity_type = %s AND taxonomy = %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
			'artist-email-sharing',
			'artist',
			'venue-email-sharing',
			'venue'
		)
	);

	if ( false === $deleted ) {
		return new WP_Error( 'email_sharing_retirement_delete_failed', __( 'Retired email-sharing subscriptions could not be deleted.', 'extrachill-users' ) );
	}

	return (int) $deleted;
}

/**
 * Run the account email-sharing retirement once after the table is available.
 *
 * The version is advanced only after a successful delete, leaving database
 * failures retryable on the next request.
 *
 * @return int|WP_Error Number of deleted rows, or a database error.
 */
function extrachill_users_maybe_purge_retired_email_sharing_subscriptions() {
	$option_key       = 'extrachill_users_email_sharing_retirement_version';
	$migrated_version = (string) get_site_option( $option_key, '0' );

	if ( version_compare( $migrated_version, EXTRACHILL_USERS_EMAIL_SHARING_RETIREMENT_VERSION, '>=' ) ) {
		return 0;
	}

	$deleted = extrachill_users_purge_retired_email_sharing_subscriptions();
	if ( is_wp_error( $deleted ) ) {
		return $deleted;
	}

	update_site_option( $option_key, EXTRACHILL_USERS_EMAIL_SHARING_RETIREMENT_VERSION );

	return $deleted;
}

/**
 * Run the retirement migration from WordPress initialization.
 *
 * @return void
 */
function extrachill_users_run_email_sharing_retirement(): void {
	extrachill_users_maybe_purge_retired_email_sharing_subscriptions();
}
add_action( 'init', 'extrachill_users_run_email_sharing_retirement', 20 );
