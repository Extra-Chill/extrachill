<?php
/**
 * Network Comments Integration
 *
 * Shows a member's approved comments from every discussion-hosting content
 * site (main articles and events by default) on their community profile feed.
 * The hosting site list comes from extrachill-users
 * (`ec_users_get_comment_activity_blog_ids()`, filterable through
 * `ec_comment_activity_blog_ids`) so profiles, rank points, and the heatmap
 * always agree on which comments count. Blogs are resolved through
 * ec_get_blog_id(), never hardcoded.
 *
 * The legacy main-site function names are kept as thin wrappers.
 *
 * @package ExtraChillCommunity
 * @subpackage Content
 */

/**
 * Blog IDs whose comments appear on community profiles.
 *
 * Falls back to the main site alone when extrachill-users is unavailable.
 *
 * @return int[]
 */
function extrachill_community_get_comment_activity_blog_ids() {
	if ( function_exists( 'ec_users_get_comment_activity_blog_ids' ) ) {
		return ec_users_get_comment_activity_blog_ids();
	}

	$main_blog_id = (int) ec_get_blog_id( 'main' );
	return $main_blog_id > 0 ? array( $main_blog_id ) : array();
}

/**
 * Get a user's approved comments across the comment activity sites.
 *
 * Each item carries its blog ID and links back to its post or event and to
 * the comment anchor. Items are sorted newest first across all sites.
 *
 * @param int        $user_id  User ID.
 * @param int[]|null $blog_ids Optional blog IDs. Defaults to the activity sites.
 * @return array<int, array<string, mixed>>
 */
function extrachill_community_get_user_network_comments( $user_id, $blog_ids = null ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return array();
	}

	$blog_ids = null === $blog_ids ? extrachill_community_get_comment_activity_blog_ids() : array_map( 'intval', (array) $blog_ids );
	$comments = array();

	foreach ( $blog_ids as $blog_id ) {
		if ( $blog_id <= 0 ) {
			continue;
		}

		switch_to_blog( $blog_id );
		try {
			$user_comments = get_comments(
				array(
					'user_id' => $user_id,
					'status'  => 'approve',
					'type'    => 'comment',
					'order'   => 'DESC',
					'orderby' => 'comment_date_gmt',
				)
			);

			foreach ( (array) $user_comments as $comment ) {
				if ( ! $comment instanceof WP_Comment ) {
					continue;
				}
				$post = get_post( (int) $comment->comment_post_ID );
				if ( ! $post ) {
					continue;
				}
				$post_permalink = (string) get_permalink( $post->ID );
				$comments[]     = array(
					'blog_id'           => $blog_id,
					'comment_ID'        => $comment->comment_ID,
					'post_id'           => (int) $post->ID,
					'post_type'         => (string) $post->post_type,
					'post_permalink'    => $post_permalink,
					'comment_permalink' => $post_permalink . '#comment-' . $comment->comment_ID,
					'post_title'        => $post->post_title,
					'comment_date_gmt'  => $comment->comment_date_gmt,
					'comment_content'   => $comment->comment_content,
					'author_id'         => $comment->user_id,
					'author_name'       => get_the_author_meta( 'display_name', (int) $comment->user_id ),
					'author_avatar'     => get_avatar_url( $comment->user_id, array( 'size' => 80 ) ),
					'upvote_count'      => 0,
				);
			}
		} finally {
			restore_current_blog();
		}
	}

	usort(
		$comments,
		static function ( $a, $b ) {
			return strcmp( (string) $b['comment_date_gmt'], (string) $a['comment_date_gmt'] );
		}
	);

	return $comments;
}

/**
 * Display a user's comments from every comment activity site.
 *
 * @param int $community_user_id User ID.
 * @return string HTML markup or error message.
 */
function extrachill_community_display_network_comments_for_user( $community_user_id ) {
	$community_user_id = (int) $community_user_id;
	if ( $community_user_id <= 0 ) {
		return '<div class="bbpress-comments-error">Invalid user ID provided.</div>';
	}

	$user_info     = get_userdata( $community_user_id );
	$user_nicename = $user_info ? $user_info->user_nicename : 'Unknown User';

	if ( empty( extrachill_community_get_comment_activity_blog_ids() ) ) {
		return '<div class="bbpress-comments-error">Comment sites not configured.</div>';
	}

	$comments = extrachill_community_get_user_network_comments( $community_user_id );

	if ( empty( $comments ) ) {
		return '<div class="bbpress-comments-list"><h3>Comments Feed for <span class="comments-feed-user">' . esc_html( $user_nicename ) . '</span></h3><p>No comments found for this user.</p></div>';
	}

	ob_start();
	?>
	<div class="bbpress-comments-list">
		<h3>Comments Feed for <span class="comments-feed-user"><?php echo esc_html( $user_nicename ); ?></span></h3>
		<?php
		foreach ( $comments as $comment ) {
			set_query_var( 'comment_data', $comment );
			set_query_var( 'is_main_site_comment', true );

			locate_template( 'bbpress/loop-single-blog-comment-card.php', true, false );
		}
		?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Legacy wrapper: display a user's comments.
 *
 * Now lists comments from every comment activity site, not only the main site.
 *
 * @param int $community_user_id User ID.
 * @return string HTML markup or error message.
 */
function display_main_site_comments_for_user( $community_user_id ) {
	return extrachill_community_display_network_comments_for_user( $community_user_id );
}

/**
 * Render the /blog-comments feed body.
 *
 * An explicit user_id shows that user's comments. Without one, a logged-in
 * visitor sees their own comments and a logged-out visitor gets a prompt to
 * log in (returning to the feed afterwards).
 *
 * @param mixed  $requested_user_id User ID from the user_id query var (0 when absent).
 * @param string $feed_url          Optional. Feed URL to return to after login.
 * @return string HTML markup.
 */
function extrachill_community_render_blog_comments_feed( $requested_user_id, $feed_url = '' ) {
	$user_id = absint( $requested_user_id );

	if ( ! $user_id && is_user_logged_in() ) {
		$user_id = get_current_user_id();
	}

	if ( $user_id ) {
		return extrachill_community_display_network_comments_for_user( $user_id );
	}

	$login_url = untrailingslashit( ec_get_site_url( 'community' ) ) . '/login';
	if ( '' !== $feed_url ) {
		$login_url = add_query_arg( 'redirect_to', rawurlencode( $feed_url ), $login_url );
	}

	return sprintf(
		'<div class="bbpress-comments-list"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
		esc_html__( 'Log in to see the comments you have left on Extra Chill articles and events.', 'extra-chill-community' ),
		esc_url( $login_url ),
		esc_html__( 'Log in', 'extra-chill-community' )
	);
}

/**
 * Get comment count for a user from the main site.
 *
 * Main-site only on purpose: the profile meta line labels it "blog comments".
 *
 * @param int $user_id User ID.
 * @return int Comment count.
 */
function get_user_main_site_comment_count( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return 0;
	}

	$main_blog_id = ec_get_blog_id( 'main' );
	if ( ! $main_blog_id ) {
		return 0;
	}

	switch_to_blog( $main_blog_id );
	try {
		$count = get_comments(
			array(
				'user_id' => $user_id,
				'count'   => true,
				'status'  => 'approve',
			)
		);
	} finally {
		restore_current_blog();
	}

	return intval( $count );
}

/**
 * Add user_id to allowed query vars
 *
 * @param array $vars Existing query vars
 * @return array Modified query vars
 */
function extrachill_add_query_vars_filter( $vars ) {
	$vars[] = 'user_id';
	return $vars;
}
add_filter( 'query_vars', 'extrachill_add_query_vars_filter' );
