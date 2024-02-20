<?php
/*
 * Template Name: Following Feed
 * Description: A page template to show either topics or replies from followed users in BBPress format.
 */

get_header();

echo '<div id="chill-home">';
echo '<div id="chill-home-header"><span>';

// Check if we are on a user profile page
$isUserProfile = bbp_is_single_user();

if ($isUserProfile) {
    $title = '@' . bbp_get_displayed_user_field('user_nicename');
    echo '<h1 class="profile-title-inline">' . $title . '</h1>';

    // Display the follow button only on user profile pages
    if (function_exists('extrachill_follow_button')) {
        extrachill_follow_button(bbp_get_displayed_user_id());
    }
} else {
    // Display the title for non-profile pages
    echo '<h1>' . get_the_title() . '</h1>';
}

echo '</span>';

if (is_user_logged_in()) :
    echo '<p>Logged in as <a href="/user-dashboard">' . esc_html(wp_get_current_user()->display_name) . '.</a></p>';
else :
    echo '<p>You are not signed in. <a href="/login">Login</a> or <a href="/register">Register</a></p>';
endif;

echo '</div>'; // End of chill-home-header

// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;

echo '</div>'; // End of chill-home

// Set the default filter type and handle the user filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'replies'; // Default to showing replies
$post_type = ($filter === 'topics') ? 'topic' : 'reply';
$user_id_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

// Fetch the posts
$posts = extrachill_get_following_posts($post_type, $user_id_filter);
?>
<div id="bbpress-forums" class="bbpress-wrapper">
    <div class="following-feed">
                        <div class="following-feed-dropdowns">
            <!-- Filter for Replies/Topics -->
            <form method="get" class="bbp-topics-front-page">
                <select name="filter" id="filter" onchange="this.form.submit()">
                    <option value="replies" <?php selected($filter, 'replies'); ?>>Show Replies</option>
                    <option value="topics" <?php selected($filter, 'topics'); ?>>Show Topics</option>
                </select>
                <!-- User Filter Dropdown -->
                <select id="userFilter" name="user_id" onchange="this.form.submit()">
                    <option value="">Filter By User</option>
                    <?php foreach ($following_users as $user): ?>
                        <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($user_id_filter, $user->ID); ?>>
                            <?php echo esc_html($user->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
       <!-- Display Posts -->
        <?php if ($posts->have_posts()): ?>
            <ul id="bbp-forum-<?php echo $current_user_id; ?>" class="bbp-topics">
                <?php while ($posts->have_posts()): $posts->the_post(); 
                    switch ($post_type) {
                        case 'topic':
                        ?>
                                                <div id="topics-ec">
                            <ul id="bbp-topic-<?php bbp_topic_id(); ?>" <?php bbp_topic_class(); ?>>
                                <li class="bbp-topic-title">
                                    <?php
$user_id = get_current_user_id();
$topic_id = get_the_ID(); // using the core WP function
$upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
$icon_class = is_array($upvoted_posts) && in_array($topic_id, $upvoted_posts) ? 'fa-solid' : 'fa-regular';
?>
                                    <?php do_action('bbp_theme_before_topic_title'); ?>
    <div class="upvote"><span class="upvote-icon" data-post-id="<?php echo $topic_id; ?>" data-type="topic" data-nonce="<?php echo wp_create_nonce('upvote_nonce'); ?>" role="button" aria-label="Upvote this topic">
        <i class="<?php echo $icon_class; ?> fa-circle-up"></i>
    </span>
    <span class="upvote-count"><?php echo get_upvote_count($topic_id); ?></span> |</div>
                                    <a class="bbp-topic-permalink" href="<?php bbp_topic_permalink(); ?>"><?php bbp_topic_title(); ?></a>
                                    <?php do_action('bbp_theme_after_topic_title'); ?>
                                    <?php bbp_topic_pagination(); ?>
                                    <?php do_action('bbp_theme_before_topic_meta'); ?>
            <p class="bbp-topic-meta">
                <?php do_action('bbp_theme_before_topic_started_by'); ?>
<span class="bbp-topic-started-by">
    <?php 
    // Get the topic author ID using core WP function
    $topic_author_id = get_post_field('post_author', $post->ID);
    // Get the author's username
    $author_username = get_the_author_meta('user_nicename', $topic_author_id);
    // Construct the BBPress profile URL
    $author_bbpress_profile_url = site_url('/u/') . $author_username;

    // Display the link to the author's BBPress profile
    printf(esc_html__('Started by: %1$s', 'bbpress'), '<a href="' . esc_url($author_bbpress_profile_url) . '">' . esc_html(get_the_author_meta('display_name', $topic_author_id)) . '</a>');
    ?>
</span>
                <?php do_action('bbp_theme_after_topic_started_by'); ?>
                                        <?php if (!bbp_is_single_forum() || (bbp_get_topic_forum_id() !== bbp_get_forum_id())): ?>
                                            <?php do_action('bbp_theme_before_topic_started_in'); ?>
                                            <span class="bbp-topic-started-in"><?php printf(esc_html__('in: %1$s', 'bbpress'), '<a href="' . bbp_get_forum_permalink(bbp_get_topic_forum_id()) . '">' . bbp_get_forum_title(bbp_get_topic_forum_id()) . '</a>'); ?></span>
                                            <?php do_action('bbp_theme_after_topic_started_in'); ?>
                                        <?php endif; ?>
                                    </p>
                                    <?php do_action('bbp_theme_after_topic_meta'); ?>
                                    <?php bbp_topic_row_actions(); ?>
                                </li>
                                <li class="bbp-topic-voice-count"><?php bbp_topic_voice_count(); ?></li>
                                <li class="bbp-topic-reply-count"><?php bbp_show_lead_topic() ? bbp_topic_reply_count() : bbp_topic_post_count(); ?></li>
                                <li class="bbp-topic-freshness">
                                    <?php do_action('bbp_theme_before_topic_freshness_link'); ?>
                                    <?php bbp_topic_freshness_link(); ?>
                                    <?php do_action('bbp_theme_after_topic_freshness_link'); ?>
                                    <p class="bbp-topic-meta">
                                        <?php do_action('bbp_theme_before_topic_freshness_author'); ?>
                                        <span class="bbp-topic-freshness-author"><?php bbp_author_link(array('post_id' => bbp_get_topic_last_active_id(), 'size' => 14)); ?></span>
                                        <?php do_action('bbp_theme_after_topic_freshness_author'); ?>
                                    </p>
                                </li>
                            </ul><!-- #bbp-topic-<?php bbp_topic_id(); ?> -->
                        <?php
                        break;
                        case 'reply':
                        ?>
                            <div id="post-<?php bbp_reply_id(); ?>" class="bbp-reply-header">
                                <div class="bbp-meta">
                                    <?php
$user_id = get_current_user_id();
$reply_id = get_the_ID(); // using the core WP function
$upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
$icon_class = is_array($upvoted_posts) && in_array($reply_id, $upvoted_posts) ? 'fa-solid' : 'fa-regular';
?>
                                    <div class="upvote-date"><div class="upvote">
    <span class="upvote-icon" data-post-id="<?php echo $reply_id; ?>" data-type="reply" data-nonce="<?php echo wp_create_nonce('upvote_nonce'); ?>">
        <i class="<?php echo $icon_class; ?> fa-circle-up"></i>
    </span>
    <span class="upvote-count"><?php echo get_upvote_count($reply_id); ?></span> |</div>
                                        <span class="bbp-reply-post-date"><?php bbp_reply_post_date(); ?></span>
    <span class="bbp-header">
        <?php esc_html_e(' in reply to: ', 'bbpress'); ?>
        <a class="bbp-topic-permalink" href="<?php bbp_topic_permalink(bbp_get_reply_topic_id()); ?>">
            <?php bbp_topic_title(bbp_get_reply_topic_id()); ?>
        </a>
    </span>
                                        </div>

<?php
$reply_id = bbp_get_reply_id();
$topic_id = bbp_get_reply_topic_id($reply_id);
$topic_permalink = get_permalink($topic_id);
?>
<a href="<?php echo esc_url($topic_permalink . '#post-' . get_the_ID()); ?>" class="bbp-reply-permalink">#<?php echo intval(get_the_ID()); ?></a>




                                    <?php do_action('bbp_theme_before_reply_admin_links'); ?>
                                    <?php bbp_reply_admin_links(); ?>
                                    <?php do_action('bbp_theme_after_reply_admin_links'); ?>
                                </div><!-- .bbp-meta -->
                            </div><!-- #post-<?php bbp_reply_id(); ?> -->
                            <div <?php bbp_reply_class(); ?>>
                                <div class="bbp-reply-author" id="following-feed">
                                    <?php do_action('bbp_theme_before_reply_author_details'); ?>
<div class="folowdetails">
<div class="author-details" id="following-feed">
    <a href="<?php echo bbp_get_reply_author_url(); ?>" title="<?php printf(esc_attr__('View %s\'s profile', 'bbpress'), bbp_get_reply_author_display_name()); ?>" class="bbp-author-link">
<span class="bbp-author-avatar"><?php echo get_avatar(bbp_get_reply_author_id(), 80); ?></span>
        <span class="bbp-author-name"><?php echo bbp_get_reply_author_display_name(); ?></span>
    </a>
<div class="bbp-author-role"><?php echo bbp_get_user_display_role(bbp_get_reply_author_id()); ?></div>
</div>
        <div class="author-follow-button">
            <?php 
            if (function_exists('extrachill_follow_button')) {
                extrachill_follow_button(bbp_get_reply_author_id());
            } 
            ?>
        </div></div>


                                    <?php if (current_user_can('moderate', bbp_get_reply_id())): ?>
                                        <div class="bbp-reply-ip"><?php bbp_author_ip(bbp_get_reply_id()); ?></div>
                                    <?php endif; ?>
                                    <?php do_action('bbp_theme_after_reply_author_details'); ?>
                                </div><!-- .bbp-reply-author -->
                                <div class="bbp-reply-content" id="following-feed">
                                    <?php do_action('bbp_theme_before_reply_content'); ?>
                                    <?php bbp_reply_content(); ?>
                                    <?php do_action('bbp_theme_after_reply_content'); ?>
                                </div><!-- .bbp-reply-content -->
                            </div><!-- .reply -->
                        <?php
                        break;
                    }

                endwhile; ?>
            </ul>
            <?php
            // Pagination
            $pagination_args = array(
                'total'   => $posts->max_num_pages,
                'current' => max(1, get_query_var('paged')),
                'format'  => '?paged=%#%',
                'type'    => 'plain',
            );
            echo '<div class="pagination">';
            echo paginate_links($pagination_args);
            echo '</div>';
            ?>
            <?php wp_reset_postdata(); ?>
        <?php else: ?>
            <div class="bbp-template-notice"><p>No posts found.</p></div>
        <?php endif; ?>
    </div>
</div>

<?php get_footer(); ?>
