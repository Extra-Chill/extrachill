<?php
/**
 * Modify The Events Calendar query to filter events by custom taxonomy 'location'.
 */

/**
 * Filter events based on the selected location for non-AJAX and AJAX requests.
 */
function ctc_filter_events_by_location( $query ) {
    // Only modify the main query on the front end
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    // Check if it's an events query
    if ( ! function_exists( 'tribe_is_event_query' ) || ! tribe_is_event_query( $query ) ) {
        return;
    }

    // Check if 'tribe-bar-location' parameter is set in the request
    if (
        isset( $_REQUEST['tribe-bar-location'] ) &&
        ! empty( $_REQUEST['tribe-bar-location'] )
    ) {
        $location = sanitize_text_field( $_REQUEST['tribe-bar-location'] );

        // Get existing tax_query
        $tax_query = (array) $query->get( 'tax_query' );

        // Set relation to 'AND' if there are existing tax queries
        if ( count( $tax_query ) > 0 ) {
            $tax_query['relation'] = 'AND';
        }

        // Append the location filter
        $tax_query[] = array(
            'taxonomy' => 'location',
            'field'    => 'slug',
            'terms'    => $location,
        );

        // Apply the modified tax_query to the query
        $query->set( 'tax_query', $tax_query );

        // Add logging for debugging
        error_log( 'ctc_filter_events_by_location applied. Location: ' . $location );
    } else {
        error_log( 'ctc_filter_events_by_location: No location parameter' );
    }
}
add_action( 'pre_get_posts', 'ctc_filter_events_by_location' );

/**
 * Modify view switcher links to include the 'tribe-bar-location' parameter.
 */
function ctc_modify_view_selector_links( $views ) {
    if ( isset( $_REQUEST['tribe-bar-location'] ) && ! empty( $_REQUEST['tribe-bar-location'] ) ) {
        $location = sanitize_text_field( $_REQUEST['tribe-bar-location'] );
        foreach ( $views as &$view ) {
            $view['url'] = add_query_arg( 'tribe-bar-location', $location, $view['url'] );
        }
    }
    return $views;
}
add_filter( 'tribe_events_views_v2_view_selector_views', 'ctc_modify_view_selector_links' );

/**
 * Modify The Events Calendar repository args to filter events by 'location' during AJAX requests.
 */
function ctc_modify_view_repository_args( $repository_args, $context, $view ) {
    // Check if 'tribe-bar-location' is set in the request
    if ( isset( $_REQUEST['tribe-bar-location'] ) && ! empty( $_REQUEST['tribe-bar-location'] ) ) {
        $location = sanitize_text_field( $_REQUEST['tribe-bar-location'] );

        // Initialize 'tax_query' if not set
        if ( ! isset( $repository_args['tax_query'] ) ) {
            $repository_args['tax_query'] = array();
        }

        // Append the location filter to 'tax_query'
        $repository_args['tax_query'][] = array(
            'taxonomy' => 'location',
            'field'    => 'slug',
            'terms'    => $location,
        );

        // Add logging for debugging
        error_log( 'ctc_modify_view_repository_args applied. Location: ' . $location );
    } else {
        error_log( 'ctc_modify_view_repository_args: No location parameter' );
    }

    return $repository_args;
}
add_filter( 'tribe_events_views_v2_view_repository_args', 'ctc_modify_view_repository_args', 10, 3 );
