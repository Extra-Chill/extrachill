<?php
/**
 * User Details
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

?>

<?php if ( bbp_get_displayed_user_id() == get_current_user_id() ) : ?>


<?php

// Check for user's bbPress posts (topics or replies)
$user_id = bbp_get_displayed_user_id();
$current_user = wp_get_current_user();
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

?>
<?php endif; ?>


<div class="bbp-user-header-card">
    <div class="bbp-user-avatar-area">
        <span class='vcard'>
            <a class="url fn n" href="<?php bbp_user_profile_url(); ?>" title="<?php bbp_displayed_user_field('display_name'); ?>" rel="me">
                <?php
                // Use filtered get_avatar() for consistent avatar handling across all locations
                echo get_avatar(bbp_get_displayed_user_field('ID'), apply_filters('bbp_single_user_details_avatar_size', 150));
                ?>
            </a>
        </span>
    </div>
    <div class="bbp-user-header-text-area">
        <h1 class="bbp-user-display-name">
            <?php bbp_displayed_user_field('display_name'); ?>
            <div class="forum-badges">
                <?php do_action( 'bbp_theme_after_user_name', bbp_get_displayed_user_id() ); ?>
            </div>
        </h1>
        <p class="bbp-user-title-rank">
            <b>Title:</b> <?php printf(esc_html__('%s', 'bbpress'), bbp_get_user_display_role()); ?> | 
            <b>Rank:</b> <?php printf(esc_html__('%s', 'bbpress'), extrachill_display_user_rank(bbp_get_displayed_user_id())); ?>
            | <b>Points:</b> <?php printf(esc_html__('%s', 'bbpress'), extrachill_display_user_points(bbp_get_displayed_user_id())); ?>
        </p>
        <div class="bbp-user-actions-area">
                <?php if ( bbp_get_displayed_user_id() == get_current_user_id() ) : ?>
                    <a href="/settings" class="settings-button"><?php esc_html_e('Settings', 'bbpress'); ?></a>
                <a href="<?php echo esc_url( bbp_get_user_profile_edit_url( bbp_get_displayed_user_id() ) ); ?>" class="extrachill-edit-profile-button"><?php esc_html_e('Edit Profile', 'extra-chill-community'); ?></a>
            <?php endif; ?>
        </div>

    </div>
    
    <?php
    $website = bbp_get_displayed_user_field('user_url');
    $social_media_fields = [
        'instagram' => get_user_meta(bbp_get_displayed_user_id(), 'instagram', true),
        'spotify' => get_user_meta(bbp_get_displayed_user_id(), 'spotify', true),
        'soundcloud' => get_user_meta(bbp_get_displayed_user_id(), 'soundcloud', true),
        'twitter' => get_user_meta(bbp_get_displayed_user_id(), 'twitter', true),
        'facebook' => get_user_meta(bbp_get_displayed_user_id(), 'facebook', true),
        'bandcamp' => get_user_meta(bbp_get_displayed_user_id(), 'bandcamp', true),
    ];
    
    $utility_links = [];
    for ($i = 1; $i <= 3; $i++) {
        $link = get_user_meta(bbp_get_displayed_user_id(), "utility_link_$i", true);
        if ($link) $utility_links[] = $link;
    }
    
    $has_links = $website || array_filter($social_media_fields) || !empty($utility_links);
    ?>
    
    <?php if ($has_links) : ?>
    <div class="bbp-user-links-inline">
        <?php if ($website) : ?>
            <a href="<?php echo esc_url($website); ?>" class="social-link website" target="_blank" rel="noopener">
                <i class="fas fa-globe"></i>
            </a>
        <?php endif; ?>

        <?php foreach ($social_media_fields as $platform => $url) : ?>
            <?php if (!empty($url)) : ?>
                <a href="<?php echo esc_url($url); ?>" class="social-link <?php echo esc_attr($platform); ?>" target="_blank" rel="noopener">
                    <i class="fab fa-<?php echo esc_attr($platform); ?>"></i>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php foreach ($utility_links as $url) : ?>
            <a href="<?php echo esc_url($url); ?>" class="social-link utility" target="_blank" rel="noopener">
                <i class="fas fa-link"></i>
            </a>
        <?php endforeach; ?>
    </div><!-- .bbp-user-links-inline -->
<?php endif; ?>

</div><!-- .bbp-user-header-card -->


<?php do_action( 'bbp_template_after_user_details' ); ?>
