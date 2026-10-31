<?php
/**
 * Online Users Stats Widget
 *
 * Displays the network-wide "online now" count plus total members in the footer.
 *
 * The online count is read directly from the NetworkStats `online_users` metric
 * provider (extrachill-network) via ec_get_network_stats() — the single source
 * and single cache for that number. Total members is counted from the community
 * blog and cached locally for a day.
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
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id ) {
		return;
	}

	// Online-now count: read straight from the NetworkStats primitive (single
	// source, single cache). When the primitive is unavailable (e.g. multisite
	// a version behind during deploy) fall back to 0.
	$online_users_count = 0;
	if ( function_exists( 'ec_get_network_stats' ) ) {
		$stats              = ec_get_network_stats( array( 'online_users' ) );
		$online_users_count = (int) ( $stats['online_users']['value'] ?? 0 );
	}

	$total_members = 0;
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
		}
	}
	?>
	<div class="online-stats-card">
		<div class="online-stat">
			<?php echo ec_icon( 'circle', 'online-indicator' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ec_icon() returns trusted local SVG sprite markup. ?>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( (string) $online_users_count ); ?></span>
				<span class="stat-label"><?php echo esc_html( apply_filters( 'extrachill_users_online_label', __( 'Online Now', 'extrachill-users' ) ) ); ?></span>
			</div>
		</div>
		<div class="online-stat">
			<?php echo ec_icon( 'users' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ec_icon() returns trusted local SVG sprite markup. ?>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( (string) $total_members ); ?></span>
				<span class="stat-label"><?php echo esc_html( apply_filters( 'extrachill_users_members_label', __( 'Total Members', 'extrachill-users' ) ) ); ?></span>
			</div>
		</div>
	</div>
	<?php
}
add_action( 'extrachill_before_footer', 'extrachill_users_display_online_stats' );
