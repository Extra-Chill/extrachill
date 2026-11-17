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
 * (ec_users_get_recent_comment_activity()). It is registered as the
 * `comments` source on the community activity feed (`ec_activity_feed_sources`,
 * owned by extrachill_build_activity_feed() in extrachill-community), which
 * merges it chronologically with forum topics and replies for /recent, the
 * network sidebar widget, and the `extrachill/community-recent-public-activity`
 * ability.
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
const EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_KEY = 'recent_v2';

/**
 * Object-cache key for the unscoped network comment activity count.
 */
const EC_USERS_RECENT_COMMENT_ACTIVITY_COUNT_CACHE_KEY = 'recent_count_v1';

/**
 * Most items one uncached recent comment activity read returns (feed depth).
 */
const EC_USERS_COMMENT_ACTIVITY_QUERY_MAX = 1000;

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
 * The unscoped head of the list (up to EC_USERS_RECENT_COMMENT_ACTIVITY_MAX
 * items) is cached network-wide (global object-cache group) and busted
 * whenever a comment enters or leaves the approved state. Scoped reads (one
 * author, excluded authors) and deeper reads (pagination) query directly. It
 * never ranks: order is purely by comment date.
 *
 * Each item:
 *   - blog_id, comment_id, post_id (int)
 *   - post_type, post_title, post_url, comment_url (string)
 *   - user_id (int, 0 for guests), author_name (string)
 *   - date_gmt (string, MySQL UTC), content (string, raw comment text)
 *
 * @param int   $limit Items to return (1-EC_USERS_COMMENT_ACTIVITY_QUERY_MAX).
 * @param array $args {
 *     Optional. Scope.
 *
 *     @type int   $user_id          Only this member's comments. Default 0 (all).
 *     @type int[] $exclude_user_ids Drop these members' comments. Default none.
 * }
 * @return array<int, array<string, mixed>>
 */
function ec_users_get_recent_comment_activity( $limit = 10, $args = array() ) {
	$limit = max( 1, min( EC_USERS_COMMENT_ACTIVITY_QUERY_MAX, (int) $limit ) );

	if ( ec_users_is_scoped_comment_activity_query( ec_users_recent_comment_activity_query_args( $args ) ) || $limit > EC_USERS_RECENT_COMMENT_ACTIVITY_MAX ) {
		return ec_users_query_recent_comment_activity( $limit, $args );
	}

	$items = wp_cache_get( EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_KEY, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_GROUP );
	if ( ! is_array( $items ) ) {
		$items = ec_users_query_recent_comment_activity();
		wp_cache_set( EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_KEY, $items, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_GROUP, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_TTL );
	}

	return array_slice( $items, 0, $limit );
}

/**
 * Build the get_comments() arguments shared by the recent activity readers.
 *
 * @param array $args Scope, see ec_users_get_recent_comment_activity().
 * @return array
 */
function ec_users_recent_comment_activity_query_args( $args ) {
	$query   = array(
		'status'      => 'approve',
		'type'        => 'comment',
		'post_status' => 'publish',
	);
	$user_id = (int) ( $args['user_id'] ?? 0 );
	$exclude = array_values( array_filter( array_map( 'intval', (array) ( $args['exclude_user_ids'] ?? array() ) ) ) );

	if ( $user_id > 0 ) {
		$query['user_id'] = $user_id;
	}
	if ( $exclude ) {
		$query['author__not_in'] = $exclude;
	}

	return $query;
}

/**
 * Whether recent comment activity query arguments carry an author scope.
 *
 * Only unscoped reads share the network cache.
 *
 * @param array $query Arguments from ec_users_recent_comment_activity_query_args().
 * @return bool
 */
function ec_users_is_scoped_comment_activity_query( array $query ) {
	return isset( $query['user_id'] ) || isset( $query['author__not_in'] );
}

/**
 * Run get_comments() on one comment activity site.
 *
 * @param int   $blog_id Site to read.
 * @param array $query   get_comments() arguments.
 * @return array|int Comments, or a count when `count` is set.
 */
function ec_users_comment_activity_query_blog( $blog_id, array $query ) {
	$switched = get_current_blog_id() !== $blog_id;
	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	try {
		return get_comments( $query );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Query the recent comment activity list without the cache.
 *
 * Fetches up to `$limit` comments from each activity site, merges them, and
 * keeps the newest `$limit`.
 *
 * @param int   $limit Items to return. Default EC_USERS_RECENT_COMMENT_ACTIVITY_MAX.
 * @param array $args  Scope, see ec_users_get_recent_comment_activity().
 * @return array<int, array<string, mixed>>
 */
function ec_users_query_recent_comment_activity( $limit = EC_USERS_RECENT_COMMENT_ACTIVITY_MAX, $args = array() ) {
	$limit = max( 1, min( EC_USERS_COMMENT_ACTIVITY_QUERY_MAX, (int) $limit ) );
	$query = ec_users_recent_comment_activity_query_args( $args );
	$items = array();

	$query['number']  = $limit;
	$query['orderby'] = 'comment_date_gmt';
	$query['order']   = 'DESC';

	foreach ( ec_users_get_comment_activity_blog_ids() as $blog_id ) {
		foreach ( (array) ec_users_comment_activity_query_blog( $blog_id, $query ) as $comment ) {
			$item = ec_users_prepare_recent_comment_activity_item( $comment, $blog_id );
			if ( null !== $item ) {
				$items[] = $item;
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

	return array_slice( $items, 0, $limit );
}

/**
 * Count the comments the recent activity readers can return.
 *
 * Counts approved discussion comments on published posts across the activity
 * sites. Password-protected posts are only filtered when items are shaped, so
 * the count can run slightly high on sites with protected posts; it sizes
 * pagination, nothing else. The unscoped count is cached alongside the list.
 *
 * @param array $args Scope, see ec_users_get_recent_comment_activity().
 * @return int
 */
function ec_users_count_recent_comment_activity( $args = array() ) {
	$query    = ec_users_recent_comment_activity_query_args( $args );
	$unscoped = ! ec_users_is_scoped_comment_activity_query( $query );

	if ( $unscoped ) {
		$cached = wp_cache_get( EC_USERS_RECENT_COMMENT_ACTIVITY_COUNT_CACHE_KEY, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}
	}

	$query['count'] = true;
	$total          = 0;
	foreach ( ec_users_get_comment_activity_blog_ids() as $blog_id ) {
		$total += (int) ec_users_comment_activity_query_blog( $blog_id, $query );
	}

	if ( $unscoped ) {
		wp_cache_set( EC_USERS_RECENT_COMMENT_ACTIVITY_COUNT_CACHE_KEY, $total, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_GROUP, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_TTL );
	}

	return $total;
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
		'content'     => (string) $comment->comment_content,
	);
}

/**
 * Drop the cached network recent comment activity list.
 */
function ec_users_flush_recent_comment_activity_cache() {
	wp_cache_delete( EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_KEY, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_GROUP );
	wp_cache_delete( EC_USERS_RECENT_COMMENT_ACTIVITY_COUNT_CACHE_KEY, EC_USERS_RECENT_COMMENT_ACTIVITY_CACHE_GROUP );
}

/**
 * Register network comments as a source on the community activity feed.
 *
 * The feed builder (extrachill_build_activity_feed() in extrachill-community)
 * owns the `ec_activity_feed_sources` registry, the chronological merge, and
 * pagination. This source only answers "the newest N comments, and how many
 * exist" for the requested scope, through the reader above.
 *
 * @param array $sources Source-id => source definition.
 * @return array
 */
function ec_users_comment_activity_feed_source( $sources ) {
	$sources['comments'] = array(
		'types' => array( 'comment' ),
		'query' => 'ec_users_query_comment_activity_feed_source',
	);

	return $sources;
}
add_filter( 'ec_activity_feed_sources', 'ec_users_comment_activity_feed_source', 10 );

/**
 * Answer an activity feed source query with recent network comments.
 *
 * @param array $query {
 *     Feed source query from the builder.
 *
 *     @type int    $limit           Newest items wanted.
 *     @type int    $author          Only this member's comments (0 = all).
 *     @type int[]  $exclude_authors Members to drop (viewer blocks).
 *     @type string $artist          Artist scope. Comments carry no artist, so
 *                                   an artist-scoped query returns nothing.
 * }
 * @return array{items:array[],total:int}
 */
function ec_users_query_comment_activity_feed_source( array $query ) {
	if ( '' !== (string) ( $query['artist'] ?? '' ) ) {
		return array(
			'items' => array(),
			'total' => 0,
		);
	}

	$scope = array(
		'user_id'          => (int) ( $query['author'] ?? 0 ),
		'exclude_user_ids' => (array) ( $query['exclude_authors'] ?? array() ),
	);

	$items = array();
	foreach ( ec_users_get_recent_comment_activity( (int) ( $query['limit'] ?? 10 ), $scope ) as $comment ) {
		$timestamp = strtotime( $comment['date_gmt'] . ' UTC' );
		if ( false === $timestamp ) {
			continue;
		}
		$items[] = array(
			'type'      => 'comment',
			'timestamp' => $timestamp,
			'author_id' => (int) $comment['user_id'],
			'comment'   => $comment,
		);
	}

	return array(
		'items' => $items,
		'total' => ec_users_count_recent_comment_activity( $scope ),
	);
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
