<?php
/**
 * Rank System - Rank Tiers
 *
 * Centralized rank tier resolution for the Extra Chill Platform.
 *
 * Rank is currently derived from user points stored in `extrachill_total_points`.
 * This logic lives in extrachill-users as the single source of truth for
 * user-related primitives consumed by UI plugins and the centralized API.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determine rank name from point total.
 *
 * @param float|int|string $points Point total.
 * @return string Rank label.
 */
function ec_determine_rank_by_points( $points ) {
	$points = (float) $points;

	if ( $points >= 516246 ) {
		return 'Frozen Deep Space';
	}
	if ( $points >= 344164 ) {
		return 'Upper Atmosphere';
	}
	if ( $points >= 229442 ) {
		return 'Ice Age';
	}
	if ( $points >= 152961 ) {
		return 'Antarctica';
	}
	if ( $points >= 101974 ) {
		return 'Glacier';
	}
	if ( $points >= 67983 ) {
		return 'Blizzard';
	}
	if ( $points >= 45322 ) {
		return 'Ski Resort';
	}
	if ( $points >= 30214 ) {
		return 'Snowstorm';
	}
	if ( $points >= 20143 ) {
		return 'Flurry';
	}
	if ( $points >= 13428 ) {
		return 'Ice Rink';
	}
	if ( $points >= 8952 ) {
		return 'Frozen Foods Isle';
	}
	if ( $points >= 5968 ) {
		return 'Walk-In Freezer';
	}
	if ( $points >= 3978 ) {
		return 'Ice Machine';
	}
	if ( $points >= 2652 ) {
		return 'Freezer';
	}
	if ( $points >= 1768 ) {
		return 'Fridge';
	}
	if ( $points >= 1178 ) {
		return 'Cooler';
	}
	if ( $points >= 785 ) {
		return 'Ice Maker';
	}
	if ( $points >= 523 ) {
		return 'Bag of Ice';
	}
	if ( $points >= 349 ) {
		return 'Ice Tray';
	}
	if ( $points >= 232 ) {
		return 'Ice Cube';
	}
	if ( $points >= 155 ) {
		return 'Overnight Freeze';
	}
	if ( $points >= 103 ) {
		return 'First Frost';
	}
	if ( $points >= 69 ) {
		return 'Crisp Air';
	}
	if ( $points >= 35 ) {
		return 'Puddle';
	}
	if ( $points >= 15 ) {
		return 'Droplet';
	}
	return 'Dew';
}

/**
 * Get rank label for a point total.
 *
 * @param float|int|string $points Point total.
 * @return string Rank label.
 */
function ec_get_rank_for_points( $points ) {
	return ec_determine_rank_by_points( $points );
}
