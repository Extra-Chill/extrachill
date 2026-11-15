<?php
/**
 * Online Users Stats Widget
 *
 * Displays the network-wide "online now" count plus total members in the footer.
 *
 * Both numbers are read directly from the NetworkStats `online_users` and
 * `total_members` metric providers (extrachill-network) via
 * ec_get_network_stats() — the single source and single cache for each.
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
	$community_blog_id = ec_get_blog_id( 'community' );
	if ( ! $community_blog_id ) {
		return;
	}

	// Online-now and total-members counts: read straight from the NetworkStats
	// primitive (single source, single cache per metric). When the primitive
	// is unavailable (e.g. multisite a version behind during deploy) or a
	// metric cannot resolve, fall back to 0.
	$online_users_count = 0;
	$total_members      = 0;
	if ( function_exists( 'ec_get_network_stats' ) ) {
		$stats              = ec_get_network_stats( array( 'online_users', 'total_members' ) );
		$online_users_count = (int) ( $stats['online_users']['value'] ?? 0 );
		$total_members      = (int) ( $stats['total_members']['value'] ?? 0 );
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
