# Extra Chill Artist Platform

A comprehensive WordPress plugin that provides artist profile management, link pages, and community features for musicians on the Extra Chill platform.

## Features

### 🚀 Join Flow System
- Complete onboarding flow: user registration → artist profile → link page creation
- Modal interface for existing vs new account selection
- Automatic artist profile and link page creation on registration
- Roster membership auto-assignment for profile owners
- Forum creation integration with bbPress
- Transient-based post-registration redirect tracking

### 🎵 Artist Profiles
- Custom post type for artist/band profiles with comprehensive meta data
- Activity-based artist grid display with smart sorting
- Forum integration with bbPress for artist-specific discussions
- Roster management with email invitation system and role assignment
- Artist directory with user exclusion logic for personalized views
- Profile manager assignment and centralized permissions
- Following system with database-backed relationships

### 🔗 Link Pages
- Custom link page creation with comprehensive management interface
- Real-time live preview with event-driven JavaScript architecture and dedicated live preview handler
- Drag-and-drop link reordering with SortableJS integration and live preview updates
- Advanced styling system with custom fonts, colors, backgrounds, and profile image management
- YouTube video embed support with toggle control and preview
- QR code generation with download functionality
- Native Web Share API integration with social media fallbacks
- Featured link highlighting and link expiration scheduling
- Social platform integration with 15+ platforms including Apple Music, Bandcamp, Bluesky, Pinterest, and more
- Comprehensive social link management with smart icon validation and URL sanitization
- Comprehensive click analytics with Chart.js dashboard and automatic data pruning

### 📊 Analytics Dashboard
- Daily aggregation of page views and link clicks
- Chart.js-powered visual analytics with date filtering
- Real-time click tracking with automatic data pruning
- Export capabilities for comprehensive data analysis
- Public tracking via AJAX with privacy-conscious data collection

### 👥 Subscription Management
- Modal and inline subscription forms with AJAX processing
- Artist-specific subscriber lists with export tracking
- Database-backed subscriber management with deduplication
- Link page and artist profile subscription integration
- Email marketing workflow integration with export status tracking

### 🔐 Permission System
- Centralized access control via `inc/core/filters/permissions.php`
- Role-based artist profile management with granular permissions
- Server-side permission validation with context-aware checks
- AJAX permission validation with comprehensive nonce verification
- WordPress multisite provides native cross-domain authentication across all Extra Chill domains
- Template-level permission checks using native WordPress authentication

## Requirements

- **WordPress**: 5.0 or higher (tested up to 6.4)
- **PHP**: 7.4 or higher  
- **Theme**: Extrachill theme
- **Required Plugin**: bbPress (for forum features)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Ensure the extrachill theme and bbPress plugin are active
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
ExtraChillArtistPlatform_PageTemplates::instance();

// Asset management
ExtraChillArtistPlatform_Assets::instance();

// Social link management with comprehensive platform support
ExtraChillArtistPlatform_SocialLinks::instance();

// Live preview processing
ExtraChill_Live_Preview_Handler::instance();

// Centralized data provider function
$data = ec_get_link_page_data($artist_id, $link_page_id);

// Core features include live preview, social platform integration,
// analytics dashboard, subscription management, and permission system
```

### AJAX System

The plugin uses a modular AJAX system organized by functionality:

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

// Template-level permission checks with cross-domain session support
$can_edit = ec_can_manage_artist( get_current_user_id(), get_the_ID() );
```

### Data Provider System

Centralized data management with comprehensive validation:

```php
// Get comprehensive link page data using centralized function
$data = ec_get_link_page_data( $artist_id, $link_page_id );

// Access specific data sections
$links = $data['links']; // All links with expiration and visibility
$css_vars = $data['css_vars']; // CSS custom properties for styling
$social_links = $data['social_links']; // Social platform integrations
$advanced_settings = $data['advanced_settings']; // Tracking, redirects, etc.
$subscription_settings = $data['subscription_settings']; // Email collection config

// Use with live preview overrides
$preview_data = ec_get_link_page_data( $artist_id, $link_page_id, $override_data );
```

### Artist Grid Display

Activity-based artist sorting with comprehensive timestamp calculation:

```php
// Display artist grid with current user's artists excluded
ec_display_artist_cards_grid( 12, true );

// Get comprehensive activity timestamp (profile, link page, forum activity)
$activity_timestamp = ec_get_artist_profile_last_activity_timestamp( $artist_id );

// Template integration with context-aware rendering
echo ec_render_template( 'artist-profile-card', array(
    'artist_id' => $artist_id,
    'context' => 'directory'
) );

// Check user ownership for conditional display
$user_artists = ec_get_user_owned_artists( get_current_user_id() );
if ( ! in_array( $artist_id, $user_artists ) ) {
    // Display artist card in grid
}
```

### Adding Custom AJAX Actions

The plugin uses WordPress native AJAX patterns with modular organization:

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

    // Join flow assets loaded on login page
    public function enqueue_join_flow_assets() {
        if ( is_page_template( 'page-templates/login-register-template.php' ) ) {
            // Loads from inc/join/assets/
        }
    }
}

// Asset management handled by core class
```

### JavaScript Development

```javascript
// Event-driven module communication with standardized patterns
// Management modules dispatch events, preview modules listen

// Background management dispatches changes
document.dispatchEvent(new CustomEvent('backgroundChanged', {
    detail: { backgroundData: newBackgroundData }
}));

// Links management dispatches comprehensive link data
document.dispatchEvent(new CustomEvent('linksChanged', {
    detail: { 
        links: linkData,
        order: newOrder,
        visibility: visibilityStates
    }
}));

// Preview modules listen for specific events
document.addEventListener('backgroundChanged', function(e) {
    updatePreviewBackground(e.detail.backgroundData);
});

document.addEventListener('linksChanged', function(e) {
    updatePreviewLinks(e.detail.links);
    updateLinkOrder(e.detail.order);
});

// Social icons with live preview integration
document.addEventListener('socialIconsChanged', function(e) {
    updatePreviewSocials(e.detail.socials);
});

// Drag-and-drop with SortableJS integration
const sortable = Sortable.create(linkList, {
    animation: 150,
    onEnd: function(evt) {
        // Dispatch reorder event for live preview
        document.dispatchEvent(new CustomEvent('linksReordered', {
            detail: { newOrder: getNewOrder() }
        }));
    }
});

// Profile image management with live preview
document.addEventListener('profileImageChanged', function(e) {
    updatePreviewProfileImage(e.detail.imageData);
});

// Social platform integration
document.addEventListener('socialIconsChanged', function(e) {
    updatePreviewSocials(e.detail.socials);
});

// Join flow modal handling
document.addEventListener('DOMContentLoaded', function() {
    // Modal interactions for account selection
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
- `_youtube_embed_url` - YouTube video URL for embedded content

### 🎯 Advanced Features

- **Event-Driven JavaScript Architecture**: CustomEvent-based communication between management and preview modules
- **Artist Grid System**: Activity-based sorting with forum integration and comprehensive timestamp calculation
- **Drag-and-Drop Interface**: SortableJS-powered link reordering with real-time live preview updates
- **Link Expiration System**: Time-based link scheduling with automatic deactivation and preview integration
- **Artist Context Switching**: Multi-artist management with seamless state preservation
- **Centralized Data Provider**: Single source of truth via `ec_get_link_page_data()` with live preview support
- **Component Templates**: Modular UI components with AJAX-driven rendering
- **Permission System**: Centralized access control with context-aware validation
- **Save System**: WordPress-native form processing with comprehensive data preparation and validation

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

/* Customize analytics charts */
.analytics-chart-container {
    background: #fff;
    border-radius: 6px;
}

/* Join flow modal customization */
.join-flow-modal-content {
    background: #fff;
    border-radius: 8px;
}
```

### Custom JavaScript Integration

```javascript
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
// Modify link page data (centralized data provider)
add_filter('extrch_get_link_page_data', function($data, $artist_id, $link_page_id) {
    // Customize comprehensive link page data
    return $data;
}, 10, 3);

// Hook into save operations
add_action('ec_link_page_save', function($link_page_id) {
    // Custom save logic after successful save
}, 10, 1);

add_action('ec_artist_profile_save', function($artist_id) {
    // Custom logic after artist profile save
}, 10, 1);

// Track link clicks  
add_action('extrachill_link_clicked', function($link_url, $link_page_id) {
    // Custom tracking logic
}, 10, 2);

// Template rendering
add_filter('ec_render_template', function($output, $template_name, $args) {
    // Customize template output
    return $output;
}, 10, 3);
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
Ensure the extrachill theme and bbPress plugin are active.

### Link Page Not Loading
Check that rewrite rules are flushed by deactivating and reactivating the plugin.

### Analytics Not Tracking
Verify that JavaScript is not blocked and check browser console for errors. Check that AJAX endpoints are accessible.

### Permission Issues
Ensure user has proper role assignments and check permission functions in `inc/core/filters/permissions.php`.

### Roster Invitations Not Sending
Check that WordPress can send emails and verify SMTP configuration. Review invitation tokens in database if needed.

### Live Preview Not Updating
Check browser console for JavaScript errors. Verify that event-driven communication between management and preview modules is working correctly.

### File Structure
```
inc/
├── core/                             # Core plugin functionality
│   ├── artist-platform-assets.php       # Asset management class
│   ├── class-templates.php              # Page template handling
│   ├── artist-platform-post-types.php   # CPT registration
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
│   │   ├── data.php                     # Centralized data provider (ec_get_link_page_data)
│   │   ├── defaults.php                 # Default configurations
│   │   └── avatar-menu.php              # Avatar menu customization
│   └── templates/                       # Core template components
├── join/                             # Join flow system
│   ├── join-flow.php                 # Registration handlers and login redirects
│   ├── templates/
│   │   └── join-flow-modal.php       # Account selection modal
│   └── assets/
│       ├── css/join-flow.css         # Join flow styles
│       └── js/join-flow-ui.js        # Modal interaction handling
├── artist-profiles/                  # Profile management
│   ├── admin/                       # Admin meta boxes, user linking
│   ├── frontend/                    # Public forms, directory
│   │   ├── artist-grid.php         # Artist grid display functions
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
│   │   │   └── subscribe.php          # Subscription templates
│   │   ├── advanced-tab/           # Advanced features (tracking, redirects)
│   │   ├── live-preview/           # Live preview functionality
│   │   │   ├── class-live-preview-handler.php  # Live preview handler (ExtraChill_Live_Preview_Handler)
│   │   │   └── assets/js/          # Live preview JavaScript modules
│   │   └── templates/              # Management templates
│   │       ├── manage-link-page.php
│   │       ├── components/         # Modular UI components
│   │       └── manage-link-page-tabs/
│   ├── live/                       # Live page functionality
│   │   ├── ajax/                   # Public AJAX handlers
│   │   │   └── analytics.php          # Public tracking and data pruning
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
    ├── artist-switcher.js           # Artist context switching
    ├── artist-platform.js           # Core plugin functionality
    └── artist-platform-home.js     # Homepage-specific features
```

## Support

For issues and feature requests, contact the development team or submit issues through the project repository.

## Build System

The plugin includes a comprehensive build system for creating production-ready distributions:

### Building the Plugin

```bash
# Using NPM script
npm run build

# Or directly with shell script
./build.sh
```

### Build Features

- **Automated Packaging**: Creates `/build/extrachill-artist-platform/` directory and `/build/extrachill-artist-platform.zip` file
- **File Filtering**: Excludes development files via `.buildignore`
- **Version Extraction**: Automatically reads version from main plugin file
- **Structure Validation**: Ensures plugin integrity before packaging
- **Dependency Checking**: Verifies required tools (rsync, zip)
- **Clean Builds**: Automatic cleanup of previous artifacts

### Build Configuration

The build process excludes:
- Development documentation (README.md, CLAUDE.md, docs/)
- Version control files (.git/, .gitignore)
- Development tools (build.sh, package.json, .buildignore)
- Testing files and temporary artifacts
- Node modules and PHP vendor directories

## Custom CSS System

The plugin extends Font Awesome with custom social platform icons:

- **Substack Integration**: Custom mask-based icon for Substack links
- **Venmo Support**: CSS mask implementation for Venmo payment links
- **Dynamic Color Inheritance**: Icons automatically adapt to theme colors
- **Seamless Integration**: Works with existing Font Awesome classes

Custom icons are defined in `assets/css/custom-social-icons.css` using SVG mask techniques.

## Image Meta Key Reference

**CRITICAL**: These meta keys must be used consistently throughout the codebase. Any inconsistency will break image associations after migration.

### Artist Profile Images

| Image Type | Meta Key | Storage Method | Usage |
|-----------|----------|----------------|-------|
| **Profile Avatar** | `_thumbnail_id` | WordPress native featured image | `get_post_thumbnail_id()` / `set_post_thumbnail()` |
| **Header Image** | `_artist_profile_header_image_id` | Custom post meta | `get_post_meta()` / `update_post_meta()` |

**Files Using These Keys**:
- ✅ `inc/core/filters/upload.php` line 160, 164 (WRITE)
- ✅ `inc/artist-profiles/frontend/templates/single-artist_profile.php` line 93 (READ)
- ✅ `inc/artist-profiles/frontend/templates/artist-profile-card.php` line 33 (READ)
- ✅ `inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-info.php` line 95 (READ)
- ✅ `inc/artist-profiles/frontend/templates/manage-artist-profiles.php` line 148, 150 (READ) - **FIXED 2025-10-09**

### Link Page Images

| Image Type | Meta Key | Storage Method | Usage |
|-----------|----------|----------------|-------|
| **Featured Image** | `_thumbnail_id` | WordPress native featured image | `get_post_thumbnail_id()` / `set_post_thumbnail()` |
| **Background Image** | `_link_page_background_image_id` | Custom post meta | `get_post_meta()` / `update_post_meta()` |
| **Profile Image Reference** | `_link_page_profile_image_id` | Custom post meta (stores artist's `_thumbnail_id`) | Synced from artist profile |

**Files Using These Keys**:
- ✅ `inc/core/filters/upload.php` line 61 (background WRITE), line 95 (profile reference WRITE)
- ✅ `inc/link-pages/management/ajax/background.php` line 81 (background WRITE)
- ✅ `inc/core/actions/sync.php` line 135, 139 (profile reference sync)

### Migration Script References

**Admin Tools Plugin**: `extrachill-admin-tools/inc/tools/artist-platform-migration.php`
- Line 809-811: Collect `_artist_profile_header_image_id` from profiles
- Line 819-820: Collect `_link_page_background_image_id` from link pages
- Line 805-806: Collect `_thumbnail_id` from profiles (featured image)
- Line 815-816: Collect `_thumbnail_id` from link pages (featured image)

### Historical Issues (Resolved)

**2025-10-09**: Fixed inconsistent meta keys that broke image associations after migration:
- ❌ **WRONG**: `_artist_header_image_id` was being READ in `manage-artist-profiles.php`
- ✅ **CORRECT**: `_artist_profile_header_image_id` is the actual key used everywhere else
- ❌ **WRONG**: `_artist_profile_image_id` was being READ (completely incorrect key)
- ✅ **CORRECT**: Should use WordPress native `get_post_thumbnail_id()`
- ❌ **WRONG**: `_ec_background_image_id` was being checked in migration script
- ✅ **CORRECT**: `_link_page_background_image_id` is the actual key

**If keys need to be changed in the future**: Run a database migration to update all instances of the old key to the new key across `wp_postmeta` table.

## License

GPL v2 or later - see LICENSE file for details.