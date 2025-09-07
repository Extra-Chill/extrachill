# Live Preview System

Real-time preview functionality allowing users to see changes instantly without page refreshes during link page customization.

## System Architecture

The live preview system uses an event-driven architecture with separate management and preview modules communicating through custom DOM events.

### Core Components

1. **Management Modules**: Handle form interactions and dispatch change events
2. **Preview Modules**: Listen for events and update preview DOM elements
3. **Preview Handler**: Server-side AJAX processing for complex updates
4. **Preview Template**: Dedicated template for rendering preview content

## Event-Driven Communication

### Management Module Pattern

Management modules dispatch standardized events:

```javascript
// Example from info.js
(function() {
    'use strict';
    
    const InfoManager = {
        init: function() {
            this.bindEvents();
        },
        
        bindEvents: function() {
            $('#artist-title').on('input', this.handleTitleChange.bind(this));
            $('#bio-textarea').on('input', this.handleBioChange.bind(this));
        },
        
        handleTitleChange: function(e) {
            const newTitle = e.target.value;
            
            // Dispatch event for preview update
            document.dispatchEvent(new CustomEvent('infoChanged', {
                detail: { 
                    title: newTitle,
                    bio: this.getCurrentBio()
                }
            }));
        }
    };
    
    document.addEventListener('DOMContentLoaded', InfoManager.init.bind(InfoManager));
})();
```

### Preview Module Pattern

Preview modules listen for events and update DOM:

```javascript
// Example from info-preview.js
(function() {
    'use strict';
    
    const InfoPreview = {
        init: function() {
            this.bindEvents();
        },
        
        bindEvents: function() {
            document.addEventListener('infoChanged', this.handleInfoUpdate.bind(this));
        },
        
        handleInfoUpdate: function(e) {
            const { title, bio } = e.detail;
            
            // Update preview elements
            const titleElement = document.querySelector('.preview-title');
            if (titleElement) {
                titleElement.textContent = title;
            }
            
            const bioElement = document.querySelector('.preview-bio');
            if (bioElement) {
                bioElement.innerHTML = this.formatBio(bio);
            }
        }
    };
    
    document.addEventListener('DOMContentLoaded', InfoPreview.init.bind(InfoPreview));
})();
```

## Preview Handler Class

### ExtraChill_Live_Preview_Handler

Location: `inc/link-pages/management/live-preview/class-live-preview-handler.php`

```php
class ExtraChill_Live_Preview_Handler {
    
    /**
     * Handle live preview update AJAX request
     */
    public function handle_preview_update() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'extrch_link_page_ajax_nonce')) {
            wp_die('Security check failed');
        }
        
        $link_page_id = apply_filters('ec_get_link_page_id', $_POST);
        $artist_id = apply_filters('ec_get_artist_id', $_POST);
        
        // Prepare preview data with overrides
        $preview_data = $this->prepare_preview_data($link_page_id, $artist_id, $_POST);
        
        // Generate preview HTML
        $preview_html = $this->generate_preview_html($preview_data);
        
        wp_send_json_success([
            'html' => $preview_html,
            'css_vars' => $preview_data['css_vars']
        ]);
    }
    
    /**
     * Prepare data for preview with form overrides
     */
    private function prepare_preview_data($link_page_id, $artist_id, $form_data) {
        // Build overrides from form data
        $overrides = [];
        
        if (isset($form_data['artist_profile_title'])) {
            $overrides['artist_profile_title'] = $form_data['artist_profile_title'];
        }
        
        if (isset($form_data['link_page_bio_text'])) {
            $overrides['link_page_bio_text'] = $form_data['link_page_bio_text'];
        }
        
        if (isset($form_data['css_vars'])) {
            $overrides['css_vars'] = json_decode($form_data['css_vars'], true);
        }
        
        // Get data with overrides applied
        return ec_get_link_page_data($artist_id, $link_page_id, $overrides);
    }
}
```

## Preview Template

### Dedicated Preview Rendering

Location: `inc/link-pages/management/live-preview/preview.php`

```php
<?php
// Get preview data with overrides
$preview_data = ec_get_link_page_data($artist_id, $link_page_id, $overrides);
extract($preview_data);

// Generate CSS variables
$css_block = ec_generate_css_variables_style_block($css_vars, 'live-preview-vars');
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo $css_block; ?>
    <link rel="stylesheet" href="<?php echo $preview_styles_url; ?>">
</head>
<body data-bg-type="<?php echo esc_attr($background_type); ?>">
    <div class="preview-container">
        <?php if ($profile_img_url): ?>
            <img src="<?php echo esc_url($profile_img_url); ?>" 
                 alt="Profile" 
                 class="preview-profile-image <?php echo esc_attr($profile_img_shape); ?>">
        <?php endif; ?>
        
        <h1 class="preview-title"><?php echo esc_html($display_title); ?></h1>
        
        <?php if ($bio): ?>
            <div class="preview-bio"><?php echo wp_kses_post($bio); ?></div>
        <?php endif; ?>
        
        <!-- Social icons -->
        <?php if (!empty($social_links) && $social_icons_position === 'above'): ?>
            <div class="preview-social-icons above">
                <?php foreach ($social_links as $social): ?>
                    <a href="<?php echo esc_url($social['url']); ?>" class="social-icon">
                        <i class="<?php echo esc_attr($social['icon']); ?>"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Links -->
        <div class="preview-links">
            <?php foreach ($link_sections as $section): ?>
                <?php if (!empty($section['section_title'])): ?>
                    <h3 class="section-title"><?php echo esc_html($section['section_title']); ?></h3>
                <?php endif; ?>
                
                <?php foreach ($section['links'] as $link): ?>
                    <a href="<?php echo esc_url($link['link_url']); ?>" class="preview-link-button">
                        <?php echo esc_html($link['link_text']); ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
```

## Preview Module Types

### Visual Update Modules

Handle immediate visual changes:

1. **colors-preview.js**: Color scheme updates
2. **fonts-preview.js**: Typography changes  
3. **background-preview.js**: Background modifications
4. **sizing-preview.js**: Layout adjustments
5. **profile-image-preview.js**: Profile image updates

### Content Update Modules

Handle content structure changes:

1. **info-preview.js**: Title and bio updates
2. **links-preview.js**: Link structure modifications
3. **socials-preview.js**: Social link updates
4. **subscribe-preview.js**: Subscription form changes

### Interactive Modules

Handle user interaction features:

1. **sorting-preview.js**: Drag-and-drop link ordering
2. **overlay-preview.js**: Modal and overlay updates
3. **link-expiration-preview.js**: Expiration status display

## CSS Variable Updates

### Real-Time Styling

Preview system injects CSS variables for instant styling updates:

```javascript
// Example from colors-preview.js
function updatePreviewColors(colorData) {
    const previewFrame = document.getElementById('live-preview-iframe');
    const previewDoc = previewFrame.contentDocument;
    
    // Update CSS variables
    const root = previewDoc.documentElement;
    root.style.setProperty('--link-page-background-color', colorData.backgroundColor);
    root.style.setProperty('--link-page-text-color', colorData.textColor);
    root.style.setProperty('--link-page-button-color', colorData.buttonColor);
}
```

### CSS Variable Generation

Server-side CSS variable generation:

```php
function ec_generate_css_variables_style_block($css_vars, $element_id) {
    $output = '<style id="' . esc_attr($element_id) . '">:root {';
    
    foreach ($css_vars as $key => $value) {
        if ($value !== null && $value !== false) {
            $output .= esc_html($key) . ':' . $value . ';';
        }
    }
    
    $output .= '}</style>';
    return $output;
}
```

## Data Override System

### Form Data Processing

Preview system processes form changes as overrides:

```javascript
// Collect form data for preview
function collectPreviewOverrides() {
    return {
        artist_profile_title: $('#artist-title').val(),
        link_page_bio_text: $('#bio-textarea').val(),
        css_vars: JSON.stringify(getCurrentCSSVars()),
        link_page_links_json: JSON.stringify(getCurrentLinks()),
        artist_profile_social_links_json: JSON.stringify(getCurrentSocials())
    };
}
```

### Override Application

Server-side override processing in `ec_get_link_page_data()`:

```php
// Apply overrides to display data
$display_data = [
    'display_title' => (isset($overrides['artist_profile_title']) && $overrides['artist_profile_title'] !== '') 
        ? $overrides['artist_profile_title'] 
        : get_the_title($artist_id),
    
    'bio' => (isset($overrides['link_page_bio_text']) && $overrides['link_page_bio_text'] !== '') 
        ? $overrides['link_page_bio_text'] 
        : get_post($artist_id)->post_content,
        
    'css_vars' => isset($overrides['css_vars']) 
        ? $overrides['css_vars'] 
        : $stored_css_vars
];
```

## Performance Optimization

### Debounced Updates

Prevent excessive AJAX calls:

```javascript
// Debounce preview updates
const debouncedPreviewUpdate = debounce(function() {
    updateLivePreview();
}, 300);

// Use debounced function for input events
$('#title-input').on('input', debouncedPreviewUpdate);
```

### Selective Updates

Update only changed elements rather than full preview reload:

```javascript
// Targeted DOM updates
function updatePreviewTitle(newTitle) {
    const titleElement = previewFrame.contentDocument.querySelector('.preview-title');
    if (titleElement && titleElement.textContent !== newTitle) {
        titleElement.textContent = newTitle;
    }
}
```

## Integration Points

### Save System Integration

Preview data can be persisted via save system:

```php
// Save preview state as actual data
function save_preview_as_final($link_page_id, $preview_overrides) {
    foreach ($preview_overrides as $key => $value) {
        update_post_meta($link_page_id, $key, $value);
    }
}
```

### Template System Integration

Preview system reuses main template components:

```php
// Shared template rendering
$link_html = ec_render_link_section($section_data, $preview_args);
$social_html = ec_render_social_icons_container($social_links, 'above', $social_manager);
```