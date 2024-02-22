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
            <p><a href="<?php echo esc_url($forum_url); ?>">Community Home</a> | <a href="<?php echo esc_url($profile_url); ?>">Community Profile</a></li></p>
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
    echo "<p>Welcome, <b>" . esc_html($current_user->display_name) . "</b>! You haven't posted yet. Start by sharing some of your favorite music in <a href='" . esc_url($forum_url) . "#TheRabbitHole'>The Rabbit Hole</a>!</p>";
}
wp_reset_postdata(); // Reset the global post object


        if ($is_artist_pending && !$is_artist): ?>
            <div class="status-notice">
                <p>Artist status pending admin verification. Introduce yourself in the Community to speed up the process.</p>
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
    echo "<p><span>Topics Started: $topics_count <a href='" . bbp_get_user_topics_created_url($user_id) . "'>View All</a></span>";

    // Replies Created
    $replies_count = bbp_get_user_reply_count_raw($user_id);
    echo "<span>Total Replies: $replies_count <a href='" . bbp_get_user_replies_created_url($user_id) . "'>View All</a></span>";

    // Main Site Comments and Blog Articles
    // The functions convert_community_user_id_to_author_id() and fetch_main_site_post_count_for_user() need to be defined accordingly
    $author_id = convert_community_user_id_to_author_id($user_id);
    if ($author_id !== null) {
        $post_count = fetch_main_site_post_count_for_user($author_id);
        $comment_count = display_main_site_comment_count_for_user($author_id); // Assume this function exists and works similarly
        $author_slug = get_author_nicename_by_id($author_id);
        $author_url = "https://extrachill.com/author/{$author_slug}/";

        if ($post_count > 0) {
            echo "<span>Extra Chill Articles: $post_count <a href='" . esc_url($author_url) . "'>View All</a></span>";
        }
            // Inside the dashboard template
            $current_user_id = get_current_user_id();
            echo "<span>" . display_main_site_comment_count_for_user($current_user_id) . "</span>";
        
    }

    // Rank and Points
    $rank = wp_surgeon_display_user_rank($user_id);
    $points = wp_surgeon_display_user_points($user_id);
    echo "<span>Rank: $rank<br></span>";
    echo "<span>Points: $points<br></span>";
    echo "<span><small><a href='/rank-system'>Learn About the Rank System</a></small></span></p>";

    ?>
</div>




    <h3>Feeds</h3>
    <ul>
        <li><a href="/notificatons">Notifications</a></li>
        <li><a href="/recent">Recent</a></li>
        <li><a href="/following">Following</a></li>
        <li><a href="/upvoted">Upvoted</a></li>
    </ul>
    <?php 
    // Fan links with added "View All Fans" link
    if ($is_fan || current_user_can('administrator')) {
        $post_type = 'fan_profile';
        $post_id = wp_surgeon_has_profile_post($current_user->ID, $post_type);

        if ($post_id) {
            $fan_link_text_view = 'View Fan Profile';
            $fan_link_url_view = "?p={$post_id}";
            $fan_link_text_edit = 'Edit Fan Profile';
            $fan_link_url_edit = "/edit-profile?post_id={$post_id}";
        } else {
            $fan_link_text_view = '';
            $fan_link_text_edit = 'Create Fan Profile';
            $fan_link_url_edit = "/create-profile/?profile_type={$post_type}";
        }
        ?>
        <h3>Fan Links</h3>
        <ul>
            <?php if ($fan_link_text_view) { ?>
                <li><a href="<?php echo home_url($fan_link_url_view); ?>"><?php echo esc_html($fan_link_text_view); ?></a></li>
            <?php } ?>
            <li><a href="<?php echo home_url($fan_link_url_edit); ?>"><?php echo esc_html($fan_link_text_edit); ?></a></li>
        </ul>
    <?php }

    // Artist links with added "View All Artists" link
    if ($is_artist || current_user_can('administrator')) {
        $post_type = 'artist_profile';
        $post_id = wp_surgeon_has_profile_post($current_user->ID, $post_type);

        if ($post_id) {
            $artist_link_text_view = 'View Artist Profile';
            $artist_link_url_view = "?p={$post_id}";
            $artist_link_text_edit = 'Edit Artist Profile';
            $artist_link_url_edit = "/edit-profile?post_id={$post_id}";
        } else {
            $artist_link_text_view = '';
            $artist_link_text_edit = 'Create Artist Profile';
            $artist_link_url_edit = "/create-profile/?profile_type={$post_type}";
        }
        ?>
        <h3>Artist Links</h3>
        <ul>
            <?php if ($artist_link_text_view) { ?>
                <li><a href="<?php echo home_url($artist_link_url_view); ?>"><?php echo esc_html($artist_link_text_view); ?></a></li>
            <?php } ?>
            <li><a href="<?php echo home_url($artist_link_url_edit); ?>"><?php echo esc_html($artist_link_text_edit); ?></a></li>
        </ul>
    <?php }

    // Industry Pro links with added "View All Industry Pros" link
    if ($is_professional || current_user_can('administrator')) {
        $post_type = 'professional_profile';
        $post_id = wp_surgeon_has_profile_post($current_user->ID, $post_type);

        if ($post_id) {
            $pro_link_text_view = 'View Industry Pro Profile';
            $pro_link_url_view = "?p={$post_id}";
            $pro_link_text_edit = 'Edit Industry Pro Profile';
            $pro_link_url_edit = "/edit-profile?post_id={$post_id}";
        } else {
            $pro_link_text_view = '';
            $pro_link_text_edit = 'Create Industry Pro Profile';
            $pro_link_url_edit = "/create-profile/?profile_type={$post_type}";
        }
        ?>
        <h3>Industry Pro Links</h3>
        <ul>
            <?php if ($pro_link_text_view) { ?>
                <li><a href="<?php echo home_url($pro_link_url_view); ?>"><?php echo esc_html($pro_link_text_view); ?></a></li>
            <?php } ?>
            <li><a href="<?php echo home_url($pro_link_url_edit); ?>"><?php echo esc_html($pro_link_text_edit); ?></a></li>
        </ul>
    <?php }

    // Logout button
    ?>
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
