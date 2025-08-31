# Extra Chill Artist Platform

A comprehensive WordPress plugin that provides artist profile management, link pages, and community features for musicians on the Extra Chill platform.

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

### 🔐 Permission System
- Centralized access control via `inc/core/filters/permissions.php`
- Role-based artist profile management
- Server-side permission validation

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

### AJAX System

The plugin uses a comprehensive modular AJAX system:

```php
// Live (Public) AJAX Actions: inc/link-pages/live/ajax/
add_action( 'wp_ajax_extrch_record_link_event', 'extrch_record_link_event_ajax' );
add_action( 'wp_ajax_nopriv_extrch_record_link_event', 'extrch_record_link_event_ajax' );
add_action( 'wp_ajax_link_page_click_tracking', 'handle_link_click_tracking' );
add_action( 'wp_ajax_nopriv_link_page_click_tracking', 'handle_link_click_tracking' );

// Management (Admin) AJAX Actions: inc/link-pages/management/ajax/
add_action( 'wp_ajax_render_link_item_editor', 'ec_ajax_render_link_item_editor' );
add_action( 'wp_ajax_render_social_item_editor', 'ec_ajax_render_social_item_editor' );
add_action( 'wp_ajax_extrch_fetch_link_page_analytics', 'extrch_fetch_link_page_analytics_ajax' );
add_action( 'wp_ajax_extrch_fetch_og_image_for_preview', 'extrch_ajax_fetch_og_image_for_preview' );
add_action( 'wp_ajax_extrch_link_page_subscribe', 'extrch_link_page_subscribe_ajax_handler' );
add_action( 'wp_ajax_nopriv_extrch_link_page_subscribe', 'extrch_link_page_subscribe_ajax_handler' );
```

### Permission System

Server-side permission validation with centralized access control:

```php
// Permission helpers in inc/core/filters/permissions.php
if ( ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
    // User has permission to manage this artist
}

if ( ec_ajax_can_manage_link_page() ) {
    // AJAX context: User can manage link pages
}

// Template-level permission checks (no REST API needed)
$can_edit = ec_can_manage_artist( get_current_user_id(), get_the_ID() );
```

### Adding Custom AJAX Actions

The plugin uses WordPress native AJAX patterns with centralized permission checking:

```php
// Register AJAX actions using WordPress native patterns
add_action( 'wp_ajax_custom_action', 'my_custom_handler' );
add_action( 'wp_ajax_nopriv_custom_action', 'my_custom_handler' ); // For public access

function my_custom_handler() {
    // Security checks
    check_ajax_referer( 'custom_nonce_action', 'nonce' );
    
    // Permission validation using centralized system
    if ( ! ec_ajax_can_manage_artist() ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    
    // Your custom AJAX logic
    wp_send_json_success( array( 'message' => 'Success!' ) );
}
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
// All modules are self-contained with event-driven communication
// Example: Listening for background changes
document.addEventListener('backgroundChanged', function(e) {
    console.log('Background updated:', e.detail.backgroundData);
});

// Example: Dispatching custom events for inter-module communication  
document.dispatchEvent(new CustomEvent('featuredLinkChanged', {
    detail: { featuredLink: linkData }
}));

// Example: Subscribe to social icons changes
document.addEventListener('socialIconsChanged', function(e) {
    console.log('Social icons updated:', e.detail.socials);
});
```

### Database Structure

The plugin creates several custom tables with complete schema:

#### Analytics Tables
```sql
-- Daily page view aggregates
CREATE TABLE {prefix}_extrch_link_page_daily_views (
    view_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    link_page_id bigint(20) unsigned NOT NULL,
    stat_date date NOT NULL,
    view_count bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (view_id),
    UNIQUE KEY unique_daily_view (link_page_id, stat_date)
);

-- Daily link click aggregates
CREATE TABLE {prefix}_extrch_link_page_daily_link_clicks (
    click_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    link_page_id bigint(20) unsigned NOT NULL,
    stat_date date NOT NULL,
    link_url varchar(2083) NOT NULL,
    click_count bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (click_id),
    UNIQUE KEY unique_daily_link_click (link_page_id, stat_date, link_url(191))
);

-- Artist subscriber data
CREATE TABLE {prefix}_artist_subscribers (
    subscriber_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_profile_id BIGINT(20) UNSIGNED NOT NULL,
    subscriber_email VARCHAR(255) NOT NULL,
    username VARCHAR(60) NULL DEFAULT NULL,
    subscribed_at DATETIME NOT NULL,
    exported TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (subscriber_id),
    UNIQUE KEY email_artist (subscriber_email, artist_profile_id)
);
```

### Roster Data Storage

Artist roster data is stored using WordPress post meta:
- `_pending_invitations` - Array of pending roster invitations with tokens
- `_roster_members` - Confirmed band/artist member data with roles

### Link Page Data Storage

Link page configuration stored as post meta:
- `_link_page_data` - JSON configuration for links, styling, and settings
- `_featured_link_id` - ID of the currently featured link
- `_youtube_embed_url` - YouTube video URL for embedded content

### 🎯 Advanced Features

- **Drag-and-Drop Interface**: SortableJS-powered link reordering with live preview
- **Link Expiration**: Time-based link scheduling and automatic deactivation
- **Artist Context Switching**: Multi-artist management with seamless switching
- **Component Templates**: Modular UI components for extensible interfaces
- **Cross-Domain Authentication**: Secure session management across subdomains

## Customization

### Styling

Override plugin styles in your theme:

```css
/* Customize public link page appearance */
.extrch-link-page {
    background: var(--custom-bg-color);
    font-family: var(--custom-font-family);
}

.extrch-link-page .link-item {
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.9);
}

/* Modify management interface */
.shared-tabs-component .shared-tab-button {
    background: #f0f0f0;
    border: 1px solid #ddd;
}

/* Customize analytics charts */
.analytics-chart-container {
    background: #fff;
    border-radius: 6px;
}
```

### Custom JavaScript Integration

```javascript
// Listen for plugin events in your theme
document.addEventListener('sharedTabActivated', function(e) {
    console.log('Tab activated:', e.detail.tabId);
    // Custom logic when tabs change
});

// Extend link page functionality
document.addEventListener('DOMContentLoaded', function() {
    // Add custom tracking
    document.querySelectorAll('.link-item').forEach(link => {
        link.addEventListener('click', function(e) {
            // Custom analytics or behavior
            console.log('Link clicked:', e.target.href);
        });
    });
});
```

### Available Hooks

```php
// Modify link page data
add_filter('ec_get_link_page_data', function($data, $link_page_id) {
    return $data;
}, 10, 2);

// Hook into save operations
add_action('ec_link_page_save', function($link_page_id, $data) {
    // Custom save logic
}, 10, 2);

// Track link clicks  
add_action('extrachill_link_clicked', function($link_url, $link_page_id) {
    // Custom tracking logic
}, 10, 2);
```

### Permission System

```php
// Check if user can manage an artist
if ( ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
    // User has permission
}

// Permission callbacks used in AJAX registry
$permission_callbacks = [
    'ec_ajax_can_manage_artist',
    'ec_ajax_can_manage_link_page', 
    'ec_ajax_is_admin',
    'ec_ajax_can_create_artists'
];
```

## Troubleshooting

### Theme Compatibility Issues
Ensure the Extra Chill Community theme is active. The plugin will display an admin notice if an incompatible theme is detected.

### Link Page Not Loading
Check that rewrite rules are flushed by deactivating and reactivating the plugin.

### Analytics Not Tracking
Verify that JavaScript is not blocked and check browser console for errors.

### Permission Issues
Ensure user has proper role assignments and check permission functions in `inc/core/filters/permissions.php`.

### Roster Invitations Not Sending
Check that WordPress can send emails and verify SMTP configuration. Review invitation tokens in database if needed.

### File Structure
```
inc/
├── core/                             # Core plugin functionality
│   ├── artist-platform-assets.php       # Asset management class  
│   ├── class-templates.php              # Page template handling
│   ├── artist-platform-post-types.php   # CPT registration
│   ├── artist-platform-migration.php    # Migration system
│   ├── artist-platform-rewrite-rules.php # URL routing
│   ├── actions/
│   │   ├── save.php                     # Centralized save operations
│   │   └── sync.php                     # Data synchronization
│   ├── filters/
│   │   ├── social-icons.php             # Social link management
│   │   ├── fonts.php                    # Font configuration
│   │   ├── ids.php                      # ID generation and management
│   │   ├── templates.php                # Component template filtering
│   │   ├── permissions.php              # Centralized permission system
│   │   ├── data.php                     # Data filtering and validation
│   │   ├── defaults.php                 # Default configurations
│   │   └── avatar-menu.php              # Avatar menu customization
│   ├── templates/                       # Core template components
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
│   ├── artist-following.php         # Follow system
│   └── subscribe-data-functions.php # Artist subscription data
├── link-pages/                      # Link page system
│   ├── management/                  # Management interface
│   │   ├── ajax/                   # Modular AJAX handlers
│   │   │   ├── links.php              # Link section management
│   │   │   ├── social.php             # Social icon management
│   │   │   ├── analytics.php          # Admin analytics dashboard
│   │   │   ├── background.php         # Background image uploads
│   │   │   ├── qrcode.php             # QR code generation
│   │   │   ├── featured-link.php      # Open Graph image fetching
│   │   │   └── subscribe.php          # Subscription templates
│   │   ├── advanced-tab/           # Advanced features (tracking, redirects)
│   │   ├── live-preview/           # Live preview functionality
│   │   └── templates/              # Management templates
│   │       ├── manage-link-page.php
│   │       ├── components/         # Modular UI components
│   │       └── manage-link-page-tabs/
│   ├── live/                       # Live page functionality
│   │   ├── ajax/                   # Public AJAX handlers
│   │   │   ├── analytics.php          # Public tracking and data pruning
│   │   │   └── edit-icon.php          # Deprecated REST API endpoints
│   │   ├── assets/js/               # Public JavaScript modules
│   │   └── templates/              # Public link page templates
│   │       ├── single-artist_link_page.php
│   │       └── extrch-link-page-template.php
│   ├── templates/                  # Subscription forms
│   │   ├── subscribe-inline-form.php
│   │   └── subscribe-modal.php
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
│   ├── extrch-links.css            # Public link page styles
│   └── extrch-share-modal.css      # Share modal styles
└── js/
    ├── shared-tabs.js               # Responsive tabbed interface
    ├── artist-switcher.js           # Artist context switching
    ├── artist-platform.js           # Core plugin functionality
    └── artist-platform-home.js     # Homepage-specific features
```

## Support

For issues and feature requests, contact the development team or submit issues through the project repository.

## License

GPL v2 or later - see LICENSE file for details.