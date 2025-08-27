<?php

/**
 * Most Active Users Section - Sorted by 30-day activity, with Custom Title (as Role) and Points
 * Optimized with caching to improve performance
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Cache key and duration
$cache_key = 'most_active_users_30_days';
$cache_duration = 6 * HOUR_IN_SECONDS; // Cache for 6 hours

// Try to get cached data first
$user_ids = get_transient( $cache_key );

// If no cached data, run the query and cache the results
if ( false === $user_ids ) {
    global $wpdb;
    
    $query = "
        SELECT u.ID, COUNT(p.ID) as activity_count 
        FROM {$wpdb->users} u
        LEFT JOIN {$wpdb->posts} p 
            ON u.ID = p.post_author 
            AND p.post_type IN ('topic', 'reply')
            AND p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND p.post_status = 'publish'
        GROUP BY u.ID
        ORDER BY activity_count DESC
        LIMIT 5
    ";

    $user_ids = $wpdb->get_col( $query );
    
    // Cache the results for 6 hours
    set_transient( $cache_key, $user_ids, $cache_duration );
}

// Get complete user objects
$users = array();
if ( !empty( $user_ids ) ) {
    foreach ( $user_ids as $user_id ) {
        $user_data = get_userdata( $user_id );
        if ( $user_data ) {
            $users[] = $user_data;
        }
    }
}

?>

<div class="most-active-users-section">
    <h2 class="forum-front-ec">Most Active Users</h2>
    <div class="active-users-grid">
        <?php if (!empty($users)) : ?>
            <?php foreach ($users as $user): ?>
                <?php 
                    $profile_url = bbp_get_user_profile_url($user->ID);
                    $points = get_user_meta($user->ID, 'extrachill_total_points', true);
                ?>
                <div class="user-card">
                    <div class="user-avatar">
                        <a href="<?php echo esc_url($profile_url); ?>">
                            <?php echo get_avatar($user->ID, 64); ?>
                        </a>
                    </div>
                    <div class="user-details">
                        <span class="username-badge">
                            <a class="user-name"href="<?php echo esc_url($profile_url); ?>">
                               <?php echo esc_html($user->display_name); ?>
                            </a>
                             <div class="forum-badges">
                                <?php do_action( 'bbp_theme_after_user_name', $user->ID ); ?>
                            </div>
                            </span>
                       <div class="user-custom-title">
                            <?php echo esc_html(bbp_get_user_display_role( $user->ID )); ?>
                        </div>
                        <div class="bbp-author-role">
                            <?php echo esc_html(extrachill_display_user_rank($user->ID)); ?>
                        </div>
                        <div class="user-stats">
                            <span class="post-count">
                                <?php echo number_format((float)$points, 1); ?> points
                            </span>
                        </div>
                        <div class="user-actions">
                            <a href="<?php echo esc_url($profile_url); ?>" class="view-profile">
                                View Profile
                            </a>
                            <?php if (function_exists('extrachill_follow_button')) {
                                extrachill_follow_button($user->ID);
                            } ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <p>No active users found.</p>
        <?php endif; ?>
    </div>
    <div class="view-all-users-link">
    <a href="<?php echo esc_url(home_url('/all-users')); ?>">View Leaderboards</a>
</div>
</div>

