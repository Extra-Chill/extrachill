# Cross-Domain Authentication System

Secure session-based authentication system enabling seamless access across `.extrachill.com` subdomains with server-side validation.

## System Architecture

### Session Token System

The authentication system uses secure session tokens to maintain user sessions across subdomains without relying on client-side cookies or API calls.

### Domain Coverage

Authentication works across all `.extrachill.com` subdomains:
- Main site: `extrachill.com`
- Artist subdomains: `artist-name.extrachill.com`
- Platform subdomains: `platform.extrachill.com`

## Server-Side Session Validation

### Session Handler

Location: `inc/link-pages/live/link-page-session-validation.php`

```php
/**
 * Validate Extra Chill session token
 * 
 * @param string $token Session token from cookie or request
 * @return bool True if valid session
 */
function validate_extrch_session_token($token) {
    if (empty($token)) {
        return false;
    }
    
    // Validate token format
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    
    // Check token against WordPress session system
    $session_data = get_transient('extrch_session_' . $token);
    
    if (!$session_data) {
        return false;
    }
    
    // Validate session data structure
    if (!isset($session_data['user_id']) || !isset($session_data['expires'])) {
        return false;
    }
    
    // Check expiration
    if (time() > $session_data['expires']) {
        delete_transient('extrch_session_' . $token);
        return false;
    }
    
    // Validate user still exists
    $user = get_userdata($session_data['user_id']);
    if (!$user) {
        delete_transient('extrch_session_' . $token);
        return false;
    }
    
    // Set current user for request
    wp_set_current_user($session_data['user_id']);
    
    return true;
}
```

### Template-Level Validation

```php
/**
 * Check authentication at template level
 */
function check_template_authentication() {
    $token = null;
    
    // Check for token in various sources
    if (isset($_COOKIE['extrch_session_token'])) {
        $token = sanitize_text_field($_COOKIE['extrch_session_token']);
    } elseif (isset($_REQUEST['session_token'])) {
        $token = sanitize_text_field($_REQUEST['session_token']);
    } elseif (isset($_SESSION['extrch_token'])) {
        $token = sanitize_text_field($_SESSION['extrch_token']);
    }
    
    if ($token) {
        return validate_extrch_session_token($token);
    }
    
    return false;
}

// Use in templates
if (check_template_authentication()) {
    // User is authenticated - show management interface
    include 'authenticated-content.php';
} else {
    // Show login prompt or public view
    include 'public-content.php';
}
```

## Token Generation and Management

### Session Creation

```php
/**
 * Create new session token for user
 * 
 * @param int $user_id WordPress user ID
 * @param int $duration Session duration in seconds (default: 6 months)
 * @return string Generated session token
 */
function create_extrch_session_token($user_id, $duration = null) {
    if (!$duration) {
        $duration = 6 * MONTH_IN_SECONDS; // 6 months default
    }
    
    // Generate secure token
    $token = wp_hash(wp_generate_password(32) . time() . $user_id, 'secure_auth');
    
    // Store session data
    $session_data = [
        'user_id' => (int) $user_id,
        'created' => time(),
        'expires' => time() + $duration,
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
    
    // Save session with expiration
    set_transient('extrch_session_' . $token, $session_data, $duration);
    
    // Log session creation
    do_action('extrch_session_created', $token, $user_id, $duration);
    
    return $token;
}
```

### Cookie Management

```php
/**
 * Set session cookie for cross-domain access
 * 
 * @param string $token Session token
 * @param int $duration Cookie duration
 */
function set_extrch_session_cookie($token, $duration = null) {
    if (!$duration) {
        $duration = 6 * MONTH_IN_SECONDS;
    }
    
    $cookie_domain = '.extrachill.com'; // Works for all subdomains
    $secure = is_ssl(); // Use secure cookies on HTTPS
    $httponly = true; // Prevent JavaScript access
    
    setcookie(
        'extrch_session_token',
        $token,
        time() + $duration,
        '/', // Available on all paths
        $cookie_domain,
        $secure,
        $httponly
    );
    
    // Set same-site attribute for additional security
    if (PHP_VERSION_ID >= 70300) {
        setcookie(
            'extrch_session_token',
            $token,
            [
                'expires' => time() + $duration,
                'path' => '/',
                'domain' => $cookie_domain,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => 'Lax'
            ]
        );
    }
}
```

## Auto-Login Integration

### WordPress Login Hook

```php
/**
 * Create session on WordPress login
 */
function create_session_on_login($user_login, $user) {
    // Create session token
    $token = create_extrch_session_token($user->ID);
    
    // Set cross-domain cookie
    set_extrch_session_cookie($token);
    
    // Store token in user session for immediate use
    if (!session_id()) {
        session_start();
    }
    $_SESSION['extrch_token'] = $token;
}
add_action('wp_login', 'create_session_on_login', 10, 2);
```

### Logout Cleanup

```php
/**
 * Clean up session on logout
 */
function cleanup_session_on_logout() {
    $token = null;
    
    // Get token from cookie
    if (isset($_COOKIE['extrch_session_token'])) {
        $token = sanitize_text_field($_COOKIE['extrch_session_token']);
    }
    
    if ($token) {
        // Remove session data
        delete_transient('extrch_session_' . $token);
        
        // Clear cookie
        setcookie('extrch_session_token', '', time() - 3600, '/', '.extrachill.com');
    }
    
    // Clear session variable
    if (session_id()) {
        unset($_SESSION['extrch_token']);
    }
}
add_action('wp_logout', 'cleanup_session_on_logout');
```

## Client-Side Session Handling

### JavaScript Session Management

Location: `inc/link-pages/live/assets/js/link-page-session.js`

```javascript
const SessionManager = {
    init: function() {
        this.checkSessionStatus();
        this.bindEvents();
    },
    
    checkSessionStatus: function() {
        // Session validation handled server-side
        // Client just needs to detect presence of session
        const hasSession = document.body.classList.contains('user-authenticated');
        
        if (hasSession) {
            this.enableAuthenticatedFeatures();
        } else {
            this.showLoginPrompt();
        }
    },
    
    enableAuthenticatedFeatures: function() {
        // Show management interfaces
        $('.authenticated-only').show();
        $('.login-required').hide();
        
        // Enable AJAX requests with session
        this.setupAuthenticatedAjax();
    },
    
    setupAuthenticatedAjax: function() {
        // Add session token to AJAX requests if needed
        $(document).ajaxSend(function(event, xhr, settings) {
            if (settings.url.indexOf(ajaxurl) > -1) {
                // Session validation handled by server-side nonce verification
                // No additional client-side token needed
            }
        });
    },
    
    showLoginPrompt: function() {
        $('.login-required').show();
        $('.authenticated-only').hide();
    }
};

document.addEventListener('DOMContentLoaded', SessionManager.init.bind(SessionManager));
```

## Security Features

### Token Validation

```php
/**
 * Enhanced token validation with security checks
 */
function validate_session_with_security_checks($token) {
    $session_data = get_transient('extrch_session_' . $token);
    
    if (!$session_data) {
        return false;
    }
    
    // IP address validation (optional, configurable)
    if (get_option('extrch_validate_ip', false)) {
        if ($session_data['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
            delete_transient('extrch_session_' . $token);
            return false;
        }
    }
    
    // User agent validation (optional)
    if (get_option('extrch_validate_user_agent', false)) {
        $current_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($session_data['user_agent'] !== $current_ua) {
            delete_transient('extrch_session_' . $token);
            return false;
        }
    }
    
    // Check for session hijacking patterns
    if ($this->detect_session_anomalies($token, $session_data)) {
        delete_transient('extrch_session_' . $token);
        return false;
    }
    
    return true;
}
```

### Brute Force Protection

```php
/**
 * Rate limiting for session validation attempts
 */
function check_session_validation_rate_limit() {
    $ip = $_SERVER['REMOTE_ADDR'];
    $attempts_key = 'session_attempts_' . md5($ip);
    $attempts = get_transient($attempts_key) ?: 0;
    
    if ($attempts > 10) { // Max 10 attempts per minute
        return false;
    }
    
    set_transient($attempts_key, $attempts + 1, MINUTE_IN_SECONDS);
    return true;
}
```

## Session Cleanup

### Automatic Cleanup

```php
/**
 * Clean up expired sessions
 */
function cleanup_expired_sessions() {
    global $wpdb;
    
    // Find expired session transients
    $expired = $wpdb->get_col("
        SELECT option_name 
        FROM {$wpdb->options} 
        WHERE option_name LIKE '_transient_extrch_session_%' 
        AND option_value < " . time()
    );
    
    foreach ($expired as $option_name) {
        delete_option($option_name);
        delete_option('_transient_timeout_' . substr($option_name, 11));
    }
    
    return count($expired);
}

// Schedule cleanup
function schedule_session_cleanup() {
    if (!wp_next_scheduled('cleanup_expired_sessions')) {
        wp_schedule_event(time(), 'hourly', 'cleanup_expired_sessions');
    }
}
add_action('wp', 'schedule_session_cleanup');
add_action('cleanup_expired_sessions', 'cleanup_expired_sessions');
```

### Manual Session Management

```php
/**
 * Revoke all sessions for a user
 */
function revoke_user_sessions($user_id) {
    global $wpdb;
    
    // Get all session transients
    $sessions = $wpdb->get_col($wpdb->prepare("
        SELECT option_name 
        FROM {$wpdb->options} 
        WHERE option_name LIKE '_transient_extrch_session_%'
    "));
    
    $revoked = 0;
    foreach ($sessions as $session_key) {
        $token = substr($session_key, 20); // Remove '_transient_extrch_session_'
        $session_data = get_transient('extrch_session_' . $token);
        
        if ($session_data && $session_data['user_id'] == $user_id) {
            delete_transient('extrch_session_' . $token);
            $revoked++;
        }
    }
    
    return $revoked;
}
```

## Integration Points

### Permission System Integration

```php
// Check session and permissions together
function check_authenticated_permission($artist_id) {
    // First check if user is authenticated via session
    if (!check_template_authentication()) {
        return false;
    }
    
    // Then check specific permissions
    return ec_can_manage_artist(get_current_user_id(), $artist_id);
}
```

### Template Usage

```php
// In link page management templates
if (check_authenticated_permission($artist_id)) {
    // Show management interface
    include 'management-interface.php';
} else {
    // Show login form or redirect
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}
```

## Subdomain Routing

### Domain-Specific Handling

```php
/**
 * Handle subdomain-specific authentication
 */
function handle_subdomain_authentication() {
    $host = $_SERVER['HTTP_HOST'];
    
    // Check if this is an artist subdomain
    if (preg_match('/^([a-z0-9-]+)\.extrachill\.com$/', $host, $matches)) {
        $subdomain = $matches[1];
        
        // Get artist ID from subdomain
        $artist_id = get_artist_by_subdomain($subdomain);
        
        if ($artist_id) {
            // Check if current session has access to this artist
            if (check_template_authentication()) {
                $user_id = get_current_user_id();
                if (!ec_can_manage_artist($user_id, $artist_id)) {
                    // User authenticated but no permission for this artist
                    wp_redirect('https://extrachill.com/access-denied/');
                    exit;
                }
            }
        }
    }
}
add_action('init', 'handle_subdomain_authentication', 5);
```