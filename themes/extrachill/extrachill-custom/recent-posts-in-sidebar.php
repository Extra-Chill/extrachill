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

    $args = array(
        'post_type' => 'post',
        'posts_per_page' => 3,
        'orderby' => 'post_modified', // Use 'post_modified' to leverage the index
        'order' => 'DESC',
        'post__not_in' => array_merge($displayed_posts, array(get_the_ID())), // Exclude displayed posts and current post
        'category__in' => array(), // Initialize category filter - will be conditionally added
    );

    $use_tag_query = false;
    if (is_single()) {
        $tags = get_the_tags();
        if ($tags) {
            foreach ($tags as $tag) {
                $tag_post_count = get_term($tag->term_id, 'post_tag')->count;
                if ($tag_post_count > 3) {
                    $args['tag__in'] = array($tag->term_id);
                    unset($args['category__in']); // Remove category filter
                    $title = sprintf(
                        'More from <a href="%1$s" title="View all posts in %2$s" aria-label="View all posts in %2$s">%2$s</a>',
                        esc_url(get_tag_link($tag->term_id)),
                        esc_html($tag->name)
                    );
                    $use_tag_query = true;
                    break; // Stop after finding the first qualifying tag
                }
            }
        }
        if (!$use_tag_query) {
            $categories = get_the_category();
            if ($categories) {
                $category = $categories[0]; // Use the first category
                $args['category__in'] = array($category->term_id); // Set the category filter for the query
                $title = sprintf(
                    'More from <a href="%1$s" title="View all posts in %2$s" aria-label="View all posts in %2$s">%2$s</a>',
                    esc_url(get_category_link($category->term_id)),
                    esc_html($category->name)
                );
            } else {
                $title = 'Recent Posts'; // Default title if no category or tag
            }
        }
        
    } else {
        $title = 'Recent Posts'; // Default title for non-single pages
    }

    $query = new WP_Query($args);
    $output = '<div class="my-recent-posts">';
    $output .= '<h3 class="widget-title"><span>' . $title . '</span></h3>'; // Add title to output
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

    return $output;
}

add_shortcode('my_recent_posts', 'my_recent_posts_shortcode');
