<?php
/**
 * Ability Helpers
 *
 * Shared utility functions used by community ability execute callbacks.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve user_id from ability input, falling back to current user.
 *
 * @param array $input Ability input array.
 * @return int User ID, or 0 if unresolvable.
 */
function extrachill_community_resolve_user_id( $input ) {
	$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : get_current_user_id();
	return $user_id > 0 ? $user_id : 0;
}

/**
 * Switch to community blog for cross-site operations.
 *
 * @return array{switched: bool, blog_id: int|null}
 */
function extrachill_community_switch_to_community_blog() {
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	$switched          = false;

	if ( $community_blog_id && get_current_blog_id() !== $community_blog_id ) {
		switch_to_blog( $community_blog_id );
		$switched = true;
	}

	return array(
		'switched' => $switched,
		'blog_id'  => $community_blog_id,
	);
}
