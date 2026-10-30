# User Badges

Badge system providing achievement recognition, user engagement incentives, and visual status indicators across the Extra Chill Platform.

## Badge Categories

### Achievement Badges
```php
// Achievement-based badges
function ec_get_achievement_badges() {
    return [
        'first_post' => [
            'name' => 'First Post',
            'description' => 'Made your first community post',
            'icon' => 'edit-3',
            'color' => '#22c55e',
            'type' => 'automatic',
            'condition' => ['action' => 'post_created', 'count' => 1]
        ],
        'pioneer' => [
            'name' => 'Pioneer',
            'description' => 'One of the first 100 members',
            'icon' => 'compass',
            'color' => '#f59e0b',
            'type' => 'automatic',
            'condition' => ['user_id_position' => '<= 100']
        ],
        'veteran' => [
            'name' => 'Veteran',
            'description' => 'Member for over one year',
            'icon' => 'calendar-check',
            'color' => '#6366f1',
            'type' => 'automatic',
            'condition' => ['membership_duration' => '> 1 year']
        ],
        'centurion' => [
            'name' => 'Centurion',
            'description' => 'Created 100+ posts',
            'icon' => 'pen-tool',
            'color' => '#8b5cf6',
            'type' => 'automatic',
            'condition' => ['action' => 'post_created', 'count' => 100]
        ]
    ];
}
```

### Engagement Badges
```php
// Engagement-based badges
function ec_get_engagement_badges() {
    return [
        'helpful' => [
            'name' => 'Helpful',
            'description' => 'Received 50 helpful votes',
            'icon' => 'hand-heart',
            'color' => '#10b981',
            'type' => 'automatic',
            'condition' => ['helpful_votes' => 50]
        ],
        'conversationalist' => [
            'name' => 'Conversationalist',
            'description' => 'Made 200+ replies',
            'icon' => 'message-circle',
            'color' => '#3b82f6',
            'type' => 'automatic',
            'condition' => ['action' => 'reply_posted', 'count' => 200]
        ],
        'social_butterfly' => [
            'name' => 'Social Butterfly',
            'description' => 'Connected with 25+ users',
            'icon' => 'users',
            'color' => '#ec4899',
            'type' => 'automatic',
            'condition' => ['connections' => 25]
        ]
    ];
}
```

### Role-Based Badges
```php
// Role and status badges
function ec_get_role_badges() {
    return [
        'moderator' => [
            'name' => 'Moderator',
            'description' => 'Community moderator',
            'icon' => 'shield',
            'color' => '#ef4444',
            'type' => 'role_based',
            'condition' => ['role' => 'moderator']
        ],
        'artist' => [
            'name' => 'Artist',
            'description' => 'Verified artist on platform',
            'icon' => 'music',
            'color' => '#a855f7',
            'type' => 'role_based',
            'condition' => ['is_artist' => true]
        ],
        'team_member' => [
            'name' => 'Team Member',
            'description' => 'Extra Chill team member',
            'icon' => 'award',
            'color' => '#0891b2',
            'type' => 'role_based',
            'condition' => ['is_team_member' => true]
        ]
    ];
}
```

## Badge Awarding System

### Automatic Badge Detection
```php
// Check and award badges for user
function ec_check_and_award_badges($user_id) {
    $all_badges = array_merge(
        ec_get_achievement_badges(),
        ec_get_engagement_badges(),
        ec_get_role_badges()
    );
    
    $user_badges = ec_get_user_badges($user_id);
    
    foreach ($all_badges as $badge_key => $badge_data) {
        // Skip if already awarded
        if (in_array($badge_key, $user_badges)) {
            continue;
        }
        
        // Check if condition is met
        if (ec_badge_condition_met($user_id, $badge_data['condition'])) {
            ec_award_badge_to_user($user_id, $badge_key, $badge_data);
        }
    }
}
```

### Condition Evaluation
```php
// Evaluate badge condition
function ec_badge_condition_met($user_id, $condition) {
    switch (key($condition)) {
        case 'action':
            return ec_check_action_count($user_id, $condition['action'], $condition['count'] ?? 1);
            
        case 'helpful_votes':
            return ec_check_helpful_votes($user_id, $condition['helpful_votes']);
            
        case 'membership_duration':
            return ec_check_membership_duration($user_id, $condition['membership_duration']);
            
        case 'role':
            return ec_check_user_role($user_id, $condition['role']);
            
        case 'is_artist':
            return ec_check_artist_status($user_id);
            
        case 'is_team_member':
            return ec_is_team_member($user_id);
            
        default:
            return false;
    }
}
```

### Action Count Checking
```php
// Check if user has performed action specified times
function ec_check_action_count($user_id, $action, $required_count) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ec_points_log';
    
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} 
         WHERE user_id = %d AND action = %s",
        $user_id, $action
    ));
    
    return $count >= $required_count;
}
```

### Badge Awarding Process
```php
// Award badge to user
function ec_award_badge_to_user($user_id, $badge_key, $badge_data) {
    // Get current badges
    $user_badges = ec_get_user_badges($user_id);
    
    // Add new badge
    $user_badges[] = $badge_key;
    update_user_meta($user_id, 'rank_badges', array_unique($user_badges));
    
    // Log badge award
    ec_log_badge_award($user_id, $badge_key, $badge_data);
    
    // Award points for badge
    ec_award_points($user_id, 'badge_earned');
    
    // Send notification
    ec_send_badge_notification($user_id, $badge_data);
    
    // Trigger action for integrations
    do_action('ec_badge_awarded', $user_id, $badge_key, $badge_data);
    
    return true;
}
```

## Badge Display System

### Badge Rendering
```php
// Render user badges with HTML
function ec_render_user_badges($user_id, $size = 'medium', $show_tooltips = true) {
    $user_badges = ec_get_user_badges($user_id);
    $all_badges = ec_get_all_available_badges();
    
    if (empty($user_badges)) {
        return '';
    }
    
    $output = '<div class="user-badges ' . esc_attr($size) . '">';
    
    foreach ($user_badges as $badge_key) {
        if (isset($all_badges[$badge_key])) {
            $badge = $all_badges[$badge_key];
            $tooltip_attr = $show_tooltips ? ' title="' . esc_attr($badge['description']) . '"' : '';
            
            $output .= '<span class="badge badge-' . esc_attr($badge_key) . '"' . $tooltip_attr . '>';
            $output .= '<span class="badge-icon" style="color: ' . esc_attr($badge['color']) . '">';
            $output .= ec_get_icon_svg($badge['icon']);
            $output .= '</span>';
            if ($size === 'large') {
                $output .= '<span class="badge-name">' . esc_html($badge['name']) . '</span>';
            }
            $output .= '</span>';
        }
    }
    
    $output .= '</div>';
    
    return $output;
}
```

### Badge Collection Page
```php
// Display all badges with earned status
function ec_render_badge_collection($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    $user_badges = ec_get_user_badges($user_id);
    $all_badges = ec_get_all_available_badges();
    
    $output = '<div class="badge-collection">';
    $output .= '<h2>Badge Collection</h2>';
    
    // Group badges by category
    $categories = [
        'achievement' => 'Achievements',
        'engagement' => 'Engagement',
        'role' => 'Roles & Status'
    ];
    
    foreach ($categories as $category => $title) {
        $output .= '<h3>' . esc_html($title) . '</h3>';
        $output .= '<div class="badge-category">';
        
        foreach ($all_badges as $badge_key => $badge) {
            if ($badge['type'] === $category || ($category === 'role' && $badge['type'] === 'role_based')) {
                $is_earned = in_array($badge_key, $user_badges);
                $earned_class = $is_earned ? 'earned' : 'locked';
                
                $output .= '<div class="badge-item ' . $earned_class . '">';
                $output .= '<span class="badge-icon" style="color: ' . esc_attr($badge['color']) . '">';
                $output .= ec_get_icon_svg($badge['icon']);
                $output .= '</span>';
                $output .= '<span class="badge-info">';
                $output .= '<span class="badge-name">' . esc_html($badge['name']) . '</span>';
                $output .= '<span class="badge-description">' . esc_html($badge['description']) . '</span>';
                $output .= '</span>';
                $output .= '</div>';
            }
        }
        
        $output .= '</div>';
    }
    
    $output .= '</div>';
    
    return $output;
}
```

## Manual Badge Management

### Admin Badge Interface
```php
// Admin interface for manual badge awarding
function ec_admin_badge_management_interface() {
    add_users_page(
        'Badge Management',
        'Badges',
        'manage_options',
        'badge-management',
        'ec_render_badge_management_page'
    );
}
```

### Manual Badge Awarding
```php
// Award badge manually (admin function)
function ec_manual_award_badge($user_id, $badge_key, $reason = '') {
    $all_badges = ec_get_all_available_badges();
    
    if (!isset($all_badges[$badge_key])) {
        return new WP_Error('invalid_badge', 'Badge does not exist');
    }
    
    $user_badges = ec_get_user_badges($user_id);
    
    if (in_array($badge_key, $user_badges)) {
        return new WP_Error('already_awarded', 'User already has this badge');
    }
    
    // Award the badge
    $success = ec_award_badge_to_user($user_id, $badge_key, $all_badges[$badge_key]);
    
    if ($success) {
        // Log manual award
        ec_log_manual_badge_award($user_id, $badge_key, $reason);
        
        return true;
    }
    
    return false;
}
```

## Badge Analytics

### Badge Statistics
```php
// Get badge awarding statistics
function ec_get_badge_statistics($days = 30) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ec_badges_log';
    $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    
    return [
        'total_badges_awarded' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE awarded_at > %s",
            $date_limit
        )),
        'unique_users_awarded' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$table_name} WHERE awarded_at > %s",
            $date_limit
        )),
        'most_popular_badge' => ec_get_most_popular_badge($date_limit),
        'badge_award_rate' => ec_get_badge_award_rate($date_limit)
    ];
}
```

### User Badge Progress
```php
// Get user's progress toward next badges
function ec_get_user_badge_progress($user_id) {
    $all_badges = ec_get_all_available_badges();
    $user_badges = ec_get_user_badges($user_id);
    $progress = [];
    
    foreach ($all_badges as $badge_key => $badge) {
        if (in_array($badge_key, $user_badges)) {
            continue; // Already earned
        }
        
        if ($badge['type'] === 'automatic') {
            $progress[$badge_key] = [
                'badge' => $badge,
                'progress' => ec_calculate_badge_progress($user_id, $badge_key, $badge)
            ];
        }
    }
    
    return $progress;
}
```

## Integration Points

### Community Integration
```php
// Check badges after community actions
function ec_community_badge_integration($action, $user_id, $context) {
    // Immediately check for new badges
    ec_check_and_award_badges($user_id);
    
    // Schedule background check for complex badges
    wp_schedule_single_event(time() + 60, 'ec_check_complex_badges', [$user_id]);
}
```

### Artist Platform Integration
```php
// Artist-specific badge logic
function ec_artist_badge_integration($artist_id, $action) {
    $user_id = get_post_meta($artist_id, 'user_id', true);
    
    if ($user_id) {
        switch ($action) {
            case 'profile_approved':
                ec_manual_award_badge($user_id, 'artist', 'Artist profile approved');
                break;
            case 'show_scheduled':
                ec_check_and_award_badges($user_id); // Check for performance badges
                break;
        }
    }
}
```

## Security and Validation

### Badge Validation
```php
// Validate badge awarding request
function ec_validate_badge_award($user_id, $badge_key, $context = []) {
    // Check user exists
    if (!get_userdata($user_id)) {
        return new WP_Error('invalid_user', 'User does not exist');
    }
    
    // Check badge exists
    $all_badges = ec_get_all_available_badges();
    if (!isset($all_badges[$badge_key])) {
        return new WP_Error('invalid_badge', 'Badge does not exist');
    }
    
    // Check for duplicates
    $user_badges = ec_get_user_badges($user_id);
    if (in_array($badge_key, $user_badges)) {
        return new WP_Error('duplicate_badge', 'User already has this badge');
    }
    
    // Validate manual award permissions
    if ($context['manual'] && !current_user_can('manage_options')) {
        return new WP_Error('permission_denied', 'Cannot manually award badges');
    }
    
    return true;
}
```

### Anti-Exploitation
- Rate limiting for badge checks
- Validation of manual awards
- Audit logging for all badge changes
- Protection against badge farming

## Performance Optimization

### Efficient Badge Checking
```php
// Cache user badge checks
function ec_cached_badge_check($user_id, $badge_key) {
    $cache_key = "ec_badge_check_{$user_id}_{$badge_key}";
    $cached_result = wp_cache_get($cache_key, 'ec_badges');
    
    if ($cached_result !== false) {
        return $cached_result;
    }
    
    $result = ec_badge_condition_met($user_id, $badge_key);
    wp_cache_set($cache_key, $result, 'ec_badges', 300); // 5 minutes
    
    return $result;
}
```

### Batch Processing
```php
// Schedule batch badge checking
function ec_schedule_batch_badge_check($user_ids) {
    // Process in batches of 10 users
    $batches = array_chunk($user_ids, 10);
    
    foreach ($batches as $batch) {
        wp_schedule_single_event(time() + 30, 'ec_check_batch_badges', [$batch]);
    }
}
```

## API Integration

### Badge API Endpoints
```php
// Register badge API endpoints
function ec_register_badge_api_endpoints() {
    register_rest_route('extrachill/v1', '/users/(?P<user_id>\d+)/badges', [
        'methods' => 'GET',
        'callback' => 'ec_api_get_user_badges',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    register_rest_route('extrachill/v1', '/badges', [
        'methods' => 'GET',
        'callback' => 'ec_api_get_all_badges',
        'permission_callback' => '__return_true'
    ]);
    
    register_rest_route('extrachill/v1', '/users/(?P<user_id>\d+)/badges/award', [
        'methods' => 'POST',
        'callback' => 'ec_api_award_badge',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
}
```

### Badge Progress API
```php
// Get user's badge progress
function ec_api_get_badge_progress($request) {
    $user_id = $request->get_param('user_id');
    
    return [
        'earned_badges' => ec_get_user_badges($user_id),
        'progress' => ec_get_user_badge_progress($user_id),
        'total_available' => count(ec_get_all_available_badges()),
        'completion_percentage' => ec_get_badge_completion_percentage($user_id)
    ];
}
```