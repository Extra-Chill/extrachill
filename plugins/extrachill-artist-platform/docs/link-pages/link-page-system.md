# Link Page System

Comprehensive link page creation and management system with live preview, drag-and-drop interface, and advanced customization options.

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

The management interface is a **Gutenberg block editor component** (React-based) located at `src/blocks/link-page-editor/`.

### Tab Structure

The editor provides a tabbed interface with five sections:

1. **TabInfo** (`components/tabs/TabInfo.js`): Basic information and biography editing
2. **TabLinks** (`components/tabs/TabLinks.js`): Link management with drag-and-drop reordering
3. **TabCustomize** (`components/tabs/TabCustomize.js`): Styling and appearance options (fonts, colors)
4. **TabAnalytics** (`components/tabs/TabAnalytics.js`): View tracking and link click analytics
5. **TabAdvanced** (`components/tabs/TabAdvanced.js`): Advanced features (expiration, redirects, tracking codes)

### Block Architecture

The Gutenberg block provides:
- **React Components**: Modern UI built with React
- **Live Preview**: Real-time preview panel showing changes
- **REST API Integration**: All data operations via REST API (not AJAX)
- **File Upload**: Media upload and attachment management
- **State Management**: Context API for component state sharing

See [Gutenberg Block Editor documentation](./gutenberg-block-editor.md) for implementation details.

## Live Preview System

**Note**: The Gutenberg block editor provides integrated live preview via the Preview component. There is no separate live preview infrastructure.

## Link Management

### Gutenberg Block Interface

Primary location: `src/blocks/link-page-editor/components/tabs/TabLinks.js`

Features:
- React-based link management
- Drag-and-drop reordering via dnd-kit
- Add/edit/delete link functionality
- Real-time preview updates
- REST API integration

Location: `src/blocks/link-page-editor/components/shared/ColorPicker.js`

Color picker interface for:
- Background colors
- Text colors
- Button colors
- Accent colors

### Font Management

Location: `src/blocks/link-page-editor/components/tabs/TabCustomize.js`

Integration with Google Fonts:
- Title font selection
- Body font selection
- Font weight options
- Font size controls

### Background Options

Location: `src/blocks/link-page-editor/components/tabs/TabCustomize.js`

Background types:
- Solid colors
- Gradient backgrounds
- Image uploads

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

All assets loaded based on page context:
- **Join flow assets**: Only on login page with `from_join` parameter
- **Public link page assets**: Only on public link pages
- **Gutenberg block assets**: Auto-enqueued via block registration

## Data Persistence

### Save System

Location: `src/blocks/link-page-editor/api/client.js` (REST API-based)

All save operations handled through Gutenberg block:

```javascript
// Save via REST API
const response = await apiClient.post( `/extrachill/v1/link-pages/${linkPageId}`, {
    links: formData.links,
    css_vars: formData.css_vars,
    settings: formData.settings
});
```

### Data Synchronization

Location: `inc/core/actions/sync.php`

Automatic synchronization:
- Cross-system data consistency
- Cache invalidation
- Template data updates