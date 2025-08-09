<?php
/**
 * Partial: Single Band Card
 * Usage: get_template_part('bbpress/loop', 'single-band-card', ['band_id' => $band_id]);
 */
$band_id = isset($args['band_id']) ? $args['band_id'] : get_the_ID();
if (!$band_id) return;

// Fetch meta
$genre = get_post_meta($band_id, '_genre', true);
$city = get_post_meta($band_id, '_local_city', true);
$band_forum_id = get_post_meta($band_id, '_band_forum_id', true);

// Last activity
if (function_exists('bp_get_band_profile_last_activity_timestamp')) {
    $last_activity = bp_get_band_profile_last_activity_timestamp($band_id);
} else {
    $last_activity = get_post_modified_time('U', false, $band_id);
}

// Latest topic
$latest_topic_title = __('No topics yet', 'extra-chill-community');
$latest_topic_url = '#';
if ($band_forum_id) {
    $latest_topic_args = array(
        'post_type' => bbp_get_topic_post_type(),
        'posts_per_page' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
        'post_status' => array('publish', 'closed'),
        'meta_query' => array(array('key' => '_bbp_forum_id', 'value' => $band_forum_id)),
        'no_found_rows' => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    );
    $latest_topic_query = new WP_Query($latest_topic_args);
    if ($latest_topic_query->have_posts()) {
        $latest_topic_query->the_post();
        $latest_topic_title = get_the_title();
        $latest_topic_url = get_the_permalink();
        wp_reset_postdata();
    } else {
        $latest_topic_title = __('No topics yet', 'extra-chill-community');
    }
} else {
    $latest_topic_title = __('Forum not linked', 'extra-chill-community');
}

// Fetch band header image ID
$header_image_id = get_post_meta($band_id, '_band_profile_header_image_id', true);

$visuals_container_classes = 'band-card-visuals-container';
if (has_post_thumbnail($band_id)) {
    $visuals_container_classes .= ' has-profile-picture';
}

?>
<li id="band-<?php echo esc_attr($band_id); ?>" <?php post_class('bbp-band-item', $band_id); ?>>
    <div class="band-card">
        <div class="<?php echo esc_attr($visuals_container_classes); ?>">
            <?php 
            // Display header image if available
            if ($header_image_id) {
                echo '<a href="' . esc_url(get_permalink($band_id)) . '" class="band-card-header-link">';
                echo '<div class="band-card-header-image-wrapper">'; // Wrapper for the header image itself
                echo wp_get_attachment_image($header_image_id, 'large', false, array('class' => 'band-card-header-img'));
                echo '</div>';
                echo '</a>';
            } elseif (has_post_thumbnail($band_id)) { // ELIF: No header, BUT there IS a profile picture
                // Output placeholder only if no header image IS SET AND a profile picture IS SET
                echo '<a href="' . esc_url(get_permalink($band_id)) . '" class="band-card-header-link">';
                echo '<div class="band-card-header-image-placeholder"></div>';
                echo '</a>';
            }
            // If no header AND no profile picture, nothing is output here for the header area, which is correct.

            // Display profile picture (post thumbnail) if available
            if (has_post_thumbnail($band_id)) : ?>
                <div class="band-card-profile-image">
                    <a href="<?php echo esc_url(get_permalink($band_id)); ?>">
                        <?php echo get_the_post_thumbnail($band_id, 'thumbnail', array('class' => 'band-profile-pic-square')); // Use 'thumbnail' or a specific square size ?>
                    </a>
                </div>
            <?php endif; ?>
            
            <?php 
            $title_classes = 'band-card-title';
            if ($header_image_id) { // If there's a header, it's an overlay style
                $title_classes .= ' band-title-overlay';
            } elseif (!has_post_thumbnail($band_id) && !$header_image_id) { // No header AND no profile pic means "no image" style
                $title_classes .= ' band-title-no-image';
            }
            // If only profile pic (and no header), it will just be 'band-card-title' 
            // and its left position is handled by '.has-profile-picture .band-card-title' CSS rule.
            ?>
            <h3 class="<?php echo esc_attr($title_classes); ?>"><a href="<?php echo esc_url(get_permalink($band_id)); ?>"><?php echo esc_html(get_the_title($band_id)); ?></a></h3>
        </div>

        <div class="band-card-details">
            <?php if ($genre) : ?><p class="band-card-genre"><?php esc_html_e('Genre:', 'extra-chill-community'); ?> <?php echo esc_html($genre); ?></p><?php endif; ?>
            <?php if ($city) : ?><p class="band-card-location"><?php esc_html_e('Location:', 'extra-chill-community'); ?> <?php echo esc_html($city); ?></p><?php endif; ?>
            <p class="band-card-activity"><?php esc_html_e('Last Activity:', 'extra-chill-community'); ?> <?php echo $last_activity ? esc_html(human_time_diff($last_activity)) . ' ago' : 'N/A'; ?></p>
            <p class="band-card-latest-topic"><?php esc_html_e('Latest Topic:', 'extra-chill-community'); ?> <a href="<?php echo esc_url($latest_topic_url); ?>"><?php echo esc_html($latest_topic_title); ?></a></p>
        </div>
    </div>
</li> 