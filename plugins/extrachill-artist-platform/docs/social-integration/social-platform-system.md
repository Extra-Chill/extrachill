# Social Platform Integration

Comprehensive social media link management system supporting 15+ platforms with validation, rendering, and CRUD operations.

## Platform Support

### ExtraChillArtistPlatform_SocialLinks Class

Location: `inc/core/filters/social-icons.php`

Singleton class managing all social platform functionality:

```php
class ExtraChillArtistPlatform_SocialLinks {
    const META_KEY = '_artist_profile_social_links';
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
```

### Supported Platforms

The system supports 15+ social platforms including:
- Apple Music
- Bandcamp
- Bluesky
- Facebook
- GitHub
- Instagram
- Patreon
- Pinterest
- SoundCloud
- Spotify
- TikTok
- Twitch
- Twitter/X
- YouTube
- Custom links with user-defined labels

## URL Validation System

### Smart URL Processing

The system provides intelligent URL validation with automatic protocol addition and comprehensive validation:

```php
/**
 * Validate and process social link URL
 */
public function validate_social_url($url, $type) {
    if (empty($url)) {
        return ['valid' => false, 'message' => 'URL is required'];
    }
    
    // Add protocol if missing
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'https://' . $url;
    }
    
    // Validate URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['valid' => false, 'message' => 'Invalid URL format'];
    }
    
    // Platform-specific validation
    $supported_types = $this->get_supported_types();
    if (isset($supported_types[$type]['base_url'])) {
        $base_url = $supported_types[$type]['base_url'];
        if (strpos($url, $base_url) === false) {
            return [
                'valid' => false, 
                'message' => "URL must contain {$base_url}"
            ];
        }
    }
    
    return ['valid' => true, 'url' => esc_url_raw($url)];
}
```

### Platform-Specific Validation

```php
/**
 * Validate platform-specific URL patterns
 */
private function validate_platform_url($url, $type) {
    $patterns = [
        'spotify' => '/open\.spotify\.com\/(artist|album|track|playlist)\/[a-zA-Z0-9]+/',
        'youtube' => '/(youtube\.com\/channel\/|youtube\.com\/c\/|youtube\.com\/user\/|youtu\.be\/)/',
        'instagram' => '/instagram\.com\/[a-zA-Z0-9._]+/',
        'twitter' => '/(twitter\.com\/|x\.com\/)[a-zA-Z0-9_]+/',
        'facebook' => '/facebook\.com\/[a-zA-Z0-9.]+/',
        'tiktok' => '/tiktok\.com\/@[a-zA-Z0-9._]+/',
        'soundcloud' => '/soundcloud\.com\/[a-zA-Z0-9-_]+/',
        'bandcamp' => '/[a-zA-Z0-9-]+\.bandcamp\.com/'
    ];
    
    if (isset($patterns[$type])) {
        return preg_match($patterns[$type], $url);
    }
    
    return true; // No specific pattern required
}
```

## Custom CSS System

### Extended Platform Support

Location: `assets/css/custom-social-icons.css`

The system extends Font Awesome with custom social platform icons using CSS mask techniques:

```css
/* Substack Icon - uses CSS mask to inherit parent text color */
.fab.fa-substack:before {
    content: "";
    display: inline-block;
    width: 0.85em;
    height: 1em;
    vertical-align: middle;
    background-color: currentColor;
    mask: url("data:image/svg+xml;charset=utf8,%3Csvg...%3E") no-repeat center;
    mask-size: contain;
    -webkit-mask: url("data:image/svg+xml;charset=utf8,%3Csvg...%3E") no-repeat center;
    -webkit-mask-size: contain;
}

/* Venmo Icon - uses CSS mask to inherit parent text color */
.fab.fa-venmo:before {
    content: "";
    display: inline-block;
    width: 1em;
    height: 1em;
    vertical-align: middle;
    background-color: currentColor;
    mask: url("data:image/svg+xml;charset=utf8,%3Csvg...%3E") no-repeat center;
    mask-size: contain;
    -webkit-mask: url("data:image/svg+xml;charset=utf8,%3Csvg...%3E") no-repeat center;
    -webkit-mask-size: contain;
}
```

### Custom Icon Features

- **Dynamic Color Inheritance**: Icons automatically adapt to theme colors via `currentColor`
- **Seamless Font Awesome Integration**: Works with existing icon classes and styling
- **SVG Mask Technique**: High-quality scalable icons using embedded SVG data
- **Size Consistency**: Maintains proper alignment and sizing with native Font Awesome icons
- **Cross-Browser Support**: WebKit and standard mask properties for maximum compatibility

### Extending Custom Icons

To add new custom social platform icons:

1. Create SVG icon with black fill color
2. Convert to data URL format
3. Add CSS class following the pattern above
4. Update platform configuration in `inc/core/filters/social-icons.php`

```php
// Add to supported types array
'substack' => [
    'label' => 'Substack',
    'icon' => 'fab fa-substack', // References custom CSS class
    'base_url' => 'substack.com'
],
'venmo' => [
    'label' => 'Venmo',
    'icon' => 'fab fa-venmo', // References custom CSS class
    'base_url' => 'venmo.com'
]
```

## Icon Management

### Font Awesome Integration

```php
/**
 * Get Font Awesome icon class for platform
 */
public function get_platform_icon($type) {
    $supported_types = $this->get_supported_types();
    
    if (!isset($supported_types[$type])) {
        return 'fas fa-link'; // Default icon
    }
    
    $icon_class = $supported_types[$type]['icon'];
    
    // Validate icon class exists
    if ($this->validate_icon_class($icon_class)) {
        return $icon_class;
    }
    
    return 'fas fa-link'; // Fallback
}

/**
 * Validate Font Awesome icon class
 */
private function validate_icon_class($icon_class) {
    $valid_prefixes = ['fas', 'fab', 'far', 'fal', 'fad', 'fa-brands'];
    $parts = explode(' ', $icon_class);
    
    if (count($parts) < 2) {
        return false;
    }
    
    return in_array($parts[0], $valid_prefixes);
}
```

## Data Management

### Social Links Storage

Social links stored as serialized array in artist profile meta:

```php
// Data structure
$social_links = [
    [
        'type' => 'spotify',
        'url' => 'https://open.spotify.com/artist/example',
        'label' => '', // Empty for standard platforms
        'order' => 1
    ],
    [
        'type' => 'custom',
        'url' => 'https://example.com',
        'label' => 'Official Website',
        'order' => 2
    ]
];

// Storage
update_post_meta($artist_id, '_artist_profile_social_links', $social_links);
```

### CRUD Operations

```php
/**
 * Add social link to artist profile
 */
public function add_social_link($artist_id, $type, $url, $label = '') {
    // Validate permission
    if (!ec_can_manage_artist(get_current_user_id(), $artist_id)) {
        return ['success' => false, 'message' => 'Insufficient permissions'];
    }
    
    // Validate URL
    $validation = $this->validate_social_url($url, $type);
    if (!$validation['valid']) {
        return ['success' => false, 'message' => $validation['message']];
    }
    
    // Get existing links
    $social_links = $this->get_artist_social_links($artist_id);
    
    // Check for duplicates
    foreach ($social_links as $link) {
        if ($link['type'] === $type && $link['url'] === $validation['url']) {
            return ['success' => false, 'message' => 'Link already exists'];
        }
    }
    
    // Add new link
    $new_link = [
        'type' => $type,
        'url' => $validation['url'],
        'label' => sanitize_text_field($label),
        'order' => count($social_links) + 1,
        'id' => wp_generate_uuid4()
    ];
    
    $social_links[] = $new_link;
    
    // Save updated links
    $result = update_post_meta($artist_id, self::META_KEY, $social_links);
    
    if ($result) {
        do_action('extrch_social_link_added', $artist_id, $new_link);
        return ['success' => true, 'link' => $new_link];
    }
    
    return ['success' => false, 'message' => 'Failed to save social link'];
}

/**
 * Remove social link from artist profile
 */
public function remove_social_link($artist_id, $link_id) {
    if (!ec_can_manage_artist(get_current_user_id(), $artist_id)) {
        return ['success' => false, 'message' => 'Insufficient permissions'];
    }
    
    $social_links = $this->get_artist_social_links($artist_id);
    
    // Find and remove link
    foreach ($social_links as $key => $link) {
        if ($link['id'] === $link_id) {
            $removed_link = $link;
            unset($social_links[$key]);
            break;
        }
    }
    
    if (!isset($removed_link)) {
        return ['success' => false, 'message' => 'Link not found'];
    }
    
    // Reorder remaining links
    $social_links = array_values($social_links);
    foreach ($social_links as $index => &$link) {
        $link['order'] = $index + 1;
    }
    
    // Save updated links
    update_post_meta($artist_id, self::META_KEY, $social_links);
    
    do_action('extrch_social_link_removed', $artist_id, $removed_link);
    
    return ['success' => true];
}
```

## Block-Based Social Management

The Gutenberg block provides social platform management through the **TabSocials** component:

**Location**: `src/blocks/link-page-editor/components/tabs/TabSocials.js`

**Features**:
- Add/remove social platform links
- URL validation for each platform
- Custom label support (for custom links)
- Real-time preview updates
- REST API integration via `src/blocks/shared/api/client.js`

## Rendering System

Social links are rendered on the public link page template at `inc/link-pages/live/templates/extrch-link-page-template.php`.

**Social Icon Integration**:
- Uses `ExtraChillArtistPlatform_SocialLinks` class for platform data
- Font Awesome icons with fallback support
- Custom social icons via `assets/css/custom-social-icons.css` (SVG mask technique)
- Automatic protocol handling and URL validation

## Custom Link Support

### Custom Platform Configuration

Custom links support user-defined labels:

```php
// Custom link data structure
$custom_link = [
    'type' => 'custom',
    'url' => 'https://artist-website.com',
    'label' => 'Official Website',
    'order' => 1,
    'id' => wp_generate_uuid4()
];
```

### Label Management

```php
/**
 * Check if platform supports custom labels
 */
public function platform_supports_custom_label($type) {
    $supported_types = $this->get_supported_types();
    return isset($supported_types[$type]['has_custom_label']) && $supported_types[$type]['has_custom_label'];
}

/**
 * Get display label for social link
 */
public function get_social_display_label($social_data) {
    if (!empty($social_data['label']) && $this->platform_supports_custom_label($social_data['type'])) {
        return $social_data['label'];
    }
    
    return $this->get_platform_label($social_data['type']);
}
```

## WordPress Integration

### Action Hooks

```php
// Social link added
do_action('extrch_social_link_added', $artist_id, $link_data);

// Social link removed
do_action('extrch_social_link_removed', $artist_id, $removed_link);

// Social links updated
do_action('extrch_social_links_updated', $artist_id, $social_links);
```

### Filter Hooks

```php
// Filter supported platforms
$platforms = apply_filters('extrch_supported_social_platforms', $default_platforms);

// Filter social link validation
$validation = apply_filters('extrch_social_link_validation', $validation_result, $url, $type);

// Filter rendered social icon HTML
$icon_html = apply_filters('extrch_social_icon_html', $html, $social_data, $social_manager);
```