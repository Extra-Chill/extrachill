<?php
/**
 * Community User Creation Filter
 *
 * Thin delegate — receives the extrachill_create_community_user filter and
 * executes the extrachill/create-user ability. All business logic lives in
 * the ability (inc/core/abilities/create-user.php).
 *
 * @package ExtraChill\Users
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'extrachill_create_community_user', 'ec_multisite_create_community_user', 10, 2 );

/**
 * Create user account via the extrachill/create-user ability.
 *
 * @param mixed $user_id          Default false, replaced with actual user_id.
 * @param array $registration_data Registration data from form or OAuth.
 * @return int|WP_Error User ID on success, WP_Error on failure.
 */
function ec_multisite_create_community_user( $user_id, $registration_data ) {
	$ability = wp_get_ability( 'extrachill/create-user' );

	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill/create-user ability is not registered.' );
	}

	return $ability->execute( $registration_data );
}
