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
$latest_topic_title = __('No topics yet', 'generatepress_child');
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
        $latest_topic_title = __('No topics yet', 'generatepress_child');
    }
} else {
    $latest_topic_title = __('Forum not linked', 'generatepress_child');
}
?>
<li id="band-<?php echo esc_attr($band_id); ?>" <?php post_class('bbp-band-item', $band_id); ?>>
    <div class="band-card">
        <?php if (has_post_thumbnail($band_id)) : ?>
            <div class="band-card-header">
                <div class="band-card-image"><a href="<?php echo esc_url(get_permalink($band_id)); ?>"><?php echo get_the_post_thumbnail($band_id, 'medium_large'); ?></a></div>
                <h3 class="band-card-title band-title-overlay"><a href="<?php echo esc_url(get_permalink($band_id)); ?>"><?php echo esc_html(get_the_title($band_id)); ?></a></h3>
            </div>
        <?php else : ?>
            <h3 class="band-card-title band-title-no-image"><a href="<?php echo esc_url(get_permalink($band_id)); ?>"><?php echo esc_html(get_the_title($band_id)); ?></a></h3>
        <?php endif; ?>
        <div class="band-card-details">
            <?php if ($genre) : ?><p class="band-card-genre"><?php esc_html_e('Genre:', 'generatepress_child'); ?> <?php echo esc_html($genre); ?></p><?php endif; ?>
            <?php if ($city) : ?><p class="band-card-location"><?php esc_html_e('Location:', 'generatepress_child'); ?> <?php echo esc_html($city); ?></p><?php endif; ?>
            <p class="band-card-activity"><?php esc_html_e('Last Activity:', 'generatepress_child'); ?> <?php echo $last_activity ? esc_html(human_time_diff($last_activity)) . ' ago' : 'N/A'; ?></p>
            <p class="band-card-latest-topic"><?php esc_html_e('Latest Topic:', 'generatepress_child'); ?> <a href="<?php echo esc_url($latest_topic_url); ?>"><?php echo esc_html($latest_topic_title); ?></a></p>
        </div>
    </div>
</li> 