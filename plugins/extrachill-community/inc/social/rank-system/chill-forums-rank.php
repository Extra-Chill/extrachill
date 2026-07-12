<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function extrachill_determine_rank_by_points( $points ) {
	if ( function_exists( 'ec_get_rank_for_points' ) ) {
		return ec_get_rank_for_points( $points );
	}

	// extrachill-users is network-activated, so the canonical
	// ec_get_rank_for_points() is always loaded and this is never reached.
	return 'Dew';
}

function extrachill_display_user_rank( $user_id ) {
	$total_points = (float) get_user_meta( $user_id, 'extrachill_total_points', true );
	return extrachill_determine_rank_by_points( $total_points );
}

function extrachill_add_rank_and_points_to_reply() {
	$reply_author_id = bbp_get_reply_author_id();

	echo '<div class="rankpoints">';

	$local_scene = extrachill_community_get_public_local_scene( $reply_author_id );
	if ( ! empty( $local_scene['name'] ) ) {
		echo '<div class="reply-author-local-scene">';
		echo '<span>' . esc_html__( 'Local Scene:', 'extra-chill-community' ) . '</span> ';
		extrachill_community_render_local_scene_badge( $local_scene, true );
		echo '</div>';
	}

	echo '<div class="reply-author-rank">';
	echo '<span>Rank:</span> ' . esc_html( extrachill_display_user_rank( $reply_author_id ) );
	echo '</div>';

	echo '<div class="reply-author-points">';
	echo '<span>Points:</span> ' . esc_html( extrachill_display_user_points( $reply_author_id ) );
	echo '</div>';

	echo '</div>';
}
