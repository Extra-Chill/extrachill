<?php
/**
 * Artist Profile Subscriber Data Functions
 * 
 * Backend functions for fetching and managing artist profile subscriber data.
 * Handles subscriber management and CSV exports for artist profiles.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches subscriber data for a given artist profile ID.
 *
 * @param int $artist_id The ID of the artist profile.
 * @param array $args Optional arguments for querying.
 * @return array List of subscriber objects or empty array.
 */
function extrachill_artist_get_artist_subscribers( $artist_id, $args = array() ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'artist_subscribers';
    $artist_id = absint( $artist_id );

    if ( empty( $artist_id ) ) {
        return array();
    }

    // Default arguments
    $defaults = array(
        'orderby' => 'subscribed_at',
        'order' => 'DESC',
        'limit' => -1, // -1 for no limit, fetch all
        'offset' => 0,
        'exported' => null, // null means include all, 0 for not exported, 1 for exported
    );
    $args = wp_parse_args( $args, $defaults );

    $sql = "SELECT * FROM $table_name WHERE artist_profile_id = %d";
    $sql_args = array( $artist_id );

    // Filter by exported status if specified
    if ( $args['exported'] !== null ) {
        $sql .= " AND exported = %d";
        $sql_args[] = absint( $args['exported'] );
    }

    // Add order by clause
    $orderby = sanitize_sql_orderby( $args['orderby'] ); // Sanitize orderby input
    $order = ( strtoupper( $args['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';
    if ( $orderby ) {
         // Ensure ordering by a valid column to prevent SQL errors
         $valid_orderby = array('subscriber_id', 'artist_profile_id', 'subscriber_email', 'username', 'subscribed_at', 'exported');
         if ( in_array( $orderby, $valid_orderby ) ) {
            $sql .= " ORDER BY " . $orderby . " " . $order;
         }
    }

    // Add limit and offset
    if ( $args['limit'] > 0 ) {
        $sql .= " LIMIT %d";
        $sql_args[] = absint( $args['limit'] );
        if ( $args['offset'] >= 0 ) {
            $sql .= " OFFSET %d";
            $sql_args[] = absint( $args['offset'] );
        }
    }

    // Prepare and execute the query
    $query = $wpdb->prepare( $sql, $sql_args );
    $subscribers = $wpdb->get_results( $query );

    return is_array( $subscribers ) ? $subscribers : array();
}
