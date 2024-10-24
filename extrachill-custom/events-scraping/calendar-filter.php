<?php
// calendar-filter.php

/**
 * Modify The Events Calendar repository args to filter events by 'location' during AJAX requests.
 */
function ctc_modify_view_repository_args( $args, $context, $view ) {
    // Debugging output to ensure the function is called
    error_log( 'ctc_modify_view_repository_args called' );

    // Retrieve the 'tribe-bar-location' parameter from the context
    $location = $context->get( 'tribe-bar-location' );

    // Log the retrieved location value for debugging
    error_log( 'Location parameter: ' . print_r( $location, true ) );

    // If the location is not empty, add a tax_query for the location
    if ( ! empty( $location ) ) {
        if ( ! isset( $args['tax_query'] ) ) {
            $args['tax_query'] = array();
        }

        // Filter events based on the location slug
        $args['tax_query'][] = array(
            'taxonomy' => 'location',
            'field'    => 'slug',
            'terms'    => $location,
            'operator' => 'IN',
        );
    }

    return $args;
}
add_filter( 'tribe_events_views_v2_view_repository_args', 'ctc_modify_view_repository_args', 10, 3 );
