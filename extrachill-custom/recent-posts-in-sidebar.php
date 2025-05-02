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

    $query = new WP_Query($args);
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
