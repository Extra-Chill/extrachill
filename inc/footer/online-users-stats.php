<?php
/**
 * Online Users Stats Widget
 *
 * Displays network-wide online users count in footer.
 * Data provided by extrachill-users plugin via ec_get_online_users_count().
 *
 * @package ExtraChill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display online users stats widget.
 */
function extrachill_display_online_users_stats() {
	if ( ! function_exists( 'ec_get_online_users_count' ) ) {
		return;
	}

	$online_users_count = ec_get_online_users_count();

	switch_to_blog( 2 );
	$total_members = get_transient( 'total_members_count' );
	if ( false === $total_members ) {
		$user_count_data = count_users();
		$total_members   = $user_count_data['total_users'];
		set_transient( 'total_members_count', $total_members, DAY_IN_SECONDS );
	}
	restore_current_blog();
	?>
	<div class="online-stats-card">
		<div class="online-stat">
			<?php echo ec_icon( 'circle', 'online-indicator' ); ?>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( $online_users_count ); ?></span>
				<span class="stat-label">Online Now</span>
			</div>
		</div>
		<div class="online-stat">
			<?php echo ec_icon( 'users' ); ?>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( $total_members ); ?></span>
				<span class="stat-label">Total Members</span>
			</div>
		</div>
	</div>
	<?php
}
add_action( 'extrachill_before_footer', 'extrachill_display_online_users_stats' );
