<?php
/**
 * Forum Topic Migration REST API Endpoints
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'extrachill_api_register_forum_topics_routes' );

/**
 * Register forum topics REST routes.
 */
function extrachill_api_register_forum_topics_routes() {
	register_rest_route(
		'extrachill/v1',
		'/admin/forum-topics/forums',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_get_forums',
			'permission_callback' => 'extrachill_api_forum_topics_permission_check',
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/forum-topics',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_get_forum_topics',
			'permission_callback' => 'extrachill_api_forum_topics_permission_check',
			'args'                => array(
				'forum_id' => array(
					'default' => 0,
					'type'    => 'integer',
				),
				'search'   => array(
					'default' => '',
					'type'    => 'string',
				),
				'page'     => array(
					'default' => 1,
					'type'    => 'integer',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/forum-topics/move',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_move_forum_topics',
			'permission_callback' => 'extrachill_api_forum_topics_permission_check',
			'args'                => array(
				'topic_ids'            => array(
					'required' => true,
					'type'     => 'array',
				),
				'destination_forum_id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			),
		)
	);
}

/**
 * Permission check for forum topics endpoints.
 *
 * @return bool|WP_Error
 */
function extrachill_api_forum_topics_permission_check() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage forum topics.',
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Get all forums with topic counts.
 *
 * @return WP_REST_Response
 */
function extrachill_api_get_forums() {
	// Switch to community site.
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id ) {
		return new WP_Error( 'no_community_site', 'Community site not configured.', array( 'status' => 400 ) );
	}

	switch_to_blog( $community_blog_id );

	$all_forums = get_posts(
		array(
			'post_type'      => 'forum',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$forums = array();
	foreach ( $all_forums as $forum ) {
		$topic_count = absint( get_post_meta( $forum->ID, '_bbp_topic_count', true ) );

		$forums[] = array(
			'id'          => $forum->ID,
			'title'       => $forum->post_title,
			'topic_count' => $topic_count,
			'parent_id'   => $forum->post_parent,
		);
	}

	// Sort hierarchically.
	$forums = extrachill_api_sort_forums_hierarchically( $forums );

	restore_current_blog();

	return rest_ensure_response( array( 'forums' => $forums ) );
}

/**
 * Sort forums hierarchically with depth indicators.
 *
 * @param array $forums    Flat array of forums.
 * @param int   $parent_id Parent ID to start from.
 * @param int   $depth     Current depth level.
 * @return array Sorted forums with depth.
 */
function extrachill_api_sort_forums_hierarchically( $forums, $parent_id = 0, $depth = 0 ) {
	$sorted = array();

	foreach ( $forums as $forum ) {
		if ( (int) $forum['parent_id'] === (int) $parent_id ) {
			$forum['depth'] = $depth;
			$sorted[]       = $forum;

			$children = extrachill_api_sort_forums_hierarchically( $forums, $forum['id'], $depth + 1 );
			$sorted   = array_merge( $sorted, $children );
		}
	}

	return $sorted;
}

/**
 * Get forum topics with pagination.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function extrachill_api_get_forum_topics( $request ) {
	$forum_id = $request->get_param( 'forum_id' );
	$search   = $request->get_param( 'search' );
	$page     = $request->get_param( 'page' );
	$per_page = 25;

	// Switch to community site.
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id ) {
		return new WP_Error( 'no_community_site', 'Community site not configured.', array( 'status' => 400 ) );
	}

	switch_to_blog( $community_blog_id );

	$args = array(
		'post_type'      => 'topic',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'post_status'    => 'publish',
	);

	if ( $forum_id > 0 ) {
		$args['post_parent'] = $forum_id;
	}

	if ( ! empty( $search ) ) {
		$args['s'] = $search;
	}

	$query  = new WP_Query( $args );
	$topics = array();

	foreach ( $query->posts as $topic ) {
		$forum_id_meta = absint( get_post_meta( $topic->ID, '_bbp_forum_id', true ) );
		$forum_title   = $forum_id_meta ? get_the_title( $forum_id_meta ) : 'Unknown Forum';
		$reply_count   = absint( get_post_meta( $topic->ID, '_bbp_reply_count', true ) );
		$author        = get_user_by( 'ID', $topic->post_author );

		$topics[] = array(
			'id'          => $topic->ID,
			'title'       => $topic->post_title,
			'forum_id'    => $forum_id_meta ? $forum_id_meta : $topic->post_parent,
			'forum_title' => $forum_title,
			'author_id'   => $topic->post_author,
			'author_name' => $author ? $author->display_name : 'Unknown',
			'reply_count' => $reply_count,
			'date'        => $topic->post_date,
			'url'         => get_permalink( $topic->ID ),
		);
	}

	restore_current_blog();

	return rest_ensure_response(
		array(
			'topics' => $topics,
			'total'  => $query->found_posts,
			'pages'  => $query->max_num_pages,
		)
	);
}

/**
 * Move topics to a different forum.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_move_forum_topics( $request ) {
	$topic_ids            = $request->get_param( 'topic_ids' );
	$destination_forum_id = $request->get_param( 'destination_forum_id' );

	// Switch to community site.
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id ) {
		return new WP_Error( 'no_community_site', 'Community site not configured.', array( 'status' => 400 ) );
	}

	switch_to_blog( $community_blog_id );

	$dest_forum = get_post( $destination_forum_id );
	if ( ! $dest_forum || 'forum' !== $dest_forum->post_type ) {
		restore_current_blog();
		return new WP_Error( 'invalid_destination', 'Invalid destination forum.', array( 'status' => 400 ) );
	}

	$moved  = array();
	$failed = array();

	foreach ( $topic_ids as $topic_id ) {
		$topic_id = (int) $topic_id;
		$topic    = get_post( $topic_id );

		if ( ! $topic || 'topic' !== $topic->post_type ) {
			$failed[] = array(
				'id'    => $topic_id,
				'title' => 'Unknown',
				'error' => 'Invalid topic ID',
			);
			continue;
		}

		$source_forum_id = absint( get_post_meta( $topic_id, '_bbp_forum_id', true ) );
		if ( ! $source_forum_id ) {
			$source_forum_id = $topic->post_parent;
		}

		// Don't move if already in destination.
		if ( $source_forum_id === $destination_forum_id ) {
			$failed[] = array(
				'id'    => $topic_id,
				'title' => $topic->post_title,
				'error' => 'Topic is already in this forum',
			);
			continue;
		}

		// Update topic post_parent.
		wp_update_post(
			array(
				'ID'          => $topic_id,
				'post_parent' => $destination_forum_id,
			)
		);

		// Update topic _bbp_forum_id meta.
		update_post_meta( $topic_id, '_bbp_forum_id', $destination_forum_id );

		// Update all replies _bbp_forum_id meta.
		$replies = get_posts(
			array(
				'post_type'      => 'reply',
				'post_parent'    => $topic_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$reply_count = count( $replies );
		foreach ( $replies as $reply_id ) {
			update_post_meta( $reply_id, '_bbp_forum_id', $destination_forum_id );
		}

		// Update forum counts for source forum.
		if ( $source_forum_id ) {
			extrachill_api_update_forum_counts( $source_forum_id );
		}

		// Update forum counts for destination forum.
		extrachill_api_update_forum_counts( $destination_forum_id );

		$moved[] = array(
			'topic_id'     => $topic_id,
			'title'        => $topic->post_title,
			'reply_count'  => $reply_count,
			'source_forum' => $source_forum_id,
			'dest_forum'   => $destination_forum_id,
		);
	}

	restore_current_blog();

	return rest_ensure_response(
		array(
			'moved'   => $moved,
			'failed'  => $failed,
			'message' => sprintf(
				'Moved %d topic(s). %d failed.',
				count( $moved ),
				count( $failed )
			),
		)
	);
}

/**
 * Update forum topic count, reply count, and last active time.
 *
 * Replaces bbPress functions with direct WordPress queries for use in network admin context.
 *
 * @param int $forum_id The forum ID to update.
 */
function extrachill_api_update_forum_counts( $forum_id ) {
	if ( ! $forum_id ) {
		return;
	}

	// Count topics in this forum.
	$topic_count = (int) ( new WP_Query(
		array(
			'post_type'      => 'topic',
			'post_parent'    => $forum_id,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	) )->found_posts;

	update_post_meta( $forum_id, '_bbp_topic_count', $topic_count );

	// Count replies across all topics in this forum.
	$reply_count = 0;
	$topics      = get_posts(
		array(
			'post_type'      => 'topic',
			'post_parent'    => $forum_id,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $topics as $topic_id ) {
		$reply_count += (int) ( new WP_Query(
			array(
				'post_type'      => 'reply',
				'post_parent'    => $topic_id,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) )->found_posts;
	}

	update_post_meta( $forum_id, '_bbp_reply_count', $reply_count );

	// Update last active time from most recent topic or reply.
	$last_active = extrachill_api_get_forum_last_active_time( $forum_id );
	if ( $last_active ) {
		update_post_meta( $forum_id, '_bbp_last_active_time', $last_active );
	}
}

/**
 * Get the most recent activity time for a forum.
 *
 * @param int $forum_id The forum ID.
 * @return string|null MySQL datetime string or null if no activity.
 */
function extrachill_api_get_forum_last_active_time( $forum_id ) {
	// Get most recent topic.
	$latest_topic = get_posts(
		array(
			'post_type'      => 'topic',
			'post_parent'    => $forum_id,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		)
	);

	$latest_topic_time = $latest_topic ? strtotime( $latest_topic[0]->post_modified ) : 0;

	// Get most recent reply across all topics in this forum.
	$latest_reply_time = 0;
	$topics            = get_posts(
		array(
			'post_type'      => 'topic',
			'post_parent'    => $forum_id,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $topics ) ) {
		$latest_reply = get_posts(
			array(
				'post_type'      => 'reply',
				'post_parent__in' => $topics,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( $latest_reply ) {
			$latest_reply_time = strtotime( $latest_reply[0]->post_date );
		}
	}

	$latest_time = max( $latest_topic_time, $latest_reply_time );

	return $latest_time ? gmdate( 'Y-m-d H:i:s', $latest_time ) : null;
}
