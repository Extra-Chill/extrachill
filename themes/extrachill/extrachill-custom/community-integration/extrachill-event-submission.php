<?php

// this code is used to add event submission modal to the site for logged-in users

/**
 * Enqueue scripts for event submission modal.
 */
function extrachill_enqueue_event_submission_scripts() {
    if ( tribe_is_event_query() ) { // Conditionally enqueue only on event calendar pages

        // Determine which script to enqueue based on login status (session token cookie)
        if ( isset( $_COOKIE['ecc_user_session_token'] ) ) {
            // Logged-in user: enqueue main modal script
            $script_path   = get_template_directory() . '/js/event-submission-modal.js';
            $script_handle = 'extrachill-event-submission-modal';
        } else {
            // Logged-out user: enqueue logged-out specific script
            $script_path   = get_template_directory() . '/js/event-submission-logged-out.js';
            $script_handle = 'extrachill-event-submission-modal-logged-out';
        }

        $script_version = filemtime( $script_path ); // Dynamic versioning

        wp_enqueue_script(
            $script_handle, // Dynamic handle based on login status
            get_template_directory_uri() . '/js/' . basename( $script_path ), // Dynamic source URL
            array(), // Dependencies (none for now)
            $script_version, // Version
            true // In footer
        );

        wp_localize_script( $script_handle, 'eventSubmissionModalData', array(
            'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
            'locationFilterNonce' => wp_create_nonce( 'location_filter_nonce' ),
            'venueNonce'          => wp_create_nonce( 'venue_nonce' ),
            'submissionNonce'     => wp_create_nonce( 'event_submission_nonce' ),
        ) );
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_event_submission_scripts' );

/**
 * AJAX handler to get location suggestions.
 */
function get_location_suggestions() {
    check_ajax_referer( 'location_filter_nonce', 'nonce' );

    $term = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';

    if ( empty( $term ) ) {
        wp_send_json_error( array( 'message' => 'No search term provided.' ) );
    }

    $locations = get_terms( array(
        'taxonomy'  => 'location',
        'search'    => $term,
        'hide_empty'=> false,
    ) );

    if ( is_wp_error( $locations ) || empty( $locations ) ) {
        wp_send_json_error( array( 'message' => 'No locations found.', 'error' => $locations ) );
    }

    $location_data = array_map( function( $location ) {
        return array(
            'id'   => $location->term_id,
            'name' => $location->name,
            'slug' => $location->slug,
        );
    } , $locations );

    wp_send_json_success( array( 'locations' => $location_data ) );
}
add_action( 'wp_ajax_get_location_suggestions', 'get_location_suggestions' );
add_action( 'wp_ajax_nopriv_get_location_suggestions', 'get_location_suggestions' );

/**
 * AJAX handler to get venue suggestions.
 */
function get_venue_suggestions() {
    check_ajax_referer( 'venue_nonce', 'nonce' );
    $term = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';
    if ( empty( $term ) ) {
        wp_send_json_error( array( 'message' => 'No search term provided.' ) );
    }
    $venues = get_posts( array(
        'post_type'   => 'tribe_venue',
        's'           => $term,
        'numberposts' => 10,
    ) );
    if ( empty( $venues ) ) {
        wp_send_json_success( array( 'venues' => array() ) );
    }
    $results = array();
    foreach ( $venues as $venue ) {
        $results[] = array(
            'id'   => $venue->ID,
            'name' => $venue->post_title,
        );
    }
    wp_send_json_success( array( 'venues' => $results ) );
}
add_action( 'wp_ajax_get_venue_suggestions', 'get_venue_suggestions' );
add_action( 'wp_ajax_nopriv_get_venue_suggestions', 'get_venue_suggestions' );

/**
 * AJAX handler to process event submission (handles both new and existing venues).
 */
function process_event_submission_ajax() {
    check_ajax_referer( 'event_submission_nonce', 'nonce' );

    // Ensure authorization credentials are defined for REST API calls
    if ( ! defined( 'EVENTS_CALENDAR_AUTH' ) ) {
        wp_send_json_error( array( 'message' => 'Authorization credentials not defined.' ) );
    }
    $authorization = 'Basic ' . base64_encode( EVENTS_CALENDAR_AUTH );

    error_log( 'process_event_submission_ajax: $_POST Data received: ' . print_r( $_POST, true ) );

    $title         = sanitize_text_field( $_POST['event-title'] ?? '' );
    $description   = sanitize_textarea_field( $_POST['event-description'] ?? '' );
    $date          = sanitize_text_field( $_POST['event-date'] ?? '' );
    $start_time    = sanitize_text_field( $_POST['event-time'] ?? '' );
    $end_time      = sanitize_text_field( $_POST['event-end-time'] ?? '' );
    $location      = sanitize_text_field( $_POST['event-location'] ?? '' );
    $submitted_venue_id = sanitize_text_field( $_POST['event-venue-id'] ?? '' );
    $venue_name    = sanitize_text_field( $_POST['event-venue'] ?? '' );
    $venue_address = sanitize_text_field( $_POST['venue-address'] ?? '' );
    $ticket_link    = esc_url_raw( $_POST['ticket-link'] ?? '' );

    if ( empty( $title ) || empty( $date ) ) {
        wp_send_json_error( array( 'message' => 'Title and Date are required.' ) );
    }

    $tz_string = get_option( 'timezone_string' )
        ? get_option( 'timezone_string' )
        : ( timezone_name_from_abbr( '', get_option( 'gmt_offset' ) * 3600, 0 ) ?: 'UTC' );
    $timezone  = new DateTimeZone( $tz_string );

    $start_time = $start_time ? "$start_time:00" : '00:00:00';
    $start_dt   = DateTime::createFromFormat( 'Y-m-d H:i:s', "$date $start_time", $timezone );
    if ( ! $start_dt ) {
        wp_send_json_error( array( 'message' => 'Invalid start date/time.' ) );
    }
    $start_datetime = $start_dt->format( 'Y-m-d H:i:s' );

    if ( empty( $end_time ) ) {
        $end_dt = clone $start_dt;
        $end_dt->modify( '+1 hour' );
    } else {
        $end_dt = DateTime::createFromFormat( 'Y-m-d H:i:s', "$date {$end_time}:00", $timezone );
        if ( ! $end_dt ) {
            wp_send_json_error( array( 'message' => 'Invalid end date/time.' ) );
        }
    }
    $end_datetime = $end_dt->format( 'Y-m-d H:i:s' );

    $location_term = $location ? get_term_by( 'name', $location, 'location' ) : false;

    $eventData = array(
        'title'       => $title,
        'description' => $description,
        'start_date'  => $start_datetime,
        'end_date'    => $end_datetime,
        'status'      => 'pending',
        'website'    => $ticket_link, // Use ticket-link as website for event
    );

    // --- New Venue Creation ---
    if ( empty( $submitted_venue_id ) && ! empty( $venue_name ) ) {
        error_log( 'process_event_submission_ajax: Starting New Venue Creation' );
        error_log( 'process_event_submission_ajax: $_POST data: ' . print_r( $_POST, true ) );
        error_log( 'process_event_submission_ajax: Venue Name (sanitized): ' . $venue_name );
        error_log( 'process_event_submission_ajax: Venue Address (sanitized): ' . $venue_address );

        $body = wp_json_encode( array(
            'venue'   => $venue_name,
            'address' => $venue_address,
        ) );
        error_log( 'process_event_submission_ajax: REST API request body (venue creation): ' . print_r( $body, true ) );

        $endpoint = get_site_url() . '/wp-json/tribe/events/v1/venues';
        $venue_creation_response = wp_remote_post( $endpoint, array(
            'headers' => array(
                'Authorization' => $authorization,
                'Content-Type'  => 'application/json',
            ),
            'body'    => $body,
        ) );

        if ( is_wp_error( $venue_creation_response ) ) {
            error_log( 'process_event_submission_ajax: REST API venue creation request failed (wp_error).' );
            error_log( 'process_event_submission_ajax: wp_remote_post() error message: ' . $venue_creation_response->get_error_message() );
            error_log( 'process_event_submission_ajax: Raw REST API response (venue creation): ' . print_r( $venue_creation_response, true ) );
            wp_send_json_error( array( 'message' => 'Venue creation error via REST API.' ) );
        } else {
            $venueResponseData = json_decode( wp_remote_retrieve_body( $venue_creation_response ), true );
            $venue_id = intval( $venueResponseData['id'] ?? 0 );
            if ( ! $venue_id ) {
                error_log( 'process_event_submission_ajax: Venue creation successful, but no venue ID returned.' );
                error_log( 'process_event_submission_ajax: REST API response data (venue creation): ' . print_r( $venueResponseData, true ) );
                wp_send_json_error( array( 'message' => 'Venue creation failed, no venue ID returned.' ) );
            }

            $eventData['venue'] = array( 'id' => $venue_id );

            // --- Event Creation with New Venue ---
            $response = wp_remote_post( get_site_url() . '/wp-json/tribe/events/v1/events', array(
                'headers'     => array(
                    'Authorization' => $authorization,
                    'Content-Type'  => 'application/json',
                ),
                'body'        => wp_json_encode( $eventData ),
                'method'      => 'POST',
                'data_format' => 'body',
            ) );

            if ( is_wp_error( $response ) ) {
                error_log( $response->get_error_message() );
                wp_send_json_error( array( 'message' => 'Event creation failed via REST API.' ) );
            }

            $responseData = json_decode( wp_remote_retrieve_body( $response ), true );
            $event_id     = intval( $responseData['id'] ?? 0 );
            if ( ! $event_id ) {
                error_log( 'REST response: ' . print_r( $responseData, true ) );
                wp_send_json_error( array( 'message' => 'Event creation failed, no event ID returned.' ) );
            }

            if ( $location_term ) {
                wp_set_object_terms( $event_id, array( (int) $location_term->term_id ), 'location', false );
            }

            send_event_submission_admin_email(
                $event_id, $title, $description, $date, $start_time, $end_time, $location, $venue_id, $venue_name
            );
            wp_send_json_success( array( 'message' => 'Event submitted successfully! Please allow 24-48 hours to see your event on the calendar.', 'event_id' => $event_id ) );
            return;
        }
    } elseif ( ! empty( $submitted_venue_id ) ) {
        // --- Use Existing Venue ---
        $venue_id = intval( $submitted_venue_id );
        $eventData['venue'] = array( 'id' => $venue_id );

        $response = wp_remote_post( get_site_url() . '/wp-json/tribe/events/v1/events', array(
            'headers'     => array(
                'Authorization' => $authorization,
                'Content-Type'  => 'application/json',
            ),
            'body'        => wp_json_encode( $eventData ),
            'method'      => 'POST',
            'data_format' => 'body',
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( $response->get_error_message() );
            wp_send_json_error( array( 'message' => 'Event creation failed via REST API.' ) );
        }

        $responseData = json_decode( wp_remote_retrieve_body( $response ), true );
        $event_id     = intval( $responseData['id'] ?? 0 );
        if ( ! $event_id ) {
            error_log( 'REST response: ' . print_r( $responseData, true ) );
            wp_send_json_error( array( 'message' => 'Event creation failed, no event ID returned.' ) );
        }

        if ( $location_term ) {
            wp_set_object_terms( $event_id, array( (int) $location_term->term_id ), 'location', false );
        }

        send_event_submission_admin_email(
            $event_id, $title, $description, $date, $start_time, $end_time, $location, $venue_id, $venue_name
        );
        wp_send_json_success( array( 'message' => 'Event submitted successfully!', 'event_id' => $event_id ) );
        return;
    } else {
        wp_send_json_error( array( 'message' => 'Venue is required.' ) );
        return;
    }
}
add_action( 'wp_ajax_submit_event', 'process_event_submission_ajax' );
add_action( 'wp_ajax_nopriv_submit_event', 'process_event_submission_ajax' );

/**
 * Send an admin email when a new event is submitted.
 */
function send_event_submission_admin_email( $event_id, $title, $description, $date, $start_time, $end_time, $location, $venue_id, $venue_name ) {
    $admin_email = get_option( 'admin_email' );
    $subject     = 'New Event Submitted: ' . $title;

    $message  = "A new event has been submitted:\n\n";
    $message .= "Title: {$title}\n";
    $message .= "Description: {$description}\n";
    $message .= "Date: {$date}\n";
    $message .= "Start Time: {$start_time}\n";
    $message .= "End Time: {$end_time}\n";
    $message .= "Location: {$location}\n";
    $message .= "Venue: " . ( $venue_id ? get_the_title( $venue_id ) : $venue_name ) . "\n";
    $message .= "Event Link: " . get_permalink( $event_id ) . "\n";

    wp_mail( $admin_email, $subject, $message );
}
