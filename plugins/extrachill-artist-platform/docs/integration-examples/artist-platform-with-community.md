# Artist Platform & Community Integration

The artist platform integrates seamlessly with the community forums on community.extrachill.com (Blog ID 2), automatically setting up forum spaces for artists and syncing roster membership.

## Integration Pattern

When an artist profile is created, the platform automatically:
1. Creates a dedicated forum for the artist on community.extrachill.com
2. Adds the artist owner as a forum moderator
3. Syncs roster members to forum groups
4. Links community discussions back to artist profile

## Forum Automation

### Automatic Forum Creation

**Hook**: `extrachill_artist_created`

When a new artist is created via the join flow:

```php
add_action('extrachill_artist_created', 'ec_create_artist_forum', 10, 2);

function ec_create_artist_forum($artist_id, $user_id) {
    // Get artist data
    $artist = get_post($artist_id);
    
    // Switch to community blog
    try {
        switch_to_blog(2); // community.extrachill.com
        
        // Create forum post type for artist
        $forum_args = [
            'post_type' => 'forum',
            'post_title' => $artist->post_title . ' Forum',
            'post_content' => 'Discuss ' . $artist->post_title . ' here',
            'post_status' => 'publish',
            'post_author' => $user_id,
        ];
        
        $forum_id = wp_insert_post($forum_args);
        
        // Store forum ID on artist profile
        add_post_meta($artist_id, 'community_forum_id', $forum_id);
        
        // Set user as forum moderator
        if (function_exists('bbp_set_user_role')) {
            bbp_set_user_role($user_id, 'bbp_moderator', $forum_id);
        }
        
    } finally {
        restore_current_blog();
    }
}
```

### Forum URL Structure

Forums are accessible at:

```
https://community.extrachill.com/forums/[artist-name]/
```

Cross-domain cookie configuration (managed by extrachill-users plugin) allows seamless forum access for authenticated users across all sites.

## Roster Synchronization

### Roster Members as Forum Members

**Location**: `inc/artist-profiles/roster/`

When roster members are added to an artist profile, they're automatically added to the artist's community forum group:

```php
add_action('extrachill_roster_member_added', 'ec_sync_roster_to_forum', 10, 2);

function ec_sync_roster_to_forum($artist_id, $user_id) {
    // Get forum ID for artist
    $forum_id = get_post_meta($artist_id, 'community_forum_id', true);
    
    if (!$forum_id) {
        return; // No forum created yet
    }
    
    // Switch to community blog
    try {
        switch_to_blog(2);
        
        // Add user to forum member group
        if (function_exists('bbp_add_user_to_group')) {
            bbp_add_user_to_group($user_id, $forum_id, 'member');
        }
        
        // Grant forum participation capability
        if (function_exists('bbp_set_user_role')) {
            bbp_set_user_role($user_id, 'bbp_participant', $forum_id);
        }
        
    } finally {
        restore_current_blog();
    }
}
```

## Cross-Site Forum Access

### Single Sign-On

Users authenticated on any network site can access forums without re-logging in:

**Authentication Flow**:
1. User logs in on artist.extrachill.com
2. WordPress multisite sets authentication cookies for `.extrachill.com` domain (via extrachill-users plugin)
3. User navigates to community.extrachill.com
4. Community site recognizes user via multisite cookies
5. User access forums as authenticated member

**Cookie Configuration** (set by extrachill-users):
```php
// wp-config.php or mu-plugins
define('COOKIE_DOMAIN', '.extrachill.com');
define('COOKIEPATH', '/');
define('COOKIE_SECURE', true);
define('COOKIE_HTTPONLY', true);
define('COOKIENAME_LOGGED_IN', 'wordpress_logged_in');
```

## Community Data Access

### Query Community Activity

Access community forum posts from artist profile:

```php
// Get latest topics for artist forum
function ec_get_artist_forum_topics($artist_id, $limit = 5) {
    $forum_id = get_post_meta($artist_id, 'community_forum_id', true);
    
    if (!$forum_id) {
        return [];
    }
    
    try {
        switch_to_blog(2); // community.extrachill.com
        
        $topics = get_posts([
            'post_type' => 'topic',
            'posts_per_page' => $limit,
            'meta_query' => [
                [
                    'key' => '_bbp_forum_id',
                    'value' => $forum_id,
                ]
            ]
        ]);
        
        return $topics;
    } finally {
        restore_current_blog();
    }
}
```

### Display Forum Feed on Artist Profile

```php
// In artist profile template
$topics = ec_get_artist_forum_topics($artist_id);

if ($topics) {
    echo '<h3>Community Discussion</h3>';
    echo '<ul>';
    foreach ($topics as $topic) {
        setup_postdata($topic);
        echo '<li>';
        echo '<a href="' . esc_url(get_permalink($topic->ID)) . '">';
        echo esc_html($topic->post_title);
        echo '</a>';
        echo '</li>';
    }
    wp_reset_postdata();
    echo '</ul>';
}
```

## Forum Moderation

### Artist as Forum Moderator

The artist (original owner) is automatically set as forum moderator:

```php
// Only artist owner can moderate forum
function ec_is_forum_moderator($user_id, $artist_id) {
    $artist = get_post($artist_id);
    
    // Check if user is artist owner
    if ($artist->post_author !== $user_id) {
        return false;
    }
    
    // Check if moderator on community site
    $forum_id = get_post_meta($artist_id, 'community_forum_id', true);
    
    if (!$forum_id) {
        return false;
    }
    
    try {
        switch_to_blog(2);
        
        $can_moderate = bbp_is_user_forum_moderator($user_id, $forum_id);
        
    } finally {
        restore_current_blog();
    }
    
    return $can_moderate;
}
```

### Moderation Capabilities

Forum moderators can:
- Edit/delete forum posts
- Mark topics as spam
- Close/reopen topics
- Pin important topics
- Ban abusive users

## Best Practices

### 1. Always Use Blog Switching

Always switch blog context when accessing community data:

```php
try {
    switch_to_blog(2); // community.extrachill.com
    // Perform queries/operations
} finally {
    restore_current_blog();
}
```

### 2. Cache Forum Lookups

Store forum IDs on artist profile to avoid repeated queries:

```php
// Store when forum created
add_post_meta($artist_id, 'community_forum_id', $forum_id);

// Retrieve when needed
$forum_id = get_post_meta($artist_id, 'community_forum_id', true);
```

### 3. Handle Missing Community Site

Gracefully handle if community site doesn't exist:

```php
$community_site = get_blog_details(2);

if (!$community_site) {
    // Community site doesn't exist, skip forum creation
    return;
}
```

### 4. Verify bbPress Installed

Check for bbPress before using bbPress functions:

```php
if (!function_exists('bbp_get_forum')) {
    // bbPress not installed
    return;
}
```

### 5. Handle Roster Changes

Update forum membership when roster changes:

```php
// When roster member removed
add_action('extrachill_roster_member_removed', function($artist_id, $user_id) {
    try {
        switch_to_blog(2);
        bbp_remove_user_from_group($user_id, $forum_id);
    } finally {
        restore_current_blog();
    }
});
```

## Common Integration Scenarios

### Scenario 1: Display Community Activity on Artist Profile

```php
// Show recent forum activity on artist page
add_action('extrachill_artist_profile_sidebar', function($artist_id) {
    $topics = ec_get_artist_forum_topics($artist_id, 3);
    
    if (empty($topics)) {
        return;
    }
    
    echo '<div class="artist-community-activity">';
    echo '<h3>Community Discussion</h3>';
    echo '<ul>';
    
    foreach ($topics as $topic) {
        setup_postdata($topic);
        echo '<li>';
        echo '<a href="' . esc_url(get_permalink($topic->ID)) . '">';
        echo esc_html($topic->post_title);
        echo '</a>';
        echo ' <span class="reply-count">' . bbp_get_topic_reply_count($topic->ID) . ' replies</span>';
        echo '</li>';
    }
    wp_reset_postdata();
    
    echo '</ul>';
    echo '<a href="' . esc_url($forum_url) . '" class="button">Visit Forum</a>';
    echo '</div>';
});
```

### Scenario 2: Notify Forum When Artist Updates

```php
// Post update to artist forum when profile updated
add_action('extrachill_artist_updated', function($artist_id) {
    $artist = get_post($artist_id);
    $forum_id = get_post_meta($artist_id, 'community_forum_id', true);
    
    if (!$forum_id) return;
    
    try {
        switch_to_blog(2);
        
        // Create topic notifying about update
        $topic = bbp_insert_topic([
            'post_title' => 'Profile Updated: ' . $artist->post_title,
            'post_content' => 'The artist profile has been updated.',
            'forum_id' => $forum_id,
        ]);
        
    } finally {
        restore_current_blog();
    }
});
```

### Scenario 3: Auto-Approve Roster Members' Posts

```php
// Auto-approve posts from roster members
add_filter('bbp_new_reply_pre_insert', function($args) {
    $user_id = $args['post_author'];
    $forum_id = $args['forum_id'];
    
    // Check if user is roster member for this forum's artist
    if (ec_is_roster_member($user_id, $forum_id)) {
        $args['post_status'] = 'publish'; // Auto-approve
    }
    
    return $args;
});
```

## Troubleshooting

### Forum Not Created

Check if community site exists:
```php
$community = get_blog_details(2);
if (!$community) {
    error_log('Community site not found');
}
```

### Forum ID Not Found

Retrieve or recreate forum:
```php
$forum_id = get_post_meta($artist_id, 'community_forum_id', true);

if (!$forum_id || get_post($forum_id, ARRAY_A) === null) {
    // Forum deleted, create new one
    ec_create_artist_forum($artist_id, $artist->post_author);
}
```

### Blog Switching Issues

Ensure proper try/finally cleanup:
```php
try {
    switch_to_blog(2);
    // Operations
} catch (Exception $e) {
    error_log('Community operation failed: ' . $e->getMessage());
} finally {
    restore_current_blog(); // ALWAYS restore
}
```

## Related Documentation

- [bbPress Integration](../integration-patterns.md) - Full bbPress integration patterns
- [Multisite Architecture](../../AGENTS.md#multisite-patterns) - Blog switching best practices
- [Community Site](../../NETWORK-ARCHITECTURE.MD#site-2-communityextrach illcom-community-site)
