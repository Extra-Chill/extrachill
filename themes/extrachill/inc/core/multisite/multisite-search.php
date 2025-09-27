<?php
/**
 * Multisite Search - Real-Time Search Across Sites
 *
 * Provides unified search functionality across WordPress multisite network.
 * Searches both local site and community forum in real-time with no caching.
 */

/**
 * Fetch forum results from community site using multisite functions
 */
function ec_fetch_forum_results_multisite($search_term, $limit = 100) {
    if (empty($search_term)) {
        return array();
    }

    if (!is_multisite()) {
        error_log('Forum search multisite error: WordPress multisite not detected');
        return array();
    }

    $results = array();

    switch_to_blog(2);

    try {
        $args = array(
            'post_type'      => array('topic', 'reply'),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            's'              => $search_term,
            'meta_query'     => array(
                array(
                    'key'     => '_bbp_forum_id',
                    'value'   => '',
                    'compare' => '!='
                )
            )
        );

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                global $post;

                // Create virtual post object for forum content
                $forum_post = new stdClass();
                $forum_post->ID = $post->ID;
                $forum_post->post_title = get_the_title();
                $forum_post->post_content = get_the_content();
                $forum_post->post_excerpt = ec_get_contextual_excerpt_multisite(wp_strip_all_tags(get_the_content()), $search_term, 30);
                $forum_post->post_date = $post->post_date;
                $forum_post->post_type = $post->post_type;
                $forum_post->post_name = $post->post_name;
                $forum_post->is_forum_post = true;

                $results[] = $forum_post;
            }
            wp_reset_postdata();
        }
    } catch (Exception $e) {
        error_log('Forum search error: ' . $e->getMessage());
    }

    restore_current_blog();

    return $results;
}

/**
 * Get contextual excerpt for search results
 */
function ec_get_contextual_excerpt_multisite($content, $search_term, $word_limit = 30) {
    $content = wp_strip_all_tags($content);
    $words = explode(' ', $content);

    if (count($words) <= $word_limit) {
        return $content;
    }

    // Find the search term in the content
    $search_pos = stripos($content, $search_term);
    if ($search_pos !== false) {
        // Get words around the search term
        $start_word = max(0, floor($search_pos / 6) - ($word_limit / 2));
        $excerpt_words = array_slice($words, $start_word, $word_limit);
        return ($start_word > 0 ? '...' : '') . implode(' ', $excerpt_words) . '...';
    }

    // Fallback to first words
    return implode(' ', array_slice($words, 0, $word_limit)) . '...';
}

/**
 * Hijack search query to include real-time forum results
 */
add_filter('posts_pre_query', 'ec_hijack_search_query', 10, 2);
function ec_hijack_search_query($posts, $query) {
    // Only hijack main search queries on frontend
    if (!$query->is_main_query() || is_admin() || !$query->is_search()) {
        return null; // Let WordPress proceed normally
    }

    $search_term = $query->get('s');
    if (empty($search_term)) {
        return null;
    }

    // Get real-time forum results from community site
    $forum_posts = ec_fetch_forum_results_multisite($search_term, 100);

    // Get real-time local posts
    $local_args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 100,
        's' => $search_term,
        'fields' => 'all'
    );
    $local_query = new WP_Query($local_args);
    $local_posts = $local_query->posts;

    // Merge and sort all posts by date (most recent first)
    $all_posts = array_merge($local_posts, $forum_posts);
    usort($all_posts, function($a, $b) {
        return strtotime($b->post_date) - strtotime($a->post_date);
    });

    // Calculate pagination
    $posts_per_page = get_option('posts_per_page');
    $total_posts = count($all_posts);
    $max_num_pages = ceil($total_posts / $posts_per_page);
    $current_page = max(1, get_query_var('paged', 1));

    // Get posts for current page
    $offset = ($current_page - 1) * $posts_per_page;
    $paginated_posts = array_slice($all_posts, $offset, $posts_per_page);

    // Set query vars for pagination
    $query->found_posts = $total_posts;
    $query->max_num_pages = $max_num_pages;

    return $paginated_posts;
}

/**
 * Get contextual excerpt for search results
 * Enhanced version for local search results with search term highlighting context
 */
if (!function_exists('ec_get_contextual_excerpt')) {
    function ec_get_contextual_excerpt($content, $search_term, $word_limit = 30) {
        $position = stripos($content, $search_term);
        if ($position === false) {
            // If no match, fallback to default trimmed content
            $excerpt = '...' . wp_trim_words($content, $word_limit) . '...';
        } else {
            // Get surrounding text
            $words = explode(' ', $content);
            $match_position = 0;

            // Count words until we find the match
            foreach ($words as $index => $word) {
                if (stripos($word, $search_term) !== false) {
                    $match_position = $index;
                    break;
                }
            }

            // Get the range of words around the match
            $start = max(0, $match_position - floor($word_limit / 2));
            $length = min(count($words) - $start, $word_limit);

            // Extract the excerpt
            $excerpt = array_slice($words, $start, $length);

            // Add ellipses based on whether we're truncating at the start or end
            $prefix = $start > 0 ? '...' : '';
            $suffix = ($start + $length) < count($words) ? '...' : '';

            $excerpt = $prefix . implode(' ', $excerpt) . $suffix;
        }

        return $excerpt;
    }
}

/**
 * Display search query header on search results pages
 */
add_action('extrachill_archive_above_posts', 'ec_display_search_header');
function ec_display_search_header() {
    if (!is_search()) {
        return;
    }

    echo '<div class="search-header"><h2>Search Results for: <span class="search-query">' . esc_html(get_search_query()) . '</span></h2></div>';
}

/**
 * Ensure forum post links point to community site
 */
add_filter('post_link', 'ec_forum_post_permalink', 10, 2);
add_filter('the_permalink', 'ec_forum_post_permalink', 10, 2);
function ec_forum_post_permalink($permalink, $post) {
    if (isset($post->is_forum_post) && $post->is_forum_post) {
        return 'https://community.extrachill.com/forums/' . $post->post_type . '/' . $post->post_name . '/';
    }
    return $permalink;
}