# User Rank System

User ranking and badge system providing gamification elements, reputation tracking, and user engagement metrics across the Extra Chill Platform.

## Rank Tiers Structure

### Rank Configuration
```php
// Rank tier definitions
function ec_get_rank_tiers() {
    return [
        'newcomer' => [
            'name' => 'Newcomer',
            'description' => 'Just getting started',
            'min_points' => 0,
            'badge_icon' => 'seedling',
            'color' => '#22c55e',
            'privileges' => ['basic_posting', 'profile_edit']
        ],
        'contributor' => [
            'name' => 'Contributor',
            'description' => 'Active community member',
            'min_points' => 100,
            'badge_icon' => 'star',
            'color' => '#3b82f6',
            'privileges' => ['enhanced_posting', 'comment_moderation']
        ],
        'expert' => [
            'name' => 'Expert',
            'description' => 'Respected community voice',
            'min_points' => 500,
            'badge_icon' => 'award',
            'color' => '#8b5cf6',
            'privileges' => ['content_moderation', 'user_mention']
        ],
        'veteran' => [
            'name' => 'Veteran',
            'description' => 'Long-time pillar of the community',
            'min_points' => 1000,
            'badge_icon' => 'crown',
            'color' => '#f59e0b',
            'privileges' => ['advanced_moderation', 'exclusive_content']
        ],
        'legend' => [
            'name' => 'Legend',
            'description' => 'Community icon and inspiration',
            'min_points' => 2500,
            'badge_icon' => 'gem',
            'color' => '#ef4444',
            'privileges' => ['full_moderation', 'community_leadership']
        ]
    ];
}
```

### Rank Calculation
```php
// Calculate user's current rank
function ec_calculate_user_rank($user_id) {
    $user_points = ec_get_user_points($user_id);
    $rank_tiers = ec_get_rank_tiers();
    
    $current_rank = 'newcomer';
    
    foreach ($rank_tiers as $tier_key => $tier_data) {
        if ($user_points >= $tier_data['min_points']) {
            $current_rank = $tier_key;
        }
    }
    
    return $current_rank;
}
```

## Points System

### Point Actions
```php
// Point awarding actions
function ec_get_point_actions() {
    return [
        'post_created' => 10,
        'comment_posted' => 5,
        'comment_received' => 2,
        'topic_created' => 15,
        'reply_posted' => 3,
        'helpful_vote_received' => 5,
        'daily_login' => 2,
        'profile_completed' => 20,
        'avatar_uploaded' => 10,
        'first_post' => 25,
        'welcome_message' => 5,
        'referral_joined' => 50,
        'content_shared' => 3,
        'badge_earned' => 15
    ];
}
```

### Points Awarding
```php
// Award points to user
function ec_award_points($user_id, $action, $context = []) {
    $point_actions = ec_get_point_actions();
    $points = $point_actions[$action] ?? 0;
    
    if ($points > 0) {
        $current_points = ec_get_user_points($user_id);
        $new_points = $current_points + $points;
        
        // Update user points
        update_user_meta($user_id, 'rank_points', $new_points);
        
        // Log point award
        ec_log_point_award($user_id, $action, $points, $context);
        
        // Check for rank progression
        ec_check_rank_progression($user_id, $new_points);
        
        // Trigger action for other integrations
        do_action('ec_points_awarded', $user_id, $action, $points, $context);
    }
    
    return $points;
}
```

### Points Tracking
```php
// Get user's total points
function ec_get_user_points($user_id) {
    return (int) get_user_meta($user_id, 'rank_points', true) ?: 0;
}

// Get points breakdown by action
function ec_get_points_breakdown($user_id, $days = 30) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ec_points_log';
    $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT action, COUNT(*) as count, SUM(points) as total_points 
         FROM {$table_name} 
         WHERE user_id = %d AND created_at > %s 
         GROUP BY action 
         ORDER BY total_points DESC",
        $user_id, $date_limit
    ));
}
```

## Badge System

### Badge Definitions
```php
// Available badges
function ec_get_badges() {
    return [
        'pioneer' => [
            'name' => 'Pioneer',
            'description' => 'One of the first 100 members',
            'icon' => 'compass',
            'color' => '#f59e0b',
            'award_type' => 'automatic',
            'condition' => 'early_adopter'
        ],
        'helpful' => [
            'name' => 'Helpful',
            'description' => 'Received 50 helpful votes',
            'icon' => 'hand-heart',
            'color' => '#10b981',
            'award_type' => 'automatic',
            'condition' => 'helpful_votes_50'
        ],
        'veteran' => [
            'name' => 'Veteran',
            'description' => 'Member for over a year',
            'icon' => 'calendar-check',
            'color' => '#6366f1',
            'award_type' => 'automatic',
            'condition' => 'member_one_year'
        ],
        'creator' => [
            'name' => 'Creator',
            'description' => 'Created 100+ posts',
            'icon' => 'pen-tool',
            'color' => '#8b5cf6',
            'award_type' => 'automatic',
            'condition' => 'posts_100'
        ],
        'moderator' => [
            'name' => 'Moderator',
            'description' => 'Community moderator',
            'icon' => 'shield',
            'color' => '#ef4444',
            'award_type' => 'manual'
        ]
    ];
}
```

### Badge Awarding
```php
// Award badge to user
function ec_award_badge($user_id, $badge_key) {
    $badges = ec_get_user_badges($user_id);
    
    if (!in_array($badge_key, $badges)) {
        $badges[] = $badge_key;
        update_user_meta($user_id, 'rank_badges', $badges);
        
        // Award bonus points for badge
        ec_award_points($user_id, 'badge_earned');
        
        // Log badge award
        ec_log_badge_award($user_id, $badge_key);
        
        // Trigger action
        do_action('ec_badge_awarded', $user_id, $badge_key);
        
        return true;
    }
    
    return false;
}
```

### Badge Display
```php
// Get user's badges
function ec_get_user_badges($user_id) {
    return get_user_meta($user_id, 'rank_badges', true) ?: [];
}

// Render user badges
function ec_render_user_badges($user_id, $size = 'small') {
    $badges = ec_get_user_badges($user_id);
    $all_badges = ec_get_badges();
    
    if (empty($badges)) {
        return '';
    }
    
    $output = '<div class="user-badges ' . esc_attr($size) . '">';
    
    foreach ($badges as $badge_key) {
        if (isset($all_badges[$badge_key])) {
            $badge = $all_badges[$badge_key];
            $output .= '<span class="badge" title="' . esc_attr($badge['description']) . '">';
            $output .= '<span class="badge-icon" style="color: ' . esc_attr($badge['color']) . '">' . ec_get_icon($badge['icon']) . '</span>';
            $output .= '</span>';
        }
    }
    
    $output .= '</div>';
    
    return $output;
}
```

## Rank Progression

### Progress Tracking
```php
// Calculate rank progress
function ec_get_rank_progress($user_id) {
    $user_points = ec_get_user_points($user_id);
    $current_rank = ec_calculate_user_rank($user_id);
    $rank_tiers = ec_get_rank_tiers();
    
    $current_rank_data = $rank_tiers[$current_rank];
    $next_rank_key = ec_get_next_rank_key($current_rank);
    
    if (!$next_rank_key) {
        // User is at highest rank
        return [
            'current_rank' => $current_rank,
            'current_points' => $user_points,
            'rank_name' => $current_rank_data['name'],
            'progress_percent' => 100,
            'points_to_next' => 0,
            'is_max_rank' => true
        ];
    }
    
    $next_rank_data = $rank_tiers[$next_rank_key];
    $points_in_current_rank = $user_points - $current_rank_data['min_points'];
    $points_needed_for_next = $next_rank_data['min_points'] - $current_rank_data['min_points'];
    $progress_percent = min(100, ($points_in_current_rank / $points_needed_for_next) * 100);
    
    return [
        'current_rank' => $current_rank,
        'next_rank' => $next_rank_key,
        'current_points' => $user_points,
        'rank_name' => $current_rank_data['name'],
        'next_rank_name' => $next_rank_data['name'],
        'progress_percent' => round($progress_percent),
        'points_to_next' => $next_rank_data['min_points'] - $user_points,
        'is_max_rank' => false
    ];
}
```

### Rank Progression Check
```php
// Check if user has progressed to next rank
function ec_check_rank_progression($user_id, $new_points) {
    $old_rank = get_user_meta($user_id, 'current_rank', true) ?: 'newcomer';
    $new_rank = ec_calculate_user_rank($user_id);
    
    if ($new_rank !== $old_rank) {
        // User ranked up!
        ec_handle_rank_promotion($user_id, $old_rank, $new_rank);
    }
}

// Handle rank promotion
function ec_handle_rank_promotion($user_id, $old_rank, $new_rank) {
    // Update user's current rank
    update_user_meta($user_id, 'current_rank', $new_rank);
    
    // Award bonus points for ranking up
    $rank_bonus = ec_get_rank_promotion_bonus($new_rank);
    if ($rank_bonus > 0) {
        ec_award_points($user_id, 'rank_promotion', ['bonus_points' => $rank_bonus]);
    }
    
    // Check for rank-specific badges
    ec_check_rank_badges($user_id, $new_rank);
    
    // Send notification
    ec_send_rank_promotion_notification($user_id, $old_rank, $new_rank);
    
    // Trigger action
    do_action('ec_rank_promoted', $user_id, $old_rank, $new_rank);
}
```

## Leaderboard System

### Leaderboard Generation
```php
// Get top users by points
function ec_get_leaderboard($limit = 10, $period = 'all_time') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'usermeta';
    
    $date_condition = '';
    if ($period === 'monthly') {
        $date_condition = "AND um2.meta_key = 'monthly_points' AND um2.meta_value";
    } elseif ($period === 'weekly') {
        $date_condition = "AND um2.meta_key = 'weekly_points' AND um2.meta_value";
    }
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT u.ID, u.display_name, um1.meta_value as points 
         FROM {$wpdb->users} u 
         JOIN {$table_name} um1 ON u.ID = um1.user_id 
         LEFT JOIN {$table_name} um2 ON u.ID = um2.user_id 
         WHERE um1.meta_key = 'rank_points' {$date_condition}
         ORDER BY CAST(um1.meta_value AS UNSIGNED) DESC 
         LIMIT %d",
        $limit
    ));
    
    return array_map(function($row) {
        return [
            'user_id' => $row->ID,
            'display_name' => $row->display_name,
            'points' => (int) $row->points,
            'rank' => ec_calculate_user_rank($row->ID),
            'badges' => ec_get_user_badges($row->ID)
        ];
    }, $results);
}
```

### Leaderboard Display
```php
// Render leaderboard widget
function ec_render_leaderboard($limit = 10) {
    $leaders = ec_get_leaderboard($limit);
    $current_user_id = get_current_user_id();
    
    $output = '<div class="leaderboard-widget">';
    $output .= '<h3>Top Contributors</h3>';
    
    foreach ($leaders as $index => $leader) {
        $position = $index + 1;
        $is_current_user = $leader['user_id'] === $current_user_id;
        
        $output .= '<div class="leaderboard-entry ' . ($is_current_user ? 'current-user' : '') . '">';
        $output .= '<span class="position">' . $position . '</span>';
        $output .= '<span class="user-info">';
        $output .= get_avatar($leader['user_id'], 24);
        $output .= '<span class="name">' . esc_html($leader['display_name']) . '</span>';
        $output .= '</span>';
        $output .= '<span class="points">' . number_format($leader['points']) . ' pts</span>';
        $output .= '</div>';
    }
    
    $output .= '</div>';
    
    return $output;
}
```

## Integration Points

### Community Integration
```php
// Award points for community actions
function ec_community_points_integration($action, $user_id, $context) {
    $point_actions = ec_get_point_actions();
    
    switch ($action) {
        case 'bbp_new_topic':
            ec_award_points($user_id, 'topic_created', $context);
            break;
        case 'bbp_new_reply':
            ec_award_points($user_id, 'reply_posted', $context);
            break;
        case 'helpful_vote':
            ec_award_points($context['author_id'], 'helpful_vote_received', $context);
            break;
    }
}
```

### Artist Platform Integration
```php
// Artist-specific ranks and badges
function ec_artist_rank_integration($artist_id, $action) {
    $user_id = get_post_meta($artist_id, 'user_id', true);
    
    if ($user_id) {
        switch ($action) {
            case 'profile_created':
                ec_award_points($user_id, 'artist_profile_created');
                ec_award_badge($user_id, 'artist');
                break;
            case 'show_added':
                ec_award_points($user_id, 'show_added');
                break;
        }
    }
}
```

## API Endpoints

### Points API
```php
// Register points endpoints
function ec_register_points_api_endpoints() {
    register_rest_route('extrachill/v1', '/users/(?P<user_id>\d+)/points', [
        'methods' => 'GET',
        'callback' => 'ec_api_get_user_points',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    register_rest_route('extrachill/v1', '/leaderboard', [
        'methods' => 'GET',
        'callback' => 'ec_api_get_leaderboard',
        'permission_callback' => '__return_true'
    ]);
}
```

## Performance Optimization

### Points Caching
```php
// Cache user points
function ec_cached_get_user_points($user_id) {
    $cache_key = "ec_user_points_{$user_id}";
    $cached_points = wp_cache_get($cache_key, 'ec_points');
    
    if ($cached_points !== false) {
        return $cached_points;
    }
    
    $points = ec_get_user_points($user_id);
    wp_cache_set($cache_key, $points, 'ec_points', 300); // 5 minutes
    
    return $points;
}
```

### Database Optimization
- Indexed columns for queries
- Optimized leaderboard queries
- Efficient badge lookups
- Scheduled cleanup routines

## Security and Validation

### Points Security
```php
// Validate point awarding
function ec_validate_point_award($user_id, $action, $points) {
    // Check user exists
    if (!get_userdata($user_id)) {
        return false;
    }
    
    // Validate action
    $valid_actions = array_keys(ec_get_point_actions());
    if (!in_array($action, $valid_actions)) {
        return false;
    }
    
    // Rate limiting for automated awards
    if (ec_should_rate_limit_point_award($user_id, $action)) {
        return false;
    }
    
    return true;
}
```

### Anti-Exploitation
- Rate limiting for point awards
- Validation of point actions
- Audit logging for all changes
- Duplicate prevention mechanisms

## Analytics and Reporting

### Points Analytics
```php
// Get points statistics
function ec_get_points_statistics($days = 30) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ec_points_log';
    $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    
    return [
        'total_points_awarded' => $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(points) FROM {$table_name} WHERE created_at > %s",
            $date_limit
        )),
        'active_users' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$table_name} WHERE created_at > %s",
            $date_limit
        )),
        'top_action' => ec_get_top_point_action($date_limit),
        'daily_average' => ec_get_daily_points_average($date_limit)
    ];
}
```