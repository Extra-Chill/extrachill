# ExtraChill Artist Platform

WordPress plugin providing comprehensive artist platform functionality for the ExtraChill community. Enables artists to create profiles, link pages, manage subscribers, and integrate with forums.

## Architecture

### Core Classes
- **ExtraChillArtistPlatform**: Main plugin class (singleton), initialization (`extrachill-artist-platform.php`)
- **ExtraChillArtistPlatform_Templates**: Template handling, routing, custom template loading (`inc/core/class-templates.php`)
- **ExtraChillArtistPlatform_Assets**: Asset management and conditional enqueuing (`inc/core/artist-platform-assets.php`)
- **ExtraChillArtistPlatform_Migration**: Database migration from band to artist terminology (`inc/core/artist-platform-migration.php`)
- **ExtraChillArtistPlatform_SocialLinks**: Social link type management and configuration (`inc/core/filters/social-icons.php`)

### File Organization
- **Core Directory**: `inc/core/` - Post types, rewrite rules, asset management, templates, data providers
- **Artist Profiles**: `inc/artist-profiles/` - Admin, frontend, roster management, forum integration
- **Link Pages**: `inc/link-pages/` - Live pages, management interface, subscription system
- **Database**: `inc/database/` - Analytics and subscriber database functions
- **Assets**: `assets/` - Consolidated CSS/JS, organized by functionality

### Custom Post Types
- **artist_profile**: Artist/band profiles (archive: `/artists/`, single: `/artists/{slug}`)  
- **artist_link_page**: Link pages (slug: `/{slug}` - top-level rewrite)

### Key Features

#### Link Page System
**Location**: `inc/link-pages/`
- **Live Pages**: `inc/link-pages/live/` - Public templates, analytics, session validation
- **Management Interface**: `inc/link-pages/management/` - Admin interface with modular JS/CSS
- **Live Preview**: `inc/link-pages/management/live-preview/` - Real-time preview functionality
- **Advanced Features**: `inc/link-pages/management/advanced-tab/` - Tracking, redirects, expiration
- **Subscription System**: `inc/link-pages/subscription/` - Email collection forms and modals

#### Cross-Domain Authentication  
**File**: `inc/link-pages/live/link-page-session-validation.php`
- Session token system for `.extrachill.com` domain
- Auto-login across subdomains using secure cookies
- Token validation for link page access
- 6-month token expiration with cleanup

#### Forum Integration
**Files**: `inc/artist-profiles/artist-forums.php`, `inc/artist-profiles/artist-forum-section-overrides.php`
- bbPress integration with artist-specific forum sections
- Custom forum permissions and artist-linked discussions
- Centralized permission system (`inc/core/filters/permissions.php`)

#### Subscription System
**Locations**: `inc/artist-profiles/`, `inc/link-pages/subscription/`, `inc/database/`
- Email collection with artist association (`inc/artist-profiles/subscribe-data-functions.php`)
- Database table: `{prefix}_artist_subscribers` (`inc/database/subscriber-db.php`)
- AJAX-driven subscription forms with modal support
- Inline and modal subscription interfaces (`inc/link-pages/subscription/`)
- Link page subscription functions (`inc/link-pages/subscribe-functions.php`)
- Export tracking and management capabilities

#### Analytics System
**Files**: `inc/database/link-page-analytics-db.php`, `inc/link-pages/live/link-page-analytics-tracking.php`
- Daily aggregation of page views and link clicks
- Database tables: `{prefix}_extrch_link_page_daily_views`, `{prefix}_extrch_link_page_daily_link_clicks`
- Chart.js-powered analytics dashboard with date filtering
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

#### JavaScript Architecture

**Management Interface**: `inc/link-pages/management/assets/js/`
- **Core modules**: `info.js`, `links.js`, `colors.js`, `fonts.js`, `sizing.js`, `background.js`
- **Advanced features**: `analytics.js`, `qrcode.js`, `socials.js`, `subscribe.js`, `advanced.js`, `featured-link.js`
- **UI utilities**: `ui-utils.js` (responsive tab management, copy URL functionality), `save.js` (centralized form serialization)
- **CSS management**: `css-variables.js` (DEPRECATED - CSS variables now managed directly via CSSOM)

**Live Preview System**: `inc/link-pages/management/live-preview/assets/js/`
- **Preview modules**: `background-preview.js`, `colors-preview.js`, `fonts-preview.js`, `info-preview.js`, `links-preview.js`, `sizing-preview.js`, `socials-preview.js`
- **UI components**: `overlay-preview.js`, `featured-link-preview.js`

**Public Interface**: `inc/link-pages/live/assets/js/`
- `link-page-public-tracking.js` - Analytics and click tracking
- `link-page-subscribe.js` - Subscription form functionality
- `link-page-youtube-embed.js` - YouTube video embed handling
- `link-page-session.js` - Cross-domain session validation with REST API integration
- `extrch-share-modal.js` - Native Web Share API with social fallbacks

**Artist Profile Management**: `inc/artist-profiles/assets/js/`
- `manage-artist-profiles.js` - Profile editing, image previews, roster management with AJAX
- `manage-artist-subscribers.js` - Subscriber list management
- `artist-members-admin.js` - Backend member administration

**Global Components**: `assets/js/`
- `shared-tabs.js` - Responsive tabbed interface (accordion on mobile, tabs on desktop)
- `artist-platform.js` - Core plugin functionality
- `artist-platform-home.js` - Homepage-specific features

### Database Tables
- `{prefix}_extrch_link_page_daily_views` - Daily page view aggregates
- `{prefix}_extrch_link_page_daily_link_clicks` - Daily click aggregates  
- `{prefix}_artist_subscribers` - Subscription data with export tracking

### Dependencies
- **WordPress**: 5.0+ (tested up to 6.4)
- **PHP**: 7.4+
- **Plugin Dependencies**: Extra Chill Community theme (enforced via WordPress native plugin dependency system)
- **External**: bbPress, Font Awesome, Google Fonts

### Additional Features

#### Artist Following System
**File**: `inc/artist-profiles/artist-following.php`
- Follow/unfollow functionality with database integration

#### Frontend Forms & Permissions  
**Files**: `inc/artist-profiles/frontend/frontend-forms.php`, `inc/core/filters/permissions.php`
- Public form handling and validation
- Centralized permission system with role-based access
- Profile editing and management interfaces
- Artist directory and search functionality (`inc/artist-profiles/frontend/artist-directory.php`)

#### Centralized Save System
**Core Files**: `inc/core/actions/save.php`, `inc/core/actions/ajax.php`
- **Unified save operations**: `ec_handle_link_page_save()`, `ec_handle_artist_profile_save()`
- **Data preparation**: `ec_prepare_link_page_save_data()`, `ec_prepare_artist_profile_save_data()`
- **File handling**: Centralized upload processing with cleanup of old attachments
- **Hook integration**: `ec_link_page_save`, `ec_artist_profile_save` action hooks
- **Admin post handlers**: Security-validated form submission processing

**JavaScript Coordination**: `inc/link-pages/management/assets/js/save.js`
- **Form serialization**: Centralized hidden input management for complex data structures
- **CSS variables**: Direct CSSOM reading (not textContent parsing)
- **Module integration**: Calls serializeForSave() methods on feature modules
- **Tab preservation**: Active tab state maintained across page reloads
- **Loading states**: User feedback during save operations

**AJAX System**: `inc/core/actions/ajax.php` 
- **Centralized registry**: `EC_Ajax_Registry` class for standardized AJAX handling
- **Permission validation**: Role-based access control with nonce verification
- **Error handling**: Try-catch with proper error logging and user feedback
- **Extensible architecture**: Register new AJAX actions with permission callbacks

#### Data Synchronization
**Files**: `inc/core/actions/sync.php`, `inc/core/filters/data.php`
- Cross-system data consistency and component synchronization
- Centralized data filtering and validation
- Automatic sync triggers after save operations

#### Template System
**Files**: `inc/core/class-templates.php`, feature-specific template directories
- Custom template overrides with routing and conditional loading
- **Artist Profile Templates**: `inc/artist-profiles/frontend/templates/`
- **Link Page Templates**: `inc/link-pages/live/templates/` (public), `inc/link-pages/management/templates/` (admin)
- **Component Templates**: Modular tab interfaces and shared components

#### Admin Interface
**Files**: `inc/artist-profiles/admin/meta-boxes.php`, `inc/artist-profiles/admin/user-linking.php`
- Custom meta boxes for artist profile management
- User account linking and validation
- Administrative assets: `inc/artist-profiles/assets/js/`

### Migration System  
**File**: `inc/core/artist-platform-migration.php`
- Band-to-artist terminology migration with transaction safety and rollback

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

#### Module Organization
- **Namespace pattern**: `window.ExtrchLinkPageManager` for management interface modules
- **Event-driven architecture**: Custom events (`sharedTabActivated`) for tab system integration
- **Self-contained modules**: Each feature module handles its own initialization and cleanup
- **CSSOM integration**: Direct CSS variable manipulation via CSSOM rather than text parsing

#### Key JavaScript Features
- **Responsive UI components**: Shared tabs system with accordion/tabs layout switching
- **REST API integration**: Session validation with retry logic and cross-domain support
- **Modern web APIs**: Native Web Share API with fallback to social media links
- **Form serialization**: Centralized save system with JSON data handling
- **Live preview system**: Real-time CSS variable updates via preview modules
- **AJAX workflows**: Comprehensive error handling with nonce validation

#### Save System Data Flow
1. **JavaScript modules** serialize complex data structures to hidden form inputs
2. **Form submission** triggers `admin_post_ec_save_link_page` handler
3. **Security validation**: Nonce verification and permission checks
4. **Data preparation**: `ec_prepare_link_page_save_data()` sanitizes and validates all inputs
5. **Save execution**: `ec_handle_link_page_save()` processes meta updates and file uploads
6. **Sync trigger**: Action hooks trigger cross-system data synchronization
7. **Redirect**: User returned to management interface with success feedback