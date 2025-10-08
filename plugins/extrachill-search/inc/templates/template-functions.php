<?php
/**
 * Template Functions for ExtraChill Search Plugin
 *
 * @package ExtraChill\Search
 * @since 1.0.0
 */

function extrachill_get_search_results() {
    $search_term = get_search_query();
    $search_results = array();
    $total_results = 0;
    $current_page = max( 1, get_query_var( 'paged' ) );
    $posts_per_page = get_option( 'posts_per_page', 10 );
    $offset = ( $current_page - 1 ) * $posts_per_page;

    if ( ! empty( $search_term ) && function_exists( 'extrachill_multisite_search' ) ) {
        $search_data = extrachill_multisite_search(
            $search_term,
            array(),
            array(
                'limit'        => $posts_per_page,
                'offset'       => $offset,
                'return_count' => true,
            )
        );

        if ( ! empty( $search_data ) && is_array( $search_data ) ) {
            $search_results = isset( $search_data['results'] ) ? $search_data['results'] : array();
            $total_results = isset( $search_data['total'] ) ? $search_data['total'] : 0;
        }
    }

    return array( 'results' => $search_results, 'total' => $total_results );
}

/**
 * Create mock WP_Query object for search results pagination
 *
 * Allows use of theme's native extrachill_pagination() function with
 * multisite search results by providing required WP_Query properties.
 *
 * @param int $total_results Total number of search results
 * @param int $posts_per_page Number of results per page
 * @return WP_Query Mock query object with pagination data
 */
function extrachill_create_search_query_object( $total_results, $posts_per_page ) {
    $current_page = max( 1, get_query_var( 'paged' ) );

    // Create mock WP_Query object
    $query = new WP_Query();
    $query->found_posts = $total_results;
    $query->max_num_pages = ceil( $total_results / $posts_per_page );
    $query->query_vars['posts_per_page'] = $posts_per_page;
    $query->query_vars['paged'] = $current_page;

    return $query;
}