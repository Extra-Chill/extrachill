<?php
/**
 * Network-Wide Online Users Tracking
 *
 * Tracks activity across all multisite network sites with centralized storage on community.extrachill.com.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $online_users_count;

/**
 * Record user activity network-wide with centralized storage.
 *
 * Updates every 15 minutes, stores on community.extrachill.com regardless of active site.
 */
function ec_record_user_activity() {
	$user_id = get_current_user_id();
	if ( $user_id ) {
		$current_time            = current_time( 'timestamp' );
		$user_activity_cache_key = 'user_activity_' . $user_id;


		// Throttle updates to every 15 minutes (900 seconds)
		// Check and set transients on community site for consistency
		$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
		if ( $community_blog_id ) {
			switch_to_blog( $community_blog_id );
		}
		try {
			$last_update = get_transient( $user_activity_cache_key );

			if ( false === $last_update || ( $current_time - intval( $last_update ) ) > 900 ) {
				update_user_meta( $user_id, 'last_active', $current_time );
				delete_transient( 'online_users_count' );
				set_transient( $user_activity_cache_key, $current_time, 900 );
			}
		} finally {
			restore_current_blog();
		}
	}

	global $online_users_count;
	if ( ! isset( $online_users_count ) ) {
		$online_users_count = ec_get_online_users_count();
	}
}
add_action( 'wp', 'ec_record_user_activity' );

/**
 * Get network-wide online users count with 5-minute caching.
 *
 * @return int Users active within last 15 minutes across network
 */
function ec_get_online_users_count() {
	global $wpdb;

	$transient_key     = 'online_users_count';
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;

	if ( $community_blog_id ) {
		switch_to_blog( $community_blog_id );
	}
	try {
		$online_users_count = get_transient( $transient_key );

		if ( false === $online_users_count ) {
			$time_limit     = 15 * MINUTE_IN_SECONDS;
			$time_threshold = current_time( 'timestamp' ) - $time_limit;

			$online_users_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $wpdb->usermeta WHERE meta_key = 'last_active' AND meta_value > %d",
					$time_threshold
				)
			);

			set_transient( $transient_key, intval( $online_users_count ), 5 * MINUTE_IN_SECONDS );
		}
	} finally {
		restore_current_blog();
	}

	return intval( $online_users_count );
}
