# Centralized Data System

The platform uses a unified data system to provide consistent access to artist and link page data across all components.

## Primary Data Function

### ec_get_link_page_data()

Single source of truth for all link page settings, CSS variables, links, and social data. Replaces scattered `get_post_meta()` calls throughout the codebase.

```php
/**
 * Get comprehensive link page data
 * 
 * @param int $artist_id Artist profile post ID
 * @param int $link_page_id Link page post ID (optional)
 * @param array $overrides Live preview override data
 * @return array Comprehensive data structure
 */
$data = ec_get_link_page_data($artist_id, $link_page_id, $overrides);
```

### Data Structure

Returns structured data array:

```php
[
    // Basic IDs
    'artist_id' => 123,
    'link_page_id' => 456,
    
    // CSS Variables (merged with defaults)
    'css_vars' => [
        '--link-page-background-color' => '#ffffff',
        '--link-page-text-color' => '#000000',
        // ... complete CSS variable set
    ],
    
    // Links data structure
    'links' => [
        [
            'section_title' => 'Music',
            'links' => [
                ['link_url' => '...', 'link_text' => '...']
            ]
        ]
    ],
    
    // Social links from artist profile
    'socials' => [
        ['type' => 'spotify', 'url' => '...', 'label' => '...']
    ],
    
    // Advanced settings
    'settings' => [
        'link_expiration_enabled' => false,
        'redirect_enabled' => false,
        'youtube_embed_enabled' => true,
        // ... complete settings structure
    ]
]
```

## Artist Data Functions

### ec_get_artist_profile_data()

Single source of truth for all artist profile data. Provides comprehensive artist information with CSS variables and additional metadata.

```php
/**
 * Get comprehensive artist profile data
 * 
 * @param int $artist_id Artist profile post ID
 * @param array $overrides Live preview override data (optional)
 * @return array Comprehensive artist profile data
 */
$data = ec_get_artist_profile_data($artist_id, $overrides);
```

**Data Structure**:

```php
[
    // Basic IDs and metadata
    'artist_id' => 123,
    'artist_name' => 'Artist Name',
    'biography' => 'Artist biography content',
    'profile_image_url' => 'https://...',
    
    // CSS Variables for artist styling
    'css_vars' => [
        '--artist-card-background-color' => '#ffffff',
        '--artist-card-text-color' => '#000000',
        // ... complete CSS variable set
    ],
    
    // Social links
    'social_links' => [
        ['type' => 'spotify', 'url' => '...', 'label' => '...']
    ],
    
    // Associated resources
    'link_page_id' => 456,
    
    // Roster/member information
    'roster_members' => [
        ['user_id' => 1, 'user_login' => 'username', 'role' => 'admin']
    ]
]
```

### User Artist Relationships

```php
// Get artist profile IDs for user
$artist_ids = ec_get_user_artist_ids($user_id);

// Get full artist profile objects
$profiles = ec_get_user_artist_profiles($user_id);

// Check artist membership
$is_member = ec_is_user_artist_member($user_id, $artist_id);
```

## Data Relationships

### Artist-Link Page Connection

```php
// Get link page for artist
$link_page_id = ec_get_link_page_for_artist($artist_id);
```

## CSS Variables System

CSS variables are processed through the font management system:

```php
// Raw font values for form population
$raw_fonts = $data['raw_font_values'];

// Processed font stacks for CSS output
$processed_vars = $font_manager->process_font_css_vars($css_vars);
```

## Override System

The data system supports live preview overrides:

```php
// Override data from form changes
$overrides = [
    'artist_profile_title' => 'New Title',
    'link_page_bio_text' => 'Updated bio',
    'css_vars' => ['--link-page-background-color' => '#ff0000']
];

// Get data with overrides applied
$data = ec_get_link_page_data($artist_id, $link_page_id, $overrides);
```

## Defaults Integration

Data functions merge with centralized defaults:

```php
// Get defaults for specific component
$defaults = ec_get_link_page_defaults_for('styles');

// Automatic merging in main data function
$css_vars = array_merge($defaults, $stored_data);
```

## WordPress Integration

All data functions support WordPress filters for extensibility:

```php
// Apply filters to final data
return apply_filters('extrch_get_link_page_data', $display_data, $artist_id, $link_page_id, $overrides);
```