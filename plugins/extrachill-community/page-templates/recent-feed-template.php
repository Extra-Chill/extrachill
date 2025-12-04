<?php
/*
 * Template Name: Recent Activity Feed
 * Description: A page template to show the most recent replies across all forums in a Twitter-like stream.
 */

get_header();
?>
<div class="page-content">
    <?php
    /**
     * Custom hook for inside site container.
     */
    do_action( 'extra_chill_inside_site_container' );
    ?>
    <div class="site-content">
        <div class="container">
            <?php
            /**
             * Custom hook for inside container.
             */
            do_action( 'extra_chill_inside_container' );
            ?>
        <?php extrachill_breadcrumbs(); ?>

<?php

// Check if we are on a user profile page
$isUserProfile = bbp_is_single_user();

if ($isUserProfile) {
    $title = '@' . bbp_get_displayed_user_field('user_nicename');
    echo '<h1 class="profile-title-inline">' . $title . '</h1>';
} else {
    echo '<h1>Recent Activity</h1>';
}

// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;

// Set up the query to fetch the most recent replies
$recent_feed = extrachill_get_recent_feed_query(15);

if ($recent_feed && !empty($recent_feed['items'])) {
    $feed_items  = $recent_feed['items'];
    $pagination  = $recent_feed['pagination'];
    $bbp         = bbpress();
    $previous_reply_id = isset($bbp->current_reply_id) ? $bbp->current_reply_id : 0;
    $previous_topic_id = isset($bbp->current_topic_id) ? $bbp->current_topic_id : 0;
    $previous_forum_id = isset($bbp->current_forum_id) ? $bbp->current_forum_id : 0;
    ?>
    <div id="bbpress-forums" class="bbpress-wrapper">
        <ul class="forums bbp-replies">
            <li class="bbp-body">
                <?php
                foreach ($feed_items as $feed_item) {
                    $post = $feed_item['post'];

                    if (!$post || !is_object($post)) {
                        continue;
                    }

                    setup_postdata($post);

                    // Set pre-fetched author data for template use
                    set_query_var('prefetch_author_id', $feed_item['author_id']);
                    set_query_var('prefetch_author_name', $feed_item['author_name']);
                    set_query_var('prefetch_author_avatar_url', $feed_item['author_avatar_url']);

                    // Set pre-fetched topic/forum data for template use
                    set_query_var('prefetch_topic_id', $feed_item['topic_id']);
                    set_query_var('prefetch_topic_url', $feed_item['topic_url']);
                    set_query_var('prefetch_topic_title', $feed_item['topic_title']);
                    set_query_var('prefetch_forum_id', $feed_item['forum_id']);
                    set_query_var('prefetch_forum_url', $feed_item['forum_url']);
                    set_query_var('prefetch_forum_title', $feed_item['forum_title']);

                    $bbp->current_reply_id = $post->ID;

                    if ( $post->post_type === bbp_get_topic_post_type() ) {
                        $topic_id = $post->ID;
                    } else {
                        $topic_id = (int) get_post_field( 'post_parent', $post->ID );
                        if ( empty( $topic_id ) ) {
                            $topic_id = (int) get_post_meta( $post->ID, '_bbp_topic_id', true );
                        }
                    }

                    $bbp->current_topic_id = $topic_id;
                    $bbp->current_forum_id = $topic_id ? bbp_get_topic_forum_id($topic_id) : 0;

                    bbp_get_template_part('loop', 'single-reply-card');

                    $bbp->current_reply_id = 0;
                    $bbp->current_topic_id = 0;
                    $bbp->current_forum_id = 0;

                    wp_reset_postdata();
                }

                wp_reset_postdata();
                ?>
            </li>
        </ul>
        <?php extrachill_pagination($pagination, 'bbpress'); ?>
    </div>
    <?php
    $bbp->current_reply_id = $previous_reply_id;
    $bbp->current_topic_id = $previous_topic_id;
    $bbp->current_forum_id = $previous_forum_id;
} else {
    echo '<div class="notice notice-info"><p>No recent activity found.</p></div>';
}

?>
        </div><!-- .container -->
    </div><!-- .site-content -->
</div><!-- .page-content -->
<?php
get_footer();
?>
