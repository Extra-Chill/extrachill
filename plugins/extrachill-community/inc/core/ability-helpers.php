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

/**
 * Check whether the current viewer may read a forum and its ancestors.
 *
 * @param int $forum_id Forum post ID.
 * @return bool
 */
function extrachill_community_can_read_forum( $forum_id ) {
	if ( ! function_exists( 'bbp_get_forum_post_type' ) ) {
		return false;
	}

	$public_status  = bbp_get_public_status_id();
	$private_status = function_exists( 'bbp_get_private_status_id' ) ? bbp_get_private_status_id() : 'private';
	$hidden_status  = function_exists( 'bbp_get_hidden_status_id' ) ? bbp_get_hidden_status_id() : 'hidden';
	$seen           = array();

	while ( $forum_id && ! isset( $seen[ $forum_id ] ) ) {
		$seen[ $forum_id ] = true;
		$forum             = get_post( $forum_id );

		if ( ! $forum || bbp_get_forum_post_type() !== $forum->post_type ) {
			return false;
		}

		if ( $public_status !== $forum->post_status ) {
			if ( ! in_array( $forum->post_status, array( $private_status, $hidden_status ), true ) || ! current_user_can( 'read_forum', $forum->ID ) ) {
				return false;
			}
		}

		$forum_id = (int) $forum->post_parent;
	}

	return 0 === $forum_id;
}

/**
 * Return forum IDs readable by the current viewer.
 *
 * @return int[]
 */
function extrachill_community_get_readable_forum_ids() {
	if ( ! function_exists( 'bbp_get_forum_post_type' ) ) {
		return array();
	}

	$statuses = array( bbp_get_public_status_id() );
	if ( function_exists( 'bbp_get_private_status_id' ) ) {
		$statuses[] = bbp_get_private_status_id();
	}
	if ( function_exists( 'bbp_get_hidden_status_id' ) ) {
		$statuses[] = bbp_get_hidden_status_id();
	}

	$forums = get_posts(
		array(
			'post_type'      => bbp_get_forum_post_type(),
			'post_status'    => array_unique( $statuses ),
			'posts_per_page' => -1,
		)
	);

	$forum_ids = array();
	foreach ( $forums as $forum ) {
		if ( extrachill_community_can_read_forum( $forum->ID ) ) {
			$forum_ids[] = (int) $forum->ID;
		}
	}

	return $forum_ids;
}

/**
 * Check that a forum and every forum ancestor are public.
 *
 * @param int $forum_id Forum post ID.
 * @return bool
 */
function extrachill_community_is_public_forum( $forum_id ) {
	$seen = array();

	while ( $forum_id && ! isset( $seen[ $forum_id ] ) ) {
		$seen[ $forum_id ] = true;
		$forum             = get_post( $forum_id );

		if ( ! $forum || bbp_get_forum_post_type() !== $forum->post_type || bbp_get_public_status_id() !== $forum->post_status ) {
			return false;
		}

		$forum_id = (int) $forum->post_parent;
	}

	return 0 === $forum_id;
}
