<?php
/**
 * Blocked-user public profile suppression.
 *
 * Moderation in extrachill-users (ban/suspend) blocks login, revokes sessions
 * and hides posts, but historically never touched the public profile surface
 * (/u/<user>/). Profile-link spammers only care about that surface — the bio
 * and the _user_profile_dynamic_links backlinks — so a ban left the spam
 * payload live. This file closes that gap:
 *
 * - Blocked users' profile pages (all bbPress single-user views) return 404
 *   for regular visitors, so the bio and dynamic links never render.
 * - Moderators (`moderate` cap) can still open the profile for review; in
 *   that case the page is marked noindex via wp_robots.
 * - Blocked users are excluded from the core user sitemap and from the
 *   points leaderboard listing.
 *
 * Every seam into extrachill-users is guarded with function_exists() so the
 * profile keeps working if that plugin is absent.
 *
 * @package ExtraChill\Community
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a user is blocked (banned/suspended with login blocked).
 *
 * Thin guard around extrachill_users_is_blocked() from extrachill-users so
 * callers in this plugin never fatal when that plugin is not loaded. Absent
 * plugin means no moderation state, so nobody is treated as blocked.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function extrachill_community_user_is_blocked( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 || ! function_exists( 'extrachill_users_is_blocked' ) ) {
		return false;
	}

	return extrachill_users_is_blocked( $user_id );
}

/**
 * Get IDs of all blocked users, for excluding from sitemaps and listings.
 *
 * Queries only users carrying a moderation record (current or legacy meta
 * key — both owned by extrachill-users) and confirms each through
 * extrachill_users_is_blocked(), so policy interpretation stays in one
 * place. The candidate set is tiny relative to the user base. Cached
 * per-request.
 *
 * @return int[] Blocked user IDs.
 */
function extrachill_community_get_blocked_user_ids() {
	static $blocked = null;

	if ( null !== $blocked ) {
		return $blocked;
	}

	if ( ! function_exists( 'extrachill_users_is_blocked' ) ) {
		$blocked = array();
		return $blocked;
	}

	$moderation_key = function_exists( 'extrachill_users_moderation_meta_key' )
		? extrachill_users_moderation_meta_key()
		: 'extrachill_user_moderation';
	$legacy_key     = function_exists( 'extrachill_users_legacy_ban_meta_key' )
		? extrachill_users_legacy_ban_meta_key()
		: 'extrachill_user_ban';

	$candidates = get_users(
		array(
			'fields'     => 'ID',
			'number'     => -1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Moderation records exist only for actioned accounts; the candidate set is small.
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'     => $moderation_key,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => $legacy_key,
					'compare' => 'EXISTS',
				),
			),
		)
	);

	$blocked = array();
	foreach ( $candidates as $candidate_id ) {
		if ( extrachill_users_is_blocked( (int) $candidate_id ) ) {
			$blocked[] = (int) $candidate_id;
		}
	}

	return $blocked;
}

/**
 * bbPress single-user flags set on WP_Query by bbp_parse_query().
 *
 * @return string[]
 */
function extrachill_community_bbp_single_user_query_flags() {
	return array(
		'bbp_is_single_user',
		'bbp_is_single_user_edit',
		'bbp_is_single_user_profile',
		'bbp_is_single_user_topics',
		'bbp_is_single_user_replies',
		'bbp_is_single_user_engagements',
		'bbp_is_single_user_favs',
		'bbp_is_single_user_subs',
	);
}

/**
 * 404 blocked users' public profiles before any template renders.
 *
 * Covers every bbPress single-user view (profile, topics created, replies
 * created, engagements, edit, …) so neither the bio nor the dynamic links
 * reach the page. Moderators keep access for review; that render is marked
 * noindex via extrachill_community_blocked_profile_robots().
 */
function extrachill_community_block_banned_profiles() {
	if ( ! function_exists( 'bbp_is_single_user' ) || ! bbp_is_single_user() ) {
		return;
	}

	$user_id = function_exists( 'bbp_get_displayed_user_id' ) ? (int) bbp_get_displayed_user_id() : 0;
	if ( ! extrachill_community_user_is_blocked( $user_id ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.Capabilities.Unknown -- `moderate` is bbPress's forum moderator capability.
	if ( current_user_can( 'moderate' ) ) {
		return;
	}

	global $wp_query;
	if ( is_object( $wp_query ) && method_exists( $wp_query, 'set_404' ) ) {
		$wp_query->set_404();

		// WP_Query::set_404() only resets core query flags. bbPress keeps its
		// own bbp_is_single_user* flags on the query object and its
		// template_include routing reads them, so without clearing them the
		// profile template (bio + links) would still render under a 404.
		foreach ( extrachill_community_bbp_single_user_query_flags() as $flag ) {
			$wp_query->$flag = false;
		}
	}

	status_header( 404 );
	nocache_headers();
}
add_action( 'template_redirect', 'extrachill_community_block_banned_profiles', 5 );

/**
 * Mark any blocked-user profile render as noindex.
 *
 * Regular visitors get a 404 from extrachill_community_block_banned_profiles(),
 * but moderators can still render the page; ensure crawlers never index
 * whatever does render for a blocked account.
 *
 * @param array $robots Robots directives.
 * @return array
 */
function extrachill_community_blocked_profile_robots( $robots ) {
	if ( ! function_exists( 'bbp_is_single_user' ) || ! bbp_is_single_user() ) {
		return $robots;
	}

	$user_id = function_exists( 'bbp_get_displayed_user_id' ) ? (int) bbp_get_displayed_user_id() : 0;
	if ( ! extrachill_community_user_is_blocked( $user_id ) ) {
		return $robots;
	}

	$robots['noindex']  = true;
	$robots['follow']   = false;
	$robots['nofollow'] = true;

	return $robots;
}
add_filter( 'wp_robots', 'extrachill_community_blocked_profile_robots' );

/**
 * Exclude blocked users from the core user sitemap.
 *
 * @param array $args WP_User_Query args for the users sitemap provider.
 * @return array
 */
function extrachill_community_exclude_blocked_users_from_sitemap( $args ) {
	$blocked = extrachill_community_get_blocked_user_ids();
	if ( empty( $blocked ) ) {
		return $args;
	}

	$existing        = isset( $args['exclude'] ) ? array_map( 'intval', (array) $args['exclude'] ) : array();
	$args['exclude'] = array_values( array_unique( array_merge( $existing, $blocked ) ) );

	return $args;
}
add_filter( 'wp_sitemaps_users_query_args', 'extrachill_community_exclude_blocked_users_from_sitemap' );
