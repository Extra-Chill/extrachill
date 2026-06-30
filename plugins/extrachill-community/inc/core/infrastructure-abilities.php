<?php
/**
 * Infrastructure Abilities
 *
 * Abilities-first primitives for core community infrastructure:
 * stats, forum management, and cache operations.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_infrastructure_abilities' );

/**
 * Register infrastructure abilities.
 */
function extrachill_community_register_infrastructure_abilities() {

	wp_register_ability(
		'extrachill/community-get-stats',
		array(
			'label'               => __( 'Get Community Stats', 'extra-chill-community' ),
			'description'         => __( 'Get overall community statistics: forums, topics, replies, users, upvotes.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'forums'        => array( 'type' => 'integer' ),
					'topics'        => array( 'type' => 'integer' ),
					'replies'       => array( 'type' => 'integer' ),
					'active_users'  => array( 'type' => 'integer' ),
					'total_upvotes' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_get_stats',
			'permission_callback' => '__return_true',
			'meta'                => array(
				// Exposed over REST so the cross-site NetworkStats loopback
				// (blog 1 → community blog) can reach the core Abilities run
				// route. Read-only aggregate counts rendered on the public
				// /power page — readable, not manage-gated. Mirrors the
				// `extrachill/get-network-stats` posture in extrachill-multisite.
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/community-list-forums',
		array(
			'label'               => __( 'List Forums', 'extra-chill-community' ),
			'description'         => __( 'List all forums with topic/reply counts and forum-archive visibility status.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'archive_only' => array(
						'type'        => 'boolean',
						'description' => 'Only return forums shown in the forum archive (/forums/) list',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'forums' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_list_forums',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/community-toggle-forum-archive',
		array(
			'label'               => __( 'Toggle Forum Archive Display', 'extra-chill-community' ),
			'description'         => __( 'Toggle whether a forum is shown in the forum archive (/forums/) list.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'forum_id' => array(
						'type'        => 'integer',
						'description' => 'Forum post ID',
					),
				),
				'required'   => array( 'forum_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'forum_id'        => array( 'type' => 'integer' ),
					'title'           => array( 'type' => 'string' ),
					'show_in_archive' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_toggle_forum_archive',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/community-flush-cache',
		array(
			'label'               => __( 'Flush Community Cache', 'extra-chill-community' ),
			'description'         => __( 'Flush all community transients, leaderboard cache, and edge caches.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'flushed' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_flush_cache',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callbacks ─────────────────────────────────────────────────────────

/**
 * Get overall community statistics.
 *
 * @param array $input Ability input.
 * @return array
 */
function extrachill_community_ability_get_stats() {
	global $wpdb;

	$forum_count = function_exists( 'bbp_get_forum_post_type' )
		? (int) wp_count_posts( bbp_get_forum_post_type() )->publish
		: 0;

	$topic_count = function_exists( 'bbp_get_topic_post_type' )
		? (int) wp_count_posts( bbp_get_topic_post_type() )->publish
		: 0;

	$reply_count = function_exists( 'bbp_get_reply_post_type' )
		? (int) wp_count_posts( bbp_get_reply_post_type() )->publish
		: 0;

	$user_count = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = 'extrachill_total_points'"
	);

	$total_upvotes = (int) $wpdb->get_var(
		"SELECT COALESCE(SUM(meta_value), 0) FROM {$wpdb->postmeta} WHERE meta_key = 'upvote_count'"
	);

	return array(
		'forums'        => $forum_count,
		'topics'        => $topic_count,
		'replies'       => $reply_count,
		'active_users'  => $user_count,
		'total_upvotes' => $total_upvotes,
	);
}

/**
 * List all forums.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_list_forums( $input ) {
	if ( ! function_exists( 'bbp_get_forum_post_type' ) ) {
		return new WP_Error( 'bbpress_unavailable', 'bbPress is not active.' );
	}

	$archive_only = ! empty( $input['archive_only'] );

	$args = array(
		'post_type'      => bbp_get_forum_post_type(),
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
	);

	if ( $archive_only ) {
		// Stored key is still `_show_on_homepage` (legacy name; rename tracked in #137).
		$args['meta_query'] = array(
			array(
				'key'   => '_show_on_homepage',
				'value' => '1',
			),
		);
	}

	$forums = get_posts( $args );
	$result = array();

	foreach ( $forums as $forum ) {
		$result[] = array(
			'forum_id'         => (int) $forum->ID,
			'title'            => $forum->post_title,
			'parent_id'        => (int) $forum->post_parent,
			'topic_count'      => function_exists( 'bbp_get_forum_topic_count' ) ? (int) bbp_get_forum_topic_count( $forum->ID ) : 0,
			'reply_count'      => function_exists( 'bbp_get_forum_reply_count' ) ? (int) bbp_get_forum_reply_count( $forum->ID ) : 0,
			'show_in_archive'  => (bool) get_post_meta( $forum->ID, '_show_on_homepage', true ),
			'url'              => function_exists( 'bbp_get_forum_permalink' ) ? bbp_get_forum_permalink( $forum->ID ) : get_permalink( $forum->ID ),
		);
	}

	return array( 'forums' => $result );
}

/**
 * Toggle forum-archive visibility for a forum.
 *
 * Controls whether the forum appears in the forum archive (/forums/) list,
 * rendered by bbpress/loop-forums.php. The stored meta key is still
 * `_show_on_homepage` (legacy name; rename tracked in #137).
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_toggle_forum_archive( $input ) {
	$forum_id = isset( $input['forum_id'] ) ? (int) $input['forum_id'] : 0;
	if ( ! $forum_id ) {
		return new WP_Error( 'missing_forum_id', 'A forum_id is required.' );
	}

	if ( ! function_exists( 'bbp_get_forum_post_type' ) ) {
		return new WP_Error( 'bbpress_unavailable', 'bbPress is not active.' );
	}

	$post = get_post( $forum_id );
	if ( ! $post || bbp_get_forum_post_type() !== $post->post_type ) {
		return new WP_Error( 'not_a_forum', 'Post ID is not a valid forum.' );
	}

	$current = get_post_meta( $forum_id, '_show_on_homepage', true );

	if ( $current ) {
		delete_post_meta( $forum_id, '_show_on_homepage' );
		$new_state = false;
	} else {
		update_post_meta( $forum_id, '_show_on_homepage', '1' );
		$new_state = true;
	}

	return array(
		'forum_id'        => $forum_id,
		'title'           => $post->post_title,
		'show_in_archive' => $new_state,
	);
}

/**
 * Flush all community caches.
 *
 * @param array $input Ability input.
 * @return array
 */
function extrachill_community_ability_flush_cache() {
	if ( function_exists( 'extrachill_delete_leaderboard_cache' ) ) {
		extrachill_delete_leaderboard_cache();
	}

	if ( function_exists( 'breeze_purge_cache' ) ) {
		breeze_purge_cache();
	}

	return array( 'flushed' => true );
}
