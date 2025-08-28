<?php
/**
 * Backend functions for fetching and managing Band Link Page Subscriber data.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches subscriber data for a given band profile ID.
 *
 * @param int $artist_id The ID of the band profile.
 * @param array $args Optional arguments for querying.
 * @return array List of subscriber objects or empty array.
 */
function extrch_get_artist_subscribers( $artist_id, $args = array() ) {
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

/**
 * AJAX handler to fetch subscriber data for a band.
 */
function extrch_fetch_artist_subscribers_ajax() {
    // Check for required parameters
    if ( ! isset( $_POST['artist_id'], $_POST['_ajax_nonce'] ) ) {
        wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'extrachill-artist-platform' ) ) );
    }

    $artist_id = absint( $_POST['artist_id'] );
    $nonce = sanitize_text_field( $_POST['_ajax_nonce'] );

    // Verify nonce
    if ( ! wp_verify_nonce( $nonce, 'extrch_fetch_subscribers_nonce' ) ) { // Use a new nonce action for fetching
        wp_send_json_error( array( 'message' => __( 'Security check failed.', 'extrachill-artist-platform' ) ) );
    }

    // Basic permission check: Does the current user have the capability to manage this artist's members?
    if ( ! current_user_can( 'manage_artist_members', $artist_id ) ) {
         wp_send_json_error( array( 'message' => __( 'You do not have permission to view subscribers for this artist.', 'extrachill-artist-platform' ) ) );
    }

    // Pagination support
    $per_page = isset($_POST['per_page']) ? max(1, absint($_POST['per_page'])) : 20;
    $page = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
    $offset = ($page - 1) * $per_page;

    // Fetch paginated subscribers
    $subscribers = extrch_get_artist_subscribers( $artist_id, array(
        'limit' => $per_page,
        'offset' => $offset,
    ) );

    // Get total count for pagination
    global $wpdb;
    $table_name = $wpdb->prefix . 'artist_subscribers';
    $total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE artist_profile_id = %d", $artist_id ) );

    if ( $subscribers !== false ) {
        wp_send_json_success( array(
            'subscribers' => $subscribers,
            'total' => intval($total),
            'per_page' => $per_page,
            'page' => $page,
        ) );
    }

        wp_send_json_error( array( 'message' => __( 'Could not fetch subscriber data.', 'extrachill-artist-platform' ) ) );
    wp_die(); // Always include wp_die() at the end of AJAX handlers
}

// Hook the handler to WordPress AJAX actions (only for logged-in users as it's an admin function)
add_action( 'wp_ajax_extrch_fetch_artist_subscribers', 'extrch_fetch_artist_subscribers_ajax' );

/**
 * Handles the CSV export of band subscriber data.
 * Hooked to admin_post and admin_post_nopriv.
 */
function extrch_export_artist_subscribers_csv() {
    error_log('extrch_export_artist_subscribers_csv: Function started.');

    // Check for required parameters (artist_id and nonce) - accept GET or POST
    // Using $_REQUEST to get parameters from either GET or POST
    if ( ! isset( $_REQUEST['artist_id'], $_REQUEST['_wpnonce'] ) ) {
        error_log('extrch_export_artist_subscribers_csv: Missing required parameters (GET/POST).');
        exit;
    }
    error_log('extrch_export_artist_subscribers_csv: Required parameters found (GET/POST).');

    $artist_id = absint( $_REQUEST['artist_id'] );
    $nonce = sanitize_text_field( $_REQUEST['_wpnonce'] );
    error_log('extrch_export_artist_subscribers_csv: Band ID: ' . $artist_id . ', Nonce: ' . $nonce);

    // Verify nonce (using the nonce action defined in the subscribers tab template)
    if ( ! wp_verify_nonce( $nonce, 'export_artist_subscribers_csv_' . $artist_id ) ) {
        error_log('extrch_export_artist_subscribers_csv: Nonce verification failed.');
        exit;
    }
    error_log('extrch_export_artist_subscribers_csv: Nonce verified.');

    // Permission check: Does the current user have the capability to manage this band's members?
    if ( ! current_user_can( 'manage_artist_members', $artist_id ) ) {
         error_log('extrch_export_artist_subscribers_csv: Permission denied for band ID: ' . $artist_id);
         exit;
    }
    error_log('extrch_export_artist_subscribers_csv: Permission granted.');

    // Determine if we should include already exported subscribers - accept GET or POST
    $include_exported = isset($_REQUEST['include_exported']) && $_REQUEST['include_exported'] == '1';
    $exported_filter = $include_exported ? null : 0;
    error_log('extrch_export_artist_subscribers_csv: Include exported: ' . ($include_exported ? 'Yes' : 'No'));

    // Fetch subscriber data
    $subscribers = extrch_get_artist_subscribers( $artist_id, array( 'limit' => -1, 'exported' => $exported_filter ) );
    error_log('extrch_export_artist_subscribers_csv: Fetched ' . count($subscribers) . ' subscribers.');

    // Get band name for filename
    $artist_name = get_the_title( $artist_id );
    $filename = sanitize_title( $artist_name ) . '-subscribers-' . date( 'Y-m-d' ) . '.csv';
    error_log('extrch_export_artist_subscribers_csv: Filename: ' . $filename);

    // Clean any output buffer before setting headers
    if (ob_get_contents()) {
        ob_clean();
    }
    error_log('extrch_export_artist_subscribers_csv: Output buffer cleaned (if active).');

    // Set headers for CSV download
    header( 'Content-Type: text/csv; charset=' . get_option( 'blog_charset' ) );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"');
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );
    error_log('extrch_export_artist_subscribers_csv: Headers set.');

    // Output CSV content
    $output = fopen( 'php://output', 'w' );
    error_log('extrch_export_artist_subscribers_csv: Output stream opened.');

    // Add BOM for UTF-8 support in some spreadsheet programs
    fputs( $output, chr(0xEF) . chr(0xBB) . chr(0xBF) );

    // Add CSV headers
    fputcsv( $output, array( __( 'Email', 'extrachill-artist-platform' ), __( 'Username', 'extrachill-artist-platform' ), __( 'Subscribed At (UTC)', 'extrachill-artist-platform' ), __( 'Exported', 'extrachill-artist-platform' ) ) );
    error_log('extrch_export_artist_subscribers_csv: CSV headers written.');

    // Add data rows and collect IDs to mark as exported if needed
    $subscriber_ids_to_mark_exported = array();
    foreach ( $subscribers as $subscriber ) {
        $is_exported = (isset($subscriber->exported) && $subscriber->exported == 1);
        fputcsv( $output, array(
            $subscriber->subscriber_email,
            $subscriber->username,
            $subscriber->subscribed_at,
            $is_exported ? __( 'Yes', 'extrachill-artist-platform' ) : __( 'No', 'extrachill-artist-platform' )
        ) );
        // Only mark as exported if not already exported and not including all
        if (!$is_exported && !$include_exported) {
            $subscriber_ids_to_mark_exported[] = $subscriber->subscriber_id;
        }
    }
    error_log('extrch_export_artist_subscribers_csv: Data rows written.');

    fclose( $output );
    error_log('extrch_export_artist_subscribers_csv: Output stream closed.');

    // Mark exported subscribers as exported in the database (only if not including all)
    if ( !$include_exported && ! empty( $subscriber_ids_to_mark_exported ) ) {
         error_log('extrch_export_artist_subscribers_csv: Marking ' . count($subscriber_ids_to_mark_exported) . ' subscribers as exported.');
         global $wpdb;
         $table_name = $wpdb->prefix . 'artist_subscribers';
         $ids_string = implode( ', ', array_map( 'absint', $subscriber_ids_to_mark_exported ) );
         $wpdb->query("UPDATE $table_name SET exported = 1 WHERE subscriber_id IN ($ids_string)");
    }

    // For AJAX, just exit after sending output
    exit; // Or let the function naturally end
}

// Hook the export function to the admin-ajax actions instead of admin-post
remove_action( 'admin_post_extrch_export_subscribers_csv', 'extrch_export_artist_subscribers_csv' );

add_action( 'wp_ajax_extrch_export_subscribers_csv', 'extrch_export_artist_subscribers_csv' );
add_action( 'wp_ajax_nopriv_extrch_export_subscribers_csv', 'extrch_export_artist_subscribers_csv' ); // Allow non-logged-in if needed, though subscribers is likely for logged in

/**
 * AJAX handler for public subscribe form on the link page.
 * Accepts: artist_id, subscriber_email, _ajax_nonce
 */
function extrch_link_page_subscribe_ajax_handler() {
    // Validate required fields
    if ( ! isset( $_POST['artist_id'], $_POST['subscriber_email'], $_POST['_ajax_nonce'] ) ) {
        wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'extrachill-artist-platform' ) ), 400 );
    }
    $artist_id = absint( $_POST['artist_id'] );
    $email = sanitize_email( $_POST['subscriber_email'] );
    $nonce = sanitize_text_field( $_POST['_ajax_nonce'] );

    // Verify nonce
    if ( ! wp_verify_nonce( $nonce, 'extrch_subscribe_nonce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'extrachill-artist-platform' ) ), 403 );
    }
    // Validate artist_id
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        wp_send_json_error( array( 'message' => __( 'Invalid band specified.', 'extrachill-artist-platform' ) ), 400 );
    }
    // Validate email
    if ( ! is_email( $email ) ) {
        wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'extrachill-artist-platform' ) ), 400 );
    }
    global $wpdb;
    $table = $wpdb->prefix . 'artist_subscribers';
    // Check for existing subscription
    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE artist_profile_id = %d AND subscriber_email = %s", $artist_id, $email ) );
    if ( $exists ) {
        wp_send_json_error( array( 'message' => __( 'You are already subscribed to this band.', 'extrachill-artist-platform' ) ), 409 );
    }
    // Insert new subscriber
    $result = $wpdb->insert( $table, array(
        'artist_profile_id'   => $artist_id,
        'subscriber_email'  => $email,
        'username'          => '', // Optionally fill if user is logged in
        'subscribed_at'     => current_time( 'mysql', 1 ), // GMT
        'exported'          => 0,
    ), array( '%d', '%s', '%s', '%s', '%d' ) );
    if ( $result ) {
        wp_send_json_success( array( 'message' => __( 'Thank you for subscribing!', 'extrachill-artist-platform' ) ) );
    } else {
        wp_send_json_error( array( 'message' => __( 'Could not save your subscription. Please try again later.', 'extrachill-artist-platform' ) ), 500 );
    }
    wp_die();
}
add_action( 'wp_ajax_extrch_link_page_subscribe', 'extrch_link_page_subscribe_ajax_handler' );
add_action( 'wp_ajax_nopriv_extrch_link_page_subscribe', 'extrch_link_page_subscribe_ajax_handler' );

// Note: CSV export function will be added next. 