# Extra Chill Artist Platform

WordPress plugin providing comprehensive artist platform functionality for the Extra Chill community. Enables artists to create profiles, link pages, manage subscribers, and integrate with forums.

## Architecture

### Core Classes
- **ExtraChillArtistPlatform**: Main plugin class (singleton), initialization (`extrachill-artist-platform.php`)
- **ExtraChillArtistPlatform_PageTemplates**: Template handling, routing, custom template loading (`inc/core/class-templates.php`)
- **ExtraChillArtistPlatform_Assets**: Asset management and conditional enqueuing (`inc/core/artist-platform-assets.php`)
- **ExtraChillArtistPlatform_Migration**: Database migration from band to artist terminology (`inc/core/artist-platform-migration.php`)
- **ExtraChillArtistPlatform_SocialLinks**: Comprehensive social link management with 15+ platform support, validation, and rendering (`inc/core/filters/social-icons.php`)
- **ExtraChill_Live_Preview_Handler**: Live preview processing and data override management (`inc/link-pages/management/live-preview/class-live-preview-handler.php`)

### File Organization
- **Core Directory**: `inc/core/` - Post types, rewrite rules, asset management, templates, centralized data functions
- **Artist Profiles**: `inc/artist-profiles/` - Admin, frontend, roster management, forum integration
- **Link Pages**: `inc/link-pages/` - Live pages, management interface, subscription system
- **Join Flow**: `inc/join/` - User registration and artist platform onboarding
- **Database**: `inc/database/` - Analytics and subscriber database functions
- **Assets**: `assets/` - Consolidated CSS/JS, organized by functionality

### Custom Post Types
- **artist_profile**: Artist/band profiles (archive: `/artists/`, single: `/artists/{slug}`)  
- **artist_link_page**: Link pages (slug: `/{slug}` - top-level rewrite)

### Key Features

#### Join Flow System
**Location**: `inc/join/`
- **Complete onboarding flow**: User registration → automatic artist profile creation → automatic link page creation
- **Modal interface**: `inc/join/templates/join-flow-modal.php` - Existing vs new account selection
- **Integration point**: Hooks into `extrachill_below_login_register_form` action from community plugin
- **Assets**: `inc/join/assets/css/join-flow.css`, `inc/join/assets/js/join-flow-ui.js`
- **Registration handler**: `ec_handle_join_flow_user_registration()` - Creates artist profile and link page on user registration
- **User detection**: `ec_is_join_flow_registration()` - Identifies join flow registrations via `from_join` parameter
- **Login redirect**: `ec_join_flow_login_redirect()` - Handles post-login navigation for join flow users
- **Roster integration**: Automatically links user as member of their own artist profile via `bp_add_artist_membership()`
- **Forum creation**: Automatically creates artist forum via `bp_create_artist_forum_on_save()`
- **Transient tracking**: Stores completion data for post-registration redirect using `join_flow_completion_{user_id}` transients

#### Link Page System
**Location**: `inc/link-pages/`
- **Live Pages**: `inc/link-pages/live/` - Public templates, analytics, session validation
- **Management Interface**: `inc/link-pages/management/` - Admin interface with modular JS/CSS, drag-and-drop link reordering
- **Live Preview**: `inc/link-pages/management/live-preview/` - Real-time preview functionality
  - **Live Preview Handler**: `inc/link-pages/management/live-preview/class-live-preview-handler.php` - Live preview handler (ExtraChill_Live_Preview_Handler)
  - **Preview Template**: `inc/link-pages/management/live-preview/preview.php` - Live preview rendering with data override support
- **Advanced Features**: `inc/link-pages/management/advanced-tab/` - Tracking, redirects, link expiration, YouTube embeds
- **Component Templates**: `inc/link-pages/management/templates/components/` - Modular UI components
- **Subscription Templates**: `inc/link-pages/templates/` - Email collection forms and modals

#### Forum Integration
**Files**: `inc/artist-profiles/artist-forums.php`, `inc/artist-profiles/artist-forum-section-overrides.php`
- bbPress integration with artist-specific forum sections
- Custom forum permissions and artist-linked discussions
- Centralized permission system (`inc/core/filters/permissions.php`)

#### Subscription System
**Locations**: `inc/artist-profiles/`, `inc/link-pages/templates/`, `inc/database/`
- Email collection with artist association (`inc/artist-profiles/subscribe-data-functions.php`)
- Database table: `{prefix}_artist_subscribers` (`inc/database/subscriber-db.php`)
- AJAX-driven subscription forms with modal support
- Inline and modal subscription interfaces (`inc/link-pages/templates/`)
- Link page subscription functions (`inc/link-pages/subscribe-functions.php`)
- Export tracking and management capabilities

#### Analytics System
**Files**: `inc/database/link-page-analytics-db.php`, `inc/link-pages/live/ajax/analytics.php`, `inc/link-pages/management/ajax/analytics.php`
- **Page View Tracking**: Real-time tracking via theme-level `ec_post_views` system (WordPress post meta)
- **Daily Aggregation**: Scheduled cron calculates daily view deltas and populates `{prefix}_extrch_link_page_daily_views` table
- **Link Click Tracking**: Direct tracking to `{prefix}_extrch_link_page_daily_link_clicks` table via sendBeacon
- **Data Retention**: Automatic 90-day pruning of daily analytics tables
- **Dashboard**: Chart.js-powered analytics with daily breakdown charts
- Management interface: `inc/link-pages/management/assets/js/analytics.js`

#### Roster Management System
**Location**: `inc/artist-profiles/roster/`
- Band member invitation system with email notifications (`inc/artist-profiles/roster/artist-invitation-emails.php`)
- Pending invitation tracking and management (`inc/artist-profiles/roster/manage-roster-ui.php`)
- AJAX-powered roster UI with role assignment (`inc/artist-profiles/roster/roster-ajax-handlers.php`)
- Token-based invitation acceptance system
- Data functions and validation (`inc/artist-profiles/roster/roster-data-functions.php`)

### Asset Management
**Class**: `ExtraChillArtistPlatform_Assets` in `inc/core/artist-platform-assets.php`
- File existence checks before enqueuing
- Timestamp-based cache busting via `filemtime()`
- Conditional loading based on template context
- Context-aware asset loading for different page types
- **Join flow asset loading**: `enqueue_join_flow_assets()` - Loads join flow CSS/JS on login page with `from_join` parameter detection

#### JavaScript Architecture

**Management Interface**: `inc/link-pages/management/assets/js/`
- **Core modules**: `info.js`, `links.js`, `colors.js`, `fonts.js`, `sizing.js`, `background.js`, `profile-image.js`
- **Advanced features**: `analytics.js`, `qrcode.js`, `socials.js`, `subscribe.js`, `advanced.js`, `link-expiration.js`
- **UI utilities**: `ui-utils.js` (responsive tab management, copy URL functionality), `sortable.js` (SortableJS drag-and-drop reordering)

**Live Preview System**: `inc/link-pages/management/live-preview/assets/js/`
- **Preview modules**: `background-preview.js`, `colors-preview.js`, `fonts-preview.js`, `info-preview.js`, `links-preview.js`, `sizing-preview.js`, `socials-preview.js`, `link-expiration-preview.js`, `subscribe-preview.js`, `profile-image-preview.js`
- **UI components**: `overlay-preview.js`, `sorting-preview.js`

**Public Interface**: `inc/link-pages/live/assets/js/`
- `link-page-public-tracking.js` - Analytics and click tracking
- `link-page-subscribe.js` - Subscription form functionality
- `link-page-youtube-embed.js` - YouTube video embed handling
- `extrch-share-modal.js` - Native Web Share API with social fallbacks

**Artist Profile Management**: `inc/artist-profiles/assets/js/`
- `manage-artist-profiles.js` - Profile editing, image previews, roster management with AJAX
- `manage-artist-subscribers.js` - Subscriber list management
- `artist-members-admin.js` - Backend member administration

**Global Components**: `assets/js/`
- `artist-switcher.js` - Artist selection dropdown for switching contexts
- `artist-platform.js` - Core plugin functionality
- `artist-platform-home.js` - Homepage-specific features

**Join Flow Interface**: `inc/join/assets/js/`
- `join-flow-ui.js` - Modal handling for existing vs new account selection

**Key JavaScript Modules**:
- `link-expiration.js` & `link-expiration-preview.js` - Time-based link scheduling and preview
- `sortable.js` - SortableJS integration for drag-and-drop link reordering  
- `sorting-preview.js` - Live preview for drag-and-drop operations
- `subscribe-preview.js` - Live preview for subscription form changes
- `profile-image.js` & `profile-image-preview.js` - Profile image upload and preview management
- `analytics.js` - Chart.js-powered analytics dashboard with event-driven initialization

### Database Schema

#### Analytics Tables
```sql
CREATE TABLE {prefix}_extrch_link_page_daily_views (
    view_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    link_page_id bigint(20) unsigned NOT NULL,
    stat_date date NOT NULL,
    view_count bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (view_id),
    UNIQUE KEY unique_daily_view (link_page_id, stat_date)
);

CREATE TABLE {prefix}_extrch_link_page_daily_link_clicks (
    click_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    link_page_id bigint(20) unsigned NOT NULL,
    stat_date date NOT NULL,
    link_url varchar(2083) NOT NULL,
    click_count bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (click_id),
    UNIQUE KEY unique_daily_link_click (link_page_id, stat_date, link_url(191)),
    KEY link_page_date (link_page_id, stat_date)
);
```

#### Subscription Table
```sql
CREATE TABLE {prefix}_artist_subscribers (
    subscriber_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_profile_id BIGINT(20) UNSIGNED NOT NULL,
    subscriber_email VARCHAR(255) NOT NULL,
    username VARCHAR(60) NULL DEFAULT NULL,
    subscribed_at DATETIME NOT NULL,
    exported TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (subscriber_id),
    UNIQUE KEY email_artist (subscriber_email, artist_profile_id),
    KEY artist_profile_id (artist_profile_id),
    KEY exported (exported)
);
```

### Dependencies
- **WordPress**: 5.0+ (tested up to 6.4)
- **PHP**: 7.4+
- **Plugin Dependencies**: bbPress plugin (enforced via WordPress native plugin dependency system)
- **External**: Font Awesome, Google Fonts

### Additional Features

#### Link Expiration System
**Files**: `inc/link-pages/management/advanced-tab/link-expiration.php`, `inc/link-pages/management/assets/js/link-expiration.js`
- Time-based link lifecycle management
- Automatic link deactivation and scheduling
- Live preview integration with expiration display

#### Drag-and-Drop Interface
**File**: `inc/link-pages/management/assets/js/sortable.js`
- SortableJS integration for link reordering
- Touch-friendly drag-and-drop for mobile devices
- Real-time preview updates during reordering

#### Social Platform Integration
**File**: `inc/core/filters/social-icons.php`
- **Comprehensive Platform Support**: 15+ social platforms including Apple Music, Bandcamp, Bluesky, Facebook, GitHub, Instagram, Patreon, Pinterest, SoundCloud, Spotify, TikTok, Twitch, Twitter/X, YouTube, and custom links
- **Smart Icon Management**: Font Awesome icon class validation with fallback handling
- **URL Validation**: Automatic protocol addition and comprehensive URL sanitization
- **Custom Labels**: Support for custom link labels (e.g., custom website links)
- **CRUD Operations**: Complete social link lifecycle management with permission validation
- **Rendering System**: Flexible HTML output with customizable container classes and accessibility attributes

#### Artist Context Switching
**File**: `assets/js/artist-switcher.js`
- Artist selection dropdown for multi-artist management
- Context-aware UI updates based on selected artist
- Seamless switching between artist profiles

#### Artist Following System
**File**: `inc/artist-profiles/artist-following.php`
- Follow/unfollow functionality with database integration

#### Artist Grid System
**File**: `inc/artist-profiles/frontend/artist-grid.php`
- Activity-based artist sorting with comprehensive timestamp calculation
- User exclusion logic for personalized displays
- Forum activity integration via bbPress
- Link page activity tracking for sorting
- Responsive grid layouts with context-aware rendering
- Template integration via `ec_render_template()` system

#### Frontend Forms & Permissions  
**Files**: `inc/artist-profiles/frontend/frontend-forms.php`, `inc/core/filters/permissions.php`
- Public form handling and validation
- Centralized permission system with role-based access
- Profile editing and management interfaces

#### Centralized Save System
**Core Files**: `inc/core/actions/save.php`
- **Unified save operations**: `ec_handle_link_page_save()`, `ec_handle_artist_profile_save()`
- **Data preparation**: `ec_prepare_link_page_save_data()`, `ec_prepare_artist_profile_save_data()`
- **File handling**: Centralized upload processing with cleanup of old attachments
- **Hook integration**: `ec_link_page_save`, `ec_artist_profile_save` action hooks
- **Admin post handlers**: Security-validated form submission processing

**Form Processing**: Pure WordPress form submission
- **Direct field processing**: All data processed via individual form fields
- **No JavaScript required**: Standard WordPress admin_post handler
- **Immediate validation**: Server-side sanitization and validation
- **Standard patterns**: Follows WordPress form processing conventions

**AJAX System Architecture**: WordPress-native modular system with standardized patterns
- **WordPress Native Patterns**: Uses standard `add_action('wp_ajax_*')` throughout codebase
- **Centralized Permissions**: Permission helpers in `inc/core/filters/permissions.php`
  - `ec_ajax_can_manage_artist()`, `ec_ajax_can_manage_link_page()` for AJAX contexts  
  - `ec_can_manage_artist()`, `ec_can_create_artist_profiles()` for general contexts
  - Unified permission logic with proper nonce verification in each handler

**Live (Public) AJAX Modules**: `inc/link-pages/live/ajax/`
- **analytics.php**: Public tracking (`extrch_record_link_event`, `link_page_click_tracking`) with data pruning

**Management (Admin) AJAX Modules**: `inc/link-pages/management/ajax/`
- **links.php**: Link management (`render_link_item_editor`, `render_link_section_editor`, `render_link_template`, `render_links_section_template`, `render_links_preview_template`)
- **social.php**: Social icon management (`render_social_item_editor`, `render_social_template`)
- **analytics.php**: Admin analytics dashboard (`extrch_fetch_link_page_analytics`)
- **background.php**: Background image uploads with file cleanup
- **qrcode.php**: QR code generation for link pages
- **subscribe.php**: Subscription handling (`extrch_link_page_subscribe`, `render_subscribe_template`)

**Permission System**: Server-side permission validation
- Permission checks centralized via `inc/core/filters/permissions.php`
- Context-aware permission validation for AJAX and template rendering

#### Centralized Data System
**Core File**: `inc/core/filters/data.php`
- **`ec_get_link_page_data()`**: Single source of truth for all link page data
- Replaces scattered `get_post_meta()` calls with unified data provider function
- Supports live preview overrides and extensive data validation
- Provides comprehensive data for CSS variables, links, social icons, and advanced settings
- Applies WordPress filters for extensibility (`extrch_get_link_page_data`)
- Used throughout templates, AJAX handlers, and asset management

#### Data Synchronization
**Files**: `inc/core/actions/sync.php`, `inc/core/filters/data.php`
- Cross-system data consistency and component synchronization
- Centralized data filtering and validation
- Automatic sync triggers after save operations

#### Template System

**Dual Template Architecture**:

1. **Page Templates** (`inc/core/class-templates.php`)
   - Full page routing and template loading
   - WordPress template hierarchy integration
   - Context-aware template selection

2. **Component Templates** (`inc/core/filters/templates.php`)
   - Modular UI component rendering
   - AJAX-driven template fragments
   - Template filtering and customization

**Template Directories**:
- **Artist Profile Templates**: `inc/artist-profiles/frontend/templates/`
- **Link Page Templates**: `inc/link-pages/live/templates/` (public), `inc/link-pages/management/templates/` (admin)
- **Component Templates**: `inc/link-pages/management/templates/components/` - Modular UI components
- **Core Templates**: `inc/core/templates/` - Base template components
- **Subscription Templates**: `inc/link-pages/templates/` - Email collection forms

#### Admin Interface
**Files**: `inc/artist-profiles/admin/meta-boxes.php`, `inc/artist-profiles/admin/user-linking.php`
- Custom meta boxes for artist profile management
- User account linking and validation
- Administrative assets: `inc/artist-profiles/assets/js/`

#### Custom CSS System
**File**: `assets/css/custom-social-icons.css`
- **Custom Social Icon Support**: Extends Font Awesome with additional social platforms (Substack, Venmo)
- **CSS Mask Technique**: Uses SVG masks to inherit parent text color and sizing
- **Font Awesome Integration**: Seamless integration with existing icon classes
- **Dynamic Color Inheritance**: Icons automatically adapt to theme colors

### Migration System  
**File**: `inc/core/artist-platform-migration.php`
- Band-to-artist terminology migration with transaction safety and rollback

### Build System
**Core Files**: `build.sh -> ../../.github/build.sh`, `package.json`, `.buildignore`

**Universal Build Script**: Symlinked to shared build script at `../../.github/build.sh`
- **Automated Build Process**: Universal script auto-detects plugin type and creates production packages
- **Version Extraction**: Automatically extracts version from `Plugin Name:` header in main plugin file
- **File Filtering**: `.buildignore` provides rsync exclusion patterns for development files
- **Dependency Validation**: Checks for required tools (rsync, zip, composer)
- **Production Dependencies**: Runs `composer install --no-dev` before build, restores dev dependencies after
- **Structure Validation**: Validates plugin integrity before packaging (ensures main file exists)
- **Output Location**: Creates both `/build/extrachill-artist-platform/` clean directory AND `/build/extrachill-artist-platform.zip` non-versioned file

**Build Features**:
- **Clean Builds**: Automatic cleanup of previous `/build` artifacts (removes legacy `/dist` if exists)
- **Exclude Management**: Comprehensive file exclusion via `.buildignore` rsync patterns
- **Integrity Checks**: Plugin structure validation during build process
- **Progress Reporting**: Colored console output with success/error reporting
- **Archive Summary**: Total file count and ZIP size after successful build
- **Single Source of Truth**: Updates to `/.github/build.sh` automatically apply to all plugins/themes

**NPM Integration**: `package.json` provides linting scripts and development tooling

## Development Standards

### Security Practices
- **Nonce verification**: All forms and AJAX requests use WordPress nonce system
- **Input sanitization**: `wp_unslash()` before all sanitization functions
- **Data validation**: Type checking and allowlist validation for enums
- **Output escaping**: `esc_html()`, `esc_attr()`, `esc_url()` for all output
- **Permission checks**: Centralized `ec_can_manage_artist()` capability validation
- **File uploads**: Size limits, type validation, and cleanup of old attachments

### WordPress Patterns
- Uses WordPress native hooks and filters extensively
- Follows WordPress coding standards for translations
- Implements proper sanitization and escaping
- Uses WordPress HTTP API with custom timeout handling

### Code Organization
- Singleton pattern for core classes (`ExtraChillArtistPlatform`, `ExtraChillArtistPlatform_Templates`, `ExtraChillArtistPlatform_Assets`, `ExtraChillArtistPlatform_Migration`)
- Modular JavaScript organization by feature and functionality
- Centralized asset enqueuing with context-aware loading
- Template-based page routing system with nested organization
- Feature-based CSS organization with consolidated assets

### JavaScript Patterns

#### Module Architecture

**Self-Contained IIFE Pattern**: All JavaScript modules use immediate function expressions
```javascript
// Example from info.js
(function() {
    'use strict';
    
    const InfoManager = {
        fields: {},
        init: function() { /* initialization */ },
        bindEvents: function() { /* event binding */ }
    };
    
    document.addEventListener('DOMContentLoaded', InfoManager.init.bind(InfoManager));
})();
```

**Event-Driven Communication**: Modules communicate via CustomEvent dispatching with standardized patterns
```javascript
// Management modules dispatch specific events for each data type
document.dispatchEvent(new CustomEvent('infoChanged', {
    detail: { title: newTitle, bio: newBio }
}));
document.dispatchEvent(new CustomEvent('linksChanged', {
    detail: { links: linkData }
}));
document.dispatchEvent(new CustomEvent('backgroundChanged', {
    detail: { backgroundData: bgData }
}));

// Preview modules listen for corresponding events
document.addEventListener('infoChanged', function(e) {
    updatePreviewInfo(e.detail);
});
document.addEventListener('linksChanged', function(e) {
    updatePreviewLinks(e.detail.links);
});
document.addEventListener('backgroundChanged', function(e) {
    updatePreviewBackground(e.detail.backgroundData);
});
```

**Module Categories**:
1. **Management Modules**: Handle form interactions, dispatch events for data changes (`info.js`, `links.js`, `colors.js`, etc.)
2. **Preview Modules**: Listen for events, update live preview DOM elements (`info-preview.js`, `links-preview.js`, `colors-preview.js`, etc.)
3. **Utility Modules**: Shared functionality (`ui-utils.js`, `sortable.js` for drag-and-drop)
4. **Global Modules**: Cross-component features (`artist-switcher.js`, `artist-platform.js`)
5. **Public Interface**: User-facing functionality (`extrch-share-modal.js`, tracking modules)
6. **Join Flow**: User onboarding (`join-flow-ui.js` - modal interactions for account selection)

#### Key JavaScript Features
- **Event-Driven Architecture**: Standardized CustomEvent communication between management and preview modules
- **Responsive Tab System**: `ui-utils.js` provides tab management functionality for management interfaces
- **Drag-and-Drop**: `sortable.js` - SortableJS integration with touch support and live preview updates
- **Live Preview**: Real-time CSS variable updates via dedicated preview modules with comprehensive data synchronization
- **Form Serialization**: Complex data structures serialized to hidden inputs for WordPress native form processing
- **AJAX Integration**: WordPress-native patterns with modular organization and comprehensive error handling
- **Modern APIs**: Native Web Share API with social media fallbacks
- **Context-Aware Loading**: Asset management with conditional enqueuing based on page context
- **Artist Context Switching**: Seamless multi-artist management with state preservation
- **Join Flow Modals**: Modal-based user onboarding for account selection

 #### Save System Data Flow
 1. **JavaScript modules** serialize complex data structures to hidden form inputs
 2. **Form submission** triggers `admin_post_ec_save_link_page` handler
 3. **Security validation**: Nonce verification and permission checks
 4. **Data preparation**: `ec_prepare_link_page_save_data()` sanitizes and validates all inputs
 5. **Save execution**: `ec_handle_link_page_save()` processes meta updates and file uploads
 6. **Sync trigger**: Action hooks trigger cross-system data synchronization
 7. **Redirect**: User returned to management interface with success feedback

 ### FORBIDDEN FALLBACK TYPES
 - Placeholder fallbacks for undefined data that should be required
 - Legacy fallbacks for removed functionality
 - Fallbacks that prevent code failure or hide broken functionality
 - Fallbacks that provide dual support for multiple methods

 ### Planning Standards (Plan Mode)
 - Create a specific and refined plan outlining all explicit changes exactly as they will be implemented
 - Plans must explicitly identify which files and functions to modify, create, or delete
 - When writing todos, always include excessive detail, intermediary steps, and files to modify/create
 - Plans MUST align with existing codebase patterns
 - All code review should be completed before you present the plan

 ### Special Rules
 - All AI models in the codebase are correct. Do not change them.
 - Verify all API documentation using the context7 mcp

 ### Documentation Standards
 - Use concise inline docblocks at the top of files and/or critical functions to explain technicalities for developers
 - Inline comments are reserved strictly for nuanced behavior that is not obvious from the readable code
 - Actively remove outdated documentation, including references to deleted functionality