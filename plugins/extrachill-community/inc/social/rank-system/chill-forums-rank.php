<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function extrachill_determine_rank_by_points( $points ) {
	if ( function_exists( 'ec_get_rank_for_points' ) ) {
		return ec_get_rank_for_points( $points );
	}

	$points = (float) $points;

	if ( $points >= 516246 ) return 'Frozen Deep Space';
	if ( $points >= 344164 ) return 'Upper Atmosphere';
	if ( $points >= 229442 ) return 'Ice Age';
	if ( $points >= 152961 ) return 'Antarctica';
	if ( $points >= 101974 ) return 'Glacier';
	if ( $points >= 67983 ) return 'Blizzard';
	if ( $points >= 45322 ) return 'Ski Resort';
	if ( $points >= 30214 ) return 'Snowstorm';
	if ( $points >= 20143 ) return 'Flurry';
	if ( $points >= 13428 ) return 'Ice Rink';
	if ( $points >= 8952 ) return 'Frozen Foods Isle';
	if ( $points >= 5968 ) return 'Walk-In Freezer';
	if ( $points >= 3978 ) return 'Ice Machine';
	if ( $points >= 2652 ) return 'Freezer';
	if ( $points >= 1768 ) return 'Fridge';
	if ( $points >= 1178 ) return 'Cooler';
	if ( $points >= 785 ) return 'Ice Maker';
	if ( $points >= 523 ) return 'Bag of Ice';
	if ( $points >= 349 ) return 'Ice Tray';
	if ( $points >= 232 ) return 'Ice Cube';
	if ( $points >= 155 ) return 'Overnight Freeze';
	if ( $points >= 103 ) return 'First Frost';
	if ( $points >= 69 ) return 'Crisp Air';
	if ( $points >= 35 ) return 'Puddle';
	if ( $points >= 15 ) return 'Droplet';
	return 'Dew';
}

function extrachill_display_user_rank( $user_id ) {
	$total_points = (float) get_user_meta( $user_id, 'extrachill_total_points', true );
	return extrachill_determine_rank_by_points( $total_points );
}

function extrachill_add_rank_and_points_to_reply() {
	$reply_author_id = bbp_get_reply_author_id();

	echo '<div class="rankpoints">';

	$local_city = get_user_meta( $reply_author_id, 'local_city', true );
	if ( ! empty( $local_city ) ) {
		echo '<div class="reply-author-local-scene">';
		echo '<span>Local Scene:</span> ' . esc_html( $local_city );
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
