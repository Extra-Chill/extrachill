# ExtraChill Artist Platform

A comprehensive WordPress plugin that provides artist profile management, link pages, and community features for musicians on the ExtraChill platform.

## Features

### 🎵 Artist Profiles
- Custom post type for artist/band profiles
- Forum integration with bbPress
- Roster management with invitation system
- Artist directory and following functionality
- Profile manager assignment and permissions

### 🔗 Link Pages
- Custom link page creation and management
- Live preview interface with drag-and-drop functionality
- Custom fonts, colors, and styling options
- YouTube video embed support with toggle control
- QR code generation and sharing modal
- Featured link highlighting system
- Social platform integration
- Click analytics and reporting

### 📊 Analytics Dashboard
- Track link clicks and user engagement
- Visual charts and reporting
- Export capabilities for data analysis

### 👥 Subscription Management
- Fan email collection system
- Artist-specific subscriber lists
- Integration with email marketing workflows

### 🔐 Cross-Domain Authentication
- Seamless login across ExtraChill subdomains
- Secure session token management
- 6-month token expiration with auto-cleanup

## Requirements

- **WordPress**: 5.0 or higher (tested up to 6.4)
- **PHP**: 7.4 or higher  
- **Theme**: Extra Chill Community theme (compatibility enforced)
- **Optional**: bbPress (for forum features)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Ensure the Extra Chill Community theme is active
4. Configure plugin settings as needed

## Usage

### Creating Artist Profiles

1. Navigate to **Artist Profiles** in the WordPress admin
2. Click **Add New** to create a new artist profile
3. Fill in artist information and upload images
4. Configure forum settings and roster members
5. Set up profile managers and permissions
6. Publish the profile

### Managing Band Rosters

1. Access the artist profile management interface
2. Navigate to the **Profile Managers** tab
3. Send email invitations to band members
4. Track pending invitations and confirmations
5. Assign roles and permissions to roster members

### Managing Link Pages

1. Visit the artist profile management page
2. Navigate to the **Link Page** tab
3. Add links, customize appearance, and configure settings
4. Use the live preview to see changes in real-time
5. Save your changes

### Viewing Analytics

1. Access the artist profile management interface
2. Click on the **Analytics** tab
3. View click data, popular links, and engagement metrics
4. Use date filters to analyze specific time periods

## Development

### Core Architecture

```php
// Main plugin initialization with theme compatibility check
ExtraChillArtistPlatform::instance();

// Template handling and routing
ExtraChillArtistPlatform_Templates::instance();

// Asset management
ExtraChillArtistPlatform_Assets::instance();

// Social link management
ExtraChillArtistPlatform_SocialLinks::instance();

// Migration system (band -> artist terminology)  
ExtraChillArtistPlatform_Migration::instance();

// Features loaded via core class initialization
```

### Adding Custom Features

The plugin uses WordPress hooks and filters extensively:

```php
// Modify link page data
add_filter('extrachill_link_page_data', function($data, $link_page_id) {
    // Your custom modifications
    return $data;
}, 10, 2);

// Hook into analytics tracking
add_action('extrachill_link_clicked', function($link_url, $link_page_id) {
    // Custom tracking logic
}, 10, 2);
```

### Asset Management

Assets are managed via `ExtraChillArtistPlatform_Assets` class in `inc/core/artist-platform-assets.php`:

```php
class ExtraChillArtistPlatform_Assets {
    // Context-aware asset loading with organized structure
    public function enqueue_frontend_assets() {
        if ( $this->is_link_page_context() ) {
            $this->enqueue_link_page_assets();
            // Loads from inc/link-pages/live/assets/
        }
        
        if ( $this->is_manage_artist_profile_page() ) {
            $this->enqueue_artist_profile_management_assets();
            // Loads from inc/artist-profiles/assets/
        }
        
        if ( $this->is_manage_link_page_page() ) {
            $this->enqueue_link_page_management_assets();
            // Loads from inc/link-pages/management/assets/
        }
        
        // File existence checks and cache busting via filemtime()
        // Global assets loaded from assets/ directory
    }
}

// Asset management handled by core class
```

### JavaScript Development

```javascript
// Access management interface data (loaded in inc/link-pages/management/assets/js/)
if (window.ExtrchLinkPageManager) {
    ExtrchLinkPageManager.getInitialData(); // Access PHP config data
}

// Custom events for modular components
$(document).on('extrch:preview:updated', function(e, data) {
    // Handle preview updates
});

$(document).on('extrch:subscribe:success', function(e, data) {
    // Handle successful subscription
});
```

### Database Structure

The plugin creates several custom tables:

- `wp_extrch_link_page_daily_views` - Daily page view aggregates by link page  
- `wp_extrch_link_page_daily_link_clicks` - Daily click aggregates by individual links
- `wp_artist_subscribers` - Artist subscription data with export status tracking

### Roster Data Storage

Artist roster data is stored using WordPress post meta:
- `_pending_invitations` - Array of pending roster invitations with tokens
- `_roster_members` - Confirmed band/artist member data with roles

### Link Page Data Storage

Link page configuration stored as post meta:
- `_link_page_data` - JSON configuration for links, styling, and settings
- `_featured_link_id` - ID of the currently featured link
- `_youtube_embed_url` - YouTube video URL for embedded content

## Customization

### Styling

Override plugin styles in your theme:

```css
/* Customize link page appearance */
.extrch-link-page {
    /* Your custom styles */
}

/* Modify management interface */
.extrch-manage-tabs {
    /* Your admin styles */
}
```

### Available Hooks

```php
// Modify link page data before rendering
add_filter('extrachill_link_page_data', function($data, $link_page_id) {
    return $data;
}, 10, 2);

// Hook into link click tracking
add_action('extrachill_link_clicked', function($link_url, $link_page_id) {
    // Custom tracking logic
}, 10, 2);

// Customize social link types
add_filter('bp_supported_social_link_types', function($types) {
    $types['custom_platform'] = [
        'label' => 'Custom Platform',
        'icon' => 'fa-custom',
        'color' => '#ff0000'
    ];
    return $types;
});
```

## Troubleshooting

### Theme Compatibility Issues
Ensure the Extra Chill Community theme is active. The plugin will display an admin notice if an incompatible theme is detected.

### Link Page Not Loading
Check that rewrite rules are flushed by deactivating and reactivating the plugin.

### Analytics Not Tracking
Verify that JavaScript is not blocked and check browser console for errors.

### Session Issues
Clear cookies for the `.extrachill.com` domain and try logging in again.

### Roster Invitations Not Sending
Check that WordPress can send emails and verify SMTP configuration. Review invitation tokens in database if needed.

### File Structure
```
inc/
├── core/                             # Core plugin functionality
│   ├── artist-platform-assets.php       # Asset management class  
│   ├── class-templates.php              # Template handling
│   ├── artist-platform-post-types.php   # CPT registration
│   ├── artist-platform-migration.php    # Migration system
│   ├── artist-platform-rewrite-rules.php # URL routing
│   ├── filters/
│   │   ├── social-icons.php             # Social link management
│   │   └── fonts.php                    # Font configuration
│   ├── data-sync.php                    # Data synchronization
│   └── default-artist-page-link-profiles.php # Default configurations
├── artist-profiles/                  # Profile management
│   ├── admin/                       # Admin meta boxes, user linking
│   ├── frontend/                    # Public forms, directory
│   │   └── templates/              # Artist profile templates
│   │       ├── archive-artist_profile.php
│   │       ├── single-artist_profile.php
│   │       ├── artist-directory.php
│   │       ├── artist-platform-home.php
│   │       ├── manage-artist-profiles.php
│   │       ├── artist-profile-card.php
│   │       └── manage-artist-profile-tabs/
│   ├── roster/                      # Band member management
│   │   ├── artist-invitation-emails.php
│   │   ├── manage-roster-ui.php
│   │   ├── roster-ajax-handlers.php
│   │   └── roster-data-functions.php
│   ├── artist-forums.php            # Forum integration
│   ├── [MOVED TO inc/core/filters/permissions.php] # Centralized permission system
│   ├── artist-following.php         # Follow system
│   └── subscribe-data-functions.php # Artist subscription data
├── link-pages/                      # Link page system
│   ├── management/                  # Management interface
│   │   ├── advanced-tab/           # Advanced features (tracking, redirects)
│   │   ├── live-preview/           # Live preview functionality
│   │   └── templates/              # Management templates
│   │       ├── manage-link-page.php
│   │       └── manage-link-page-tabs/
│   ├── live/                       # Live page functionality
│   │   └── templates/              # Public link page templates
│   │       ├── single-artist_link_page.php
│   │       └── extrch-link-page-template.php
│   ├── subscription/               # Subscription forms
│   │   ├── subscribe-inline-form.php
│   │   └── subscribe-modal.php
│   ├── data/                       # Data providers
│   ├── create-link-page.php        # Link page creation
│   ├── subscribe-functions.php     # Subscription functionality
│   └── link-page-*.php             # Core link page functionality
└── database/                        # Database functionality
    ├── link-page-analytics-db.php   # Analytics database
    └── subscriber-db.php            # Subscriber database

assets/
├── css/                             # Stylesheets
│   ├── components/                  # Component-specific styles
│   ├── artist-platform.css         # Global styles
│   ├── manage-link-page.css         # Management interface
│   └── extrch-links.css            # Public link page styles
└── js/
    ├── manage-link-page/            # Modular management interface
    │   ├── manage-link-page-core.js # Core management functionality
    │   ├── manage-link-page-*.js    # Feature-specific modules
    │   └── ...
    ├── shared-tabs.js               # Shared tabbed interface
    └── [feature-specific].js       # Public functionality
```

## Support

For issues and feature requests, contact the development team or submit issues through the project repository.

## License

GPL v2 or later - see LICENSE file for details.