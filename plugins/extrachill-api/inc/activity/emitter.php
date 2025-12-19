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

    $normalized = extrachill_api_activity_normalize_event( $event );
    if ( is_wp_error( $normalized ) ) {
        return;
    }

    if ( extrachill_api_activity_should_throttle( $normalized ) ) {
        return;
    }

    $result = extrachill_api_activity_insert( $event );

    if ( is_wp_error( $result ) ) {
        if ( function_exists( 'error_log' ) ) {
            error_log( '[extrachill-api] activity insert failed: ' . $result->get_error_message() );
        }
        return;
    }

    extrachill_api_activity_mark_emitted( $normalized );
}
