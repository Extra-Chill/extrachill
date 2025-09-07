# Artist Profile Management

Comprehensive system for creating, managing, and displaying artist profiles with roster management and forum integration.

## Artist Profile Creation

### Profile Data Structure

Artist profiles store essential information:

```php
// Core WordPress post data
$profile_data = [
    'post_type' => 'artist_profile',
    'post_title' => 'Artist/Band Name',
    'post_content' => 'Biography content',
    'post_status' => 'publish'
];

// Associated metadata
$meta_data = [
    '_artist_profile_social_links' => $social_links_array,
    '_artist_forum_id' => $forum_id,
    '_artist_profile_ids' => $linked_user_ids
];
```

### Profile Creation Process

```php
// Create new artist profile
$artist_id = wp_insert_post([
    'post_type' => 'artist_profile',
    'post_title' => $artist_name,
    'post_content' => $biography,
    'post_status' => 'publish'
]);

// Link creator to profile
$user_artist_ids = get_user_meta($user_id, '_artist_profile_ids', true);
if (!is_array($user_artist_ids)) {
    $user_artist_ids = [];
}
$user_artist_ids[] = $artist_id;
update_user_meta($user_id, '_artist_profile_ids', $user_artist_ids);
```

## Management Interface

### Frontend Management Template

Location: `inc/artist-profiles/frontend/templates/manage-artist-profiles.php`

Features:
- Tabbed interface for different management areas
- Profile information editing
- Roster/member management
- Subscriber management
- Forum integration settings

### Tab Structure

1. **Info Tab**: Basic profile information and biography
2. **Profile Managers Tab**: Roster and member management
3. **Subscribers Tab**: Email subscriber management and export
4. **Forum Tab**: bbPress forum integration settings

## Profile Information Management

### Editable Fields

```php
// Profile data structure for forms
$profile_data = [
    'artist_name' => get_the_title($artist_id),
    'biography' => get_post($artist_id)->post_content,
    'profile_image' => get_the_post_thumbnail_url($artist_id, 'large'),
    'social_links' => get_post_meta($artist_id, '_artist_profile_social_links', true)
];
```

### Form Processing

Profile updates handled via centralized save system:

```php
// Save handler: inc/core/actions/save.php
function ec_handle_artist_profile_save($artist_id, $form_data) {
    // Update post data
    wp_update_post([
        'ID' => $artist_id,
        'post_title' => $form_data['artist_name'],
        'post_content' => $form_data['biography']
    ]);
    
    // Update metadata
    if (isset($form_data['social_links'])) {
        update_post_meta($artist_id, '_artist_profile_social_links', $form_data['social_links']);
    }
}
```

## Profile Display

### Archive Template

Location: `inc/artist-profiles/frontend/templates/archive-artist_profile.php`

Features:
- Grid layout of all artist profiles
- Activity-based sorting
- Search and filtering capabilities
- Responsive design

### Single Profile Template

Location: `inc/artist-profiles/frontend/templates/single-artist_profile.php`

Displays:
- Profile header with image and basic info
- Biography content
- Social links
- Associated forum (if exists)
- Follow/subscription options

### Artist Grid System

Location: `inc/artist-profiles/frontend/artist-grid.php`

```php
/**
 * Render artist grid with activity-based sorting
 * 
 * @param array $args Grid configuration
 * @return string HTML output
 */
$grid_html = render_activity_based_artist_grid([
    'posts_per_page' => 12,
    'exclude_user_artists' => true,
    'show_follow_buttons' => true
]);
```

## User-Artist Relationships

### Artist Membership

Users can be linked to multiple artist profiles:

```php
// Get user's artist profiles
$user_artists = ec_get_user_artist_profiles($user_id);

// Check membership
$is_member = ec_is_user_artist_member($user_id, $artist_id);

// Get all owned artist IDs
$artist_ids = ec_get_user_artist_ids($user_id);
```

### Artist Following System

Location: `inc/artist-profiles/artist-following.php`

```php
// Follow artist
function follow_artist($user_id, $artist_id) {
    $followed = ec_get_user_followed_artists($user_id);
    if (!in_array($artist_id, $followed)) {
        $followed[] = $artist_id;
        update_user_meta($user_id, '_followed_artist_profile_ids', $followed);
    }
}

// Check follow status
$is_following = ec_is_user_following_artist($user_id, $artist_id);
```

## Profile Permissions

### Management Access

Permission system ensures secure access:

```php
// Check management permission
if (ec_can_manage_artist($user_id, $artist_id)) {
    // Show management interface
} else {
    // Show access denied
}
```

### Profile Creation Limits

Users can create multiple artist profiles with configurable limits:

```php
// Check creation permission
$can_create = ec_can_create_artist_profiles($user_id);

// Get current profile count
$current_count = count(ec_get_user_artist_ids($user_id));
```

## Integration Points

### Forum Integration

Artist profiles can be linked to bbPress forums:

```php
// Get associated forum
$forum_id = ec_get_forum_for_artist($artist_id);

// Create artist-specific forum section
create_artist_forum_section($artist_id, $forum_data);
```

### Link Page Association

Each artist profile can have an associated link page:

```php
// Get link page for artist
$link_page_id = ec_get_link_page_for_artist($artist_id);

// Create link page association
update_post_meta($link_page_id, '_associated_artist_profile_id', $artist_id);
```

## Activity Tracking

Artist activity tracked for grid sorting:

- Forum post activity (bbPress integration)
- Link page activity (last updated dates)
- Profile update timestamps
- Social engagement metrics

Activity data used for dynamic sorting in artist grid displays.