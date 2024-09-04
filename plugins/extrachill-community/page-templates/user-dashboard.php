<?php
/*
* Template Name: User Dashboard
*/
get_header();

if (is_user_logged_in()) {
    $current_user = wp_get_current_user();
    $profile_url = bbp_get_user_profile_url($current_user->ID);
    $forum_url = home_url('/');

    // Fetch custom user types and their verification status from user meta
    $is_artist_pending = get_user_meta($current_user->ID, 'user_is_artist_pending', true) == '1';
    $is_artist = get_user_meta($current_user->ID, 'user_is_artist', true) == '1';
    $is_professional_pending = get_user_meta($current_user->ID, 'user_is_professional_pending', true) == '1';
    $is_professional = get_user_meta($current_user->ID, 'user_is_professional', true) == '1';
    $is_fan = get_user_meta($current_user->ID, 'user_is_fan', true) == '1';
    $avatar = get_avatar($current_user->ID);
    $wp_role = $current_user->roles[0]; // WordPress role of the user

    ?>
    <div class="dashboard-container">
        <div class="user-profile-header">
            <h1>User Dashboard: <?php echo esc_html($current_user->display_name); ?></h1>
            <p><a href="<?php echo esc_url($forum_url); ?>">Community Home</a> | <a href="<?php echo esc_url($profile_url); ?>">Profile</a></li></p>
        </div>

        <?php

// Check for user's bbPress posts (topics or replies)
$user_id = $current_user->ID;
$args = array(
    'author' => $user_id,
    'post_type' => array('reply', 'topic'), // Prioritize replies in the query
    'posts_per_page' => 1,
    'orderby' => 'date',
    'order' => 'DESC',
);
$user_posts_query = new WP_Query($args);

if ($user_posts_query->have_posts()) {
    while ($user_posts_query->have_posts()) {
        $user_posts_query->the_post();
        $post_type = get_post_type();

        if ($post_type == 'reply') {
            // For replies, link to the parent topic with an anchor to the reply
            $topic_id = bbp_get_reply_topic_id(get_the_ID());
            $topic_title = get_the_title($topic_id);
            $reply_anchor = '#post-' . get_the_ID(); // The anchor ID used by bbPress for replies
            $last_post_url = get_permalink($topic_id) . $reply_anchor;
            $post_date = get_the_date(); // Get the date of the post
            $post_time = get_the_time(); // Get the time of the post
            $message = "Welcome back, <b>" . esc_html($current_user->display_name) . "</b>! Your last post was in <a href='" . esc_url($last_post_url) . "'>" . esc_html($topic_title) . "</a> on " . esc_html($post_date) . " at " . esc_html($post_time) . ".";
        } else {
            // For topics, just link to the topic itself
            $last_post_title = get_the_title();
            $last_post_url = get_the_permalink();
            $post_date = get_the_date(); // Get the date of the post
            $post_time = get_the_time(); // Get the time of the post
            $message = "Welcome back, <b>" . esc_html($current_user->display_name) . "</b>! Your last post was in <a href='" . esc_url($last_post_url) . "'>" . esc_html($last_post_title) . "</a> on " . esc_html($post_date) . " at " . esc_html($post_time) . ".";
        }
        

        echo "<p>{$message}</p>";
    }
} else {
    // User hasn't posted yet
    echo "<p>Welcome, <b>" . esc_html($current_user->display_name) . "</b>! You haven't posted yet. Start by introducing yourself in <a href='/t/introductions-thread'>The Back Bar!</a></p>";
}
wp_reset_postdata(); // Reset the global post object

        if ($is_artist_pending && !$is_artist): ?>
            <div class="status-notice">
                <p>Artist status pending admin verification. Create your space in the Independent Artists forum to speed up the process.</p>
            </div>
        <?php endif; ?>

        <?php if ($is_professional_pending && !$is_professional): ?>
            <div class="status-notice">
                <p>Industry Professional status pending admin verification. Engage in the forum to speed up the process.</p>
            </div>
                    <!-- Gutenberg Editor Content -->
        <?php endif; ?>
        <div class="dashboard-gutenberg-content">
            <?php 
            if ( have_posts() ) {
                while ( have_posts() ) {
                    the_post();
                    the_content();
                }
            }
            ?>
        </div>
</div>
    <div class="dashboard-content">

    <?php do_action( 'chill_before_user_dashboard' );?>

        <!-- Custom links content -->
<nav class="dashboard-navigation">
<h3>Your Stats</h3>
<div class="user-stats">
    <?php
    // Assuming $current_user is defined as the logged-in user
    $user_id = $current_user->ID;

    // Topics Started
    $topics_count = bbp_get_user_topic_count_raw($user_id);
    echo "<p><span><b>Topics Started:</b> $topics_count <a href='" . bbp_get_user_topics_created_url($user_id) . "'>View All</a></span>";

    // Replies Created
    $replies_count = bbp_get_user_reply_count_raw($user_id);
    echo "<span><b>Total Replies:</b> $replies_count <a href='" . bbp_get_user_replies_created_url($user_id) . "'>View All</a></span>";

    // Main Site Comments and Blog Articles
    // The functions convert_community_user_id_to_author_id() and fetch_main_site_post_count_for_user() are defined in the extra chill integration directory
    $author_id = convert_community_user_id_to_author_id($user_id);
    if ($author_id !== null) {
        $post_count = fetch_main_site_post_count_for_user($author_id);
        $comment_count = display_main_site_comment_count_for_user($author_id); // Assume this function exists and works similarly
        $author_slug = get_author_nicename_by_id($author_id);
        $author_url = "https://extrachill.com/author/{$author_slug}/";

        if ($post_count > 0) {
            echo "<span><b>Extra Chill Articles:</b> $post_count <a href='" . esc_url($author_url) . "'>View All</a></span>";
        }
            // Inside the dashboard template
            $current_user_id = get_current_user_id();
            echo "<span>" . display_main_site_comment_count_for_user($current_user_id) . "</span>";
        
    }

    // Rank and Points
    $rank = wp_surgeon_display_user_rank($user_id);
    $points = wp_surgeon_display_user_points($user_id);
    echo "<span><b>Rank:</b> $rank<br></span>";
    echo "<span><b>Points:</b> $points<br></span>";
    echo "<span><small><a href='/rank-system'>Learn About the Rank System</a></small></span></p>";

    ?>
</div>

<div class="artist-topic-status">
    <?php if ($is_artist): ?>
        <?php if (has_independent_artist_boards($current_user->ID)): ?>
            <p>View your independent artist spaces:</p>
            <ul>
                <?php foreach (get_independent_artist_boards($current_user->ID) as $board_id): ?>
                    <li><a href="<?php echo esc_url(get_permalink($board_id)); ?>"><?php echo get_the_title($board_id); ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>You haven't created an independent artist space yet. <a href="<?php echo esc_url(home_url('/r/independent-artists')); ?>">Create your space now</a>.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>


    <h3>Community Links</h3>
<ul>
    <li><a href="/community-info">Community Info</a></li>
    <li><a href="/all-users">All Users</a></li>
    <li><a href="https://extrachill.com">Main Blog</a></li>
    <li><a href="/settings">Settings</a></li>
</ul>

<ul>
    <?php if ($is_artist): ?>
        <li>Artist Status Verified</li>
    <?php elseif ($is_artist_pending): ?>
        <li>Artist Status Pending</li>
    <?php else: ?>
        <li><a href="#" onclick="requestStatusChange('artist'); return false;">Request Artist Status</a></li>
    <?php endif; ?>

    <?php if ($is_professional): ?>
        <li>Music Industry Professional Status Verified</li>
    <?php elseif ($is_professional_pending): ?>
        <li>Music Industry Professional Status Pending</li>
    <?php else: ?>
        <li><a href="#" onclick="requestStatusChange('professional'); return false;">Request Music Industry Professional Status</a></li>
    <?php endif; ?>
</ul>

    <ul>
        <li><a class="log-out" href="<?php echo wp_logout_url(home_url()); ?>">Log Out</a></li>
    </ul>
</nav>

    </div>
</div>

    <?php
}

get_footer();
?>
