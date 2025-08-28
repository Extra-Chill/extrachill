# ExtraChill Artist Platform

WordPress plugin providing comprehensive artist platform functionality for the ExtraChill community. Enables artists to create profiles, link pages, manage subscribers, and integrate with forums.

## Architecture

### Core Classes
- **ExtraChillArtistPlatform**: Main plugin class (singleton), theme compatibility checks
- **ExtraChillArtistPlatform_Core**: Core functionality, CPTs, session management, platform includes
- **ExtraChillArtistPlatform_Templates**: Template handling and routing
- **ExtraChillArtistPlatform_Assets**: Asset management and conditional enqueuing
- **ExtraChillArtistPlatform_Migration**: Database and data migration system

### Custom Post Types
- **artist_profile**: Artist band profiles (slug: `/band/{slug}`)
- **artist_link_page**: Link pages (slug: `/{slug}` - top-level rewrite)

### Key Features

#### Link Page System
**Location**: `artist-platform/extrch.co-link-page/`
- Live preview management interface with modular JavaScript architecture
- Custom CSS variables and Google Fonts integration
- Click analytics with database tracking
- QR code generation for link sharing
- YouTube embed support with toggle control
- Featured link highlighting system

#### Cross-Domain Authentication  
**Files**: `includes/class-core.php` (integrated session management)
- Session token system for `.extrachill.com` domain
- Auto-login across subdomains using secure cookies
- 6-month token expiration with automatic cleanup

#### Forum Integration
**Files**: `artist-platform/artist-forums.php`, `artist-platform/artist-forum-section-overrides.php`
- bbPress integration with artist-specific forum sections
- Custom forum permissions and artist-linked discussions

#### Subscription System
**Location**: `artist-platform/subscribe/`
- Email collection with artist association
- Database table: `{prefix}_artist_subscribers`
- AJAX-driven subscription forms with modal support

#### Analytics System
**Files**: `artist-platform/extrch.co-link-page/link-page-analytics-*.php`
- Click tracking for all link page interactions
- Database table: `{prefix}_link_page_analytics`
- Chart.js-powered analytics dashboard

#### Roster Management System
**Location**: `artist-platform/roster/`
- Band member invitation system with email notifications
- Pending invitation tracking and management
- AJAX-powered roster UI with role assignment
- Token-based invitation acceptance system

### JavaScript Architecture

#### Management Interface
**Locations**: `assets/js/manage-link-page/` and `artist-platform/extrch.co-link-page/live-preview/js/`
- **Core**: `manage-link-page-core.js` - Central manager object (ExtrchLinkPageManager)
- **Modules**: Modular IIFE-based components for fonts, colors, sizing, customization, save functionality
- **Content**: `manage-link-page-content-renderer.js` - Live preview engine
- **Social Links**: `manage-link-page-socials.js` - Social platform management
- **QR Code**: `manage-link-page-qrcode.js` - QR code generation and display
- **Dependencies**: SortableJS for drag-and-drop, Chart.js for analytics

#### Public Interface  
**Location**: `assets/js/`
- `link-page-public-tracking.js` - Click tracking
- `link-page-subscribe.js` - Subscription functionality  
- `link-page-youtube-embed.js` - YouTube video handling
- `link-page-session.js` - Cross-domain session management
- `extrch-share-modal.js` - Link sharing modal functionality
- `shared-tabs.js` - Tabbed interface component

### Database Tables
- `{prefix}_user_session_tokens` - Cross-domain authentication (integrated in Core class)
- `{prefix}_link_page_analytics` - Click tracking data with referrer and timestamp info
- `{prefix}_artist_subscribers` - Artist subscription data with export tracking

### Dependencies
- **WordPress**: 5.0+ (tested up to 6.4)
- **PHP**: 7.4+
- **Theme**: Extra Chill Community theme (compatibility check enforced)
- **External**: bbPress (for forum features), Font Awesome, Google Fonts

### Asset Management
- File existence checks before enqueuing
- Timestamp-based cache busting
- Conditional loading based on template context
- Google Fonts integration with custom CSS variables

### Security Practices
- Nonce verification for all AJAX requests
- Input sanitization with `wp_unslash()` and `sanitize_text_field()`
- Output escaping with `esc_html()`, `esc_attr()`, `esc_url()`
- Capability checks for admin functions

### Migration System  
**File**: `includes/class-migration.php`
- Handles data migration from theme to plugin
- Version-based migration tracking
- Automatic execution on plugin initialization

### Additional Features

#### Artist Following System
**File**: `includes/artist-following.php`
- User follow/unfollow functionality for artists
- Database integration for follower tracking

#### Frontend Forms & Permissions
**Files**: `artist-platform/frontend-forms.php`, `artist-platform/artist-permissions.php`
- Public-facing form handling and validation
- Role-based artist profile access control

#### Data Synchronization
**File**: `artist-platform/data-sync.php`
- Cross-system data consistency management
- Automated synchronization between platform components

## Development Standards

### WordPress Patterns
- Uses WordPress native hooks and filters extensively
- Follows WordPress coding standards for translations
- Implements proper sanitization and escaping
- Uses WordPress HTTP API with custom timeout handling

### Code Organization
- Singleton pattern for core classes
- Modular JavaScript with clear dependencies
- Centralized asset enqueuing with conditional loading
- Template-based page routing system