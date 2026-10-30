# Permissions System

Centralized permission management providing consistent access control across all platform components. All permission checks are defined in a single source of truth for security, maintainability, and consistency.

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
$can_manage = ec_can_manage_artist( $user_id, $artist_id );
```

**Permission Logic** (`ec_can_manage_artist()` / `ec_can_create_artist_profiles()` live in the network-activated `extrachill-users` plugin; `inc/core/filters/permissions.php` adds request helpers and WordPress capability filtering):

See `extrachill-users` for the canonical permission logic (administrator override, author/roster membership, etc.). This plugin treats those helpers as the source of truth and uses them via `function_exists()` checks where relevant.

## REST API Permission Helpers

The permissions system includes context-aware helper functions for REST API endpoints:

### ec_get_permission_artist_id()

Extracts and validates artist ID from request data:

```php
/**
 * Extract artist ID from request data and validate permissions
 * 
 * @param array $data Request data (POST, GET, or other)
 * @return int Artist ID if user can manage, 0 otherwise
 */
function ec_get_permission_artist_id( $data ) {
    $artist_id = isset( $data['artist_id'] ) ? (int) $data['artist_id'] : 0;
    if ( ! $artist_id ) {
        return 0;
    }
    return ec_can_manage_artist( get_current_user_id(), $artist_id ) ? $artist_id : 0;
}
```

### ec_get_permission_link_page_id()

Extracts link page ID and validates that user can manage the associated artist:

```php
/**
 * Extract link page ID from request data and validate permissions
 * 
 * @param array $data Request data (POST, GET, or other)
 * @return int|false Artist ID if user can manage link page, false otherwise
 */
function ec_get_permission_link_page_id( $data ) {
    $link_page_id = isset( $data['link_page_id'] ) ? (int) $data['link_page_id'] : 0;
    if ( ! $link_page_id ) {
        return false;
    }
    
    $artist_id = apply_filters('ec_get_artist_id', $link_page_id);
    if ( ! $artist_id ) {
        return false;
    }
    
    return ec_can_manage_artist( get_current_user_id(), $artist_id ) ? $artist_id : false;
}
```

### ec_get_permission_is_admin()

Checks if current user is administrator:

```php
function ec_get_permission_is_admin( $data ) {
    return current_user_can( 'manage_options' );
}
```

### ec_get_permission_can_create_artists()

Checks if user can create new artist profiles:

```php
function ec_get_permission_can_create_artists( $data ) {
    return ec_can_create_artist_profiles( get_current_user_id() );
}
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

**Logic**:
- Administrators: Always allowed
- Regular users: Limited by configuration (typically 5 profiles per user)

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
// REST API permission validation in Gutenberg blocks
function rest_api_permission_check($request) {
    // Check user authentication
    if (!is_user_logged_in()) {
        return false;
    }
    
    // Extract and validate artist ID from request
    $artist_id = ec_get_permission_artist_id( $request->get_json_params() );
    if ( ! $artist_id ) {
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

Artist profile and link page editing is handled via Gutenberg block editor:

```php
// Block-based management (primary interface)
// Location: src/blocks/artist-manager/
// Location: src/blocks/link-page-editor/
// Block provides tab-based interface for:
// - Profile information editing
// - Roster/member management
// - Subscriber management

// Permissions automatically validated in block REST endpoints
// Uses ec_can_manage_artist() for access control
```

### Form Submission Security

All form submissions include nonce verification and permission checks:

```php
// Admin post handlers
function handle_form_submission() {
    // Verify nonce
    check_admin_referer('save_action_nonce');
    
    // Extract and validate artist ID
    $artist_id = ec_get_permission_artist_id( $_POST );
    if ( ! $artist_id ) {
        wp_die('Access denied');
    }
    
    // Process form data
    // ...
}
```

## WordPress Capability Integration

### Custom Capabilities

The system registers custom capabilities that are dynamically granted based on permission checks:

```php
/**
 * WordPress capability filtering for artist permissions
 */
function ec_filter_user_capabilities( $allcaps, $caps, $args, $user ) {
    $user_id = $user->ID;
    $cap     = $args[0];
    $object_id = isset( $args[2] ) ? $args[2] : null;
    
    // Allow create_artist_profiles capability
    if ( $cap === 'create_artist_profiles' ) {
        if ( ec_can_create_artist_profiles( $user_id ) ) {
            $allcaps[$cap] = true;
        }
        return $allcaps;
    }
    
    // Allow manage_artist_members capability
    if ( $cap === 'manage_artist_members' && $object_id ) {
        if ( ec_can_manage_artist( $user_id, $object_id ) ) {
            $allcaps[$cap] = true;
        }
        return $allcaps;
    }
    
    // Allow view_artist_link_page_analytics capability
    if ( $cap === 'view_artist_link_page_analytics' && $object_id ) {
        if ( get_post_type( $object_id ) === 'artist_link_page' ) {
            $artist_id = apply_filters('ec_get_artist_id', $object_id);
            if ( $artist_id && ec_can_manage_artist( $user_id, $artist_id ) ) {
                $allcaps[$cap] = true;
            }
        }
        return $allcaps;
    }
    
    // Allow post editing capabilities for artist profiles
    if ( $object_id && get_post_type( $object_id ) === 'artist_profile' ) {
        if ( ec_can_manage_artist( $user_id, $object_id ) ) {
            $post_caps = array( 'edit_post', 'delete_post', 'read_post', 'publish_post', 'manage_artist_members' );
            if ( in_array( $cap, $post_caps ) ) {
                $allcaps[$cap] = true;
            }
        }
    }
    
    return $allcaps;
}

add_filter( 'user_has_cap', 'ec_filter_user_capabilities', 10, 4 );
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
// Add user to artist (called by join-flow and roster invitations)
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