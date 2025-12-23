# Artist Profile Management

Comprehensive system for creating, managing, and displaying artist profiles with roster management.

## Artist Profile Creation

### Artist Creator Block

**Location**: `src/blocks/artist-creator/`

Modern React-based Gutenberg block providing guided artist profile creation interface.

**Block Features**:
- Guided artist profile creation with user permission checks
- Profile metadata initialization (name, biography, images)
- User prefill from authenticated context
- Automatic link page creation for new profiles
- REST API integration for save operations
- Post-auth routing context awareness

**Integration Points**:
- Accessible at `/create-artist/` management page
- Automatically triggered by join flow for new/unpermissioned users
- Uses centralized save system via `ec_handle_artist_profile_save()`

**Block Registration**: Registered on `artist_profile` post type via `register_block_type( __DIR__ . '/build/blocks/artist-creator' )`

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

### Gutenberg Block Editor

**Location**: `src/blocks/artist-manager/`

Modern React-based Gutenberg block providing complete artist profile management interface with tab-based organization.

**Block Features**:
- Artist information and biography editing
- Profile image upload and management
- Social link management (integrated with social platform system)
- Roster/member management with team member invitations
- Subscriber list management and export functionality
- Context-aware data synchronization via REST API

**Tab Structure**:
1. **TabInfo**: Artist name, biography, profile image, and metadata
2. **TabSocials**: Social platform link management with icon validation
3. **TabMembers/Roster**: Band member invitation and role management
4. **TabSubscribers**: Email subscriber list management and export

**Architecture**:
- **Block Registration**: Registered on `artist_profile` post type via `register_block_type( __DIR__ . '/build/blocks/artist-manager' )`
- **REST API Integration**: All management operations via REST API with centralized permission validation
- **React Components**: Tab-based interface with reusable shared components
- **Build Process**: Webpack compilation via `npm run build`
- **Asset Enqueuing**: Auto-detected via block.json manifest



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
    'exclude_user_artists' => true
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

- Link page activity (last updated dates)
- Profile update timestamps
- Social engagement metrics

Activity data used for dynamic sorting in artist grid displays.