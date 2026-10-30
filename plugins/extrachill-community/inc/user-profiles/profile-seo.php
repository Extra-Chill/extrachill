<?php
/**
 * Profile SEO hardening.
 *
 * Community profiles were being used as free followed-backlink pages: SEO
 * spammers register, fill in the bio + profile links, and never participate.
 * Two defenses:
 *
 * 1. User-supplied profile links render with `rel="nofollow ugc noopener"`
 *    so they never pass link equity (`ugc` is Google's user-generated-content
 *    link attribute).
 * 2. Profiles with no community participation (no topics and no replies) are
 *    `noindex` via the `wp_robots` filter, so an empty shell profile can't
 *    work as an indexable backlink page while it stays visible to members.
 *
 * @package ExtraChill\Community
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rel attribute value for user-supplied profile links.
 *
 * @return string
 */
function extrachill_community_profile_link_rel() {
	return 'nofollow ugc noopener';
}

/**
 * Whether a user has participated in the community (topics or replies).
 *
 * @param int $user_id User ID.
 * @return bool True when the user has at least one topic or reply.
 */
function extrachill_community_user_has_participation( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return false;
	}

	$topic_count = function_exists( 'bbp_get_user_topic_count' ) ? (int) bbp_get_user_topic_count( $user_id ) : 0;
	if ( $topic_count > 0 ) {
		return true;
	}

	$reply_count = function_exists( 'bbp_get_user_reply_count' ) ? (int) bbp_get_user_reply_count( $user_id ) : 0;

	return $reply_count > 0;
}

/**
 * Noindex profile pages for users with no community participation.
 *
 * @param array $robots Robots directives from wp_robots.
 * @return array Filtered directives.
 */
function extrachill_community_profile_robots( $robots ) {
	if ( ! function_exists( 'bbp_is_single_user' ) || ! bbp_is_single_user() ) {
		return $robots;
	}

	$user_id = function_exists( 'bbp_get_displayed_user_id' ) ? (int) bbp_get_displayed_user_id() : 0;
	if ( ! $user_id ) {
		return $robots;
	}

	if ( ! extrachill_community_user_has_participation( $user_id ) ) {
		// Only add noindex. Leave follow/nofollow to core and to stricter
		// filters (e.g. blocked-account profiles) so directives never conflict.
		$robots['noindex'] = true;
	}

	return $robots;
}
add_filter( 'wp_robots', 'extrachill_community_profile_robots' );
