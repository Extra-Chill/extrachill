<?php
/**
 * Leaderboard block server render.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$leaderboard_per_page = isset( $attributes['perPage'] ) ? (int) $attributes['perPage'] : 25;
$leaderboard_per_page = max( 1, min( 100, $leaderboard_per_page ) );

$sprite_url = get_template_directory_uri() . '/assets/fonts/extrachill.svg';

if ( defined( 'EXTRACHILL_THEME_VERSION' ) && EXTRACHILL_THEME_VERSION ) {
	$sprite_url .= '?v=' . urlencode( EXTRACHILL_THEME_VERSION );
}

$class = 'wp-block-extrachill-leaderboard extrachill-leaderboard';

$leaderboard_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/users-leaderboard' ) : null;
$leaderboard_initial = $leaderboard_ability
	? $leaderboard_ability->execute( array( 'page' => 1, 'per_page' => $leaderboard_per_page ) )
	: null;
if ( is_wp_error( $leaderboard_initial ) ) {
	$leaderboard_initial = null;
}

printf(
	'<div class="%1$s" data-per-page="%2$d" data-sprite-url="%3$s" data-initial="%4$s"></div>',
	esc_attr( $class ),
	(int) $leaderboard_per_page,
	esc_url( $sprite_url ),
	esc_attr( wp_json_encode( $leaderboard_initial ) )
);
