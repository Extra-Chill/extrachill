# Authentication Tokens

JWT-based authentication token system providing secure API access and browser handoff functionality for the Extra Chill Platform.

## Token System Overview

### Token Types
- **Bearer Tokens**: Standard API authentication
- **Handoff Tokens**: Cross-domain authentication
- **Refresh Tokens**: Token renewal without re-authentication
- **Session Tokens**: WordPress session integration

## JWT Token Implementation

### Token Generation
```php
// Generate JWT token
function extrachill_generate_token($user_id, $type = 'bearer') {
    $payload = [
        'user_id' => $user_id,
        'type' => $type,
        'iat' => time(),
        'exp' => time() + (24 * 60 * 60), // 24 hours
        'iss' => get_bloginfo('url'),
        'aud' => get_bloginfo('url')
    ];
    
    $secret = get_option('extrachill_jwt_secret');
    return jwt_encode($payload, $secret);
}
```

### Token Structure
```json
{
    "user_id": 123,
    "type": "bearer",
    "iat": 1640995200,
    "exp": 1641081600,
    "iss": "https://extrachill.com",
    "aud": "https://extrachill.com"
}
```

## Bearer Token Authentication

### Token Validation
```php
// Validate bearer token
function extrachill_validate_bearer_token($token) {
    $secret = get_option('extrachill_jwt_secret');
    $payload = jwt_decode($token, $secret);
    
    if (!$payload || $payload['type'] !== 'bearer') {
        return false;
    }
    
    // Check expiration
    if ($payload['exp'] < time()) {
        return false;
    }
    
    return $payload['user_id'];
}
```

### Bearer Auth Implementation
```php
// Bearer authentication middleware
function extrachill_bearer_authenticate($request) {
    $auth_header = $request->get_header('authorization');
    
    if ($auth_header && strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
        $user_id = extrachill_validate_bearer_token($token);
        
        if ($user_id) {
            wp_set_current_user($user_id);
            return true;
        }
    }
    
    return false;
}
```

## Browser Handoff Tokens

### Handoff Token Generation
```php
// Generate browser handoff token
function extrachill_generate_handoff_token($user_id, $target_domain) {
    $payload = [
        'user_id' => $user_id,
        'type' => 'handoff',
        'target_domain' => $target_domain,
        'source_domain' => $_SERVER['HTTP_HOST'],
        'iat' => time(),
        'exp' => time() + (5 * 60), // 5 minutes
        'nonce' => wp_create_nonce('handoff_' . $user_id)
    ];
    
    $secret = get_option('extrachill_handoff_secret');
    return jwt_encode($payload, $secret);
}
```

### Handoff Process
```php
// Process browser handoff
function extrachill_process_browser_handoff($token) {
    $secret = get_option('extrachill_handoff_secret');
    $payload = jwt_decode($token, $secret);
    
    if (!$payload || $payload['type'] !== 'handoff') {
        return new WP_Error('invalid_token', 'Invalid handoff token');
    }
    
    // Verify nonce
    if (!wp_verify_nonce($payload['nonce'], 'handoff_' . $payload['user_id'])) {
        return new WP_Error('invalid_nonce', 'Invalid nonce');
    }
    
    // Check expiration
    if ($payload['exp'] < time()) {
        return new WP_Error('token_expired', 'Handoff token expired');
    }
    
    // Authenticate user
    wp_set_auth_cookie($payload['user_id']);
    wp_set_current_user($payload['user_id']);
    
    return true;
}
```

## Token Storage

### Database Storage
```php
// Store token in database
function extrachill_store_token($user_id, $token, $type) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'extrachill_auth_tokens';
    
    $wpdb->insert(
        $table_name,
        [
            'user_id' => $user_id,
            'token_hash' => wp_hash_password($token),
            'token_type' => $type,
            'created_at' => current_time('mysql'),
            'expires_at' => date('Y-m-d H:i:s', time() + (24 * 60 * 60))
        ],
        ['%d', '%s', '%s', '%s', '%s']
    );
}
```

### Token Cleanup
```php
// Clean up expired tokens
function extrachill_cleanup_expired_tokens() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'extrachill_auth_tokens';
    $current_time = current_time('mysql');
    
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE expires_at < %s",
            $current_time
        )
    );
}
```

## Token Refresh System

### Refresh Token Logic
```php
// Refresh authentication token
function extrachill_refresh_token($refresh_token) {
    $user_id = extrachill_validate_refresh_token($refresh_token);
    
    if ($user_id) {
        // Generate new bearer token
        $new_token = extrachill_generate_token($user_id, 'bearer');
        
        // Store new token
        extrachill_store_token($user_id, $new_token, 'bearer');
        
        // Revoke old refresh token
        extrachill_revoke_refresh_token($refresh_token);
        
        return $new_token;
    }
    
    return false;
}
```

## Security Features

### Token Security
- RS256 encryption algorithm
- Secret key rotation
- Token expiration handling
- Rate limiting for token requests

### Access Controls
```php
// Check token permissions
function extrachill_check_token_permissions($token, $required_capability) {
    $user_id = extrachill_validate_bearer_token($token);
    
    if ($user_id && user_can($user_id, $required_capability)) {
        return true;
    }
    
    return false;
}
```

### Rate Limiting
```php
// Token request rate limiting
function extrachill_check_token_rate_limit($user_id) {
    $rate_limit_key = 'token_request_' . $user_id;
    $request_count = get_transient($rate_limit_key);
    
    if ($request_count === false) {
        set_transient($rate_limit_key, 1, 60); // 1 minute
        return true;
    }
    
    if ($request_count >= 5) { // 5 requests per minute
        return false;
    }
    
    set_transient($rate_limit_key, $request_count + 1, 60);
    return true;
}
```

## API Integration

### Authentication Headers
```php
// Add authentication headers to API requests
function extrachill_add_auth_headers($headers, $token) {
    $headers['Authorization'] = 'Bearer ' . $token;
    $headers['Content-Type'] = 'application/json';
    
    return $headers;
}
```

### Cross-Domain Authentication
```php
// Cross-domain authentication handler
function extrachill_cross_domain_auth($target_domain) {
    $user_id = get_current_user_id();
    
    if ($user_id) {
        $handoff_token = extrachill_generate_handoff_token($user_id, $target_domain);
        
        $auth_url = add_query_arg([
            'handoff_token' => $handoff_token,
            'redirect_to' => $target_domain
        ], "https://{$target_domain}/auth/handoff/");
        
        return $auth_url;
    }
    
    return false;
}
```

## Configuration

### Token Settings
- Token expiration times
- Secret key management
- Rate limiting configuration
- Cross-domain allowed origins

### Security Configuration
- JWT algorithm selection
- Key rotation schedule
- Token revocation handling
- Audit logging settings

## Error Handling

### Token Errors
- Invalid token format
- Expired tokens
- Revoked tokens
- Malformed tokens

### Handoff Errors
- Invalid domain
- Expired handoff tokens
- Nonce verification failures
- Cross-domain restrictions

## Monitoring and Logging

### Token Usage Tracking
```php
// Log token usage
function extrachill_log_token_usage($user_id, $token_type, $action) {
    $log_data = [
        'user_id' => $user_id,
        'token_type' => $token_type,
        'action' => $action,
        'timestamp' => current_time('mysql'),
        'ip_address' => $_SERVER['REMOTE_ADDR']
    ];
    
    // Log to security audit table
    extrachill_log_security_event('token_usage', $log_data);
}
```

### Security Monitoring
- Failed token attempts
- Unusual access patterns
- Token usage analytics
- Security event logging