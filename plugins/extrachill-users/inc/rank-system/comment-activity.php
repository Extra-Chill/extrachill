<?php
/**
 * Comments as network activity — rank points + dated contribution events.
 *
 * Approved WordPress comments on the network's discussion-hosting content
 * sites (main articles and events, resolved through ec_get_blog_id()) are
 * network activity, alongside forum posts, upvotes, and concert attendance.
 * This network-level source is owned by the engine in extrachill-users,
 * like main-site posts, because comments live on several sites and no single
 * feature plugin owns them.
 *
 *   - SCALAR points via `ec_points_sources` (`comments`, 1 point per approved
 *     comment by default, filterable through
 *     `ec_users_comment_rank_points_per_comment`).
 *   - DATED events via `ec_contribution_events` (`comment`, bucketed from
 *     the UTC `comment_date_gmt` column by the shared helper).
 *
 * The hosting site list is filterable through `ec_comment_activity_blog_ids`.
 * The counts feed the rank engine only. They are not shown as status and do
 * not rank anyone.
 *
 * Comment tables are read through their blog prefix, so the per-user reads
 * never call switch_to_blog().
 *
 * The same site list feeds the network-wide recent comment activity reader
 * (ec_users_get_recent_comment_activity()), which the community activity
 * widget and the `extrachill/community-recent-public-activity` ability merge
 * chronologically with forum topics and replies.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default rank points awarded per approved comment.
 */
const EC_USERS_COMMENT_RANK_POINTS_PER_COMMENT = 1;

/**
 * Comment types that count as discussion. Pre-5.5 comments may still carry ''.
 */
const EC_USERS_COMMENT_ACTIVITY_TYPES = array( 'comment', '' );

/**
 * Most recent comment activity items the network reader keeps.
 */
const EC_USERS_RECENT_COMMENT_ACTIVITY_MAX = 20;

/**
 * Global object-cache group for the network recent comment activity list.
 */
const EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_GROUP = 'ec_comment_activity';

/**
 * Object-cache key for the network recent comment activity list.
 */
const EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_KEY = 'recent_v1';

/**
 * Backstop TTL for the recent comment activity list. Status changes bust it.
 */
const EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_TTL = 10 * MINUTE_IN_SECONDS;

if ( function_exists( 'wp_cache_add_global_groups' ) ) {
	wp_cache_add_global_groups( array( EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_GROUP ) );
}

/**
 * Resolve the blog IDs whose comments count as network activity.
 *
 * Defaults to the main and events sites. Unknown keys, non-positive IDs,
 * duplicates, and IDs with no matching site are dropped.
 *
 * @return int[] Blog IDs, in order.
 */
function ec_users_get_comment_activity_blog_ids() {
	$defaults = array();
	if ( function_exists( 'ec_get_blog_id' ) ) {
		foreach ( array( 'main', 'events' ) as $key ) {
			$defaults[] = (int) ec_get_blog_id( $key );
		}
	}

	/**
	 * Filter the sites whose approved comments count as network activity.
	 *
	 * @param int[] $blog_ids Blog IDs. Defaults to the main and events sites.
	 */
	$blog_ids = (array) apply_filters( 'ec_comment_activity_blog_ids', $defaults );

	$clean = array();
	foreach ( $blog_ids as $blog_id ) {
		$blog_id = (int) $blog_id;
		if ( $blog_id <= 0 || in_array( $blog_id, $clean, true ) ) {
			continue;
		}
		if ( is_multisite() && ! get_site( $blog_id ) ) {
			continue;
		}
		$clean[] = $blog_id;
	}

	return $clean;
}

/**
 * Whether a blog hosts comments that count as network activity.
 *
 * @param int $blog_id Blog ID.
 * @return bool
 */
function ec_users_is_comment_activity_blog( $blog_id ) {
	return in_array( (int) $blog_id, ec_users_get_comment_activity_blog_ids(), true );
}

/**
 * Build the SQL WHERE clause shared by the comment activity readers.
 *
 * @param int $user_id WordPress user ID.
 * @return string Prepared SQL fragment.
 */
function ec_users_comment_activity_where( $user_id ) {
	global $wpdb;

	return $wpdb->prepare(
		'user_id = %d AND comment_approved = %s AND comment_type IN (%s, %s)',
		(int) $user_id,
		'1',
		EC_USERS_COMMENT_ACTIVITY_TYPES[0],
		EC_USERS_COMMENT_ACTIVITY_TYPES[1]
	);
}

/**
 * Count a user's approved comments across the comment activity sites.
 *
 * @param int $user_id WordPress user ID.
 * @return int
 */
function ec_users_get_user_comment_activity_count( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return 0;
	}

	$where = ec_users_comment_activity_where( $user_id );
	$total = 0;
	foreach ( ec_users_get_comment_activity_blog_ids() as $blog_id ) {
		$table = $wpdb->get_blog_prefix( $blog_id ) . 'comments';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table from get_blog_prefix(); $where is prepared above.
		$total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	return $total;
}

/**
 * Get the UTC timestamps of a user's approved comments across activity sites.
 *
 * @param int $user_id WordPress user ID.
 * @return string[] MySQL 'Y-m-d H:i:s' strings in UTC.
 */
function ec_users_get_user_comment_activity_timestamps( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return array();
	}

	$where      = ec_users_comment_activity_where( $user_id );
	$timestamps = array();
	foreach ( ec_users_get_comment_activity_blog_ids() as $blog_id ) {
		$table = $wpdb->get_blog_prefix( $blog_id ) . 'comments';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table from get_blog_prefix(); $where is prepared above.
		$rows = $wpdb->get_col( "SELECT comment_date_gmt FROM {$table} WHERE {$where}" );
		if ( is_array( $rows ) ) {
			$timestamps = array_merge( $timestamps, $rows );
		}
	}

	return $timestamps;
}

/**
 * Get the network's most recent approved comments, newest first.
 *
 * Reads every comment activity site, keeps approved discussion comments on
 * published, non-password-protected posts, and merges them chronologically.
 * The list is cached network-wide (global object-cache group) and busted
 * whenever a comment enters or leaves the approved state. It never ranks:
 * order is purely by comment date.
 *
 * Each item:
 *   - blog_id, comment_id, post_id (int)
 *   - post_type, post_title, post_url, comment_url (string)
 *   - user_id (int, 0 for guests), author_name (string)
 *   - date_gmt (string, MySQL UTC)
 *
 * @param int $limit Items to return (1-EC_USERS_RECENT_COMMENT_ACTIVITY_MAX).
 * @return array<int, array<string, mixed>>
 */
function ec_users_get_recent_comment_activity( $limit = 10 ) {
	$limit = max( 1, min( EC_USERS_RECENT_COMMENT_ACTIVITY_MAX, (int) $limit ) );

	$items = wp_cache_get( EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_KEY, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_GROUP );
	if ( ! is_array( $items ) ) {
		$items = ec_users_query_recent_comment_activity();
		wp_cache_set( EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_KEY, $items, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_GROUP, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_TTL );
	}

	return array_slice( $items, 0, $limit );
}

/**
 * Query the recent comment activity list without the cache.
 *
 * @return array<int, array<string, mixed>>
 */
function ec_users_query_recent_comment_activity() {
	$items = array();

	foreach ( ec_users_get_comment_activity_blog_ids() as $blog_id ) {
		$switched = get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			switch_to_blog( $blog_id );
		}

		try {
			$comments = get_comments(
				array(
					'status'      => 'approve',
					'type'        => 'comment',
					'post_status' => 'publish',
					'number'      => EC_USERS_RECENT_COMMENT_ACTIVITY_MAX,
					'orderby'     => 'comment_date_gmt',
					'order'       => 'DESC',
				)
			);

			foreach ( (array) $comments as $comment ) {
				$item = ec_users_prepare_recent_comment_activity_item( $comment, $blog_id );
				if ( null !== $item ) {
					$items[] = $item;
				}
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	usort(
		$items,
		static function ( $a, $b ) {
			$order = strcmp( (string) $b['date_gmt'], (string) $a['date_gmt'] );
			return 0 !== $order ? $order : ( (int) $b['comment_id'] <=> (int) $a['comment_id'] );
		}
	);

	return array_slice( $items, 0, EC_USERS_RECENT_COMMENT_ACTIVITY_MAX );
}

/**
 * Shape one recent comment activity item. Must run in the comment's blog.
 *
 * @param WP_Comment|mixed $comment Comment.
 * @param int              $blog_id Blog the comment lives on.
 * @return array<string, mixed>|null Item, or null when the comment is not public.
 */
function ec_users_prepare_recent_comment_activity_item( $comment, $blog_id ) {
	if ( ! $comment instanceof WP_Comment || '1' !== (string) $comment->comment_approved ) {
		return null;
	}

	if ( ! in_array( (string) $comment->comment_type, EC_USERS_COMMENT_ACTIVITY_TYPES, true ) ) {
		return null;
	}

	$post = get_post( (int) $comment->comment_post_ID );
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
		return null;
	}

	$post_url = (string) get_permalink( $post );
	if ( '' === $post_url ) {
		return null;
	}

	$user_id     = (int) $comment->user_id;
	$user        = $user_id > 0 ? get_userdata( $user_id ) : false;
	$author_name = $user ? (string) $user->display_name : (string) $comment->comment_author;

	return array(
		'blog_id'     => (int) $blog_id,
		'comment_id'  => (int) $comment->comment_ID,
		'post_id'     => (int) $post->ID,
		'post_type'   => (string) $post->post_type,
		'post_title'  => html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
		'post_url'    => $post_url,
		'comment_url' => $post_url . '#comment-' . (int) $comment->comment_ID,
		'user_id'     => $user ? $user_id : 0,
		'author_name' => $author_name,
		'date_gmt'    => (string) $comment->comment_date_gmt,
	);
}

/**
 * Drop the cached network recent comment activity list.
 */
function ec_users_flush_recent_comment_activity_cache() {
	wp_cache_delete( EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_KEY, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_GROUP );
}

/**
 * Register comment points on the `ec_points_sources` registry.
 *
 * @param array $sources Source-id => points map.
 * @param int   $user_id WordPress user ID being scored.
 * @return array
 */
function ec_users_comments_points_source( $sources, $user_id ) {
	$comments = ec_users_get_user_comment_activity_count( (int) $user_id );

	/**
	 * Filter the rank points awarded per approved comment.
	 *
	 * @param int $points_per_comment Default per-comment weight.
	 * @param int $user_id            WordPress user ID being scored.
	 */
	$per_comment = (int) apply_filters(
		'ec_users_comment_rank_points_per_comment',
		EC_USERS_COMMENT_RANK_POINTS_PER_COMMENT,
		(int) $user_id
	);

	$sources['comments'] = $comments * $per_comment;
	return $sources;
}
add_filter( 'ec_points_sources', 'ec_users_comments_points_source', 10, 2 );

/**
 * Contribute dated comment events to the contribution heatmap.
 *
 * @param array  $events    Running event list.
 * @param int    $user_id   WordPress user ID.
 * @param string $since_ymd Inclusive start date (YYYY-MM-DD), or '' for all.
 * @return array
 */
function ec_users_comments_contribution_events( $events, $user_id, $since_ymd ) {
	$timestamps = ec_users_get_user_comment_activity_timestamps( (int) $user_id );
	if ( empty( $timestamps ) ) {
		return $events;
	}

	return array_merge(
		$events,
		ec_bucket_utc_events_by_local_day( $timestamps, 'comment', (string) $since_ymd )
	);
}
add_filter( 'ec_contribution_events', 'ec_users_comments_contribution_events', 10, 3 );

/**
 * Bust a commenter's cached points and heatmap after a comment status change.
 *
 * The rank total and contribution calendar are cached as transients on the
 * community site, where profiles render them, so the bust runs there and on
 * the current site.
 *
 * @param WP_Comment|int|string $comment Comment object or ID.
 */
function ec_users_bust_comment_activity_caches( $comment ) {
	$comment = get_comment( $comment );
	if ( ! $comment instanceof WP_Comment ) {
		return;
	}

	$user_id = (int) $comment->user_id;
	if ( $user_id <= 0 || ! ec_users_is_comment_activity_blog( get_current_blog_id() ) ) {
		return;
	}

	$bust = static function () use ( $user_id ) {
		delete_transient( 'user_points_' . $user_id );
		delete_transient( 'ec_contrib_calendar_' . $user_id );
	};

	$bust();

	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'community' ) : 0;
	if ( $community_blog_id > 0 && get_current_blog_id() !== $community_blog_id && get_site( $community_blog_id ) ) {
		switch_to_blog( $community_blog_id );
		try {
			$bust();
		} finally {
			restore_current_blog();
		}
	}
}

/**
 * Bust caches when a comment enters or leaves the approved state.
 *
 * @param int|string $new_status New status.
 * @param int|string $old_status Old status.
 * @param WP_Comment $comment    Comment object.
 */
function ec_users_comment_activity_on_transition( $new_status, $old_status, $comment ) {
	if ( 'approved' === $new_status || 'approved' === $old_status ) {
		if ( ec_users_is_comment_activity_blog( get_current_blog_id() ) ) {
			ec_users_flush_recent_comment_activity_cache();
		}
		ec_users_bust_comment_activity_caches( $comment );
	}
}
add_action( 'transition_comment_status', 'ec_users_comment_activity_on_transition', 10, 3 );

/**
 * Bust caches when a new comment is inserted already approved.
 *
 * @param int        $comment_id Comment ID.
 * @param int|string $approved   1 when approved.
 */
function ec_users_comment_activity_on_post( $comment_id, $approved ) {
	if ( 1 === (int) $approved ) {
		if ( ec_users_is_comment_activity_blog( get_current_blog_id() ) ) {
			ec_users_flush_recent_comment_activity_cache();
		}
		ec_users_bust_comment_activity_caches( $comment_id );
	}
}
add_action( 'comment_post', 'ec_users_comment_activity_on_post', 10, 2 );
