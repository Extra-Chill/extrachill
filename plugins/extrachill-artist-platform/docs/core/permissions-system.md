# Permissions System

Centralized permission management providing consistent access control across all platform components.

## Core Permission Functions

### ec_can_manage_artist()

Primary permission check for artist management capabilities.

```php
/**
 * Check if user can manage specific artist
 * 
 * @param int $user_id User ID (defaults to current user)
 * @param int $artist_id Artist profile ID
 * @return bool True if user can manage artist
 */
$can_manage = ec_can_manage_artist($user_id, $artist_id);
```

### Permission Logic

1. **Administrator Override**: Users with `manage_options` capability can manage all artists
2. **Artist Membership**: Users linked to artist profile via `_artist_profile_ids` meta
3. **Security Validation**: All checks validate user and artist ID existence

## Artist Profile Creation

### User Artist Profile Limits

```php
/**
 * Check if user can create new artist profiles
 * 
 * @param int $user_id User ID (defaults to current user)
 * @return bool True if user can create profiles
 */
$can_create = ec_can_create_artist_profiles($user_id);
```

## Permission Usage Patterns

### Template Permission Checks

```php
// In management templates
if (ec_can_manage_artist(get_current_user_id(), $artist_id)) {
    // Show management interface
    include 'manage-interface.php';
} else {
    // Show access denied message
    echo '<p>Access denied.</p>';
}
```

### REST API Security

All management operations use the WordPress REST API with proper nonce verification and permission checks:

```php
// REST API permission validation
function rest_api_permission_check($request) {
    // Check user authentication
    if (!is_user_logged_in()) {
        return false;
    }
    
    // Check artist management permissions
    $artist_id = $request->get_param('artist_id');
    if (!ec_can_manage_artist(get_current_user_id(), $artist_id)) {
        return false;
    }
    
    return true;
}

// Register REST endpoint with permission check
register_rest_route('extrachill/v1', '/artists/(?P<artist_id>\d+)', [
    'methods' => 'POST',
    'callback' => 'rest_api_handler',
    'permission_callback' => 'rest_api_permission_check'
]);
```

### Gutenberg Block Management

Artist profile editing is handled via the Gutenberg block editor:

```php
// Block-based artist profile management (primary interface)
// Location: src/blocks/artist-profile-manager/
// Block provides tab-based interface for:
// - Profile information editing
// - Roster/member management
// - Subscriber management (via TabSubscribers)

// Permissions automatically validated in block REST endpoints
// Uses ec_can_manage_artist() for access control
```

### Form Submission Security

```php
// Admin post handlers
function handle_form_submission() {
    // Verify nonce
    check_admin_referer('save_action_nonce');
    
    // Check permissions
    if (!ec_can_manage_artist(get_current_user_id(), $artist_id)) {
        wp_die('Access denied');
    }
    
    // Process form data
    // ...
}
```

## User-Artist Relationships

### Membership Data Structure

Artist membership stored in user meta:

```php
// User meta structure
$artist_profile_ids = get_user_meta($user_id, '_artist_profile_ids', true);
// Returns: [123, 456, 789] - array of artist IDs user can manage
```

### Membership Management

```php
// Add user to artist
function add_user_to_artist($user_id, $artist_id) {
    $current_ids = get_user_meta($user_id, '_artist_profile_ids', true);
    if (!is_array($current_ids)) {
        $current_ids = [];
    }
    
    if (!in_array($artist_id, $current_ids)) {
        $current_ids[] = $artist_id;
        update_user_meta($user_id, '_artist_profile_ids', $current_ids);
    }
}

// Remove user from artist
function remove_user_from_artist($user_id, $artist_id) {
    $current_ids = get_user_meta($user_id, '_artist_profile_ids', true);
    if (is_array($current_ids)) {
        $current_ids = array_diff($current_ids, [$artist_id]);
        update_user_meta($user_id, '_artist_profile_ids', $current_ids);
    }
}
```

## Cross-Domain Authentication

### Session Validation

Cross-domain authentication for `.extrachill.com` subdomains handled via server-side session validation:

```php
// Session token validation
$is_valid = validate_extrch_session_token($token);

// Template-level permission checks
if ($is_valid && ec_can_manage_link_page($_REQUEST)) {
    // Allow access to management interface
}
```

## Security Best Practices

### Nonce Verification

All REST API requests and forms use WordPress nonce system:

```php
// Form nonce generation
wp_nonce_field('save_action', 'save_action_nonce');

// REST API nonce generation
wp_localize_script('block-script', 'wpApiSettings', [
    'rest_url' => rest_url('/'),
    'nonce' => wp_create_nonce('wp_rest')
]);
```

### Input Sanitization

All user input processed through WordPress sanitization:

```php
// Sanitize form data
$title = sanitize_text_field($_POST['title']);
$url = esc_url_raw($_POST['url']);
$content = wp_kses_post($_POST['content']);
```

### Output Escaping

All output properly escaped for security:

```php
// Escape for different contexts
echo esc_html($title);
echo esc_url($link_url);
echo esc_attr($css_class);
```