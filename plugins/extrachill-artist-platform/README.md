# Extra Chill Artist Platform

A comprehensive WordPress plugin that provides artist profile management, link pages, and community features for musicians on the Extra Chill platform.

## Features

### 🚀 Join Flow System
- Complete onboarding flow: user registration → artist profile → link page creation
- Modal interface for existing vs new account selection
- Automatic artist profile and link page creation during join flow registration (extrachill.link/join)
- Roster membership auto-assignment for profile owners
- Transient-based post-registration redirect tracking

### 🎵 Artist Profiles
- Custom post type for artist/band profiles with comprehensive meta data
- Activity-based artist grid display with smart sorting and context-aware rendering
- Blog coverage integration linking profiles to main site taxonomy archives
- Roster management with email invitation system and role assignment
- Artist directory with user exclusion logic for personalized views
- Profile manager assignment and centralized permissions
- Following system with database-backed relationships
- Unified artist card component for consistent display across pages

### 🔗 Link Pages
- Custom link page creation with comprehensive management interface via Gutenberg block editor
- Modern React-based editing interface with live preview
- Drag-and-drop link reordering with dnd-kit integration
- Advanced styling system with custom fonts, colors, backgrounds, and profile image management
- YouTube video embed support with toggle control
- QR code generation with download functionality
- Native Web Share API integration with social media fallbacks
- Link expiration scheduling with automatic deactivation
- Social platform integration with 15+ platforms including Apple Music, Bandcamp, Bluesky, Pinterest, and more
- Comprehensive social link management with smart icon validation and URL sanitization
- Comprehensive click analytics with Chart.js dashboard and automatic data pruning

### 📊 Analytics Dashboard
- Daily aggregation of page views and link clicks
- Chart.js-powered visual analytics with date filtering
- Real-time click tracking with automatic data pruning
- Export capabilities for comprehensive data analysis
- Public tracking via REST API with privacy-conscious data collection

### 👥 Subscription Management
- Modal and inline subscription forms with REST API processing
- Artist-specific subscriber lists with export tracking
- Database-backed subscriber management with deduplication
- Link page and artist profile subscription integration
- Email marketing workflow integration with export status tracking

### 🔔 Notification System
- **Artist platform notifications**: Artist platform activity notifications
- **Custom notification cards**: Specialized rendering via extrachill-community plugin integration
- **Smart filtering**: Excludes notification author from recipient list automatically
- **Visual card system**: Font Awesome icons with formatted timestamps and actor avatars

### 🔐 Permission System
- Centralized access control via `inc/core/filters/permissions.php`
- Role-based artist profile management with granular permissions
- Server-side permission validation with context-aware checks
- REST API permission validation with comprehensive nonce verification
- Template-level permission checks using native WordPress authentication
- Cross-domain authentication managed by extrachill-multisite plugin

## Requirements

- **WordPress**: 5.0 or higher (tested up to 6.4)
- **PHP**: 7.4 or higher
- **Theme**: Extrachill theme
- **Required Plugin**: extrachill-users (for user management)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Ensure the extrachill theme is active
4. Configure plugin settings as needed

## Usage

### Creating Artist Profiles

1. Navigate to **Artist Profiles** in the WordPress admin
2. Click **Add New** to create a new artist profile
3. Fill in artist information using the artist-profile-manager block:
   - **Info Tab**: Artist name, biography, and profile image
   - **Socials Tab**: Add social platform links
   - **Members Tab**: Invite band members via email
   - **Subscribers Tab**: View and manage email subscribers
4. Publish the profile

### Managing Band Rosters

1. Edit an artist profile in the WordPress admin
2. Navigate to the **Members** tab in the artist-profile-manager block
3. Send email invitations to band members
4. Track pending invitations and confirmations
5. Manage member roles and permissions

### Managing Link Pages

1. Edit a link page in the WordPress admin (or create a new one)
2. Use the link-page-editor block to manage your link page:
   - **Info Tab**: Artist info and biography
   - **Links Tab**: Add and organize your links with drag-and-drop
   - **Customize Tab**: Style your page with fonts, colors, backgrounds
   - **Advanced Tab**: Configure tracking, expiration, YouTube embeds
   - **Socials Tab**: Add social platform links
3. Use the live preview to see changes in real-time
4. Save your changes

### Viewing Analytics

1. Edit a link page in the WordPress admin
2. Click the **Analytics** tab in the link-page-analytics block
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

// Centralized data provider function
$data = ec_get_link_page_data($artist_id, $link_page_id);

// Core features include social platform integration,
// analytics dashboard, subscription management, and permission system
```

### React-Based Gutenberg Blocks

The plugin includes three React-based Gutenberg blocks for comprehensive platform management:

**1. Link Page Editor Block** (`src/blocks/link-page-editor/`)
- Modern interface for creating and editing artist link pages
- Tab-based components: TabInfo, TabLinks, TabCustomize, TabAdvanced, TabSocials
- Live preview with real-time updates
- Drag-and-drop link reordering
- Advanced styling with custom fonts, colors, and backgrounds
- Social platform integration with 15+ platforms

**2. Link Page Analytics Block** (`src/blocks/link-page-analytics/`)
- Dedicated analytics dashboard for tracking link page performance
- Chart.js-powered visual analytics with date filtering
- Daily page view and link click aggregation
- Artist context switching for multi-artist management

**3. Artist Profile Manager Block** (`src/blocks/artist-profile-manager/`)
- Complete artist profile management interface
- Tabs for profile info, social links, roster members, and subscribers
- Email-based roster member invitations
- Subscriber management and export functionality

**Block Registration**:
```javascript
// Block location: src/blocks/*/
// Compiled to: build/blocks/*/

// Registered in main plugin initialization
function extrachill_artist_platform_register_blocks() {
    register_block_type( __DIR__ . '/build/blocks/link-page-editor' );
    register_block_type( __DIR__ . '/build/blocks/link-page-analytics' );
    register_block_type( __DIR__ . '/build/blocks/artist-profile-manager' );
}
add_action( 'init', 'extrachill_artist_platform_register_blocks' );
```

**Features**:
- **React Components**: Tab-based interfaces with context-specific functionality
- **Live Preview**: Real-time preview in link page editor (Editor and Preview components)
- **REST API Integration**: Centralized API client for data operations
- **Custom Hooks**: useArtist, useLinks, useMediaUpload, useSocials, useAnalytics
- **Context Providers**: EditorContext, PreviewContext, AnalyticsContext for state management
- **Build Process**: Webpack compilation via `npm run build` with wp-scripts
- **Mobile Support**: Jump-to-preview button and responsive design

**Building the Blocks**:
```bash
# Development build with watch mode
npm run dev

# Production build
npm run build

# Build output location
build/blocks/link-page-editor/
build/blocks/link-page-analytics/
build/blocks/artist-profile-manager/
```

### Public Tracking (REST API)

The plugin uses REST API for all public tracking operations:

```php
// Public Tracking Endpoints: inc/link-pages/live/ajax/
// REST API registration for analytics and permission checking
// All tracking uses sendBeacon() or fetch() with REST endpoints
```

### Permission System

Server-side permission validation with centralized access control:

```php
// Permission helpers in inc/core/filters/permissions.php
if ( ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
    // User has permission to manage this artist
}

if ( ec_can_manage_link_page( get_current_user_id(), $link_page_id ) ) {
    // User can manage link pages
}

// Template-level permission checks
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

Activity-based artist sorting with context-aware rendering and pagination:

```php
// Display artist grid with current user's artists excluded
ec_display_artist_cards_grid( 24, true );

// Display with pagination data return
$data = ec_display_artist_cards_grid( 24, true, 1, true );
// Returns: array with 'html', 'pagination_html', 'current_page', 'total_pages', 'total_artists'

// Get comprehensive activity timestamp (profile, link page activity)
$activity_timestamp = ec_get_artist_profile_last_activity_timestamp( $artist_id );

// Template integration with context-aware rendering
echo ec_render_template( 'artist-card', array(
    'artist_id' => $artist_id,
    'context' => 'directory'
) );

// Check user ownership for conditional display
$user_artists = ec_get_artists_for_user( get_current_user_id() );
if ( ! in_array( $artist_id, $user_artists ) ) {
    // Display artist card in grid
}
```

### Homepage Action Hooks

Centralized homepage functionality with modular hook system:

```php
// Homepage template override filter (theme integration)
add_filter( 'extrachill_template_homepage', 'ec_artist_platform_override_homepage' );

// Hero section rendering with user state parameters
add_action( 'extrachill_artist_home_hero', 'ec_render_artist_home_hero', 10, 4 );
// Template: inc/home/templates/hero.php

// Your Artists section above grid
add_action( 'extrachill_above_artist_grid', 'ec_render_your_artists', 10, 1 );
// Template: inc/home/templates/your-artists.php

// Support buttons section
add_action( 'extrachill_above_artist_grid', 'ec_render_support_buttons', 15 );
```

**Hook Integration**:
- Uses theme's universal template routing via `extrachill_template_homepage` filter
- Blog ID detection: Only overrides on artist.extrachill.com (blog ID 4)
- Modular templates: `homepage.php`, `hero.php`, `your-artists.php`
- User state awareness for personalized hero messaging

### Programmatic Link Addition

Add links to artist link pages programmatically via WordPress action:

```php
// Add a link programmatically
do_action( 'ec_artist_add_link', $link_page_id, array(
    'link_text' => 'My Website',
    'link_url' => 'https://example.com',
    'section_index' => 0,  // Optional: which section (default: 0)
    'position' => 0,        // Optional: position in section (default: append)
    'expires_at' => ''      // Optional: expiration datetime
), $user_id );

// Hook into success/failure actions
add_action( 'ec_artist_link_added', function( $link_page_id, $link_data, $section, $position, $user_id ) {
    // Log success, send notification, trigger webhook, etc.
}, 10, 5 );

add_action( 'ec_artist_link_add_failed', function( $link_page_id, $link_data, $error, $user_id ) {
    // Log error for debugging
    error_log( 'Link add failed: ' . $error->get_error_message() );
}, 10, 4 );

// Data sanitization filter (extensible)
add_filter( 'ec_sanitize_link_data', function( $link_data ) {
    // Add custom sanitization
    return $link_data;
} );

// Data validation filter (extensible)
add_filter( 'ec_validate_link_data', function( $valid, $link_data ) {
    // Add custom validation rules
    return $valid;
}, 10, 2 );
```

### Adding Custom REST API Actions

The plugin uses WordPress native REST API patterns for extensibility:

```php
// Register custom REST routes for your functionality
register_rest_route( 'your-namespace/v1', '/custom-action', array(
    'methods'             => 'POST',
    'callback'            => 'my_custom_handler',
    'permission_callback' => 'ec_can_manage_artist',
    'args'                => array(
        'artist_id' => array(
            'type'     => 'integer',
            'required' => true,
        ),
    ),
) );

function my_custom_handler( $request ) {
    // Get parameters from request
    $artist_id = $request->get_param( 'artist_id' );

    // Permission validation using centralized system
    if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
        return new WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
    }

    // Your custom logic
    return rest_ensure_response( array( 'message' => 'Success!' ) );
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

The plugin uses React via Gutenberg blocks for management, and vanilla JavaScript for public link page functionality:

```javascript
// Gutenberg Block (React-based management interface)
// Location: src/blocks/link-page-editor/

// Public link page functionality (vanilla JavaScript)
// Click tracking, subscription forms, YouTube embeds, share modal
```

### Database Structure

The plugin creates several custom tables with complete schema:

#### Analytics Tables
```sql
-- Daily page view aggregates
CREATE TABLE wp_extrch_link_page_daily_views (
    view_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    link_page_id bigint(20) unsigned NOT NULL,
    stat_date date NOT NULL,
    view_count bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (view_id),
    UNIQUE KEY unique_daily_view (link_page_id, stat_date)
);

-- Daily link click aggregates
CREATE TABLE wp_extrch_link_page_daily_link_clicks (
    click_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    link_page_id bigint(20) unsigned NOT NULL,
    stat_date date NOT NULL,
    link_url varchar(2083) NOT NULL,
    click_count bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (click_id),
    UNIQUE KEY unique_daily_link_click (link_page_id, stat_date, link_url(191)),
    KEY link_page_date (link_page_id, stat_date)
);

-- Artist subscriber data
CREATE TABLE wp_artist_subscribers (
    subscriber_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT(20) UNSIGNED NULL,
    artist_profile_id BIGINT(20) UNSIGNED NOT NULL,
    subscriber_email VARCHAR(255) NOT NULL,
    username VARCHAR(60) NULL DEFAULT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'platform_follow_consent',
    subscribed_at DATETIME NOT NULL,
    exported TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (subscriber_id),
    UNIQUE KEY email_artist (subscriber_email, artist_profile_id),
    KEY artist_profile_id (artist_profile_id),
    KEY exported (exported),
    KEY user_id (user_id),
    KEY user_artist_source (user_id, artist_profile_id, source)
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

- **Gutenberg Block Editor**: Modern React-based interface for WordPress block editor with full feature parity
- **Artist Grid System**: Activity-based sorting with comprehensive timestamp calculation
- **Drag-and-Drop Interface**: SortableJS-powered link reordering with real-time live preview updates
- **Link Expiration System**: Time-based link scheduling with automatic deactivation and preview integration
- **Artist Context Switching**: Multi-artist management with seamless state preservation
- **Centralized Data Provider**: Single source of truth via `ec_get_link_page_data()` with live preview support
- **Component Templates**: Modular UI components with context-aware rendering
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

// Permission callbacks used in REST API
$permission_callbacks = [
    'ec_can_manage_artist',
    'ec_can_manage_link_page', 
    'current_user_can'
];
];
```

## Troubleshooting

### Theme Compatibility Issues
Ensure the extrachill theme is active.

### Link Page Not Loading
Check that rewrite rules are flushed by deactivating and reactivating the plugin.

### Analytics Not Tracking
Verify that JavaScript is not blocked and check browser console for errors. Check that REST API endpoints are accessible.

### Permission Issues
Ensure user has proper role assignments and check permission functions in `inc/core/filters/permissions.php`.

### Roster Invitations Not Sending
Check that WordPress can send emails and verify SMTP configuration. Review invitation tokens in database if needed.

### Gutenberg Block Not Loading
Ensure Webpack build has been run: `npm run build`. Check browser console for errors.

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
│   │   ├── sync.php                     # Data synchronization
│   │   ├── add.php                      # Programmatic link addition API
│   │   └── delete.php                   # Deletion operations
│   ├── filters/
│   │   ├── social-icons.php             # Social link management
│   │   ├── fonts.php                    # Font configuration
│   │   ├── ids.php                      # ID generation and management
│   │   ├── templates.php                # Component template filtering
│   │   ├── permissions.php              # Centralized permission system
│   │   ├── data.php                     # Centralized data provider (ec_get_link_page_data)
│   │   ├── defaults.php                 # Default configurations
│   │   ├── create.php                   # Creation operations
│   │   └── upload.php                   # File upload handling
│   └── templates/                       # Core template components
├── join/                             # Join flow system
│   ├── join-flow-init.php            # Join flow initialization
│   ├── templates/
│   │   └── join-flow-modal.php       # Account selection modal
│   └── assets/
│       ├── css/join-flow.css         # Join flow styles
│       └── js/join-flow-ui.js        # Modal interaction handling
├── notifications/                    # Notification system
│   ├── artist-notifications.php        # Artist platform notifications
│   └── artist-notification-cards.php   # Notification card rendering
├── home/                            # Homepage functionality
│   ├── homepage-hooks.php              # Centralized hook registrations
│   ├── homepage-artist-card-actions.php # Card rendering actions
│   └── templates/
│       ├── homepage.php                # Main homepage template
│       ├── hero.php                    # Hero section template
│       └── your-artists.php            # Your Artists section
├── artist-profiles/                  # Profile management
│   ├── admin/                       # Admin meta boxes, user linking
│   ├── frontend/                    # Public forms, directory
│   │   ├── artist-grid.php         # Artist grid display functions
│   │   ├── breadcrumbs.php         # Theme breadcrumb filter integration
│   │   └── templates/              # Artist profile templates
│   │       ├── single-artist_profile.php
│   │       ├── artist-directory.php
│   │       ├── manage-artist-profiles.php
│   │       ├── artist-card.php     # Unified artist card component
│   │       └── manage-artist-profile-tabs/
│   ├── roster/                      # Band member management
│   │   ├── artist-invitation-emails.php
│   │   ├── manage-roster-ui.php
│   │   ├── roster-filter-handlers.php
│   │   └── roster-data-functions.php
│   ├── blog-coverage.php            # Main site taxonomy archive linking
│   └── subscribe-data-functions.php # Artist subscription data
├── link-pages/                      # Link page system
│   ├── live/                       # Live page functionality
│   │   ├── ajax/                   # Public REST API modules
│   │   │   ├── analytics.php          # Public tracking and data pruning
│   │   │   └── edit-permission.php    # Live permission checking
│   │   ├── assets/js/               # Public JavaScript modules
│   │   └── templates/              # Public link page templates
│   │       ├── single-artist_link_page.php
│   │       ├── extrch-link-page-template.php
│   │       ├── subscribe-inline-form.php
│   │       └── subscribe-modal.php
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
│   ├── artist-card.css             # Unified artist card styles
│   ├── artist-profile.css          # Artist profile page styles
│   ├── artist-platform-home.css    # Homepage styles
│   ├── manage-link-page.css         # Management interface
│   ├── extrch-links.css            # Public link page styles
│   └── extrch-share-modal.css      # Share modal styles
└── js/
    ├── artist-switcher.js           # Artist context switching
    ├── artist-platform.js           # Core plugin functionality
    └── artist-platform-home.js     # Homepage-specific features
```

**Note**: Migration functionality is available in extrachill-admin-tools plugin (`inc/tools/artist-platform-migration.php`)

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

- **Automated Packaging**: Creates `/build/extrachill-artist-platform.zip` file only (unzip when directory access needed)
- **File Filtering**: Excludes development files via `.buildignore`
- **Version Extraction**: Automatically reads version from main plugin file
- **Structure Validation**: Ensures plugin integrity before packaging
- **Dependency Checking**: Verifies required tools (rsync, zip)
- **Clean Builds**: Automatic cleanup of previous artifacts

### Build Configuration

The build process excludes:
- Development documentation (README.md, AGENTS.md)
- Version control files (.git/, .gitignore)
- Development tools (build.sh, package.json, .buildignore)
- Testing files and temporary artifacts
- Node modules (node_modules/)
- Note: WordPress plugins include vendor/ directory with production dependencies (end users don't have Composer access)

## Build System & Webpack Configuration

The plugin includes a comprehensive build system with Webpack for compiling React/Gutenberg blocks:

**Build Files**:
- `webpack.config.js` - Webpack configuration for block compilation
- `package.json` - NPM scripts and dependencies
- `build.sh` - Symlinked to universal build script

**Build Commands**:
```bash
# Development build with watch mode
npm run dev

# Production build
npm run build
```

**Webpack Configuration**:
- Compiles React components in `src/blocks/`
- Processes SCSS files for styling
- Generates assets to `build/blocks/` directory
- Integrates with WordPress block registration via `block.json`

**NPM Scripts**:
```json
{
  "scripts": {
    "build": "wp-scripts build",
    "dev": "wp-scripts start",
    "lint": "wp-scripts lint-js src",
    "format": "wp-scripts format"
  }
}
```

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
- ✅ `inc/artist-profiles/frontend/templates/artist-card.php` line 33 (READ)
- ✅ `inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-info.php` line 95 (READ)
- ✅ `inc/artist-profiles/frontend/templates/manage-artist-profiles.php` line 148, 150 (READ)

### Link Page Images

| Image Type | Meta Key | Storage Method | Usage |
|-----------|----------|----------------|-------|
| **Featured Image** | `_thumbnail_id` | WordPress native featured image | `get_post_thumbnail_id()` / `set_post_thumbnail()` |
| **Background Image** | `_link_page_background_image_id` | Custom post meta | `get_post_meta()` / `update_post_meta()` |
| **Profile Image Reference** | `_link_page_profile_image_id` | Custom post meta (stores artist's `_thumbnail_id`) | Synced from artist profile |

**Files Using These Keys**:
- ✅ `inc/core/filters/upload.php` line 61 (background WRITE), line 95 (profile reference WRITE)
- ✅ `inc/core/actions/sync.php` line 135, 139 (profile reference sync)

### Migration Script References

**Admin Tools Plugin**: `extrachill-admin-tools/inc/tools/artist-platform-migration.php`
- Migration functionality moved to extrachill-admin-tools plugin
- Collects `_artist_profile_header_image_id` from profiles
- Collects `_link_page_background_image_id` from link pages
- Collects `_thumbnail_id` from profiles and link pages (featured images)

**If keys need to be changed in the future**: Run a database migration to update all instances of the old key to the new key across `wp_postmeta` table.

## License

GPL v2 or later - see LICENSE file for details.