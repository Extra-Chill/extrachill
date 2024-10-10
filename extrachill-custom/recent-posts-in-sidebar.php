<?php

function initialize_displayed_posts() {
    if (is_home() || is_single() || is_archive() || is_search()) {
        global $displayed_posts;
        if (!isset($displayed_posts) || !is_array($displayed_posts)) {
            $displayed_posts = array();
        }
    }
}
add_action('wp', 'initialize_displayed_posts');

function track_displayed_posts() {
    if (is_home() || is_single() || is_archive() || is_search()) {
        global $displayed_posts, $post;
        if (!isset($displayed_posts) || !is_array($displayed_posts)) {
            $displayed_posts = array();
        }
        if (isset($post->ID) && !in_array($post->ID, $displayed_posts)) {
            $displayed_posts[] = $post->ID;
        }
    }
}
add_action('the_post', 'track_displayed_posts');

function my_recent_posts_shortcode() {
    global $displayed_posts;

    if (!isset($displayed_posts) || !is_array($displayed_posts)) {
        $displayed_posts = array();
    }

    $cache_key = 'my_recent_posts_' . md5(implode('_', $displayed_posts));
    $output = get_transient($cache_key);

    if ($output === false) {
        $excluded_author = 'saraichinwag';
        $args = array(
            'post_type'      => 'post',
            'posts_per_page' => 3,
            'orderby'        => 'post_modified', // Use 'post_modified' to leverage the index
            'order'          => 'DESC',
            'post__not_in'   => array_merge($displayed_posts, array(get_the_ID())), // Exclude displayed posts and current post
            'tax_query'      => array(
                array(
                    'taxonomy' => 'author',
                    'field'    => 'name',
                    'terms'    => $excluded_author,
                    'operator' => 'NOT IN',
                ),
            ),
        );

        $query = new WP_Query($args);
        $output = '<div class="my-recent-posts">';
        $counter = 0;

        if ($query->have_posts()) :
            while ($query->have_posts()) : $query->the_post();
                $post_id = get_the_ID();
                $counter++;

                if (!in_array($post_id, $displayed_posts)) {
                    $displayed_posts[] = $post_id; // Add the post ID to the displayed posts array
                }

                $output .= '<div class="post">';
                if (has_post_thumbnail()) {
                    $output .= '<a id="post-thumbnail-link-' . $counter . '" href="' . get_permalink() . '" aria-label="Read more about ' . esc_attr(get_the_title()) . ', an image is attached"><div class="post-thumbnail">' . get_the_post_thumbnail($post_id, 'medium_large') . '</div></a>';
                }
                $output .= '<h2 class="recent-title"><a id="post-title-link-' . $counter . '" href="' . get_permalink() . '" aria-label="Read more about ' . esc_attr(get_the_title()) . '">' . get_the_title() . '</a></h2>';
                $output .= '</div>';
            endwhile;
            wp_reset_postdata();
        endif;

        $output .= '</div>';

        set_transient($cache_key, $output, 0); // Cache indefinitely
    }

    return $output;
}

add_shortcode('my_recent_posts', 'my_recent_posts_shortcode');

function clear_my_recent_posts_transient($post_id) {
    // Make sure this doesn't run during an autosave or for non-public post types
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (get_post_status($post_id) !== 'publish') return;

    // Clear all transients starting with 'my_recent_posts_'
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_my_recent_posts_%'
        )
    );
}
add_action('save_post', 'clear_my_recent_posts_transient');
