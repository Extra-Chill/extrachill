<?php
function enqueue_login_popup_script() {
    // Don't enqueue the script on the /login or /register pages
    if (strpos($_SERVER['REQUEST_URI'], '/login') !== false || strpos($_SERVER['REQUEST_URI'], '/register') !== false) {
        return; // Exit if on login or register page
    }

    $script_path = get_stylesheet_directory() . '/js/login-popup.js';
    $ver = date('ymd-Gis', filemtime($script_path)); // Dynamic versioning to avoid cache issues
    wp_enqueue_script('login-popup', get_stylesheet_directory_uri() . '/js/login-popup.js', array(), $ver, true);

    wp_localize_script('login-popup', 'ajax_object', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'fetchNonce' => wp_create_nonce('fetch_login_form_nonce'),
        'loginNonce' => wp_create_nonce('ajax_login_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'enqueue_login_popup_script');


function ajax_login() {
    check_ajax_referer('ajax_login_nonce', 'nonce');
    $creds = array('user_login' => $_POST['log'], 'user_password' => $_POST['pwd'], 'remember' => true);
    $user = wp_signon($creds, is_ssl());

    if (is_wp_error($user)) {
        wp_send_json_error(['message' => $user->get_error_message()]);
    } else {
        wp_send_json_success(['message' => 'Login successful', 'redirect_url' => home_url()]);
    }
}
add_action('wp_ajax_nopriv_ajax_login', 'ajax_login');
add_action('wp_ajax_ajax_login', 'ajax_login');




function fetch_login_form() {
    check_ajax_referer('fetch_login_form_nonce', 'nonce');  // This must match the nonce action used in wp_create_nonce
    echo do_shortcode('[wp_surgeon_login]');
    wp_die();
}



add_action('wp_ajax_nopriv_fetch_login_form', 'fetch_login_form');
add_action('wp_ajax_fetch_login_form', 'fetch_login_form');

