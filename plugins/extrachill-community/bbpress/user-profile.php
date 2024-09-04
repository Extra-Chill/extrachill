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
            <span class='vcard'>
                <a class="url fn n" href="<?php bbp_user_profile_url(); ?>" title="<?php bbp_displayed_user_field('display_name'); ?>" rel="me">
<?php 
// Attempt to get the custom avatar ID
$custom_avatar_id = get_user_meta(bbp_get_displayed_user_field('ID'), 'custom_avatar_id', true);

// Display custom avatar if available, otherwise default to Gravatar
if ($custom_avatar_id && wp_attachment_is_image($custom_avatar_id)) {
    // Get the medium size URL of the custom avatar
    $medium_src = wp_get_attachment_image_url($custom_avatar_id, 'medium');

    if ($medium_src) {
        // Construct the image tag with the medium size URL
        echo '<img src="' . esc_url($medium_src) . '" alt="' . esc_attr(bbp_get_displayed_user_field('display_name')) . '" class="avatar" width="150" height="150">';
    }
} else {
    // Fallback to default Gravatar with the specified size
    echo get_avatar(bbp_get_displayed_user_field('user_email', 'raw'), apply_filters('bbp_single_user_details_avatar_size', 150));
}

?>
                </a>
            </span>
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
        <div class="rank-profile">          
    <p class="bbp-user-forum-role"><b>Title:</b> <?php printf(esc_html__('%s', 'bbpress'), bbp_get_user_display_role()); ?></p>
    <p class="bbp-user-forum-rank">
        <b>Rank:</b> <?php printf(esc_html__('%s', 'bbpress'), wp_surgeon_display_user_rank(bbp_get_displayed_user_id())); ?>
    </p>
    <p class="bbp-user-total-points">
        <b>Points:</b> <?php printf(esc_html__('%s', 'bbpress'), wp_surgeon_display_user_points(bbp_get_displayed_user_id())); ?>
    </p>
    <p class="bbp-user-forum-role"><b>Joined:</b> <?php printf(esc_html__('%s', 'bbpress'), date_i18n('n/j/Y', strtotime(bbp_get_displayed_user_field('user_registered')))); ?></p>
</div>
                </ul>

            </div><!-- user navigation -->
        </div><!-- single details -->

                    <?php if (bbp_get_displayed_user_field('description')) : ?>
                        <h3>Bio</h3>
                <p class="bbp-user-description"><?php echo bbp_rel_nofollow(bbp_get_displayed_user_field('description')); ?></p>
            <?php endif; 

// Check if any link exists
$website = bbp_get_displayed_user_field('user_url');
$social_media_fields = [
    'instagram' => get_user_meta(bbp_get_displayed_user_id(), 'instagram', true),
    'spotify' => get_user_meta(bbp_get_displayed_user_id(), 'spotify', true),
    'soundcloud' => get_user_meta(bbp_get_displayed_user_id(), 'soundcloud', true),
    'twitter' => get_user_meta(bbp_get_displayed_user_id(), 'twitter', true),
    'facebook' => get_user_meta(bbp_get_displayed_user_id(), 'facebook', true),
    'bandcamp' => get_user_meta(bbp_get_displayed_user_id(), 'bandcamp', true),
];
$utility_links_exist = false;
for ($i = 1; $i <= 3; $i++) {
    if (get_user_meta(bbp_get_displayed_user_id(), "utility_link_$i", true)) {
        $utility_links_exist = true;
        break;
    }
}

if ($website || array_filter($social_media_fields) || $utility_links_exist) : ?>
    <div class="profile-links">
    <?php if ($website) : ?>
        <p class="bbp-user-website"><strong>Website:</strong> <?php printf(esc_html__('%s', 'bbpress'), bbp_rel_nofollow(bbp_make_clickable($website))); ?></p>
    <?php endif; ?>

    <?php
    foreach ($social_media_fields as $field_key => $field_value) {
        if ($field_value) : ?>
            <p class="<?php echo esc_attr($field_key); ?>"><strong><?php echo esc_html(ucfirst($field_key)); ?>:</strong> <?php printf(esc_html__('%s', 'bbpress'), bbp_rel_nofollow(bbp_make_clickable($field_value))); ?></p>
        <?php endif;
    }

    // Display utility links
    for ($i = 1; $i <= 3; $i++) {
        $utility_link = get_user_meta(bbp_get_displayed_user_id(), "utility_link_$i", true);
        if ($utility_link) : ?>
            <p class="utility-link"><strong><?php echo esc_html__("Utility Link $i:"); ?></strong> <?php printf(esc_html__('%s', 'bbpress'), bbp_rel_nofollow(bbp_make_clickable($utility_link))); ?></p>
        <?php endif;
    }
endif;
?></div>

            <?php do_action('bbp_template_before_user_details_menu_items'); ?>
        <hr>
        <div class="user-profile-activity">
    <h3><?php esc_html_e('Community Activity', 'bbpress'); ?></h3>
    <?php if (bbp_get_user_last_posted()) : ?>
        <p class="bbp-user-last-activity"><b>Last Post:</b> <?php printf(esc_html__('%s', 'bbpress'), bbp_get_time_since(bbp_get_user_last_posted(), false, true)); ?></p>
    <?php endif; ?>

    <p class="bbp-user-topic-count"><b>Threads Started:</b> <?php printf(esc_html__('%s', 'bbpress'), bbp_get_user_topic_count()); ?> <a href="<?php bbp_user_topics_created_url(); ?>"><?php printf(esc_html__("(%s's Threads)", 'bbpress'), bbp_get_displayed_user_field('display_name')); ?></a></p>
    <p class="bbp-user-reply-count"><b>Total Replies:</b> <?php printf(esc_html__('%s', 'bbpress'), bbp_get_user_reply_count()); ?> <a href="<?php bbp_user_replies_created_url(); ?>"><?php printf(esc_html__("(%s's Replies Created)", 'bbpress'), bbp_get_displayed_user_field('display_name')); ?></a></p>

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
                    
<?php
// Check if the user is marked as an artist
if (get_user_meta(bbp_get_displayed_user_id(), 'user_is_artist', true)) :
    $artist_name = get_user_meta(bbp_get_displayed_user_id(), 'artist_name', true);
    $artist_genre = get_user_meta(bbp_get_displayed_user_id(), 'artist_genre', true);
    $artist_influences = get_user_meta(bbp_get_displayed_user_id(), 'artist_influences', true);
    $featured_embed_url = get_user_meta(bbp_get_displayed_user_id(), 'featured_embed', true);
    $embed_html = !empty($featured_embed_url) ? wp_oembed_get($featured_embed_url) : '';
    $band_name = get_user_meta(bbp_get_displayed_user_id(), 'band_name', true);
    $instruments_played = get_user_meta(bbp_get_displayed_user_id(), 'instruments_played', true);

    // Only display the section if there's relevant content
    if ($artist_name || $artist_genre || $artist_influences || $embed_html || $band_name || $instruments_played) : ?>
        <div class="bbp-user-artist-details">
            <h3><?php esc_html_e('Artist Details', 'bbpress'); ?></h3>
            <?php if ($artist_name) : ?>
                <p><strong><?php esc_html_e('Artist Name:', 'bbpress'); ?></strong> <?php echo esc_html($artist_name); ?></p>
            <?php endif; ?>
            <?php if ($band_name) : ?>
                <p><strong><?php esc_html_e('Band Name:', 'bbpress'); ?></strong> <?php echo esc_html($band_name); ?></p>
            <?php endif; ?>
            <?php if ($instruments_played) : ?>
                <p><strong><?php esc_html_e('Instruments Played:', 'bbpress'); ?></strong> <?php echo esc_html($instruments_played); ?></p>
            <?php endif; ?>
            <?php if ($artist_genre) : ?>
                <p><strong><?php esc_html_e('Genre:', 'bbpress'); ?></strong> <?php echo esc_html($artist_genre); ?></p>
            <?php endif; ?>
            <?php if ($artist_influences) : ?>
                <p><strong><?php esc_html_e('Influences:', 'bbpress'); ?></strong> <?php echo nl2br(esc_html($artist_influences)); ?></p>
            <?php endif; ?>
            <?php if ($embed_html) : ?>
                <div class="bbp-user-featured-embed"><p><strong><?php esc_html_e('Featured Content:', 'bbpress'); ?></strong></p><p><?php echo $embed_html; ?></p></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
// Check if the user is marked as a music industry professional
if (get_user_meta(bbp_get_displayed_user_id(), 'user_is_professional', true)) :
    $professional_role = get_user_meta(bbp_get_displayed_user_id(), 'professional_role', true);
    $professional_company = get_user_meta(bbp_get_displayed_user_id(), 'professional_company', true);
    $professional_skills = get_user_meta(bbp_get_displayed_user_id(), 'professional_skills', true);
    $professional_goals = get_user_meta(bbp_get_displayed_user_id(), 'professional_goals', true);

    // Only display the section if there's relevant content
    if ($professional_role || $professional_company || $professional_skills || $professional_goals) : ?>
        <div class="bbp-user-professional-details">
            <h3><?php esc_html_e('Music Industry Professional', 'bbpress'); ?></h3>
            <?php if ($professional_role) : ?>
                <p><strong><?php esc_html_e('Role:', 'bbpress'); ?></strong> <?php echo esc_html($professional_role); ?></p>
            <?php endif; ?>
            <?php if ($professional_company) : ?>
                <p><strong><?php esc_html_e('Company:', 'bbpress'); ?></strong> <?php echo esc_html($professional_company); ?></p>
            <?php endif; ?>
            <?php if ($professional_skills) : ?>
                <p><strong><?php esc_html_e('Skills:', 'bbpress'); ?></strong> <?php echo esc_html($professional_skills); ?></p>
            <?php endif; ?>
            <?php if ($professional_goals) : ?>
                <p><strong><?php esc_html_e('Goals:', 'bbpress'); ?></strong> <?php echo nl2br(esc_html($professional_goals)); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>


<div class="profile-scene-fan">
    <?php
// Local Scene Section
$local_city = get_user_meta(bbp_get_displayed_user_id(), 'local_city', true);
$top_local_venues = get_user_meta(bbp_get_displayed_user_id(), 'top_local_venues', true);
$top_local_artists = get_user_meta(bbp_get_displayed_user_id(), 'top_local_artists', true);

if ($local_city || $top_local_venues || $top_local_artists) : ?>
    <div class="bbp-user-local-scene">
        <h3><?php esc_html_e('Local Scene', 'bbpress'); ?></h3>
        <?php if ($local_city) : ?>
            <p><strong><?php esc_html_e('City:', 'bbpress'); ?></strong> <?php echo nl2br(esc_html($local_city)); ?></p>
        <?php endif; ?>
        <?php if ($top_local_venues) : ?>
            <p><strong><?php esc_html_e('Top Local Venues:', 'bbpress'); ?></strong> <?php echo nl2br(esc_html($top_local_venues)); ?></p>
        <?php endif; ?>
        <?php if ($top_local_artists) : ?>
            <p><strong><?php esc_html_e('Top Local Artists:', 'bbpress'); ?></strong> <?php echo nl2br(esc_html($top_local_artists)); ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
// Music Fan Section
$favorite_artists = get_user_meta(bbp_get_displayed_user_id(), 'favorite_artists', true);
$top_concerts = get_user_meta(bbp_get_displayed_user_id(), 'top_concerts', true);
$top_festivals = get_user_meta(bbp_get_displayed_user_id(), 'top_festivals', true);
$desert_island_albums = get_user_meta(bbp_get_displayed_user_id(), 'desert_island_albums', true);

if ($favorite_artists || $top_concerts || $top_festivals || $desert_island_albums) : ?>
    <div class="bbp-user-music-fan">
        <h3><?php esc_html_e('Music Fan', 'bbpress'); ?></h3>
        <?php if ($favorite_artists) : ?>
            <p><strong><?php esc_html_e('Favorite Artists:', 'bbpress'); ?></strong> <?php echo nl2br(esc_html($favorite_artists)); ?></p>
        <?php endif; ?>
        <?php if ($top_concerts) : ?>
            <p><strong><?php esc_html_e('Top Concerts:', 'bbpress'); ?></strong> <?php echo nl2br(esc_html($top_concerts)); ?></p>
        <?php endif; ?>
        <?php if ($top_festivals) : ?>
            <p><strong><?php esc_html_e('Favorite Festivals:', 'bbpress'); ?></strong> <?php echo nl2br(esc_html($top_festivals)); ?></p>
        <?php endif; ?>
        <?php if ($desert_island_albums) : ?>
            <p><strong><?php esc_html_e('Desert Island Albums:', 'bbpress'); ?></strong> <?php echo nl2br(esc_html($desert_island_albums)); ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

        </div>
<!-- Followers and Following Tabs -->
<div class="bbp-user-social-networking">
    <h3><?php esc_html_e('Social', 'bbpress'); ?></h3>
    <ul class="tabs">
        <li><a href="#followers">Followers</a></li>
        <li><a href="#following">Following</a></li>
    </ul>
    <div id="followers" class="tab-content">
    <?php
    $followers = get_user_meta(bbp_get_displayed_user_id(), 'extrachill_followers', true);
    if (!empty($followers)) {
        echo '<ul>';
        $displayed_followers = array_slice($followers, 0, 10); // Show only the most recent 10 followers
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
        $displayed_following = array_slice($following, 0, 10); // Show only the most recent 10 following
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
</div>

</div><!-- #bbp-user-profile -->

<?php do_action('bbp_template_after_user_profile'); ?>
