<?php

// session-tokens.php contains various functons related to session management and seamless integration of extrachill.com and community.extrachill.com. the code lives on community.extrachill.com

add_action('wp_login', 'set_ecc_user_logged_in_token', 10, 2);
function set_ecc_user_logged_in_token($user_login, $user) {
    $token = generate_community_session_token();
    // Store the token and user ID in the database
    store_user_session($token, $user->ID);
    
    setcookie('ecc_user_session_token', $token, [
        'expires' => time() + (6 * 30 * 24 * 60 * 60), // 6 months
        'path' => '/',
        'domain' => '.extrachill.com',
        'secure' => is_ssl(),
        'httponly' => false,
        'samesite' => 'Lax'
    ]); 


}

function generate_community_session_token() {
    // Use a secure method to generate a unique token
    $token_name = 'ecc_user_session_token';
    $token_value = bin2hex(random_bytes(32)); // Generates a 64-character hex token
    return $token_value;
}

function is_user_logged_in_via_token($user_id) {
    global $wpdb;
    $token = $_COOKIE['ecc_user_session_token'] ?? '';

    // Assuming the presence of user_id in your request handling or another method to obtain it.
    $table_name = $wpdb->prefix . 'user_session_tokens';
    $sql = $wpdb->prepare("SELECT token FROM {$table_name} WHERE user_id = %d AND token = %s AND expiration > NOW()", $user_id, $token);
    $valid_token = $wpdb->get_var($sql);

    return $valid_token ? true : false; // Return true if a valid token exists, false otherwise
}


function store_user_session($token, $user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_session_tokens';
    // Set to 6 months from now
    $expiration_mysql = date('Y-m-d H:i:s', time() + (6 * 30 * 24 * 60 * 60));

    $wpdb->replace(
        $table_name,
        [
            'token' => $token,
            'user_id' => $user_id,
            'expiration' => $expiration_mysql
        ],
        ['%s', '%d', '%s']
    );
}



function clear_user_session($token) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_session_tokens';

    // Correctly delete the session token from the database
    $wpdb->delete(
        $table_name,
        ['token' => $token], // Correct column name here
        ['%s'] // Value format for the token
    );
}

add_action('wp_logout', function() {
    if (isset($_COOKIE['ecc_user_session_token'])) {
        $token = $_COOKIE['ecc_user_session_token'];
        clear_user_session($token);

        // Also clear the cookie client-side
        setcookie('ecc_user_session_token', '', time() - 3600, '/', '.extrachill.com');
    }
});


function create_session_tokens_table() {
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_session_tokens';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        token VARCHAR(128) NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        expiration DATETIME NOT NULL,
        PRIMARY KEY  (token),
    ) $charset_collate;";

    dbDelta($sql);
}

add_action('init', 'create_session_tokens_table');

add_action('init', function() {
    // Specify allowed origins
    $allowed_origins = ['https://staging.extrachill.com', 'https://extrachill.com'];
    $http_origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array($http_origin, $allowed_origins)) {
        header('Access-Control-Allow-Origin: ' . $http_origin);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, Authorization');
        header('Access-Control-Allow-Credentials: true');
    }

    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
            header('Access-Control-Allow-Headers: ' . $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);

        exit(0);
    }
});

add_action('init', 'auto_login_via_session_token');
function auto_login_via_session_token() {
    if (is_user_logged_in()) {
        // User is already logged in, no need to check the session token.
        return;
    }

    if (empty($_COOKIE['ecc_user_session_token'])) {
        // No session token present.
        return;
    }

    global $wpdb;
    $token = $_COOKIE['ecc_user_session_token'];
    $user_id = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->prefix}user_session_tokens WHERE token = %s AND expiration > NOW()",
        $token
    ));

    if ($user_id) {
        // Check if the session token update flag is set for this user.
        // Transient names should be unique per user, thus incorporating user_id.
        $session_updated = get_transient('session_updated_for_user_' . $user_id);

        if (!$session_updated) {
            // Token is valid, and we haven't updated the session expiration yet.
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);

            // Update the token expiration date in the database.
            $newExpiration = date('Y-m-d H:i:s', time() + (6 * 30 * 24 * 60 * 60));
            $wpdb->update(
                "{$wpdb->prefix}user_session_tokens",
                ['expiration' => $newExpiration],
                ['token' => $token],
                ['%s'], // Value format for 'expiration'
                ['%s']  // Value format for 'token'
            );

            // Set a transient to avoid updating the session expiration on subsequent requests.
            set_transient('session_updated_for_user_' . $user_id, true, DAY_IN_SECONDS);

            do_action('wp_login', $user->user_login, $user);
        }
    }
}

