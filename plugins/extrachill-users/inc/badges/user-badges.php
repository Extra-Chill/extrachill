<?php
/**
 * User Badges
 *
 * Centralized badge resolver for team members, artists, and professionals.
 *
 * @package ExtraChillUsers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get structured user badges.
 *
 * Badge ordering: artist 1 professional 1 team.
 *
 * @param int $user_id User ID.
 * @return array<int, array{key:string,icon:string,class_name:string,title:string}>
 */
function ec_get_user_badges( $user_id ) {
	$user_id = (int) $user_id;

	if ( $user_id <= 0 ) {
		return array();
	}

	$badges = array();

	if ( get_user_meta( $user_id, 'user_is_artist', true ) == '1' ) {
		$badges[] = array(
			'key'        => 'artist',
			'icon'       => 'guitar',
			'class_name' => 'user-is-artist',
			'title'      => 'Artist',
		);
	}

	if ( get_user_meta( $user_id, 'user_is_professional', true ) == '1' ) {
		$badges[] = array(
			'key'        => 'professional',
			'icon'       => 'briefcase',
			'class_name' => 'user-is-professional',
			'title'      => 'Music Industry Professional',
		);
	}

	if ( function_exists( 'ec_is_team_member' ) && ec_is_team_member( $user_id ) ) {
		$badges[] = array(
			'key'        => 'team_member',
			'icon'       => 'igloo',
			'class_name' => 'extrachill-team-member',
			'title'      => 'Extra Chill Team Member',
		);
	}

	return $badges;
}
