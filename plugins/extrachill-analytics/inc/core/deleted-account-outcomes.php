<?php
/**
 * Exclude outcomes owned by deleted accounts from conversion reporting.
 *
 * Verification and QA accounts are often deleted after use. Their outcome rows
 * stay authenticated, so without this stamp they would keep counting as human
 * conversions after the account (and the artist or link page it created) is gone.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stamp every analytics row owned by a deleted user as non-countable.
 *
 * Matches both the request user column and the payload user_id, which
 * registration outcomes use before the new account becomes current. Rows that
 * already carry a bot_reason keep it.
 *
 * @param int $user_id Deleted user ID.
 * @return void
 */
function extrachill_analytics_exclude_deleted_account_events( $user_id ) {
	global $wpdb;

	$user_id = absint( $user_id );
	if ( $user_id <= 0 || ! extrachill_analytics_events_ensure_ready() ) {
		return;
	}

	$table = extrachill_analytics_events_table();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET event_data = JSON_SET(event_data, '$.bot_reason', 'deleted_account')
			WHERE JSON_VALID(event_data) AND JSON_EXTRACT(event_data, '$.bot_reason') IS NULL
			AND ( user_id = %d OR JSON_EXTRACT(event_data, '$.user_id') = %d )",
			$user_id,
			$user_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Network deletion removes the account; single-site deletion does the same off
// multisite. Removing a user from one blog of a network keeps the account.
if ( is_multisite() ) {
	add_action( 'wpmu_delete_user', 'extrachill_analytics_exclude_deleted_account_events' );
} else {
	add_action( 'delete_user', 'extrachill_analytics_exclude_deleted_account_events' );
}
