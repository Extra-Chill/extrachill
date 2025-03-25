<?php

// this code is used to display a subscribe form in the navigation menu and handle AJAX request

function extrachill_enqueue_nav_scripts() {
    $script_path = '/js/nav-menu.js';
    $version = filemtime(get_stylesheet_directory() . $script_path);
    
    wp_enqueue_script('extrachill-nav-menu', get_template_directory_uri() . $script_path, array(), $version, true);

    // Localize script to pass AJAX URL and nonce
    wp_localize_script('extrachill-nav-menu', 'ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'subscribe_nonce' => wp_create_nonce('subscribe_to_sendy')
    ));
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_nav_scripts');

function navigation_subscribe() {
    // Verify nonce for security
    if (!isset($_POST['subscribe_nonce']) || !wp_verify_nonce($_POST['subscribe_nonce'], 'subscribe_to_sendy')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }

    // Retrieve and sanitize the email
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    if (empty($email) || !is_email($email)) {
        wp_send_json_error(array('message' => 'Invalid email address.'));
    }

    // Sendy API settings
    $sendy_url = 'https://mail.extrachill.com/sendy/subscribe';
    $sendy_api_key = 'z7RZLH84oEKNzMvFZhdt';
    $list_id = 'wg9wVXn26X8DiRykWSigwA';

    // Prepare data for Sendy API request
    $data = array(
        'email' => $email,
        'list' => $list_id,
        'boolean' => 'true',
        'api_key' => $sendy_api_key
    );

    // Make the request to Sendy
    $response = wp_remote_post($sendy_url, array(
        'method' => 'POST',
        'body' => $data
    ));

    // Check for errors in response
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Subscription failed. Please try again.'));
    } else {
        wp_send_json_success(array('message' => 'Thank you for subscribing!'));
    }
}
add_action('wp_ajax_subscribe_to_sendy', 'navigation_subscribe');
add_action('wp_ajax_nopriv_subscribe_to_sendy', 'navigation_subscribe');
