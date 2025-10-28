<?php
/**
 * Network-Wide Online Users Tracking
 *
 * Tracks activity across all 9 multisite network sites with centralized storage on community.extrachill.com.
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
		switch_to_blog( 2 );
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
	$community_blog_id = 2;

	switch_to_blog( $community_blog_id );
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

/**
 * Display online users stats widget with network-wide data.
 */
function ec_display_online_users_stats() {
	global $online_users_count;

	if ( ! isset( $online_users_count ) ) {
		$online_users_count = ec_get_online_users_count();
	}

	$community_blog_id = 2;
	switch_to_blog( $community_blog_id );
	try {
		$transient_key_total_members = 'total_members_count';
		$total_members               = get_transient( $transient_key_total_members );

		if ( false === $total_members ) {
			$user_count_data = count_users();
			$total_members   = $user_count_data['total_users'];
			set_transient( $transient_key_total_members, $total_members, 24 * HOUR_IN_SECONDS );
		}
	} finally {
		restore_current_blog();
	}

	?>
	<div class="online-stats-card">
		<div class="online-stat">
			<i class="fa-solid fa-circle online-indicator"></i>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( $online_users_count ); ?></span>
				<span class="stat-label">Online Now</span>
			</div>
		</div>
		<div class="online-stat">
			<i class="fa-solid fa-users"></i>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( $total_members ); ?></span>
				<span class="stat-label">Total Members</span>
			</div>
		</div>
	</div>
	<?php
}
