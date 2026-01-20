<?php
/**
 * Online Users Stats Widget
 *
 * Displays network-wide online users count in footer.
 * Data provided by ec_get_online_users_count() function.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display online users stats widget.
 */
function extrachill_users_display_online_stats() {
	if ( ! function_exists( 'ec_get_online_users_count' ) ) {
		return;
	}

	$online_users_count = ec_get_online_users_count();

	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id ) {
		return;
	}

	if ( function_exists( 'switch_to_blog' ) ) {
		global $current_user;

		if ( isset( $current_user ) && $current_user instanceof WP_User ) {
			switch_to_blog( $community_blog_id );
			$total_members = get_transient( 'total_members_count' );
			if ( false === $total_members ) {
				$user_count_data = count_users();
				$total_members   = $user_count_data['total_users'];
				set_transient( 'total_members_count', $total_members, DAY_IN_SECONDS );
			}
			restore_current_blog();
		} else {
			$total_members = 0;
		}
	} else {
		$total_members = 0;
	}
	?>
	<div class="online-stats-card">
		<div class="online-stat">
			<?php echo ec_icon( 'circle', 'online-indicator' ); ?>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( $online_users_count ); ?></span>
				<span class="stat-label"><?php echo esc_html( apply_filters( 'extrachill_users_online_label', __( 'Online Now', 'extrachill-users' ) ) ); ?></span>
			</div>
		</div>
		<div class="online-stat">
			<?php echo ec_icon( 'users' ); ?>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( $total_members ); ?></span>
				<span class="stat-label"><?php echo esc_html( apply_filters( 'extrachill_users_members_label', __( 'Total Members', 'extrachill-users' ) ) ); ?></span>
			</div>
		</div>
	</div>
	<?php
}
add_action( 'extrachill_before_footer', 'extrachill_users_display_online_stats' );
