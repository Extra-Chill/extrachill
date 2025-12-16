<?php
/**
 * Leaderboard block server render.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$per_page = isset( $attributes['perPage'] ) ? (int) $attributes['perPage'] : 25;
$per_page = max( 1, min( 100, $per_page ) );

$sprite_url = get_template_directory_uri() . '/assets/fonts/extrachill.svg';

if ( defined( 'EXTRACHILL_THEME_VERSION' ) && EXTRACHILL_THEME_VERSION ) {
	$sprite_url .= '?v=' . urlencode( EXTRACHILL_THEME_VERSION );
}

$class = 'wp-block-extrachill-leaderboard extrachill-leaderboard';

printf(
	'<div class="%1$s" data-per-page="%2$d" data-sprite-url="%3$s"></div>',
	esc_attr( $class ),
	$per_page,
	esc_url( $sprite_url )
);
