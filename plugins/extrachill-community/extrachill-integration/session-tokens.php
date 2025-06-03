<?php

// session-tokens.php contains various functons related to session management and seamless integration of extrachill.com and community.extrachill.com. the code lives on community.extrachill.com

add_action('wp_login', 'set_ecc_user_logged_in_token', 10, 2);
function set_ecc_user_logged_in_token($user_login, $user) {
    $token = generate_community_session_token();
    // Store the token and user ID in the database
    store_user_session($token, $user->ID);
    
    // Set cookie for the base domain and subdomains
    $cookie_params = [
        'expires' => time() + (6 * 30 * 24 * 60 * 60), // For 6 months.
        'path' => '/',
        'domain' => '.extrachill.com', // Set for base domain and subdomains
        'secure' => true, // Ensure your site is served over HTTPS.
        'httponly' => false, // Set to true if you want to prohibit JavaScript access.
        'samesite' => 'None' // Or 'Lax', depending on your cross-site request needs.
    ];
    setcookie('ecc_user_session_token', $token, $cookie_params);
    
    // Also set the cookie specifically for the alias domain if the login happened there
    $current_host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );
    if ( $current_host === 'community.extrachill.com' || $current_host === 'extrachill.link' ) {
         $alias_cookie_params = [
             'expires' => time() + (6 * 30 * 24 * 60 * 60), // For 6 months.
             'path' => '/',
             'domain' => 'extrachill.link', // Explicitly set for the alias domain
             'secure' => true, // Ensure your site is served over HTTPS.
             'httponly' => false, // Set to true if you want to prohibit JavaScript access.
             'samesite' => 'None' // Or 'Lax', depending on your cross-site request needs.
         ];
         setcookie('ecc_user_session_token', $token, $alias_cookie_params);
         error_log('[DEBUG SESSION TOKEN] Set ecc_user_session_token cookie for extrachill.link');
    }
}

function generate_community_session_token() {
    // Use a secure method to generate a unique token
    $token_name = 'ecc_user_session_token';
    $token_value = bin2hex(random_bytes(32)); // Generates a 64-character hex token
    return $token_value;
}


function store_user_session($token, $user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_session_tokens';

    // Set the expiration to 6 months from now
    $expiration_mysql = date('Y-m-d H:i:s', time() + (6 * 30 * 24 * 60 * 60));

    // Store the new session token without deleting previous ones to allow multiple active sessions
    $wpdb->insert(
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

    // Check if the table already exists
    if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $sql = "CREATE TABLE $table_name (
            token VARCHAR(128) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            expiration DATETIME NOT NULL,
            PRIMARY KEY  (token)
        ) $charset_collate;";

        dbDelta($sql);
    }
}

// Hook the function to the 'after_switch_theme' action
add_action('after_switch_theme', 'create_session_tokens_table');


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

function is_user_logged_in_via_token($user_id) {
    global $wpdb;
    $token = $_COOKIE['ecc_user_session_token'] ?? '';

    $table_name = $wpdb->prefix . 'user_session_tokens';
    $sql = $wpdb->prepare("SELECT token FROM {$table_name} WHERE user_id = %d AND token = %s AND expiration > NOW()", $user_id, $token);
    $valid_token = $wpdb->get_var($sql);

    return $valid_token ? true : false; // Return true if a valid token exists, false otherwise
}

add_action('init', 'auto_login_via_session_token', 1); // Priority 1 to run early

function auto_login_via_session_token() {
    error_log(message: '[DEBUG SESSION TOKEN] auto_login_via_session_token fired on host: ' . ($_SERVER['HTTP_HOST'] ?? 'N/A'));
    if (is_user_logged_in()) {
        error_log('[DEBUG SESSION TOKEN] User already logged in via WordPress standard session.');
        return;
    }

    if (empty($_COOKIE['ecc_user_session_token'])) {
        error_log('[DEBUG SESSION TOKEN] ecc_user_session_token cookie NOT found.');
        return;
    }
    error_log('[DEBUG SESSION TOKEN] ecc_user_session_token cookie FOUND: ' . $_COOKIE['ecc_user_session_token']);

    global $wpdb;
    $token = $_COOKIE['ecc_user_session_token'];
    $user_id = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->prefix}user_session_tokens WHERE token = %s AND expiration > NOW()",
        $token
    ));

    if ($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            error_log('[DEBUG SESSION TOKEN] User ID ' . $user_id . ' found from token, but get_user_by(\'id\') failed.');
            return;
        }
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);

        // Optionally, trigger the wp_login action to mimic the standard login process.
        do_action('wp_login', $user->user_login, $user);
        error_log('[DEBUG SESSION TOKEN] wp_set_current_user and wp_set_auth_cookie CALLED for User ID: ' . $user_id);
    } else {
        error_log('[DEBUG SESSION TOKEN] Token found in cookie, but no valid User ID found in database or token expired.');
    }
}



function cleanup_expired_session_tokens() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_session_tokens';
    $wpdb->query(
        "DELETE FROM $table_name WHERE expiration < NOW()"
    );
}

// Schedule this cleanup to run periodically, e.g., via WP-Cron
if (!wp_next_scheduled('cleanup_expired_session_tokens_hook')) {
    wp_schedule_event(time(), 'daily', 'cleanup_expired_session_tokens_hook');
}

add_action('cleanup_expired_session_tokens_hook', 'cleanup_expired_session_tokens');

