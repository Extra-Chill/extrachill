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

```php
public function get_supported_types() {
    return [
        'apple_music' => [
            'label' => 'Apple Music',
            'icon' => 'fab fa-apple',
            'base_url' => 'music.apple.com'
        ],
        'bandcamp' => [
            'label' => 'Bandcamp',
            'icon' => 'fab fa-bandcamp',
            'base_url' => 'bandcamp.com'
        ],
        'bluesky' => [
            'label' => 'Bluesky',
            'icon' => 'fa-brands fa-bluesky',
            'base_url' => 'bsky.app'
        ],
        'custom' => [
            'label' => 'Custom Link',
            'icon' => 'fas fa-link',
            'has_custom_label' => true
        ],
        'facebook' => [
            'label' => 'Facebook',
            'icon' => 'fab fa-facebook-f',
            'base_url' => 'facebook.com'
        ],
        'github' => [
            'label' => 'GitHub',
            'icon' => 'fab fa-github',
            'base_url' => 'github.com'
        ],
        'instagram' => [
            'label' => 'Instagram',
            'icon' => 'fab fa-instagram',
            'base_url' => 'instagram.com'
        ],
        'patreon' => [
            'label' => 'Patreon',
            'icon' => 'fab fa-patreon',
            'base_url' => 'patreon.com'
        ],
        'pinterest' => [
            'label' => 'Pinterest',
            'icon' => 'fab fa-pinterest',
            'base_url' => 'pinterest.com'
        ],
        'soundcloud' => [
            'label' => 'SoundCloud',
            'icon' => 'fab fa-soundcloud',
            'base_url' => 'soundcloud.com'
        ],
        'spotify' => [
            'label' => 'Spotify',
            'icon' => 'fab fa-spotify',
            'base_url' => 'open.spotify.com'
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'icon' => 'fab fa-tiktok',
            'base_url' => 'tiktok.com'
        ],
        'twitch' => [
            'label' => 'Twitch',
            'icon' => 'fab fa-twitch',
            'base_url' => 'twitch.tv'
        ],
        'twitter' => [
            'label' => 'Twitter/X',
            'icon' => 'fab fa-x-twitter',
            'base_url' => 'x.com'
        ],
        'youtube' => [
            'label' => 'YouTube',
            'icon' => 'fab fa-youtube',
            'base_url' => 'youtube.com'
        ]
    ];
}
```

## URL Validation System

### Smart URL Processing

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

## Management Interface

Legacy manage-link-page social AJAX and components have been removed. Social management flows through the block and REST.


### Social Item Editor Component

Location: `inc/link-pages/management/templates/components/social-item-editor.php`

```php
<div class="social-item-editor" data-social-id="<?php echo esc_attr($social_data['id']); ?>">
    <div class="social-item-header">
        <i class="<?php echo esc_attr($social_manager->get_platform_icon($social_data['type'])); ?>"></i>
        <span class="platform-name"><?php echo esc_html($social_manager->get_platform_label($social_data['type'])); ?></span>
        <button type="button" class="remove-social-item" data-social-id="<?php echo esc_attr($social_data['id']); ?>">
            <i class="fas fa-trash"></i>
        </button>
    </div>
    
    <div class="social-item-fields">
        <label for="social-url-<?php echo esc_attr($social_data['id']); ?>">URL:</label>
        <input type="url" 
               id="social-url-<?php echo esc_attr($social_data['id']); ?>"
               name="social_links[<?php echo esc_attr($social_data['id']); ?>][url]"
               value="<?php echo esc_attr($social_data['url']); ?>"
               placeholder="Enter URL"
               required>
        
        <?php if ($social_manager->platform_supports_custom_label($social_data['type'])): ?>
            <label for="social-label-<?php echo esc_attr($social_data['id']); ?>">Label:</label>
            <input type="text"
                   id="social-label-<?php echo esc_attr($social_data['id']); ?>"
                   name="social_links[<?php echo esc_attr($social_data['id']); ?>][label]"
                   value="<?php echo esc_attr($social_data['label']); ?>"
                   placeholder="Enter label">
        <?php endif; ?>
    </div>
</div>
```

### JavaScript Social Management

Location: `inc/link-pages/management/assets/js/socials.js`

```javascript
const SocialsManager = {
    init: function() {
        this.bindEvents();
    },
    
    bindEvents: function() {
        $('#add-social-link').on('click', this.showAddSocialForm.bind(this));
        $(document).on('click', '.remove-social-item', this.removeSocialItem.bind(this));
        $(document).on('change', '.social-item-fields input', this.updateSocialPreview.bind(this));
    },
    
    showAddSocialForm: function() {
        const availablePlatforms = this.getAvailablePlatforms();
        let optionsHtml = '';
        
        availablePlatforms.forEach(platform => {
            optionsHtml += `<option value="${platform.type}">${platform.label}</option>`;
        });
        
        const formHtml = `
            <div id="add-social-form" class="add-social-form">
                <select id="social-platform-select">
                    <option value="">Select Platform</option>
                    ${optionsHtml}
                </select>
                <input type="url" id="social-url-input" placeholder="Enter URL">
                <input type="text" id="social-label-input" placeholder="Label (for custom links)" style="display:none;">
                <button type="button" id="confirm-add-social">Add</button>
                <button type="button" id="cancel-add-social">Cancel</button>
            </div>
        `;
        
        $('#social-links-container').append(formHtml);
        $('#social-platform-select').focus();
        
        this.bindAddFormEvents();
    },
    
    addSocialLink: function(type, url, label) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'add_social_link',
                artist_id: artistId,
                social_type: type,
                social_url: url,
                social_label: label,
                nonce: socialNonce
            },
            success: function(response) {
                if (response.success) {
                    // Update social links display
                    this.refreshSocialLinks();
                    
                    // Update preview
                    document.dispatchEvent(new CustomEvent('socialsChanged', {
                        detail: { socialLinks: this.getCurrentSocialLinks() }
                    }));
                } else {
                    alert('Error: ' + response.data.message);
                }
            }.bind(this)
        });
    }
};
```

## Rendering System

### Template Functions

```php
/**
 * Render single social icon
 */
function ec_render_social_icon($social_data, $social_manager = null) {
    if (!$social_manager) {
        $social_manager = ExtraChillArtistPlatform_SocialLinks::instance();
    }
    
    $template_args = [
        'social_data' => $social_data,
        'social_manager' => $social_manager
    ];
    
    return ec_render_template('social-icon', $template_args);
}

/**
 * Render social icons container
 */
function ec_render_social_icons_container($social_links, $position = 'above', $social_manager = null) {
    if (!$social_manager) {
        $social_manager = ExtraChillArtistPlatform_SocialLinks::instance();
    }
    
    $template_args = [
        'social_links' => $social_links,
        'position' => $position,
        'social_manager' => $social_manager
    ];
    
    return ec_render_template('social-icons-container', $template_args);
}
```

### Social Icon Template

Location: `inc/link-pages/templates/social-icon.php`

```php
<?php if (!empty($social_data['url'])): ?>
    <a href="<?php echo esc_url($social_data['url']); ?>" 
       class="social-icon <?php echo esc_attr($social_data['type']); ?>"
       target="_blank"
       rel="noopener noreferrer"
       title="<?php echo esc_attr($social_data['label'] ?: $social_manager->get_platform_label($social_data['type'])); ?>">
        <i class="<?php echo esc_attr($social_manager->get_platform_icon($social_data['type'])); ?>"></i>
        <?php if (!empty($social_data['label']) && $social_data['type'] === 'custom'): ?>
            <span class="social-label"><?php echo esc_html($social_data['label']); ?></span>
        <?php endif; ?>
    </a>
<?php endif; ?>
```

### Social Icons Container Template

Location: `inc/link-pages/templates/social-icons-container.php`

```php
<?php if (!empty($social_links)): ?>
    <div class="social-icons-container <?php echo esc_attr($position); ?>">
        <?php foreach ($social_links as $social_link): ?>
            <?php echo ec_render_social_icon($social_link, $social_manager); ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
```

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