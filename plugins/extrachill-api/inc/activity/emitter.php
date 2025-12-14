<?php
/**
 * Activity event emitter listener.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'extrachill_activity_emit', 'extrachill_api_activity_handle_emit', 10, 1 );

function extrachill_api_activity_handle_emit( $event ) {
    if ( ! function_exists( 'extrachill_api_activity_insert' ) ) {
        return;
    }

    $result = extrachill_api_activity_insert( $event );

    if ( is_wp_error( $result ) ) {
        if ( function_exists( 'error_log' ) ) {
            error_log( '[extrachill-api] activity insert failed: ' . $result->get_error_message() );
        }
    }
}
