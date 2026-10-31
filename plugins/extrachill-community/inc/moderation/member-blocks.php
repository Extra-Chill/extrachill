<?php
/**
 * Member-to-member blocks.
 *
 * A member can block another member so that the blocked member's topics and
 * replies are hidden from the blocking member's community views and read
 * abilities. This is a private, self-scoped preference stored in network user
 * meta; nothing here is ever exposed to the blocked member.
 *
 * Not to be confused with moderation bans ("blocked" users in
 * inc/user-profiles/blocked-profile.php), which are platform-level
 * suspensions owned by extrachill-users. Everything in this file uses the
 * "member block" naming to keep the two concepts apart.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/** User meta key holding the member's own block list (array of user IDs). */
const EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_META = 'ec_member_blocked_user_ids';

/** Maximum number of members one member may block. */
const EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_MAX = 500;

/**
 * Normalize a raw block list into unique positive integer IDs.
 *
 * @param mixed $ids Raw stored value.
 * @return int[]
 */
function extrachill_community_normalize_member_block_ids( $ids ) {
	if ( ! is_array( $ids ) ) {
		return array();
	}

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	$ids = array_values(
		array_filter(
			$ids,
			static function ( $id ) {
				return $id > 0;
			}
		)
	);

	return array_slice( $ids, 0, EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_MAX );
}

/**
 * Get the member IDs a user has blocked.
 *
 * @param int $user_id Blocking user ID.
 * @return int[]
 */
function extrachill_community_get_member_blocked_ids( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
		return array();
	}

	return extrachill_community_normalize_member_block_ids( get_user_meta( $user_id, EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_META, true ) );
}

/**
 * Persist a user's block list.
 *
 * @param int   $user_id Blocking user ID.
 * @param int[] $ids     Blocked member IDs.
 * @return int[] Stored IDs.
 */
function extrachill_community_set_member_blocked_ids( $user_id, array $ids ) {
	$ids = extrachill_community_normalize_member_block_ids( $ids );

	if ( empty( $ids ) ) {
		delete_user_meta( (int) $user_id, EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_META );
	} else {
		update_user_meta( (int) $user_id, EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_META, $ids );
	}

	return $ids;
}

/**
 * Whether one member has blocked another.
 *
 * @param int $user_id   Blocking user ID.
 * @param int $author_id Possibly blocked member ID.
 * @return bool
 */
function extrachill_community_member_has_blocked( $user_id, $author_id ) {
	$author_id = (int) $author_id;
	return $author_id > 0 && in_array( $author_id, extrachill_community_get_member_blocked_ids( $user_id ), true );
}

/**
 * Member IDs blocked by the current viewer (empty for anonymous viewers).
 *
 * @return int[]
 */
function extrachill_community_viewer_member_blocked_ids() {
	if ( ! function_exists( 'get_current_user_id' ) ) {
		return array();
	}

	return extrachill_community_get_member_blocked_ids( get_current_user_id() );
}

/**
 * Exclude the current viewer's blocked members from a post query.
 *
 * Central filter used by every community read path (abilities, bbPress
 * loops, activity feeds). Merges with any existing author__not_in.
 *
 * @param array $args WP_Query / bbPress query args.
 * @return array
 */
function extrachill_community_exclude_member_blocked_authors( array $args ) {
	$blocked = extrachill_community_viewer_member_blocked_ids();
	if ( empty( $blocked ) ) {
		return $args;
	}

	$existing               = isset( $args['author__not_in'] ) ? array_map( 'intval', (array) $args['author__not_in'] ) : array();
	$args['author__not_in'] = array_values( array_unique( array_merge( $existing, $blocked ) ) );

	return $args;
}

/**
 * Hide blocked members' topics from bbPress topic loops.
 *
 * @param array $args bbp_has_topics() parsed args.
 * @return array
 */
function extrachill_community_member_blocks_filter_topics( $args ) {
	return extrachill_community_exclude_member_blocked_authors( (array) $args );
}
add_filter( 'bbp_after_has_topics_parse_args', 'extrachill_community_member_blocks_filter_topics' );

/**
 * Hide blocked members' replies from bbPress reply loops.
 *
 * bbp_has_replies() also returns the lead topic post on single-topic views,
 * and a member who deliberately opens a topic should still see it, so this
 * excludes only reply-type posts by blocked authors (via posts_where) rather
 * than using author__not_in, which would also drop the lead topic.
 *
 * @param array $args bbp_has_replies() parsed args.
 * @return array
 */
function extrachill_community_member_blocks_filter_replies( $args ) {
	$blocked = extrachill_community_viewer_member_blocked_ids();
	if ( ! empty( $blocked ) ) {
		$args                                    = (array) $args;
		$args['ec_member_blocked_reply_authors'] = $blocked;
	}

	return $args;
}
add_filter( 'bbp_after_has_replies_parse_args', 'extrachill_community_member_blocks_filter_replies' );

/**
 * Apply the reply-author exclusion requested by the bbPress reply loop.
 *
 * @param string $where SQL WHERE clause.
 * @param mixed  $query Query being run (filter argument; validated before use).
 * @return string
 */
function extrachill_community_member_blocks_posts_where( $where, $query ) {
	if ( ! $query instanceof WP_Query || ! function_exists( 'bbp_get_reply_post_type' ) ) {
		return $where;
	}

	$blocked = extrachill_community_normalize_member_block_ids( $query->get( 'ec_member_blocked_reply_authors' ) );
	if ( empty( $blocked ) ) {
		return $where;
	}

	global $wpdb;
	$placeholders = implode( ', ', array_fill( 0, count( $blocked ), '%d' ) );

	// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list is built from a fixed '%d' token.
	$where .= $wpdb->prepare( " AND NOT ( {$wpdb->posts}.post_type = %s AND {$wpdb->posts}.post_author IN ( {$placeholders} ) )", array_merge( array( bbp_get_reply_post_type() ), $blocked ) );

	return $where;
}
add_filter( 'posts_where', 'extrachill_community_member_blocks_posts_where', 10, 2 );
