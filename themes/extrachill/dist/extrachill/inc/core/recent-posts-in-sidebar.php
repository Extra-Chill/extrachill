<?php

// this code is used to display recent posts in the sidebar with a shortcode and exclude the current post from the list and add a category filter nd a tag filter

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

    $post_id = get_the_ID();
    $current_post_type = get_post_type($post_id);
    $args = [];
    $title = 'Recent Posts';
    $archive_url = '';

    if (is_single()) {
        if ($current_post_type === 'post') {
            $categories = get_the_category($post_id);
            if ($categories) {
                $category = $categories[0];
                $args = array(
                    'post_type' => 'post',
                    'posts_per_page' => 3,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'post__not_in' => array_merge($displayed_posts, [$post_id]),
                    'category__in' => [$category->term_id],
                );
                $archive_url = esc_url(get_category_link($category->term_id));
                $title = sprintf(
                    'More from <a href="%s" class="sidebar-tax-link" title="View all posts in %s" aria-label="View all posts in %s">%s</a>',
                    $archive_url,
                    esc_html($category->name),
                    esc_html($category->name),
                    esc_html($category->name)
                );
            } else {
                $args = array(
                    'post_type' => 'post',
                    'posts_per_page' => 3,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'post__not_in' => array_merge($displayed_posts, [$post_id]),
                );
            }
        } elseif ($current_post_type === 'festival_wire') {
            $args = array(
                'post_type' => 'festival_wire',
                'posts_per_page' => 3,
                'orderby' => 'date',
                'order' => 'DESC',
                'post__not_in' => $displayed_posts,
            );
            $archive_url = esc_url(get_post_type_archive_link('festival_wire'));
            $title = 'Latest Festival Wire';
        } else {
            $args = array(
                'post_type' => 'post',
                'posts_per_page' => 3,
                'orderby' => 'date',
                'order' => 'DESC',
                'post__not_in' => array_merge($displayed_posts, [$post_id]),
            );
        }
    } else {
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => 3,
            'orderby' => 'date',
            'order' => 'DESC',
            'post__not_in' => $displayed_posts,
        );
    }

    // Add caching for better sidebar performance
    $cache_key = 'sidebar_recent_' . md5(serialize($args));
    $cached_posts = get_transient($cache_key);

    if ($cached_posts === false) {
        $query = new WP_Query($args);
        $cached_posts = $query->posts;
        // Use longer cache duration - will be cleared when posts are updated
        set_transient($cache_key, $cached_posts, HOUR_IN_SECONDS * 4); // 4 hour cache
    } else {
        // Create mock query object for template compatibility
        $query = new WP_Query();
        $query->posts = $cached_posts;
        $query->post_count = count($cached_posts);
        
        // Initialize required query variables to prevent PHP 8+ undefined array key warnings
        $query->query_vars = array_merge([
            'fields' => '',
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
            'lazy_load_term_meta' => false,
            'ignore_sticky_posts' => false
        ], $query->query_vars ?? []);
        
        // Set current post index for proper iteration
        $query->current_post = -1;
    }
    $output = '';
    $counter = 0;
    if ($query->have_posts()) :
        $output = '<div class="my-recent-posts">';
        $output .= '<h3 class="widget-title sidebar-recent-title-margin"><span>' . $title . '</span></h3>';
        while ($query->have_posts()) : $query->the_post();
            $post_id = get_the_ID();
            $counter++;
            if (!in_array($post_id, $displayed_posts)) {
                $displayed_posts[] = $post_id;
            }
            $output .= '<div class="post mini-card">';
            if (has_post_thumbnail()) {
                $output .= '<a id="post-thumbnail-link-' . $counter . '" href="' . get_permalink() . '" aria-label="Read more about ' . esc_attr(get_the_title()) . ', an image is attached"><div class="post-thumbnail">' . get_the_post_thumbnail($post_id, 'medium_large') . '</div></a>';
            }
            $output .= '<h2 class="recent-title"><a id="post-title-link-' . $counter . '" href="' . get_permalink() . '" aria-label="Read more about ' . esc_attr(get_the_title()) . '">' . get_the_title() . '</a></h2>';
            $output .= '</div>';
        endwhile;
        wp_reset_postdata();
        $output .= '</div>';
    endif;
    return $output;
}
add_shortcode('my_recent_posts', 'my_recent_posts_shortcode');

/**
 * Clear sidebar recent posts cache when posts are updated
 * 
 * @param int $post_id The post ID that was updated
 */
function extrachill_clear_sidebar_recent_posts_cache($post_id) {
    $post_type = get_post_type($post_id);
    
    // Clear sidebar cache for relevant post types
    if (in_array($post_type, ['post', 'newsletter'])) {
        global $wpdb;
        // Clear all sidebar recent post caches since they can have different query parameters
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sidebar_recent_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sidebar_recent_%'");
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Sidebar recent posts cache cleared due to {$post_type} update (ID: {$post_id})");
        }
    }
}

// Hook cache clearing to content update actions
add_action('save_post', 'extrachill_clear_sidebar_recent_posts_cache');
add_action('wp_trash_post', 'extrachill_clear_sidebar_recent_posts_cache');
add_action('untrash_post', 'extrachill_clear_sidebar_recent_posts_cache');
add_action('delete_post', 'extrachill_clear_sidebar_recent_posts_cache');
