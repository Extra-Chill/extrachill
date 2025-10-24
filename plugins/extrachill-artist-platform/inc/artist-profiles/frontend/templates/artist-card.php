<?php
/**
 * Artist Card Loop Template
 *
 * Displays artist profile card in WordPress loop context.
 * Used in homepage, directory, and archive pages.
 *
 * Works with standard WordPress loop - uses global $post.
 *
 * @package ExtraChillArtistPlatform
 */

// Get artist data from args or fallback to loop context
$artist_id = isset($artist_id) ? $artist_id : get_the_ID();
$artist_post = get_post($artist_id);
$artist_name = $artist_post ? $artist_post->post_title : '';
$artist_url = get_permalink($artist_id);
$artist_bio = $artist_post ? $artist_post->post_content : '';

// Get images
$profile_image_id = get_post_thumbnail_id($artist_id);
$profile_image_url = $profile_image_id ? wp_get_attachment_image_url($profile_image_id, 'thumbnail') : '';
$header_image_id = get_post_meta($artist_id, '_artist_profile_header_image_id', true);
$header_image_url = $header_image_id ? wp_get_attachment_image_url($header_image_id, 'large') : '';

// Get meta data
$genre = get_post_meta($artist_id, '_genre', true);
$local_city = get_post_meta($artist_id, '_local_city', true);

// Build inline style for hero background
$hero_style = '';
if ($header_image_url) {
    $hero_style = 'background-image: url(' . esc_url($header_image_url) . ');';
}
?>

<div class="artist-profile-card">
    <div class="artist-hero-section" <?php if ($hero_style) echo 'style="' . esc_attr($hero_style) . '"'; ?>>
        <div class="artist-hero-overlay"></div>
        <div class="artist-hero-content">
            <?php if ($profile_image_url) : ?>
                <div class="artist-profile-image-overlay">
                    <img src="<?php echo esc_url($profile_image_url); ?>" alt="<?php echo esc_attr($artist_name); ?>" />
                </div>
            <?php endif; ?>

            <div class="artist-info-overlay">
                <h4 class="artist-name">
                    <a href="<?php echo esc_url($artist_url); ?>"><?php echo esc_html($artist_name); ?></a>
                </h4>

                <?php if ($genre || $local_city) : ?>
                    <div class="artist-meta">
                        <?php if ($genre) : ?>
                            <span class="artist-genre"><?php echo esc_html($genre); ?></span>
                        <?php endif; ?>
                        <?php if ($genre && $local_city) : ?>
                            <span class="meta-separator">•</span>
                        <?php endif; ?>
                        <?php if ($local_city) : ?>
                            <span class="artist-location"><?php echo esc_html($local_city); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="artist-card-content">
        <?php if ($artist_bio) : ?>
            <div class="artist-card-bio">
                <p><?php echo esc_html(wp_trim_words($artist_bio, 15, '...')); ?></p>
            </div>
        <?php endif; ?>

        <div class="artist-card-actions">
            <a href="<?php echo esc_url($artist_url); ?>" class="button-1 button-medium" data-action-button>
                <?php esc_html_e('View Profile', 'extrachill-artist-platform'); ?>
            </a>
            <?php
            /**
             * Action hook for extending artist card actions
             *
             * Allows other parts of the plugin to add additional buttons or actions
             * to the artist card based on context (homepage, directory, etc.)
             *
             * @param int $artist_id The artist profile post ID
             */
            do_action('ec_artist_card_actions', $artist_id);
            ?>
        </div>
    </div>
</div>
