<?php

/**
 * Post Sorting Functionality
 * Provides URL-based sorting options for archive pages
 *
 * @package ExtraChill
 * @since 1.0
 */

/**
 * Modify main query to support URL-based sorting on archive pages
 * Responds to 'sort' GET parameter with 'oldest' or 'recent' values
 *
 * @param WP_Query $query The WordPress query object
 * @return void
 * @since 1.0
 */
function extrachill_sort_posts($query) {
    if (!is_admin() && $query->is_main_query() && is_archive()) {
        $sort = isset($_GET['sort']) ? $_GET['sort'] : '';

        switch ($sort) {
            case 'oldest':
                $query->set('orderby', 'date');
                $query->set('order', 'ASC');
                break;
            case 'recent':
            default:
                break;
        }
    }
}
add_action('pre_get_posts', 'extrachill_sort_posts');








