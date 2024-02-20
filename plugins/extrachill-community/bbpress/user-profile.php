 <?php
/**
 * User Profile
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

do_action('bbp_template_before_user_profile');
?>

<div id="bbp-user-profile" class="bbp-user-profile">
    <div class="bbp-user-section">
        <div class="chill-profile-top">
        <div id="bbp-single-user-details">
                    <h3><?php esc_html_e('Community Profile', 'bbpress'); ?></h3>
            <span class='vcard'>
                <a class="url fn n" href="<?php bbp_user_profile_url(); ?>" title="<?php bbp_displayed_user_field('display_name'); ?>" rel="me">
<?php 
// Attempt to get the custom avatar URL
$custom_avatar_url = get_user_meta(bbp_get_displayed_user_field('ID'), 'custom_avatar', true);

// Display custom avatar if available, otherwise default to Gravatar
if ($custom_avatar_url) {
    echo '<img src="' . esc_url($custom_avatar_url) . '" alt="' . esc_attr(bbp_get_displayed_user_field('display_name')) . '" class="avatar" width="150" height="150">';
} else {
    echo get_avatar(bbp_get_displayed_user_field('user_email', 'raw'), apply_filters('bbp_single_user_details_avatar_size', 150));
}
?>
                </a>
            </span>
            <p class="bbp-user-forum-role"><?php printf(esc_html__('Title: %s', 'bbpress'), bbp_get_user_display_role()); ?></p>
<p class="bbp-user-forum-rank">
    <?php printf(esc_html__('Rank: %s', 'bbpress'), wp_surgeon_display_user_rank(bbp_get_displayed_user_id())); ?>
</p>
<p class="bbp-user-total-points">
    <?php printf(esc_html__('Points: %s', 'bbpress'), wp_surgeon_display_user_points(bbp_get_displayed_user_id())); ?>
</p>
      
                <p class="bbp-user-forum-role"><?php printf(esc_html__('Joined: %s', 'bbpress'), bbp_get_time_since(bbp_get_displayed_user_field('user_registered'))); ?></p>
        </div>
            <div id="bbp-user-navigation">
                <ul>
                   <?php // Get the follower count
$user_id = bbp_get_displayed_user_id();
$followers = extrachill_get_followers($user_id);
$follower_count = is_array($followers) ? count($followers) : 0;
?>
                 <?php do_action('bbp_template_after_user_details_menu_items'); ?>

<!-- Follower Count Display -->
    <li class="bbp-user-follower-count">
        <?php printf(esc_html__('Followers: %s', 'bbpress'), $follower_count); ?>
        </li>
                    <!-- Link trees for Artist, Fan, and Industry Professional -->
                    <?php
                    $user_id = bbp_get_displayed_user_id();

                    // Fan profile link
                    $fan_profile_id = wp_surgeon_has_profile_post($user_id, 'fan_profile');
                    if ($fan_profile_id) {
                        $fan_profile_url = get_permalink($fan_profile_id);
                        echo '<li><a href="' . esc_url($fan_profile_url) . '">' . esc_html__('Fan Profile', 'bbpress') . '</a></li>';
                    }

                    // Artist profile link
                    $artist_profile_id = wp_surgeon_has_profile_post($user_id, 'artist_profile');
                    if ($artist_profile_id) {
                        $artist_profile_url = get_permalink($artist_profile_id);
                        echo '<li><a href="' . esc_url($artist_profile_url) . '">' . esc_html__('Artist Profile', 'bbpress') . '</a></li>';
                    }

                    // Industry Professional profile link
                    $professional_profile_id = wp_surgeon_has_profile_post($user_id, 'professional_profile');
                    if ($professional_profile_id) {
                        $professional_profile_url = get_permalink($professional_profile_id);
                        echo '<li><a href="' . esc_url($professional_profile_url) . '">' . esc_html__('Industry Pro Profile', 'bbpress') . '</a></li>';
                    }
                    ?>

                </ul>

            </div><!-- user navigation -->
        </div><!-- single details -->

                    <?php if (bbp_get_displayed_user_field('description')) : ?>
                        <h4>Bio</h4>
                <p class="bbp-user-description"><?php echo bbp_rel_nofollow(bbp_get_displayed_user_field('description')); ?></p>
            <?php endif; ?>

            <?php if (bbp_get_displayed_user_field('user_url')) : ?>
                <p class="bbp-user-website"><?php printf(esc_html__('Website: %s', 'bbpress'), bbp_rel_nofollow(bbp_make_clickable(bbp_get_displayed_user_field('user_url')))); ?></p>
            <?php endif; ?>
            <?php do_action('bbp_template_before_user_details_menu_items'); ?>
        <hr>
<h3><?php esc_html_e('Community Activity', 'bbpress'); ?></h3>
<?php if (bbp_get_user_last_posted()) : ?>
    <p class="bbp-user-last-activity"><?php printf(esc_html__('Last Post: %s', 'bbpress'), bbp_get_time_since(bbp_get_user_last_posted(), false, true)); ?></p>
<?php endif; ?>

<p class="bbp-user-topic-count"><?php printf(esc_html__('Threads Started: %s', 'bbpress'), bbp_get_user_topic_count()); ?> <a href="<?php bbp_user_topics_created_url(); ?>"><?php printf(esc_html__("(%s's Threads)", 'bbpress'), bbp_get_displayed_user_field('display_name')); ?></a></p>
<p class="bbp-user-reply-count"><?php printf(esc_html__('Total Replies: %s', 'bbpress'), bbp_get_user_reply_count()); ?> <a href="<?php bbp_user_replies_created_url(); ?>"><?php printf(esc_html__("(%s's Replies Created)", 'bbpress'), bbp_get_displayed_user_field('display_name')); ?></a></p>

<!-- Display Main Site Blog Post Count -->

<?php
// Properly display the main site blog post count and "View All" link on the profile
display_main_site_post_count_on_profile();
?>

<!-- Display Main Site Comments Count -->
<?php
$main_site_comments_html = display_main_site_comment_count_for_user();
if (!empty($main_site_comments_html)) : ?>
    <p class="bbp-user-main-site-comment-count"><?php echo $main_site_comments_html; ?></p>
<?php endif; ?>

                            
    </div>

<!-- Followers and Following Tabs -->
<div class="bbp-user-social-networking">
    <h3><?php esc_html_e('Social Networking', 'bbpress'); ?></h3>
    <ul class="tabs">
        <li><a href="#followers">Followers</a></li>
        <li><a href="#following">Following</a></li>
    </ul>
    <div id="followers" class="tab-content">
    <?php
    $followers = get_user_meta(bbp_get_displayed_user_id(), 'extrachill_followers', true);
    if (!empty($followers)) {
        echo '<ul>';
        $displayed_followers = array_slice($followers, 0, 20); // Show only the most recent 20 followers
        foreach ($displayed_followers as $follower_id) {
            $follower_user = get_user_by('ID', $follower_id);
            if ($follower_user) {
                $follower_name = $follower_user->display_name;
                $follower_profile_url = bbp_get_user_profile_url($follower_id);
                $follower_avatar = get_avatar($follower_user->user_email, 32);

                echo '<li>';
                echo '<a href="' . esc_url($follower_profile_url) . '">' . $follower_avatar . '</a>';
                echo '<a href="' . esc_url($follower_profile_url) . '">' . esc_html($follower_name) . '</a>';
                echo '</li>';
            }
        }
        echo '</ul>';
        // View All link for followers
        echo '<a href="/social?section=followers&user=' . bbp_get_displayed_user_id() . '" class="view-all">View All Followers</a>';
    } else {
        echo '<p>' . esc_html__('No followers yet.', 'bbpress') . '</p>';
    }
    ?>
</div>

<div id="following" class="tab-content">
    <?php
    $following = get_user_meta(bbp_get_displayed_user_id(), 'extrachill_following', true);
    if (!empty($following)) {
        echo '<ul>';
        $displayed_following = array_slice($following, 0, 20); // Show only the most recent 20 following
        foreach ($displayed_following as $followed_user_id) {
            $followed_user = get_user_by('ID', $followed_user_id);
            if ($followed_user) {
                $followed_name = $followed_user->display_name;
                $followed_profile_url = bbp_get_user_profile_url($followed_user_id);
                $followed_avatar = get_avatar($followed_user->user_email, 32);

                echo '<li>';
                echo '<a href="' . esc_url($followed_profile_url) . '">' . $followed_avatar . '</a>';
                echo '<a href="' . esc_url($followed_profile_url) . '">' . esc_html($followed_name) . '</a>';
                echo '</li>';
            }
        }
        echo '</ul>';
        // View All link for following
        echo '<a href="/social?section=following&user=' . bbp_get_displayed_user_id() . '" class="view-all">View All Following</a>';
    } else {
        echo '<p>' . esc_html__('Not following anyone yet.', 'bbpress') . '</p>';
    }
    ?>
</div>

</div>


</div><!-- #bbp-user-profile -->

<?php do_action('bbp_template_after_user_profile'); ?>
