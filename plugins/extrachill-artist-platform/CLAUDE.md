# ExtraChill Artist Platform

WordPress plugin providing comprehensive artist platform functionality for the ExtraChill community. Enables artists to create profiles, link pages, manage subscribers, and integrate with forums.

## Architecture

### Core Classes
- **ExtraChillArtistPlatform**: Main plugin class (singleton), theme compatibility checks, initialization (`extrachill-artist-platform.php`)
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
- Theme compatibility checks for CSS files
- Context-aware asset loading for different page types

#### JavaScript Files
**Management Interface**: `inc/link-pages/management/assets/js/`
- Core modules: `info.js`, `links.js`, `colors.js`, `fonts.js`, `sizing.js`
- Advanced features: `analytics.js`, `qrcode.js`, `socials.js`, `subscribe.js`
- UI utilities: `ui-utils.js`, `save.js`, `advanced.js`
- Live preview: `inc/link-pages/management/live-preview/assets/js/`

**Public Interface**: `inc/link-pages/live/assets/js/`
- `link-page-public-tracking.js` - Click tracking
- `link-page-subscribe.js` - Subscription functionality
- `link-page-youtube-embed.js` - YouTube video handling  
- `link-page-session.js` - Cross-domain session management
- `extrch-share-modal.js` - Link sharing modal

**Global Assets**: `assets/js/`
- `shared-tabs.js` - Tabbed interface component
- `artist-platform.js` - Core functionality
- `artist-platform-home.js` - Homepage features

### Database Tables
- `{prefix}_extrch_link_page_daily_views` - Daily page view aggregates
- `{prefix}_extrch_link_page_daily_link_clicks` - Daily click aggregates  
- `{prefix}_artist_subscribers` - Subscription data with export tracking

### Dependencies
- **WordPress**: 5.0+ (tested up to 6.4)
- **PHP**: 7.4+
- **Theme**: Extra Chill Community theme (compatibility check enforced)
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

#### Data Synchronization
**Files**: `inc/core/actions/sync.php`, `inc/core/filters/data.php`
- Cross-system data consistency and component synchronization
- Centralized data filtering and validation
- AJAX action handling (`inc/core/actions/ajax.php`)
- Save operation coordination (`inc/core/actions/save.php`)

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
- Nonce verification for all AJAX requests
- Input sanitization with `wp_unslash()` and `sanitize_text_field()`
- Output escaping with `esc_html()`, `esc_attr()`, `esc_url()`
- Capability checks for admin functions

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