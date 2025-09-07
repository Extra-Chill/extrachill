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

## AJAX Permission Helpers

### Artist Management in AJAX

```php
/**
 * AJAX-specific artist management check
 * 
 * @param array $post_data POST data from AJAX request
 * @return bool Permission result
 */
$can_manage = ec_ajax_can_manage_artist($_POST);
```

### Link Page Management in AJAX

```php
/**
 * AJAX-specific link page management check
 * 
 * @param array $post_data POST data from AJAX request
 * @return bool Permission result
 */
$can_manage = ec_ajax_can_manage_link_page($_POST);
```

### Admin Capabilities Check

```php
/**
 * Check admin capabilities in AJAX context
 * 
 * @param array $post_data POST data from AJAX request
 * @return bool True if user has admin capabilities
 */
$is_admin = ec_ajax_can_admin($_POST);
```

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

### AJAX Handler Security

```php
// Standard AJAX permission pattern
function handle_ajax_request() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'ajax_nonce')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!ec_ajax_can_manage_artist($_POST)) {
        wp_send_json_error('Insufficient permissions');
    }
    
    // Process request
    // ...
}
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

All forms and AJAX requests use WordPress nonce system:

```php
// Form nonce generation
wp_nonce_field('save_action', 'save_action_nonce');

// AJAX nonce generation
wp_localize_script('ajax-script', 'ajax_object', [
    'ajax_url' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('ajax_nonce')
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