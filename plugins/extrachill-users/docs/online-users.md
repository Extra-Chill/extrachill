# Online Users

Real-time user tracking system providing online status, activity monitoring, and user presence features across the Extra Chill Platform.

## User Activity Tracking

### Activity Recording
```php
// Record user activity
function extrachill_track_user_activity($user_id) {
    $current_time = current_time('mysql');
    
    // Update last activity timestamp
    update_user_meta($user_id, 'last_activity', $current_time);
    
    // Update session data
    $_SESSION['last_seen'] = $current_time;
    $_SESSION['user_id'] = $user_id;
    
    // Track page view if available
    if (function_exists('extrachill_track_page_view')) {
        extrachill_track_page_view($user_id, $_SERVER['REQUEST_URI']);
    }
}
```

### Automatic Activity Detection
```php
// Hook into WordPress actions to track activity
function extrachill_setup_activity_tracking() {
    // Track various user actions
    add_action('wp_login', function($user_login, $user) {
        extrachill_track_user_activity($user->ID);
    }, 10, 2);
    
    add_action('comment_post', function($comment_id, $comment_approved) {
        if ($comment_approved === 1) {
            $comment = get_comment($comment_id);
            extrachill_track_user_activity($comment->user_id);
        }
    }, 10, 2);
    
    add_action('bbp_new_topic', function($topic_id, $forum_id, $anonymous_data, $topic_author) {
        extrachill_track_user_activity($topic_author);
    }, 10, 4);
    
    add_action('bbp_new_reply', function($reply_id, $topic_id, $forum_id, $anonymous_data, $reply_author) {
        extrachill_track_user_activity($reply_author);
    }, 10, 5);
}
```

## Online Status Detection

### Online Status Calculation
```php
// Check if user is currently online
function extrachill_is_user_online($user_id, $threshold_minutes = 5) {
    $last_activity = get_user_meta($user_id, 'last_activity', true);
    
    if (!$last_activity) {
        return false;
    }
    
    $last_activity_time = strtotime($last_activity);
    $threshold_time = time() - ($threshold_minutes * 60);
    
    return $last_activity_time > $threshold_time;
}
```

### Online Users Query
```php
// Get currently online users
function extrachill_get_online_users($limit = 20, $exclude_admins = false) {
    global $wpdb;
    
    $threshold_time = date('Y-m-d H:i:s', time() - (5 * 60)); // 5 minutes ago
    
    $query = $wpdb->prepare(
        "SELECT u.ID, u.display_name, u.user_nicename, um.meta_value as last_activity
         FROM {$wpdb->users} u
         JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
         WHERE um.meta_key = 'last_activity' 
         AND um.meta_value > %s
         AND u.user_status = 0",
        $threshold_time
    );
    
    if ($exclude_admins) {
        $query .= " AND u.ID NOT IN (
            SELECT user_id FROM {$wpdb->usermeta} 
            WHERE meta_key = '{$wpdb->prefix}capabilities' 
            AND meta_value LIKE '%administrator%'
        )";
    }
    
    $query .= " ORDER BY um.meta_value DESC LIMIT %d";
    $query = $wpdb->prepare($query, $limit);
    
    return $wpdb->get_results($query);
}
```

## Online Statistics

### Real-time Stats
```php
// Get online user statistics
function extrachill_get_online_stats() {
    global $wpdb;
    
    $thresholds = [
        'now' => 1,          // 1 minute
        'recent' => 5,       // 5 minutes
        'hour' => 60,        // 1 hour
        'day' => 24 * 60     // 24 hours
    ];
    
    $stats = [];
    
    foreach ($thresholds as $key => $minutes) {
        $threshold_time = date('Y-m-d H:i:s', time() - ($minutes * 60));
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT um.user_id)
             FROM {$wpdb->usermeta} um
             WHERE um.meta_key = 'last_activity'
             AND um.meta_value > %s",
            $threshold_time
        ));
        
        $stats[$key] = (int) $count;
    }
    
    return $stats;
}
```

### User Activity Summary
```php
// Get detailed activity summary
function extrachill_get_activity_summary($days = 7) {
    global $wpdb;
    
    $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    
    return [
        'unique_active_users' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) 
             FROM {$wpdb->prefix}ec_activity_log 
             WHERE created_at > %s",
            $date_limit
        )),
        'total_activities' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
             FROM {$wpdb->prefix}ec_activity_log 
             WHERE created_at > %s",
            $date_limit
        )),
        'peak_activity_hour' => extrachill_get_peak_activity_hour($days),
        'most_active_users' => extrachill_get_most_active_users($days, 5)
    ];
}
```

## Online User Display

### Online Users Widget
```php
// Render online users widget
function extrachill_render_online_users_widget($title = 'Online Now', $limit = 10) {
    $online_users = extrachill_get_online_users($limit);
    
    if (empty($online_users)) {
        return '';
    }
    
    $output = '<div class="online-users-widget">';
    $output .= '<h3>' . esc_html($title) . '</h3>';
    
    $output .= '<div class="online-users-list">';
    
    foreach ($online_users as $user) {
        $profile_url = ec_get_user_profile_url($user->ID);
        $avatar = get_avatar($user->ID, 32);
        $is_online = extrachill_is_user_online($user->ID);
        
        $output .= '<div class="online-user">';
        $output .= '<a href="' . esc_url($profile_url) . '" class="user-link">';
        $output .= '<span class="user-avatar">' . $avatar . '</span>';
        $output .= '<span class="user-info">';
        $output .= '<span class="user-name">' . esc_html($user->display_name) . '</span>';
        $output .= '<span class="online-indicator ' . ($is_online ? 'online' : 'away') . '"></span>';
        $output .= '</span>';
        $output .= '</a>';
        $output .= '</div>';
    }
    
    $output .= '</div>';
    $output .= '</div>';
    
    return $output;
}
```

### Online Status Indicators
```php
// Display online status for a user
function extrachill_display_user_status($user_id, $size = 'small') {
    $is_online = extrachill_is_user_online($user_id);
    $last_activity = get_user_meta($user_id, 'last_activity', true);
    
    $status_class = $is_online ? 'online' : 'offline';
    $status_text = $is_online ? 'Online' : extrachill_get_last_seen_text($last_activity);
    
    $output = '<div class="user-status ' . esc_attr($size) . '">';
    $output .= '<span class="status-indicator ' . esc_attr($status_class) . '"></span>';
    
    if ($size === 'large') {
        $output .= '<span class="status-text">' . esc_html($status_text) . '</span>';
    } else {
        $output .= '<span class="status-text sr-only">' . esc_html($status_text) . '</span>';
    }
    
    $output .= '</div>';
    
    return $output;
}
```

## Activity Logging

### Activity Log Recording
```php
// Log user activity to database
function extrachill_log_activity($user_id, $action, $context = []) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ec_activity_log';
    
    $log_data = [
        'user_id' => $user_id,
        'action' => $action,
        'context' => json_encode($context),
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
        'created_at' => current_time('mysql')
    ];
    
    $wpdb->insert($table_name, $log_data);
    
    // Update user's last activity
    extrachill_track_user_activity($user_id);
}
```

### Activity Types
```php
// Define activity types for tracking
function extrachill_get_activity_types() {
    return [
        'login' => 'User Login',
        'logout' => 'User Logout',
        'post_created' => 'Created Post',
        'comment_posted' => 'Posted Comment',
        'topic_created' => 'Created Forum Topic',
        'reply_posted' => 'Posted Forum Reply',
        'profile_updated' => 'Updated Profile',
        'avatar_uploaded' => 'Uploaded Avatar',
        'badge_earned' => 'Earned Badge',
        'page_view' => 'Viewed Page',
        'search_performed' => 'Performed Search'
    ];
}
```

## Cleanup and Maintenance

### Activity Cleanup
```php
// Clean up old activity data
function extrachill_cleanup_activity_data() {
    global $wpdb;
    
    $tables = [
        $wpdb->prefix . 'ec_activity_log' => 30, // Keep 30 days
    ];
    
    foreach ($tables as $table => $days) {
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff_date
        ));
    }
    
    // Clean up stale online status
    $stale_time = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} 
         WHERE meta_key = 'last_activity' 
         AND meta_value < %s",
        $stale_time
    ));
}
```

### Scheduled Maintenance
```php
// Schedule cleanup tasks
function extrachill_schedule_maintenance() {
    if (!wp_next_scheduled('extrachill_cleanup_activity_data')) {
        wp_schedule_event(time(), 'daily', 'extrachill_cleanup_activity_data');
    }
}
```

## Integration Points

### Community Integration
```php
// Community online features
function extrachill_community_online_integration() {
    // Show online status in forum posts
    add_filter('bbp_get_author_link', function($author_link, $post_id) {
        $user_id = get_post_field('post_author', $post_id);
        $status = extrachill_display_user_status($user_id, 'small');
        
        return $author_link . ' ' . $status;
    }, 10, 2);
    
    // Add online users to community sidebar
    add_action('bbp_theme_before_sidebar', function() {
        echo extrachill_render_online_users_widget('Community Members Online', 15);
    });
}
```

### Chat Integration
```php
// Chat online presence
function extrachill_chat_online_integration($user_id) {
    // Update chat presence
    if (function_exists('extrachill_chat_update_presence')) {
        extrachill_chat_update_presence($user_id, extrachill_is_user_online($user_id));
    }
    
    // Notify about user coming online/going offline
    $previous_status = get_user_meta($user_id, 'previous_online_status', true);
    $current_status = extrachill_is_user_online($user_id);
    
    if ($previous_status !== $current_status) {
        do_action('extrachill_user_status_changed', $user_id, $previous_status, $current_status);
        update_user_meta($user_id, 'previous_online_status', $current_status);
    }
}
```

## Performance Optimization

### Efficient Queries
```php
// Optimize online users query with caching
function extrachill_cached_get_online_users($limit = 20) {
    $cache_key = "online_users_{$limit}";
    $cached = wp_cache_get($cache_key, 'extrachill_online');
    
    if ($cached !== false) {
        return $cached;
    }
    
    $users = extrachill_get_online_users($limit);
    wp_cache_set($cache_key, $users, 'extrachill_online', 60); // 1 minute cache
    
    return $users;
}
```

### Database Indexing
```php
// Create database indexes for performance
function extrachill_setup_database_indexes() {
    global $wpdb;
    
    $wpdb->query(
        "CREATE INDEX IF NOT EXISTS idx_last_activity 
         ON {$wpdb->usermeta} (meta_key, meta_value)"
    );
    
    $wpdb->query(
        "CREATE INDEX IF NOT EXISTS idx_activity_created_at 
         ON {$wpdb->prefix}ec_activity_log (created_at)"
    );
    
    $wpdb->query(
        "CREATE INDEX IF NOT EXISTS idx_activity_user_created 
         ON {$wpdb->prefix}ec_activity_log (user_id, created_at)"
    );
}
```

## API Integration

### Online Status API
```php
// Register online status API endpoints
function extrachill_register_online_api_endpoints() {
    register_rest_route('extrachill/v1', '/users/online', [
        'methods' => 'GET',
        'callback' => 'extrachill_api_get_online_users',
        'permission_callback' => '__return_true'
    ]);
    
    register_rest_route('extrachill/v1', '/users/(?P<user_id>\d+)/status', [
        'methods' => 'GET',
        'callback' => 'extrachill_api_get_user_status',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    register_rest_route('extrachill/v1', '/stats/online', [
        'methods' => 'GET',
        'callback' => 'extrachill_api_get_online_stats',
        'permission_callback' => '__return_true'
    ]);
}
```

### Real-time Updates
```php
// WebSocket-like updates for real-time status
function extrachill_realtime_status_update($user_id, $action) {
    if (function_exists('extrachill_push_notification')) {
        $payload = [
            'type' => 'user_status',
            'user_id' => $user_id,
            'action' => $action,
            'timestamp' => time()
        ];
        
        extrachill_push_notification('user_activity', $payload);
    }
}
```

## Security and Privacy

### Privacy Controls
```php
// Respect user privacy settings
function extrachill_should_show_online_status($user_id) {
    $privacy_setting = get_user_meta($user_id, 'show_online_status', true);
    
    // Default to showing status
    if ($privacy_setting === '') {
        return true;
    }
    
    return (bool) $privacy_setting;
}
```

### Activity Filtering
```php
// Filter sensitive activities from logs
function extrachill_filter_sensitive_activities($activity) {
    $sensitive_actions = [
        'password_reset',
        'email_change',
        'admin_login',
        'security_event'
    ];
    
    return !in_array($activity['action'], $sensitive_actions);
}
```

## Analytics and Reporting

### Activity Reports
```php
// Generate activity report
function extrachill_generate_activity_report($start_date, $end_date) {
    global $wpdb;
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT 
            DATE(created_at) as date,
            COUNT(DISTINCT user_id) as unique_users,
            COUNT(*) as total_activities,
            action,
            COUNT(*) as action_count
         FROM {$wpdb->prefix}ec_activity_log
         WHERE created_at BETWEEN %s AND %s
         GROUP BY DATE(created_at), action
         ORDER BY date DESC, action_count DESC",
        $start_date, $end_date
    ));
}
```