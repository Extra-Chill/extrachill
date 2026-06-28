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

/**
 * Get a user's raw `last_active` timestamp.
 *
 * Reads the centralized `last_active` user meta from community.extrachill.com
 * (where ec_record_user_activity() writes it) regardless of the active site,
 * so callers on any site get a correct value.
 *
 * @param int $user_id User ID.
 * @return int|null Unix timestamp of last page activity, or null if never active.
 */
function ec_get_last_active( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return null;
	}

	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;

	if ( $community_blog_id && function_exists( 'switch_to_blog' ) ) {
		switch_to_blog( $community_blog_id );
		try {
			$last_active = get_user_meta( $user_id, 'last_active', true );
		} finally {
			restore_current_blog();
		}
	} else {
		$last_active = get_user_meta( $user_id, 'last_active', true );
	}

	return $last_active ? (int) $last_active : null;
}

/**
 * Get a human-readable "last seen" string for a user.
 *
 * Composes the `last_active` primitive (page activity). Returns "Online now"
 * when the user has been active within the activity throttle window
 * (15 minutes — the same cadence ec_record_user_activity() writes at, so a
 * user active "right now" reads as online), otherwise "Last seen X ago".
 *
 * This is the canonical formatter for the forum profile "last seen" element
 * and any other consumer; surfaces compose it rather than re-deriving the
 * threshold/format.
 *
 * @param int $user_id User ID.
 * @return string Display string, or '' when the user has no recorded activity.
 */
function ec_get_last_seen( $user_id ) {
	$last_active = ec_get_last_active( $user_id );

	if ( ! $last_active ) {
		return '';
	}

	// 900s == the ec_record_user_activity() throttle window. Within it, the
	// user is "online now"; the meta simply hasn't been rewritten yet.
	if ( ( time() - $last_active ) <= 900 ) {
		return __( 'Online now', 'extrachill-users' );
	}

	return sprintf(
		/* translators: %s: human-readable time difference, e.g. "2 hours" */
		__( 'Last seen %s ago', 'extrachill-users' ),
		human_time_diff( $last_active, time() )
	);
}
