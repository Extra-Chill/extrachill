# bbPress Forum Integration

Comprehensive integration with bbPress providing artist-specific forum sections, custom permissions, and linked discussions.

## System Overview

The forum integration creates dedicated forum spaces for each artist profile, enabling community discussions tied to specific artists with proper permission management.

## Core Integration Components

### Artist Forum Creation

Location: `inc/artist-profiles/artist-forums.php`

```php
/**
 * Create forum for artist profile
 * 
 * @param int $artist_id Artist profile post ID
 * @param array $forum_data Forum configuration data
 * @return int|false Forum ID on success, false on failure
 */
function create_artist_forum($artist_id, $forum_data = []) {
    if (!function_exists('bbp_insert_forum')) {
        return false; // bbPress not available
    }
    
    $artist_name = get_the_title($artist_id);
    
    $defaults = [
        'post_title' => $artist_name . ' Discussion',
        'post_content' => 'Discussion forum for ' . $artist_name,
        'post_status' => 'publish',
        'post_type' => bbp_get_forum_post_type()
    ];
    
    $forum_args = wp_parse_args($forum_data, $defaults);
    
    // Create forum
    $forum_id = bbp_insert_forum($forum_args);
    
    if ($forum_id) {
        // Link forum to artist profile
        update_post_meta($artist_id, '_artist_forum_id', $forum_id);
        update_post_meta($forum_id, '_associated_artist_profile_id', $artist_id);
        
        // Set forum permissions
        setup_artist_forum_permissions($forum_id, $artist_id);
        
        do_action('extrch_artist_forum_created', $forum_id, $artist_id);
    }
    
    return $forum_id;
}
```

### Forum-Artist Linking

```php
/**
 * Get forum ID associated with artist
 */
function ec_get_forum_for_artist($artist_id) {
    if (!$artist_id || get_post_type($artist_id) !== 'artist_profile') {
        return false;
    }
    
    $forum_id = get_post_meta($artist_id, '_artist_forum_id', true);
    
    // Verify forum still exists
    if ($forum_id && get_post_type($forum_id) === bbp_get_forum_post_type()) {
        return (int) $forum_id;
    }
    
    return false;
}

/**
 * Get artist associated with forum
 */
function get_artist_for_forum($forum_id) {
    if (!$forum_id || get_post_type($forum_id) !== bbp_get_forum_post_type()) {
        return false;
    }
    
    $artist_id = get_post_meta($forum_id, '_associated_artist_profile_id', true);
    
    // Verify artist still exists
    if ($artist_id && get_post_type($artist_id) === 'artist_profile') {
        return (int) $artist_id;
    }
    
    return false;
}
```

## Permission System

### Forum-Specific Permissions

Location: `inc/artist-profiles/artist-forum-section-overrides.php`

```php
/**
 * Setup permissions for artist forum
 */
function setup_artist_forum_permissions($forum_id, $artist_id) {
    // Get artist members
    $artist_members = get_artist_members($artist_id);
    
    // Set moderator permissions for artist members
    foreach ($artist_members as $member_id) {
        $user = new WP_User($member_id);
        $user->add_cap('moderate_forum_' . $forum_id);
    }
    
    // Store permission settings
    update_post_meta($forum_id, '_artist_forum_permissions', [
        'artist_id' => $artist_id,
        'moderators' => $artist_members,
        'created' => current_time('mysql')
    ]);
}

/**
 * Check if user can moderate artist forum
 */
function can_user_moderate_artist_forum($user_id, $forum_id) {
    if (!$user_id || !$forum_id) {
        return false;
    }
    
    // Admin override
    if (user_can($user_id, 'manage_options')) {
        return true;
    }
    
    // Check if user is artist member
    $artist_id = get_artist_for_forum($forum_id);
    if ($artist_id) {
        return ec_can_manage_artist($user_id, $artist_id);
    }
    
    // Check specific forum permission
    return user_can($user_id, 'moderate_forum_' . $forum_id);
}
```

### bbPress Hook Integration

```php
/**
 * Filter forum permissions based on artist association
 */
function filter_forum_permissions($caps, $cap, $user_id, $args) {
    if (!isset($args[0])) return $caps;
    
    $forum_id = $args[0];
    
    switch ($cap) {
        case 'edit_forum':
        case 'delete_forum':
            if (can_user_moderate_artist_forum($user_id, $forum_id)) {
                $caps = ['exist']; // Grant permission
            }
            break;
            
        case 'moderate_forum':
            if (can_user_moderate_artist_forum($user_id, $forum_id)) {
                $caps = ['exist']; // Grant permission
            }
            break;
    }
    
    return $caps;
}
add_filter('map_meta_cap', 'filter_forum_permissions', 10, 4);
```

## Forum Display Integration

### Artist Profile Forum Section

Template integration in artist profile displays:

```php
// In single-artist_profile.php template
$forum_id = ec_get_forum_for_artist($artist_id);

if ($forum_id && function_exists('bbp_get_forum_permalink')) {
    $forum_url = bbp_get_forum_permalink($forum_id);
    $forum_title = get_the_title($forum_id);
    ?>
    <div class="artist-forum-section">
        <h3>Community Discussion</h3>
        <p>Join the conversation about <?php echo esc_html(get_the_title($artist_id)); ?></p>
        <a href="<?php echo esc_url($forum_url); ?>" class="forum-link-button">
            Visit Forum: <?php echo esc_html($forum_title); ?>
        </a>
        
        <?php if (can_user_moderate_artist_forum(get_current_user_id(), $forum_id)): ?>
            <p class="moderator-notice">You have moderator access to this forum</p>
        <?php endif; ?>
    </div>
    <?php
}
```

### Forum Management Interface

Location: `inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-forum.php`

```php
<div class="forum-management-section">
    <h3>Forum Management</h3>
    
    <?php if ($forum_id): ?>
        <div class="existing-forum">
            <p><strong>Forum:</strong> <?php echo esc_html(get_the_title($forum_id)); ?></p>
            <p><strong>URL:</strong> <a href="<?php echo esc_url(bbp_get_forum_permalink($forum_id)); ?>" target="_blank">
                <?php echo esc_url(bbp_get_forum_permalink($forum_id)); ?>
            </a></p>
            
            <div class="forum-stats">
                <span>Topics: <?php echo bbp_get_forum_topic_count($forum_id); ?></span>
                <span>Posts: <?php echo bbp_get_forum_reply_count($forum_id); ?></span>
            </div>
            
            <div class="forum-actions">
                <a href="<?php echo esc_url(bbp_get_forum_permalink($forum_id)); ?>" class="button">
                    View Forum
                </a>
                <button type="button" class="button button-secondary" id="edit-forum-settings">
                    Edit Settings
                </button>
            </div>
        </div>
        
        <div id="forum-settings" style="display: none;">
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="update_artist_forum">
                <input type="hidden" name="artist_id" value="<?php echo esc_attr($artist_id); ?>">
                <input type="hidden" name="forum_id" value="<?php echo esc_attr($forum_id); ?>">
                <?php wp_nonce_field('update_artist_forum', 'forum_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th>Forum Title</th>
                        <td><input type="text" name="forum_title" value="<?php echo esc_attr(get_the_title($forum_id)); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Forum Description</th>
                        <td><textarea name="forum_description" rows="4" class="large-text"><?php echo esc_textarea(get_post($forum_id)->post_content); ?></textarea></td>
                    </tr>
                    <tr>
                        <th>Forum Status</th>
                        <td>
                            <select name="forum_status">
                                <option value="open" <?php selected(bbp_get_forum_status($forum_id), 'open'); ?>>Open</option>
                                <option value="closed" <?php selected(bbp_get_forum_status($forum_id), 'closed'); ?>>Closed</option>
                                <option value="private" <?php selected(bbp_get_forum_status($forum_id), 'private'); ?>>Private</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Update Forum'); ?>
            </form>
        </div>
    <?php else: ?>
        <div class="no-forum">
            <p>No forum has been created for this artist yet.</p>
            <button type="button" class="button button-primary" id="create-forum">
                Create Forum
            </button>
        </div>
        
        <div id="create-forum-form" style="display: none;">
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="create_artist_forum">
                <input type="hidden" name="artist_id" value="<?php echo esc_attr($artist_id); ?>">
                <?php wp_nonce_field('create_artist_forum', 'forum_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th>Forum Title</th>
                        <td><input type="text" name="forum_title" value="<?php echo esc_attr(get_the_title($artist_id) . ' Discussion'); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Forum Description</th>
                        <td><textarea name="forum_description" rows="4" class="large-text">Discussion forum for <?php echo esc_attr(get_the_title($artist_id)); ?></textarea></td>
                    </tr>
                </table>
                
                <?php submit_button('Create Forum'); ?>
            </form>
        </div>
    <?php endif; ?>
</div>
```

## Activity Integration

### Artist Activity Tracking

Forums contribute to artist activity for grid sorting:

```php
/**
 * Calculate forum activity for artist
 */
function calculate_artist_forum_activity($artist_id) {
    $forum_id = ec_get_forum_for_artist($artist_id);
    
    if (!$forum_id) {
        return 0;
    }
    
    // Get recent activity (last 30 days)
    $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
    
    global $wpdb;
    
    // Count recent topics and replies
    $activity_count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) 
        FROM {$wpdb->posts} 
        WHERE post_parent = %d 
        AND post_type IN ('topic', 'reply')
        AND post_status = 'publish'
        AND post_date > %s
    ", $forum_id, $thirty_days_ago));
    
    return (int) $activity_count;
}

// Integrate with artist grid sorting
function integrate_forum_activity_in_grid($activity_scores) {
    foreach ($activity_scores as $artist_id => &$score) {
        $forum_activity = calculate_artist_forum_activity($artist_id);
        $score['forum_activity'] = $forum_activity;
        $score['total'] += $forum_activity * 2; // Weight forum activity higher
    }
    
    return $activity_scores;
}
add_filter('extrch_artist_activity_scores', 'integrate_forum_activity_in_grid');
```

## Forum Notifications

### Topic/Reply Notifications

```php
/**
 * Notify artist members of new forum activity
 */
function notify_artist_of_forum_activity($topic_id, $forum_id, $user_id) {
    $artist_id = get_artist_for_forum($forum_id);
    
    if (!$artist_id) {
        return;
    }
    
    // Get artist members
    $artist_members = get_artist_members($artist_id);
    
    // Don't notify the person who posted
    $artist_members = array_diff($artist_members, [$user_id]);
    
    if (empty($artist_members)) {
        return;
    }
    
    $topic_title = get_the_title($topic_id);
    $forum_name = get_the_title($forum_id);
    $author_name = get_userdata($user_id)->display_name;
    
    foreach ($artist_members as $member_id) {
        $member = get_userdata($member_id);
        
        if ($member && $member->user_email) {
            $subject = sprintf('New activity in %s forum', $forum_name);
            $message = sprintf(
                'New topic "%s" was posted by %s in the %s forum.\n\nView topic: %s',
                $topic_title,
                $author_name,
                $forum_name,
                bbp_get_topic_permalink($topic_id)
            );
            
            wp_mail($member->user_email, $subject, $message);
        }
    }
}

// Hook into bbPress topic creation
add_action('bbp_new_topic', 'notify_artist_of_forum_activity', 10, 4);
```

## Forum Widgets and Shortcodes

### Recent Topics Widget

```php
/**
 * Display recent topics for artist forum
 */
function artist_recent_forum_topics($artist_id, $limit = 5) {
    $forum_id = ec_get_forum_for_artist($artist_id);
    
    if (!$forum_id) {
        return '';
    }
    
    $recent_topics = get_posts([
        'post_type' => bbp_get_topic_post_type(),
        'post_parent' => $forum_id,
        'posts_per_page' => $limit,
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'DESC'
    ]);
    
    if (empty($recent_topics)) {
        return '<p>No recent topics</p>';
    }
    
    $output = '<ul class="recent-forum-topics">';
    
    foreach ($recent_topics as $topic) {
        $topic_url = bbp_get_topic_permalink($topic->ID);
        $reply_count = bbp_get_topic_reply_count($topic->ID);
        $last_active = bbp_get_topic_last_active_time($topic->ID);
        
        $output .= sprintf(
            '<li><a href="%s">%s</a> <span class="topic-meta">(%d replies, %s)</span></li>',
            esc_url($topic_url),
            esc_html($topic->post_title),
            $reply_count,
            human_time_diff(strtotime($last_active))
        );
    }
    
    $output .= '</ul>';
    
    return $output;
}

// Shortcode for recent topics
function artist_forum_topics_shortcode($atts) {
    $atts = shortcode_atts([
        'artist_id' => 0,
        'limit' => 5
    ], $atts);
    
    if (!$atts['artist_id']) {
        return 'Artist ID required';
    }
    
    return artist_recent_forum_topics($atts['artist_id'], $atts['limit']);
}
add_shortcode('artist_forum_topics', 'artist_forum_topics_shortcode');
```

## Forum Search Integration

### Artist-Specific Forum Search

```php
/**
 * Filter bbPress search to include artist forum context
 */
function filter_forum_search_by_artist($search_terms, $args) {
    if (isset($_GET['artist_forum']) && $_GET['artist_forum']) {
        $artist_id = (int) $_GET['artist_forum'];
        $forum_id = ec_get_forum_for_artist($artist_id);
        
        if ($forum_id) {
            // Limit search to specific forum
            $args['post_parent'] = $forum_id;
        }
    }
    
    return $search_terms;
}
add_filter('bbp_before_has_search_results_parse_args', 'filter_forum_search_by_artist', 10, 2);
```

## Admin Integration

### Forum Management in Admin

```php
/**
 * Add forum management to artist profile admin
 */
function add_forum_meta_box() {
    add_meta_box(
        'artist-forum-management',
        'Forum Management',
        'render_forum_management_meta_box',
        'artist_profile',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'add_forum_meta_box');

function render_forum_management_meta_box($post) {
    $forum_id = get_post_meta($post->ID, '_artist_forum_id', true);
    
    if ($forum_id) {
        $forum_url = admin_url('post.php?post=' . $forum_id . '&action=edit');
        echo '<p><strong>Associated Forum:</strong></p>';
        echo '<p><a href="' . esc_url($forum_url) . '">' . esc_html(get_the_title($forum_id)) . '</a></p>';
        echo '<p><a href="' . esc_url(bbp_get_forum_permalink($forum_id)) . '" target="_blank">View Forum</a></p>';
    } else {
        echo '<p>No forum created yet.</p>';
        echo '<button type="button" id="create-forum-admin" class="button">Create Forum</button>';
    }
}
```

## Database Relationships

### Forum-Artist Data Structure

```sql
-- Artist profile meta
UPDATE wp_postmeta SET meta_value = '123' 
WHERE post_id = artist_id AND meta_key = '_artist_forum_id';

-- Forum meta
UPDATE wp_postmeta SET meta_value = '456' 
WHERE post_id = forum_id AND meta_key = '_associated_artist_profile_id';

-- Forum permissions meta
UPDATE wp_postmeta SET meta_value = 'serialized_permissions_array'
WHERE post_id = forum_id AND meta_key = '_artist_forum_permissions';
```

This integration provides a complete forum system tied to artist profiles with proper permissions, notifications, and management interfaces.