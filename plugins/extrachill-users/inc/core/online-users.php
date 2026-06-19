<?php
/**
 * Network-Wide Online Users Tracking
 *
 * Records per-user activity on community.extrachill.com (the centralized store)
 * that feeds the network-wide "online now" count.
 *
 * The COUNT itself is owned by the NetworkStats `online_users` metric provider
 * in extrachill-multisite — that primitive is the single source and single
 * cache for the number. Consumers read it directly via
 * ec_get_network_stats(['online_users']); this plugin only records activity and
 * invalidates the metric cache when a user becomes active.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Record user activity network-wide with centralized storage.
 *
 * Writes `last_active` user meta (throttled to once per 15 minutes per user via
 * the `user_activity_<id>` transient) on community.extrachill.com regardless of
 * the active site, then invalidates the NetworkStats online_users metric cache
 * so the next read reflects the new activity.
 */
function ec_record_user_activity() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	$current_time            = time();
	$user_activity_cache_key = 'user_activity_' . $user_id;

	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;

	if ( ! $community_blog_id || ! function_exists( 'switch_to_blog' ) ) {
		return;
	}

	global $current_user;

	if ( ! isset( $current_user ) || ! ( $current_user instanceof WP_User ) ) {
		return;
	}

	switch_to_blog( $community_blog_id );
	try {
		$last_update = get_transient( $user_activity_cache_key );

		if ( false === $last_update || ( $current_time - intval( $last_update ) ) > 900 ) {
			update_user_meta( $user_id, 'last_active', $current_time );
			set_transient( $user_activity_cache_key, $current_time, 900 );

			// The online-users count is owned + cached by the NetworkStats
			// primitive (extrachill-multisite). Bust just that metric so the
			// next read reflects this activity. Guarded so a version skew
			// during deploy (multisite a version behind) never fatals.
			if ( function_exists( 'ec_network_stats_forget' ) ) {
				ec_network_stats_forget( 'online_users' );
			}
		}
	} finally {
		restore_current_blog();
	}
}
add_action( 'wp', 'ec_record_user_activity' );
