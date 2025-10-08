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

    // Calculate viewing range
    $start = ( ( $current_page - 1 ) * $posts_per_page ) + 1;
    $end = min( $current_page * $posts_per_page, $total_results );

    // Generate count display
    if ( $total_results == 1 ) {
        $count_html = 'Viewing 1 result';
    } elseif ( $end == $start ) {
        $count_html = sprintf( 'Viewing result %s of %s', number_format( $start ), number_format( $total_results ) );
    } else {
        $count_html = sprintf( 'Viewing results %s-%s of %s total', number_format( $start ), number_format( $end ), number_format( $total_results ) );
    }

    // Generate pagination links
    $big = 999999999;
    $base_url = str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) );

    if ( ! empty( $_GET ) ) {
        $format = '&paged=%#%';
        if ( strpos( $base_url, '?' ) === false ) {
            $format = '?paged=%#%';
        }
    } else {
        $format = '?paged=%#%';
    }

    $links_html = paginate_links( array(
        'base'      => $base_url,
        'format'    => $format,
        'total'     => $max_num_pages,
        'current'   => $current_page,
        'prev_text' => '&laquo; Previous',
        'next_text' => 'Next &raquo;',
        'type'      => 'list',
        'end_size'  => 1,
        'mid_size'  => 2,
        'add_args'  => $_GET,
    ) );

    if ( $links_html ) {
        echo '<div class="extrachill-pagination pagination-search">';
        echo '<div class="pagination-count">' . esc_html( $count_html ) . '</div>';
        echo '<div class="pagination-links">' . $links_html . '</div>';
        echo '</div>';
    }
}