<?php
/**
 * Contribution Heatmap block server render.
 *
 * Emits the mount node with the initial (trailing-window) calendar payload
 * and year-navigation metadata inlined as JSON. The view script hydrates
 * immediately from this data — no fetch on first paint — and fetches other
 * years client-side through the extrachill/get-user-contribution-calendar
 * ability endpoint.
 *
 * The displayed user resolves from the userId attribute when set, otherwise
 * from the bbPress displayed-user context (the profile page case).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$heatmap_user_id = isset( $attributes['userId'] ) ? (int) $attributes['userId'] : 0;
if ( $heatmap_user_id <= 0 && function_exists( 'bbp_get_displayed_user_id' ) ) {
	$heatmap_user_id = (int) bbp_get_displayed_user_id();
}

if ( $heatmap_user_id <= 0 || ! function_exists( 'extrachill_community_get_contribution_calendar' ) ) {
	return;
}

// The dated-contributions seam is owned by extrachill-users. If absent, skip
// the card entirely (graceful — no fatal, no dead chart).
if ( ! function_exists( 'ec_get_contribution_events' ) ) {
	return;
}

$heatmap_calendar = extrachill_community_get_contribution_calendar( $heatmap_user_id );
if ( ! is_array( $heatmap_calendar ) ) {
	return;
}

$heatmap_user = get_userdata( $heatmap_user_id );
$join_year    = 0;
if ( $heatmap_user && ! empty( $heatmap_user->user_registered ) ) {
	$join_year = (int) get_date_from_gmt( $heatmap_user->user_registered, 'Y' );
}

try {
	$heatmap_now = current_datetime();
} catch ( Exception $e ) {
	$heatmap_now = new DateTimeImmutable( 'now', wp_timezone() );
}

$heatmap_config = array(
	'userId'      => $heatmap_user_id,
	'joinYear'    => $join_year,
	'currentYear' => (int) $heatmap_now->format( 'Y' ),
	'isOwn'       => get_current_user_id() === $heatmap_user_id,
	'initial'     => $heatmap_calendar,
);

printf(
	'<div class="wp-block-extrachill-contribution-heatmap bbp-user-profile-card ec-contribution-heatmap-card"><script type="application/json" class="ec-heatmap-data">%s</script></div>',
	wp_json_encode( $heatmap_config ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output inside a JSON script tag; parsed, never executed.
);
