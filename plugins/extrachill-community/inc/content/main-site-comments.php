<?php
/**
 * Main Site Comments Integration
 *
 * Cross-domain comment display using WordPress multisite functions.
 * Uses direct blog ID numbers for maximum performance.
 *
 * @package ExtraChillCommunity
 * @subpackage Content
 */

/**
 * Display main site comments for a specific user
 *
 * @param int $community_user_id User ID
 * @return string HTML markup or error message
 */
function display_main_site_comments_for_user($community_user_id) {
	$community_user_id = (int) $community_user_id;
	if ( $community_user_id <= 0 ) {
		return '<div class="bbpress-comments-error">Invalid user ID provided.</div>';
	}

	$user_info     = get_userdata($community_user_id);
	$user_nicename = $user_info ? $user_info->user_nicename : 'Unknown User';

	$main_blog_id = ec_get_blog_id( 'main' );
	if ( ! $main_blog_id ) {
		return '<div class="bbpress-comments-error">Main site not configured.</div>';
	}

	switch_to_blog( $main_blog_id );

	try {
		$user_comments = get_comments(array(
			'user_id' => $community_user_id,
			'status'  => 'approve',
			'order'   => 'DESC',
			'orderby' => 'comment_date_gmt',
		));

		$comments = array();
		if ( is_array($user_comments) ) {
			foreach ( $user_comments as $comment ) {
				if ( ! $comment instanceof WP_Comment ) {
					continue;
				}
				$post = get_post( (int) $comment->comment_post_ID );
				if ( $post ) {
					$comments[] = array(
						'comment_ID'       => $comment->comment_ID,
						'post_permalink'   => get_permalink($post->ID),
						'post_title'       => $post->post_title,
						'comment_date_gmt' => $comment->comment_date_gmt,
						'comment_content'  => $comment->comment_content,
						'author_id'        => $comment->user_id,
						'author_name'      => get_the_author_meta( 'display_name', (int) $comment->user_id ),
						'author_avatar'    => get_avatar_url($comment->user_id, array( 'size' => 80 )),
						'upvote_count'     => 0,
					);
				}
			}
		}
	} finally {
		restore_current_blog();
	}

	if ( empty($comments) ) {
		return '<div class="bbpress-comments-list"><h3>Comments Feed for <span class="comments-feed-user">' . esc_html($user_nicename) . '</span></h3><p>No comments found for this user.</p></div>';
	}

	ob_start();
	?>
	<div class="bbpress-comments-list">
		<h3>Comments Feed for <span class="comments-feed-user"><?php echo esc_html($user_nicename); ?></span></h3>
		<?php
		foreach ( $comments as $comment ) {
			set_query_var('comment_data', $comment);
			set_query_var('is_main_site_comment', true);

			locate_template('bbpress/loop-single-blog-comment-card.php', true, false);
		}
		?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Render the /blog-comments feed body.
 *
 * An explicit user_id shows that user's main-site comments. Without one, a
 * logged-in visitor sees their own comments and a logged-out visitor gets a
 * prompt to log in (returning to the feed afterwards).
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
		return display_main_site_comments_for_user( $user_id );
	}

	$login_url = untrailingslashit( ec_get_site_url( 'community' ) ) . '/login';
	if ( '' !== $feed_url ) {
		$login_url = add_query_arg( 'redirect_to', rawurlencode( $feed_url ), $login_url );
	}

	return sprintf(
		'<div class="bbpress-comments-list"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
		esc_html__( 'Log in to see the comments you have left on Extra Chill articles.', 'extrachill-community' ),
		esc_url( $login_url ),
		esc_html__( 'Log in', 'extrachill-community' )
	);
}

/**
 * Get comment count for a user from the main site
 *
 * @param int $user_id User ID
 * @return int Comment count
 */
function get_user_main_site_comment_count($user_id) {
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
		$count = get_comments(array(
			'user_id' => $user_id,
			'count'   => true,
			'status'  => 'approve',
		));
	} finally {
		restore_current_blog();
	}

	return intval($count);
}

/**
 * Add user_id to allowed query vars
 *
 * @param array $vars Existing query vars
 * @return array Modified query vars
 */
function extrachill_add_query_vars_filter($vars) {
	$vars[] = 'user_id';
	return $vars;
}
add_filter('query_vars', 'extrachill_add_query_vars_filter');
