# Custom Post Types

The Extra Chill Artist Platform registers two custom post types that form the foundation of the system.

## Artist Profile (artist_profile)

### Configuration
- **Slug**: `artists`
- **Archive URL**: `/artists/`
- **Single URL**: `/artists/{slug}`
- **Menu Position**: 5
- **Menu Icon**: `dashicons-groups`

### Supported Features
- Title
- Editor (biography content)
- Thumbnail (profile image)
- Custom fields (metadata storage)

### Database Fields
Key meta fields stored for artist profiles:
- `_artist_profile_ids`: Linked user accounts
- `_artist_profile_social_links`: Social media links
- `_artist_forum_id`: Associated bbPress forum ID

### Usage Patterns

```php
// Create new artist profile
$artist_id = wp_insert_post([
    'post_type' => 'artist_profile',
    'post_title' => 'Band Name',
    'post_content' => 'Artist biography',
    'post_status' => 'publish'
]);

// Get artist profiles for current user
$profiles = ec_get_user_artist_profiles();

// Check user membership
$is_member = ec_is_user_artist_member($user_id, $artist_id);
```

## Link Page (artist_link_page)

### Configuration
- **Slug**: `link-page` (internally used)
- **Public URLs**: Top-level rewrite to `/{slug}`
- **Menu Position**: 6
- **Menu Icon**: `dashicons-admin-links`
- **Archive**: Disabled

### Supported Features
- Title
- Custom fields (comprehensive link and styling data)
- Author support

### Database Fields
Core meta fields for link pages:
- `_associated_artist_profile_id`: Connected artist profile
- `_link_page_links`: Link data structure
- `_link_page_custom_css_vars`: Styling variables
- `_link_expiration_enabled`: Time-based link control
- `_link_page_redirect_enabled`: Redirect functionality

### Data Structure

Link pages store complex data structures:

```php
// Links data structure
$links_data = [
    [
        'section_title' => 'Music',
        'links' => [
            [
                'link_url' => 'https://spotify.com/artist/example',
                'link_text' => 'Listen on Spotify'
            ]
        ]
    ]
];

// CSS variables for styling
$css_vars = [
    '--link-page-background-color' => '#ffffff',
    '--link-page-text-color' => '#000000',
    '--link-page-button-color' => '#007cba'
];
```

## URL Routing

The system implements custom rewrite rules:

- Artist profiles: Standard WordPress post type routing
- Link pages: Top-level slug routing via custom rewrite rules in `artist-platform-rewrite-rules.php`

## Registration Process

Both post types are registered via `extrachill_init_post_types()` hooked to `init` with priority 5, ensuring early availability for dependent systems.