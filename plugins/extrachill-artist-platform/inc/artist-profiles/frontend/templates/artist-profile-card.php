<?php
/**
 * Artist Profile Card Component - Hero Style
 * 
 * Template for displaying an artist profile card with hero background and overlaid profile picture.
 * Used by: user profile, dashboard, directory listings
 * 
 * @param int $artist_id - Required. The artist profile post ID
 * @param string $context - Optional. Context: 'user-profile', 'dashboard', 'directory'
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

// Get artist data
if ( ! isset($artist_id) || ! $artist_id ) {
    return;
}

$artist_post = get_post($artist_id);
if ( ! $artist_post || $artist_post->post_type !== 'artist_profile' ) {
    return;
}

$context = isset($context) ? $context : 'default';
$artist_name = $artist_post->post_title;
$artist_url = get_permalink($artist_id);
$artist_bio = get_post_field('post_content', $artist_id);

// Get images
$profile_image_id = get_post_meta($artist_id, '_artist_profile_image_id', true);
$profile_image_url = $profile_image_id ? wp_get_attachment_image_url($profile_image_id, 'medium') : '';
$header_image_id = get_post_meta($artist_id, '_artist_profile_header_image_id', true);
$header_image_url = $header_image_id ? wp_get_attachment_image_url($header_image_id, 'large') : '';

// Get meta data
$genre = get_post_meta($artist_id, '_genre', true);
$local_city = get_post_meta($artist_id, '_local_city', true);
$link_page_id = get_post_meta($artist_id, '_extrch_link_page_id', true);
$forum_id = get_post_meta($artist_id, '_artist_forum_id', true);

// Get subscriber count
global $wpdb;
$subscriber_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}artist_subscribers WHERE artist_id = %d",
    $artist_id
));
$subscriber_count = (int) $subscriber_count;

// Get current user permissions for action buttons
$current_user_id = get_current_user_id();
$user_artist_ids = get_user_meta($current_user_id, '_artist_profile_ids', true);
$user_artist_ids = is_array($user_artist_ids) ? $user_artist_ids : array();
$is_user_artist = in_array($artist_id, $user_artist_ids);
$show_manage_buttons = ($context === 'user-profile' || $context === 'dashboard') && $is_user_artist;

// Build inline style for hero background
$hero_style = '';
if ( $header_image_url ) {
    $hero_style = 'background-image: url(' . esc_url($header_image_url) . ');';
}
?>

<div class="artist-profile-card hero-card" data-artist-id="<?php echo esc_attr($artist_id); ?>" data-context="<?php echo esc_attr($context); ?>">
    <div class="artist-hero-section" style="<?php echo esc_attr($hero_style); ?>">
        <div class="artist-hero-overlay"></div>
        <div class="artist-hero-content">
            <?php if ( $profile_image_url ) : ?>
                <div class="artist-profile-image-overlay">
                    <img src="<?php echo esc_url($profile_image_url); ?>" alt="<?php echo esc_attr($artist_name); ?>" />
                </div>
            <?php endif; ?>
            
            <div class="artist-info-overlay">
                <h4 class="artist-name">
                    <a href="<?php echo esc_url($artist_url); ?>"><?php echo esc_html($artist_name); ?></a>
                </h4>
                
                <?php if ( $genre || $local_city ) : ?>
                    <div class="artist-meta">
                        <?php if ( $genre ) : ?>
                            <span class="artist-genre"><?php echo esc_html($genre); ?></span>
                        <?php endif; ?>
                        <?php if ( $genre && $local_city ) : ?>
                            <span class="meta-separator">•</span>
                        <?php endif; ?>
                        <?php if ( $local_city ) : ?>
                            <span class="artist-location"><?php echo esc_html($local_city); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="artist-card-content">
        <?php if ( $artist_bio ) : ?>
            <div class="artist-card-bio">
                <p><?php echo esc_html(wp_trim_words($artist_bio, 15, '...')); ?></p>
            </div>
        <?php endif; ?>
        
        
        <?php if ( $show_manage_buttons ) : ?>
            <div class="artist-card-actions">
                <a href="<?php echo esc_url(add_query_arg('artist_id', $artist_id, home_url('/manage-artist-profiles/'))); ?>" class="button">
                    <?php esc_html_e('Manage Profile', 'extrachill-artist-platform'); ?>
                </a>
                <?php if ( $link_page_id ) : ?>
                    <a href="<?php echo esc_url(add_query_arg('artist_id', $artist_id, home_url('/manage-link-page/'))); ?>" class="button">
                        <?php esc_html_e('Manage Links', 'extrachill-artist-platform'); ?>
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url(add_query_arg('artist_id', $artist_id, home_url('/manage-link-page/'))); ?>" class="button button-primary">
                        <?php esc_html_e('Create Links', 'extrachill-artist-platform'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <div class="artist-card-actions">
                <a href="<?php echo esc_url($artist_url); ?>" class="button button-primary">
                    <?php esc_html_e('View Profile', 'extrachill-artist-platform'); ?>
                </a>
                <?php if ( $forum_id && function_exists('bbp_get_forum_permalink') ) : ?>
                    <a href="<?php echo esc_url(bbp_get_forum_permalink($forum_id)); ?>" class="button">
                        <?php esc_html_e('Forum', 'extrachill-artist-platform'); ?>
                    </a>
                <?php endif; ?>
                <?php if ( $link_page_id ) : ?>
                    <?php 
                    $link_page_post = get_post($link_page_id);
                    if ( $link_page_post ) : 
                        $link_page_slug = $link_page_post->post_name;
                        $link_page_url = 'https://extrachill.link/' . $link_page_slug;
                    ?>
                        <a href="<?php echo esc_url($link_page_url); ?>" class="button" target="_blank">
                            <?php esc_html_e('Links', 'extrachill-artist-platform'); ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>