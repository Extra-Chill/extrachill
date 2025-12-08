# Link Page System

Comprehensive link page creation and management system with live preview, drag-and-drop interface, and advanced customization options.

**Note**: Link page management now uses the Gutenberg block on the `/manage-link-page` page (no query params). The legacy PHP interface has been removed.

## System Architecture

Link pages provide artists with customizable landing pages featuring:
- Drag-and-drop link management
- Real-time live preview
- Advanced styling options
- Analytics tracking
- Social media integration

## Link Page Structure

### Data Organization

Link pages store data in structured format:

```php
// Link sections structure
$link_sections = [
    [
        'section_title' => 'Music',
        'links' => [
            [
                'link_url' => 'https://spotify.com/artist/example',
                'link_text' => 'Listen on Spotify',
                'link_description' => 'Stream our latest album'
            ]
        ]
    ],
    [
        'section_title' => 'Social',
        'links' => [
            [
                'link_url' => 'https://instagram.com/artist',
                'link_text' => 'Follow on Instagram'
            ]
        ]
    ]
];
```

### CSS Variables System

Styling controlled via CSS variables:

```php
$css_variables = [
    // Background
    '--link-page-background-color' => '#ffffff',
    '--link-page-background-type' => 'color',
    
    // Typography
    '--link-page-title-font-family' => 'Inter',
    '--link-page-body-font-family' => 'Inter',
    '--link-page-text-color' => '#000000',
    
    // Buttons
    '--link-page-button-color' => '#007cba',
    '--link-page-button-text-color' => '#ffffff',
    '--link-page-button-border-radius' => '8px',
    
    // Layout
    '--link-page-max-width' => '400px',
    '--link-page-link-spacing' => '16px'
];
```

## Management Interface

### Template Structure

Location: `inc/link-pages/management/templates/manage-link-page.php`

Tabbed interface with sections:
1. **Info**: Basic information and biography
2. **Links**: Link management with drag-and-drop
3. **Customize**: Styling and appearance options
4. **Analytics**: View tracking and link clicks
5. **Advanced**: Expiration, redirects, tracking codes

### Tab Components

Each tab loads specific functionality:

```php
// Tab templates location
inc/link-pages/management/templates/manage-link-page-tabs/
├── tab-info.php          // Basic info editing
├── tab-links.php         // Link management
├── tab-customize.php     // Styling options  
├── tab-analytics.php     // Analytics dashboard
└── tab-advanced.php      // Advanced features
```

## Live Preview System

### Real-Time Updates

Location: `inc/link-pages/management/live-preview/`

Features:
- Instant preview of changes
- No page refresh required
- CSS variable injection
- DOM element updates

### Preview Handler

Class: `ExtraChill_Live_Preview_Handler`

```php
// Handle preview updates
public function handle_preview_update() {
    $link_page_id = $_POST['link_page_id'];
    $artist_id = $_POST['artist_id'];
    
    // Get updated data with overrides
    $preview_data = $this->prepare_preview_data($link_page_id, $artist_id, $_POST);
    
    // Generate preview HTML
    $preview_html = $this->generate_preview_html($preview_data);
    
    wp_send_json_success(['html' => $preview_html]);
}
```

### JavaScript Preview Modules

Event-driven architecture with specialized modules:

```javascript
// Management modules dispatch events
document.dispatchEvent(new CustomEvent('infoChanged', {
    detail: { title: newTitle, bio: newBio }
}));

// Preview modules listen and update
document.addEventListener('infoChanged', function(e) {
    updatePreviewInfo(e.detail);
});
```

Preview modules include:
- `info-preview.js` - Title and bio updates
- `links-preview.js` - Link structure changes
- `colors-preview.js` - Color scheme updates
- `background-preview.js` - Background changes
- `fonts-preview.js` - Typography updates

## Link Management

### Drag-and-Drop Interface

Location: `inc/link-pages/management/assets/js/sortable.js`

Features:
- SortableJS integration
- Touch-friendly mobile support
- Real-time preview updates
- Persistent ordering

```javascript
// Initialize sortable interface
const sortable = new Sortable(linkContainer, {
    animation: 150,
    ghostClass: 'sortable-ghost',
    chosenClass: 'sortable-chosen',
    dragClass: 'sortable-drag',
    onEnd: function(evt) {
        // Update order and preview
        updateLinkOrder();
        triggerPreviewUpdate();
    }
});
```

### Management Interface

Legacy PHP management (templates, assets, AJAX) has been removed. Link page management is now handled solely by the Gutenberg block on `/manage-link-page` using REST.

## Styling System

### Color Management

Location: `inc/link-pages/management/assets/js/colors.js`

Color picker interface for:
- Background colors
- Text colors
- Button colors
- Accent colors

### Font Management

Location: `inc/link-pages/management/assets/js/fonts.js`

Integration with Google Fonts:
- Title font selection
- Body font selection
- Font weight options
- Font size controls

### Background Options

Location: `inc/link-pages/management/assets/js/background.js`

Background types:
- Solid colors
- Gradient backgrounds
- Image uploads
- Video backgrounds

## Public Link Pages

### Template Rendering

Location: `inc/link-pages/live/templates/single-artist_link_page.php`

Features:
- Responsive design
- Social sharing
- Analytics tracking
- Session validation

### URL Structure

Link pages use top-level URL routing:
- Format: `/{slug}`
- Example: `https://extrachill.com/artist-name`

### Template Data

Public templates receive processed data:

```php
// Get comprehensive data
$data = ec_get_link_page_data($artist_id, $link_page_id);

// Extract for template
extract($data);

// Available variables:
// $display_title, $bio, $profile_img_url
// $link_sections, $social_links, $css_vars
// $settings (advanced configurations)
```

## Advanced Features

### Link Expiration

Location: `inc/link-pages/management/advanced-tab/link-expiration.php`

Features:
- Time-based link scheduling
- Automatic deactivation
- Expiration notifications
- Bulk expiration management

### Redirect System

Location: `inc/link-pages/management/advanced-tab/temporary-redirect.php`

Temporary redirect functionality:
- 302 redirects to external URLs
- Bypass normal link page display
- Analytics tracking maintained

### YouTube Integration

Location: `inc/link-pages/management/advanced-tab/youtube-embed-control.php`

Inline YouTube video embedding:
- Automatic video detection
- Responsive embed players
- Thumbnail previews
- Play tracking

## Asset Management

### Context-Aware Loading

Class: `ExtraChillArtistPlatform_Assets`

```php
// Conditional asset loading
public function enqueue_link_page_assets($template_context) {
    if ($template_context === 'management') {
        wp_enqueue_script('link-page-management');
        wp_enqueue_style('link-page-management');
    }
    
    if ($template_context === 'public') {
        wp_enqueue_script('link-page-public');
        wp_enqueue_style('link-page-public');
    }
}
```

### File Organization

JavaScript modules organized by functionality:

```
inc/link-pages/management/assets/js/
├── info.js                 // Basic info management
├── links.js               // Link CRUD operations
├── colors.js              // Color picker interface
├── fonts.js               // Font selection
├── background.js          // Background management
├── sizing.js              // Layout controls
├── socials.js             // Social link management
├── advanced.js            // Advanced features
├── analytics.js           // Analytics dashboard
└── ui-utils.js            // Utility functions
```

## Data Persistence

### Save System

Location: `inc/core/actions/save.php`

Centralized save operations:

```php
function ec_handle_link_page_save($link_page_id, $form_data) {
    // Process links data
    if (isset($form_data['link_page_links_json'])) {
        $links = json_decode($form_data['link_page_links_json'], true);
        update_post_meta($link_page_id, '_link_page_links', $links);
    }
    
    // Process CSS variables
    if (isset($form_data['css_vars'])) {
        update_post_meta($link_page_id, '_link_page_custom_css_vars', $form_data['css_vars']);
    }
    
    // Process advanced settings
    update_post_meta($link_page_id, '_link_expiration_enabled', $form_data['link_expiration_enabled']);
}
```

### Data Synchronization

Location: `inc/core/actions/sync.php`

Automatic synchronization:
- Cross-system data consistency
- Cache invalidation
- Template data updates