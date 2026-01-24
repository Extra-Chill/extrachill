# Google OAuth Integration

Social authentication system providing Google OAuth integration for user registration and login across the Extra Chill Platform.

## OAuth Configuration

### Google Service Setup
```php
// Google OAuth service configuration
function extrachill_get_google_oauth_config() {
    return [
        'client_id' => get_option('extrachill_google_client_id'),
        'client_secret' => get_option('extrachill_google_client_secret'),
        'redirect_uri' => home_url('/auth/google/callback/'),
        'scope' => 'openid email profile',
        'access_type' => 'offline'
    ];
}
```

### Required Settings
- Google Client ID
- Google Client Secret
- Authorized redirect URI
- OAuth 2.0 scopes

## Authentication Flow

### OAuth Initiation
```php
// Start Google OAuth flow
function extrachill_init_google_oauth() {
    $config = extrachill_get_google_oauth_config();
    
    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'scope' => $config['scope'],
        'response_type' => 'code',
        'access_type' => $config['access_type'],
        'state' => wp_create_nonce('google_oauth_state')
    ]);
    
    wp_redirect($auth_url);
    exit;
}
```

### Callback Handling
```php
// Handle Google OAuth callback
function extrachill_handle_google_callback() {
    // Verify state nonce
    if (!wp_verify_nonce($_GET['state'], 'google_oauth_state')) {
        wp_die('Invalid state parameter');
    }
    
    // Exchange authorization code for access token
    $token_response = extrachill_exchange_code_for_token($_GET['code']);
    
    if (is_wp_error($token_response)) {
        wp_die('Token exchange failed');
    }
    
    // Get user profile data
    $user_data = extrachill_get_google_user_profile($token_response['access_token']);
    
    // Create or login user
    $user = extrachill_oauth_login_user($user_data);
    
    if ($user) {
        wp_set_auth_cookie($user->ID);
        wp_set_current_user($user->ID);
        
        $redirect_url = apply_filters('extrachill_oauth_redirect_url', home_url(), $user);
        wp_redirect($redirect_url);
        exit;
    }
    
    wp_die('Authentication failed');
}
```

## Token Exchange

### Authorization Code Exchange
```php
// Exchange authorization code for access token
function extrachill_exchange_code_for_token($code) {
    $config = extrachill_get_google_oauth_config();
    
    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'body' => [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $config['redirect_uri']
        ]
    ]);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['error'])) {
        return new WP_Error('oauth_error', $body['error_description']);
    }
    
    return $body;
}
```

### Token Validation
```php
// Validate Google ID token
function extrachill_validate_google_id_token($id_token) {
    $client_id = get_option('extrachill_google_client_id');
    
    // Google token verification endpoint
    $verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token);
    
    $response = wp_remote_get($verify_url);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $token_data = json_decode(wp_remote_retrieve_body($response), true);
    
    // Verify audience
    if ($token_data['aud'] !== $client_id) {
        return new WP_Error('invalid_audience', 'Token audience mismatch');
    }
    
    return $token_data;
}
```

## User Profile Retrieval

### Google Profile Data
```php
// Get user profile from Google API
function extrachill_get_google_user_profile($access_token) {
    $response = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', [
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token
        ]
    ]);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    return json_decode(wp_remote_retrieve_body($response), true);
}
```

### Profile Data Structure
```json
{
    "id": "123456789",
    "email": "user@example.com",
    "name": "John Doe",
    "picture": "https://lh3.googleusercontent.com/photo.jpg",
    "given_name": "John",
    "family_name": "Doe",
    "verified_email": true
}
```

## User Creation and Login

### OAuth User Registration
```php
// Create user from OAuth data
function extrachill_create_oauth_user($user_data, $provider = 'google') {
    $email = sanitize_email($user_data['email']);
    
    // Check if user already exists
    $existing_user = get_user_by('email', $email);
    
    if ($existing_user) {
        // Link OAuth provider to existing user
        update_user_meta($existing_user->ID, "oauth_{$provider}_id", $user_data['id']);
        return $existing_user;
    }
    
    // Generate username from email or name
    $username = extrachill_generate_username($user_data);
    
    // Create new user
    $user_id = wp_create_user($username, wp_generate_password(), $email);
    
    if (!is_wp_error($user_id)) {
        // Update user profile
        wp_update_user([
            'ID' => $user_id,
            'display_name' => sanitize_text_field($user_data['name'])
        ]);
        
        // Store OAuth data
        update_user_meta($user_id, "oauth_{$provider}_id", $user_data['id']);
        update_user_meta($user_id, "oauth_{$provider}_data", $user_data);
        
        // Import profile picture
        if (isset($user_data['picture'])) {
            extrachill_import_oauth_avatar($user_id, $user_data['picture']);
        }
        
        return get_user_by('id', $user_id);
    }
    
    return false;
}
```

### Username Generation
```php
// Generate unique username from OAuth data
function extrachill_generate_username($user_data) {
    $base_username = sanitize_user($user_data['email'] ?? $user_data['name']);
    
    // Remove @domain.com from email if used
    if (strpos($base_username, '@') !== false) {
        $base_username = substr($base_username, 0, strpos($base_username, '@'));
    }
    
    $username = $base_username;
    $counter = 1;
    
    // Ensure username is unique
    while (username_exists($username)) {
        $username = $base_username . $counter;
        $counter++;
    }
    
    return $username;
}
```

## Avatar Import

### Profile Picture Download
```php
// Import OAuth avatar
function extrachill_import_oauth_avatar($user_id, $avatar_url) {
    // Download image
    $response = wp_remote_get($avatar_url);
    
    if (!is_wp_error($response)) {
        $image_data = wp_remote_retrieve_body($response);
        
        // Upload to media library
        $upload = wp_upload_bits('oauth-avatar.jpg', null, $image_data);
        
        if (!$upload['error']) {
            // Create attachment
            $attachment_id = wp_insert_attachment([
                'post_mime_type' => 'image/jpeg',
                'post_title' => 'OAuth Avatar',
                'post_content' => '',
                'post_status' => 'inherit'
            ], $upload['file'], 0);
            
            // Generate thumbnails
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            wp_update_attachment_metadata($attachment_id, $attachment_data);
            
            // Set as user avatar
            update_user_meta($user_id, 'avatar_url', wp_get_attachment_url($attachment_id));
        }
    }
}
```

## Security Features

### State Validation
- CSRF protection with nonces
- State parameter verification
- Anti-replay attack measures

### Token Security
- Secure token storage
- Token revocation handling
- Rate limiting for OAuth requests

### Email Verification
```php
// Verify Google email is verified
function extrachill_verify_oauth_email($user_data) {
    return isset($user_data['verified_email']) && $user_data['verified_email'] === true;
}
```

## Error Handling

### OAuth Errors
- Invalid authorization code
- Token exchange failures
- Rate limiting from Google
- User denies access

### User Creation Errors
- Email already exists with different provider
- Username generation conflicts
- Avatar import failures
- Database write errors

## Integration Points

### Registration Forms
```php
// Add Google OAuth button to registration form
function extrachill_add_oauth_button() {
    $oauth_url = home_url('/auth/google/');
    
    echo '<div class="oauth-login">';
    echo '<a href="' . esc_url($oauth_url) . '" class="google-oauth-btn">';
    echo '<img src="/assets/images/google-sign-in.png" alt="Sign in with Google" />';
    echo '</a>';
    echo '</div>';
}
```

### Login Forms
- OAuth button integration
- Existing account linking
- Seamless login experience

### User Profiles
- OAuth provider indicators
- Account linking status
- Profile synchronization

## Configuration Management

### Admin Settings
```php
// OAuth configuration fields
function extrachill_oauth_admin_settings() {
    add_settings_field(
        'google_client_id',
        'Google Client ID',
        'extrachill_text_field_callback',
        'extrachill_users',
        'oauth_settings'
    );
    
    add_settings_field(
        'google_client_secret',
        'Google Client Secret',
        'extrachill_text_field_callback',
        'extrachill_users',
        'oauth_settings'
    );
}
```

### Validation Rules
- Client ID format validation
- Secret key encryption
- Redirect URI verification
- Scope permission checking

## Monitoring and Analytics

### OAuth Usage Tracking
```php
// Track OAuth login events
function extrachill_track_oauth_login($user_id, $provider) {
    $analytics_data = [
        'user_id' => $user_id,
        'provider' => $provider,
        'timestamp' => current_time('mysql'),
        'ip_address' => $_SERVER['REMOTE_ADDR']
    ];
    
    do_action('extrachill_analytics_event', 'oauth_login', $analytics_data);
}
```

### Success Metrics
- OAuth conversion rates
- Provider preference statistics
- Account linking frequency
- Login success rates

## Future Provider Support

### Extensible Architecture
- Provider abstraction layer
- Configurable OAuth settings
- Multiple provider support
- Unified user interface

### Planned Integrations
- GitHub OAuth
- Facebook Login
- Microsoft OAuth
- Apple Sign In