<?php
/**
 * Existing member ad-benefit integration.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translate the current user's existing ad-free benefit into policy language.
 *
 * @param string|null $reason Existing exclusion reason.
 * @return string|null
 */
function extrachill_member_ad_policy_exclusion( $reason ) {
	if ( null !== $reason ) {
		return $reason;
	}

	if ( function_exists( 'is_user_lifetime_member' ) && is_user_lifetime_member() ) {
		return 'member_benefit';
	}

	return null;
}
add_filter( 'extrachill_ad_policy_exclusion', 'extrachill_member_ad_policy_exclusion' );
