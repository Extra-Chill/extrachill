<?php
/**
 * Cross-Domain Session Management
 * 
 * Manages secure session tokens for seamless authentication across
 * extrachill.com and community.extrachill.com domains. Handles cookie
 * setting for multiple domains and session validation.
 * 
 * @package Extra ChillCommunity
 */

add_action('wp_login', 'set_ecc_user_logged_in_token', 10, 2);
function set_ecc_user_logged_in_token($user_login, $user) {
    $token = generate_community_session_token();
    
    // Store the token and user ID in the database
    $store_result = store_user_session($token, $user->ID);
    
    // Set cookie for the base domain and subdomains
    $cookie_params = [
        'expires' => time() + (6 * 30 * 24 * 60 * 60), // For 6 months.
        'path' => '/',
        'domain' => '.extrachill.com', // Set for base domain and subdomains
        'secure' => true, // Ensure your site is served over HTTPS.
        'httponly' => false, // Set to true if you want to prohibit JavaScript access.
        'samesite' => 'None' // Or 'Lax', depending on your cross-site request needs.
    ];
    $cookie_result1 = setcookie('ecc_user_session_token', $token, $cookie_params);
    
    // ALWAYS set the cookie for extrachill.link domain (not just conditionally)
         $alias_cookie_params = [
             'expires' => time() + (6 * 30 * 24 * 60 * 60), // For 6 months.
             'path' => '/',
        'domain' => '.extrachill.link', // Use dot prefix to include all subdomains
        'secure' => true, // Ensure your site is served over HTTPS.
        'httponly' => false, // Set to true if you want to prohibit JavaScript access.
        'samesite' => 'None' // Or 'Lax', depending on your cross-site request needs.
    ];
    error_log('[DEBUG] Attempting to set cookie for .extrachill.link domain');
    $cookie_result2 = setcookie('ecc_user_session_token', $token, $alias_cookie_params);
    error_log('[DEBUG] .extrachill.link cookie result: ' . ($cookie_result2 ? 'SUCCESS' : 'FAILED'));
    
    // Also set without dot prefix as fallback for root domain
    $root_cookie_params = [
        'expires' => time() + (6 * 30 * 24 * 60 * 60), // For 6 months.
        'path' => '/',
        'domain' => 'extrachill.link', // Root domain without dot
             'secure' => true, // Ensure your site is served over HTTPS.
             'httponly' => false, // Set to true if you want to prohibit JavaScript access.
             'samesite' => 'None' // Or 'Lax', depending on your cross-site request needs.
         ];
    error_log('[DEBUG] Attempting to set cookie for extrachill.link domain (no dot)');
    $cookie_result3 = setcookie('ecc_user_session_token', $token, $root_cookie_params);
    error_log('[DEBUG] extrachill.link cookie result: ' . ($cookie_result3 ? 'SUCCESS' : 'FAILED'));
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
    $result = $wpdb->insert(
        $table_name,
        [
            'token' => $token,
            'user_id' => $user_id,
            'expiration' => $expiration_mysql
        ],
        ['%s', '%d', '%s']
    );
    
    return $result !== false;
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

// Hook the function to multiple actions to ensure table creation
add_action('after_switch_theme', 'create_session_tokens_table');
add_action('init', 'create_session_tokens_table');


add_action('init', function() {
    // Specify allowed origins
    $allowed_origins = ['https://staging.extrachill.com', 'https://extrachill.com', 'https://extrachill.link'];
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
    $current_host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );
    
    if (is_user_logged_in()) {
        return;
    }

    if (empty($_COOKIE['ecc_user_session_token'])) {
        return;
    }

    global $wpdb;
    $token = $_COOKIE['ecc_user_session_token'];
    
    $table_name = $wpdb->prefix . 'user_session_tokens';
    $query = $wpdb->prepare(
        "SELECT user_id FROM {$table_name} WHERE token = %s AND expiration > NOW()",
        $token
    );
    
    $user_id = $wpdb->get_var($query);

    if ($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }
        
        // Set cookie for extrachill.link domain to ensure cross-domain auth works
        if ( $current_host === 'community.extrachill.com' ) {
            $alias_cookie_params = [
                'expires' => time() + (6 * 30 * 24 * 60 * 60), // For 6 months.
                'path' => '/',
                'domain' => 'extrachill.link', // Explicitly set for the alias domain
                'secure' => true, // Ensure your site is served over HTTPS.
                'httponly' => false, // Set to true if you want to prohibit JavaScript access.
                'samesite' => 'None' // Or 'Lax', depending on your cross-site request needs.
            ];
            setcookie('ecc_user_session_token', $token, $alias_cookie_params);
        }
        
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
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

// AJAX handler for cross-domain session synchronization
add_action('wp_ajax_sync_session_token', 'handle_sync_session_token');
add_action('wp_ajax_nopriv_sync_session_token', 'handle_sync_session_token');

function handle_sync_session_token() {
    // Set CORS headers for extrachill.link
    header('Access-Control-Allow-Origin: https://extrachill.link');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    $artist_id = isset($_GET['artist_id']) ? intval($_GET['artist_id']) : 0;
    
    // Check if user is logged in on community domain
    if (is_user_logged_in()) {
        $current_user_id = get_current_user_id();
        
        // Check if user can manage this band
        if ($artist_id && current_user_can('manage_artist_members', $artist_id)) {
            // Force session token creation/refresh for cross-domain access
            if (isset($_COOKIE['ecc_user_session_token'])) {
                $existing_token = $_COOKIE['ecc_user_session_token'];
                
                // Refresh cookies for extrachill.link domain
                $alias_cookie_params = [
                    'expires' => time() + (6 * 30 * 24 * 60 * 60),
                    'path' => '/',
                    'domain' => '.extrachill.link',
                    'secure' => true,
                    'httponly' => false,
                    'samesite' => 'None'
                ];
                setcookie('ecc_user_session_token', $existing_token, $alias_cookie_params);
                
                $root_cookie_params = [
                    'expires' => time() + (6 * 30 * 24 * 60 * 60),
                    'path' => '/',
                    'domain' => 'extrachill.link',
                    'secure' => true,
                    'httponly' => false,
                    'samesite' => 'None'
                ];
                setcookie('ecc_user_session_token', $existing_token, $root_cookie_params);
            }
            
            wp_send_json_success(array(
                'message' => 'Session synchronized',
                'user_id' => $current_user_id,
                'can_manage' => true
            ));
        } else {
            wp_send_json_error(array('message' => 'No permission for this band'));
        }
    } else {
        wp_send_json_error(array('message' => 'Not logged in'));
    }
}

/**
 * Invalidate user sessions across domains when email address changes
 * Called during email change process for security
 * 
 * @param int $user_id User ID whose sessions should be invalidated
 */
function invalidate_user_sessions_on_email_change( $user_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_session_tokens';
    
    // Remove all session tokens for this user from database
    $deleted = $wpdb->delete(
        $table_name,
        array( 'user_id' => $user_id ),
        array( '%d' )
    );
    
    // Clear cookies on both domains by setting expired cookies
    $past_time = time() - 3600; // 1 hour in the past
    
    // Clear .extrachill.com domain cookies
    $cookie_params = [
        'expires' => $past_time,
        'path' => '/',
        'domain' => '.extrachill.com',
        'secure' => true,
        'httponly' => false,
        'samesite' => 'None'
    ];
    setcookie('ecc_user_session_token', '', $cookie_params);
    
    // Clear .extrachill.link domain cookies  
    $alias_cookie_params = [
        'expires' => $past_time,
        'path' => '/',
        'domain' => '.extrachill.link',
        'secure' => true,
        'httponly' => false,
        'samesite' => 'None'
    ];
    setcookie('ecc_user_session_token', '', $alias_cookie_params);
    
    // Clear root extrachill.link cookies
    $root_cookie_params = [
        'expires' => $past_time,
        'path' => '/',
        'domain' => 'extrachill.link',
        'secure' => true,
        'httponly' => false,
        'samesite' => 'None'
    ];
    setcookie('ecc_user_session_token', '', $root_cookie_params);
    
    // Log the session invalidation for debugging
    error_log( "Invalidated {$deleted} session tokens for user {$user_id} due to email change" );
    
    return $deleted;
}

