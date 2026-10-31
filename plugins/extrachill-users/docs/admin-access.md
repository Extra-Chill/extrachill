# Admin Access Control

Administrative access control system providing team member management, permission overrides, and network-wide admin functionality for the Extra Chill Platform.

## Team Member System

### Team Member Detection
```php
// Check if user is a team member
function ec_is_team_member($user_id = null) {
    if ($user_id === null) {
        $user_id = get_current_user_id();
    }
    
    // Check explicit team member meta
    $is_team_member = get_user_meta($user_id, 'is_team_member', true);
    if ($is_team_member) {
        return true;
    }
    
    // Check user roles
    $user = get_userdata($user_id);
    if ($user && in_array('team_member', (array) $user->roles)) {
        return true;
    }
    
    // Check admin-level capabilities
    return user_can($user_id, 'manage_options');
}
```

### Team Member Management
```php
// Add team member
function ec_add_team_member($user_id) {
    update_user_meta($user_id, 'is_team_member', true);
    
    // Add team member role
    $user = get_userdata($user_id);
    $user->add_role('team_member');
    
    // Log team member addition
    ec_log_admin_action('team_member_added', $user_id);
}

// Remove team member
function ec_remove_team_member($user_id) {
    delete_user_meta($user_id, 'is_team_member');
    
    // Remove team member role
    $user = get_userdata($user_id);
    $user->remove_role('team_member');
    
    // Log team member removal
    ec_log_admin_action('team_member_removed', $user_id);
}
```

## Permission Override System

### Override Capability Check
```php
// Check admin override capability
function ec_can_override_admin_access($user_id, $target_site_id = null) {
    // Must be team member
    if (!ec_is_team_member($user_id)) {
        return false;
    }
    
    // Check specific override permissions
    $override_sites = get_user_meta($user_id, 'admin_override_sites', true);
    if ($override_sites && is_array($override_sites)) {
        if ($target_site_id === null) {
            return !empty($override_sites); // Has any overrides
        }
        
        return in_array($target_site_id, $override_sites);
    }
    
    // Super admin check
    return is_super_admin($user_id);
}
```

### Override Management
```php
// Grant admin override for specific site
function ec_grant_admin_override($user_id, $site_id) {
    $override_sites = get_user_meta($user_id, 'admin_override_sites', true) ?: [];
    
    if (!in_array($site_id, $override_sites)) {
        $override_sites[] = $site_id;
        update_user_meta($user_id, 'admin_override_sites', $override_sites);
        
        ec_log_admin_action('admin_override_granted', $user_id, ['site_id' => $site_id]);
    }
}

// Revoke admin override for specific site
function ec_revoke_admin_override($user_id, $site_id) {
    $override_sites = get_user_meta($user_id, 'admin_override_sites', true) ?: [];
    
    if (($key = array_search($site_id, $override_sites)) !== false) {
        unset($override_sites[$key]);
        update_user_meta($user_id, 'admin_override_sites', array_values($override_sites));
        
        ec_log_admin_action('admin_override_revoked', $user_id, ['site_id' => $site_id]);
    }
}
```

## Network Access Control

### Multisite Admin Check
```php
// Check if user can access network admin
function ec_can_access_network_admin($user_id) {
    // Super admin always has access
    if (is_super_admin($user_id)) {
        return true;
    }
    
    // Check network admin override
    return ec_has_capability_override($user_id, 'manage_network');
}
```

### Site-Specific Access
```php
// Check site-specific admin access
function ec_can_access_site_admin($user_id, $site_id) {
    // Check if user is member of the site
    if (!is_user_member_of_blog($user_id, $site_id)) {
        return false;
    }
    
    // Standard WordPress capabilities
    switch_to_blog($site_id);
    $can_access = user_can($user_id, 'manage_options');
    restore_current_blog();
    
    // Check override if standard access fails
    if (!$can_access) {
        $can_access = ec_can_override_admin_access($user_id, $site_id);
    }
    
    return $can_access;
}
```

## Permission Management Interface

### Admin UI for Team Members
```php
// Add team member management to admin
function ec_add_team_member_admin_ui() {
    add_users_page(
        'Team Members',
        'Team Members',
        'manage_options',
        'team-members',
        'ec_team_members_admin_page'
    );
}
```

### Team Member List Table
```php
// Display team members in admin table
function ec_team_members_admin_page() {
    $team_members = ec_get_all_team_members();
    
    echo '<div class="wrap">';
    echo '<h1>Team Members</h1>';
    
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th>User</th>';
    echo '<th>Role</th>';
    echo '<th>Admin Overrides</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead>';
    
    foreach ($team_members as $member) {
        echo '<tr>';
        echo '<td>' . get_avatar($member->ID, 32) . ' ' . esc_html($member->display_name) . '</td>';
        echo '<td>' . implode(', ', $member->roles) . '</td>';
        echo '<td>' . ec_get_admin_override_sites_list($member->ID) . '</td>';
        echo '<td>' . ec_get_team_member_actions($member->ID) . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</div>';
}
```

## Capability Overrides

### Custom Capability System
```php
// Check custom capabilities with overrides
function ec_has_capability_override($user_id, $capability, $args = []) {
    // Standard WordPress capability check
    if (user_can($user_id, $capability, $args)) {
        return true;
    }
    
    // Check team member overrides
    if (ec_is_team_member($user_id)) {
        $capability_overrides = get_user_meta($user_id, 'capability_overrides', true) ?: [];
        
        if (in_array($capability, $capability_overrides)) {
            return true;
        }
        
        // Pattern-based overrides
        foreach ($capability_overrides as $override_pattern) {
            if (fnmatch($override_pattern, $capability)) {
                return true;
            }
        }
    }
    
    return false;
}
```

### Override Granularity
```php
// Grant specific capability override
function ec_grant_capability_override($user_id, $capability) {
    $overrides = get_user_meta($user_id, 'capability_overrides', true) ?: [];
    
    if (!in_array($capability, $overrides)) {
        $overrides[] = $capability;
        update_user_meta($user_id, 'capability_overrides', $overrides);
        
        ec_log_admin_action('capability_override_granted', $user_id, ['capability' => $capability]);
    }
}
```

## Security Features

### Access Validation
```php
// Validate admin access attempt
function ec_validate_admin_access($user_id, $site_id = null) {
    // Check if user exists and is active
    $user = get_userdata($user_id);
    if (!$user || $user->user_status !== 0) {
        return false;
    }
    
    // Check for account restrictions
    if (get_user_meta($user_id, 'account_locked', true)) {
        return false;
    }
    
    // Time-based restrictions
    $access_hours = get_user_meta($user_id, 'admin_access_hours', true);
    if ($access_hours && !ec_is_within_access_hours($access_hours)) {
        return false;
    }
    
    return true;
}
```

### Audit Logging
```php
// Log admin access events
function ec_log_admin_access($user_id, $action, $context = []) {
    $log_entry = [
        'user_id' => $user_id,
        'action' => $action,
        'context' => $context,
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
        'timestamp' => current_time('mysql')
    ];
    
    // Store in security log table
    ec_store_security_log('admin_access', $log_entry);
}
```

## Integration Points

### Multisite Network
```php
// Network admin access control
function ec_filter_network_admin_menu($menu) {
    $user_id = get_current_user_id();
    
    if (!ec_can_access_network_admin($user_id)) {
        // Remove restricted menu items
        $restricted_items = ['themes.php', 'plugins.php', 'users.php'];
        
        foreach ($restricted_items as $item) {
            unset($menu[$item]);
        }
    }
    
    return $menu;
}
```

### Site-Specific Admin
```php
// Filter site admin menu based on permissions
function ec_filter_site_admin_menu($menu) {
    $user_id = get_current_user_id();
    $site_id = get_current_blog_id();
    
    if (!ec_can_access_site_admin($user_id, $site_id)) {
        wp_die('You do not have permission to access this admin area.');
    }
    
    // Apply capability-based menu filtering
    return ec_filter_menu_by_capabilities($menu, $user_id);
}
```

## API Integration

### Admin API Endpoints
```php
// Register admin access endpoints
function ec_register_admin_api_endpoints() {
    register_rest_route('extrachill/v1', '/admin/team-members', [
        'methods' => 'GET',
        'callback' => 'ec_api_get_team_members',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
    
    register_rest_route('extrachill/v1', '/admin/grant-override', [
        'methods' => 'POST',
        'callback' => 'ec_api_grant_admin_override',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
}
```

### Remote Admin Validation
```php
// Validate admin access via API
function ec_validate_remote_admin_access($user_id, $site_id, $token) {
    $api_url = rest_url("extrachill/v1/admin/validate-access");
    
    $response = wp_remote_post($api_url, [
        'body' => [
            'user_id' => $user_id,
            'site_id' => $site_id,
            'token' => $token
        ]
    ]);
    
    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['valid'] ?? false;
    }
    
    return false;
}
```

## Configuration and Settings

### Access Control Settings
```php
// Admin access control settings
function ec_admin_access_settings() {
    register_setting('ec_admin_access', 'ec_require_ip_whitelist');
    register_setting('ec_admin_access', 'ec_allowed_ip_ranges');
    register_setting('ec_admin_access', 'ec_require_2fa_for_admin');
    register_setting('ec_admin_access', 'ec_session_timeout_minutes');
}
```

### Security Policies
- IP address restrictions
- Time-based access controls
- Two-factor authentication requirements
- Session timeout policies

## Error Handling

### Access Denied Responses
```php
// Handle access denied scenarios
function ec_handle_access_denied($message = 'Access denied') {
    if (wp_doing_ajax()) {
        wp_send_json_error(['message' => $message], 403);
    } elseif (is_admin()) {
        wp_die($message);
    } else {
        wp_redirect(home_url('/access-denied/'));
        exit;
    }
}
```

### Validation Errors
- Invalid user IDs
- Non-existent sites
- Malformed override requests
- Permission check failures

## Performance Optimization

### Caching Strategy
```php
// Cache permission checks
function ec_cached_permission_check($user_id, $capability, $site_id) {
    $cache_key = "ec_perm_{$user_id}_{$capability}_{$site_id}";
    $cached = wp_cache_get($cache_key, 'ec_permissions');
    
    if ($cached !== false) {
        return $cached;
    }
    
    $result = ec_has_capability_override($user_id, $capability, ['site_id' => $site_id]);
    wp_cache_set($cache_key, $result, 'ec_permissions', 300); // 5 minutes
    
    return $result;
}
```