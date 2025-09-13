<?php

// this code is used to add upvoting functionality to the site with AJAX via custom REST API endpoints

function extrachill_sort_posts($query) {
    // Only modify the main query on the front-end and only for archive pages
    if (!is_admin() && $query->is_main_query() && is_archive()) {
        // Check for the 'sort' parameter in the URL
        $sort = isset($_GET['sort']) ? $_GET['sort'] : '';

        switch ($sort) {
            case 'oldest':
                // Sort posts by date ascending
                $query->set('orderby', 'date');
                $query->set('order', 'ASC');
                break;
            case 'recent':
            default:
                // Default sorting: by recent (date descending). No need to modify the query.
                break;
        }
    }
}
add_action('pre_get_posts', 'extrachill_sort_posts');








