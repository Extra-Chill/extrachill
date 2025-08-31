<?php
/**
 * Subscription AJAX Handlers
 * 
 * Single responsibility: Handle all AJAX requests related to subscription functionality
 */

// Register subscription AJAX actions using WordPress native patterns
add_action( 'wp_ajax_extrch_link_page_subscribe', 'extrch_link_page_subscribe_ajax_handler' );
add_action( 'wp_ajax_nopriv_extrch_link_page_subscribe', 'extrch_link_page_subscribe_ajax_handler' );
add_action( 'wp_ajax_render_subscribe_template', 'ec_ajax_render_subscribe_template' );

/**
 * AJAX handler for public subscribe form on the link page.
 * Accepts: artist_id, subscriber_email, _ajax_nonce
 */
function extrch_link_page_subscribe_ajax_handler() {
    // Validate required fields
    if ( ! isset( $_POST['artist_id'], $_POST['subscriber_email'], $_POST['_ajax_nonce'] ) ) {
        wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'extrachill-artist-platform' ) ), 400 );
    }
    $artist_id = apply_filters('ec_get_artist_id', $_POST);
    $email = sanitize_email( $_POST['subscriber_email'] );
    $nonce = sanitize_text_field( $_POST['_ajax_nonce'] );

    // Verify nonce
    if ( ! wp_verify_nonce( $nonce, 'extrch_subscribe_nonce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'extrachill-artist-platform' ) ), 403 );
    }
    // Validate artist_id
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        wp_send_json_error( array( 'message' => __( 'Invalid artist specified.', 'extrachill-artist-platform' ) ), 400 );
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
        wp_send_json_error( array( 'message' => __( 'You are already subscribed to this artist.', 'extrachill-artist-platform' ) ), 409 );
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

/**
 * AJAX handler for rendering subscription templates
 * Returns HTML for subscription modal or inline form using unified template system
 */
function ec_ajax_render_subscribe_template() {
    try {
        // Get and validate parameters
        $template_type = wp_unslash( sanitize_text_field( $_POST['template_type'] ?? '' ) );
        $artist_id = isset( $_POST['artist_id'] ) ? (int) $_POST['artist_id'] : 0;
        $artist_name = wp_unslash( sanitize_text_field( $_POST['artist_name'] ?? '' ) );
        $description = wp_unslash( sanitize_textarea_field( $_POST['description'] ?? '' ) );
        
        if ( ! in_array( $template_type, array( 'inline_form', 'modal' ) ) ) {
            wp_send_json_error( array( 'message' => 'Invalid template type' ) );
            return;
        }
        
        if ( ! $artist_id ) {
            wp_send_json_error( array( 'message' => 'Artist ID required' ) );
            return;
        }
        
        // Prepare template arguments matching what the PHP templates expect
        $template_args = array(
            'artist_id' => $artist_id,
            'artist_name' => $artist_name,
            'data' => array(
                'display_title' => $artist_name,
                '_link_page_subscribe_description' => $description
            )
        );
        
        // Determine template name
        $template_name = $template_type === 'modal' ? 'subscribe-modal' : 'subscribe-inline-form';
        
        // Render template using unified system
        $html = ec_render_template( $template_name, $template_args );
        
        wp_send_json_success( array( 'html' => $html ) );
        
    } catch ( Exception $e ) {
        error_log( 'Subscribe template rendering error: ' . $e->getMessage() );
        wp_send_json_error( array( 'message' => 'Subscribe template rendering failed' ) );
    }
}