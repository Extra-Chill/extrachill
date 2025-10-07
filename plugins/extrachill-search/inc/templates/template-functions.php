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

function extrachill_search_pagination( $total_results, $posts_per_page ) {
    if ( $total_results <= $posts_per_page ) {
        return;
    }

    $current_page = max( 1, get_query_var( 'paged' ) );
    $max_num_pages = ceil( $total_results / $posts_per_page );

    $pagination_args = array(
        'total'     => $max_num_pages,
        'current'   => $current_page,
        'prev_text' => '&laquo; Previous',
        'next_text' => 'Next &raquo;',
        'type'      => 'list',
    );

    echo '<div class="extrachill-pagination pagination-search">';
    echo paginate_links( $pagination_args );
    echo '</div>';
}