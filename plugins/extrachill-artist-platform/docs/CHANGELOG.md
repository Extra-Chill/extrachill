# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.6] - 2025-12-08

### Changed
- Fixed import organization in artist-profile-manager block edit component
- Added webpack build support for artist-profile-manager block view script

### Technical Improvements
- Enabled proper compilation of artist-profile-manager block assets
- Improved block editor import structure for better maintainability

## [1.2.5] - 2025-12-08

### Changed
- Enhanced asset management with dynamic blog ID resolution for improved multisite compatibility
- Improved save button UX by removing disabled state when no unsaved changes exist
- Added selective saving system that only saves modified sections for better performance
- Implemented automatic nonce middleware in API client for enhanced security

### Technical Improvements
- Better multisite support with dynamic blog ID detection
- Reduced API calls through dirty section tracking
- Improved user experience with always-available save functionality
- Enhanced security with automatic nonce handling

## [1.2.4] - 2025-12-08

### Changed
- Refactored analytics system to use action hooks from extrachill-api plugin instead of direct REST endpoints
- Moved analytics tracking handlers from AJAX files to unified analytics.php with filter-based data provision
- Updated all documentation to reflect new analytics architecture and remove legacy forum integration references
- Enhanced permissions system documentation with new helper functions

### Removed
- Legacy analytics REST endpoint files (inc/link-pages/live/ajax/analytics.php, edit-permission.php)
- Forum integration references throughout codebase and documentation
- Unused query variable `is_extrch_preview_iframe`

## [1.2.3] - 2025-12-08

### Changed
- **REST API Migration**: Completed migration from AJAX to REST API across entire codebase
- **Analytics Architecture**: Changed analytics from link-page-scoped to artist-scoped API endpoints
- **API Client Consolidation**: Unified shared API client with improved error handling and artist-scoped analytics
- **Permission System**: Added REST API-aware permission extraction functions for better security
- **Asset Management**: Standardized on REST API endpoints, removed legacy AJAX references
- **Social Links**: Enhanced data structure with icon_class field and improved ID generation for React components

### Removed
- Legacy AJAX endpoint references and AJAX-specific code paths
- Deprecated AJAX handlers and button IDs
- Outdated AJAX documentation and examples

### Documentation
- Updated all documentation files to reflect REST API architecture
- Removed AJAX-specific patterns and legacy references
- Enhanced cross-domain authentication and permission system documentation
- Clarified modern WordPress REST API integration patterns

## [1.2.2] - 2025-12-08

### Fixed
- Fixed analytics data fetching hook to use `linkPageId` parameter instead of `artistId` for correct query resolution
- Corrected API parameter passing in analytics block hooks and components

### Changed
- Consolidated duplicate API client implementations into unified `src/blocks/shared/api/client.js`
- Removed redundant `api/client.js` from individual blocks (link-page-analytics, link-page-editor) in favor of shared implementation
- Enhanced analytics block render.php with improved artist resolution and configuration handling
- Improved block configuration localization with proper nonce and REST URL setup

### Added
- New reusable link page template components for improved rendering modularity
  - `link-section.php` - Section title and link grouping component
  - `single-link.php` - Individual link rendering with YouTube embed support
  - `social-icon.php` - Social icon rendering component
  - `social-icons-container.php` - Social links grouping container
- Enhanced template system with improved component organization

### Documentation
- Updated AGENTS.md to clarify API client consolidation and shared implementation pattern
- Enhanced architecture documentation to reflect unified API client approach across all blocks
- Clarified REST API integration patterns in block documentation

## [1.2.1] - 2025-12-08

### Added
- New `ec_get_artist_profile_data()` function providing centralized single source of truth for artist profile data
- Artist profile data function with support for live preview overrides
- Comprehensive artist profile data management including images, social links, and all metadata fields

### Fixed
- Fixed SQL query column reference in `ec_get_artist_subscribers()` function (subscription_date → subscribed_at)
- Enforced link page card background color to always use default (prevents misconfigured background colors in card elements)

### Changed
- Enhanced `ec_get_link_page_data()` to enforce card background color validation
- Refactored artist profile image meta key handling with standardized access patterns
- Updated documentation to reflect removal of legacy PHP event-driven architecture
- Removed references to legacy live preview event-driven system from README
- Removed references to bbPress integration from installation instructions
- Cleaned up JavaScript architecture documentation to focus on React-based Gutenberg blocks

### Removed
- Legacy event-driven JavaScript architecture references from README and documentation
- Outdated JavaScript patterns documentation for old management interface

## [1.2.0] - 2025-12-08

### Added
- New `artist-profile-manager` Gutenberg block for comprehensive artist profile management with React-based frontend interface
- Complete Gutenberg-based management system replacing legacy PHP interfaces
- Enhanced block registration system with artist-profile-manager block support

### Removed
- Entire legacy PHP management interface for link pages (`inc/link-pages/management/` except `link-expiration.php`)
- All legacy management AJAX handlers, templates, and assets (50+ files removed)
- Traditional manage-link-page PHP interface (superseded by Gutenberg blocks)
- Legacy advanced-tab includes except link expiration cron
- Empty subscribe helper functions

### Changed
- Plugin initialization to register new artist-profile-manager block
- Asset management system (removed legacy management asset loading)
- Link expiration system simplified to cron-only functionality
- Documentation updates reflecting new Gutenberg-first architecture
- Code cleanups and improvements across multiple core files
- README.md updated to reflect removal of legacy management interface

### Block Versions
- `link-page-editor`: 0.1.2 (unchanged)
- `link-page-analytics`: 0.1.2 (unchanged)
- `artist-profile-manager`: 0.1.1 (new block)

## [1.1.13] - 2025-12-07

### Enhanced
- Added local font CSS injection support in Gutenberg block editor preview for custom fonts like WilcoLoftSans
- Enhanced breadcrumb navigation with network dropdown target classes for improved UI interaction
- Improved block editor render.php with local fonts CSS generation and configuration

### Block Version
- Updated Gutenberg blocks to version 0.1.2

## [1.1.12] - 2025-12-07

### Enhanced
- Improved drag-and-drop visual feedback in link page editor (scale effect with shadow instead of opacity)
- Added unique temporary ID generation for new links, sections, and social items in Gutenberg block editor
- Enhanced social link data handling with optional ID field support
- Improved React component keys for better rendering performance in link and social management tabs

## [1.1.11] - 2025-12-07

### Added
- New Gutenberg block for link page analytics with dedicated analytics dashboard
- Analytics navigation link in secondary header for users with link pages
- Enhanced homepage hero with differentiated messaging for artist creation permissions

### Changed
- Refactored link page editor block: removed analytics tab (now separate block), manage artist link, and live preview indicator
- Improved mobile responsiveness in link page editor with better tablet/mobile layouts
- Updated asset loading in block render.php to use proper dependency management
- Enhanced webpack configuration to support new analytics block

### Fixed
- Improved homepage user experience with clearer calls-to-action based on permissions

## [1.1.10] - 2025-12-06

### Added
- Page view tracking for link pages via REST API (previously only tracked clicks)
- SVG icon sprite system for block editor components replacing Font Awesome dependency
- Dedicated `src/blocks/link-page-editor/utils/fonts.js` utility module for centralized font management
- Dynamic Google Fonts loading in Preview component based on selected fonts

### Changed
- Refactored block editor state management: consolidated preview computation into EditorContext
- Simplified analytics tracking: direct database writes on track event instead of background aggregation
- Updated LinkPageUrl and QRCodeModal components to use SVG sprites instead of Font Awesome icons
- Enhanced Preview component with proper font stack resolution and dynamic Google Fonts loading
- Refactored TabCustomize with improved font value handling and proper defaults
- Simplified breadcrumb navigation: removed community.extrachill.com link from breadcrumb trail
- Improved link-page-public-tracking.js with unified `sendBeacon()` helper for both view and click events
- Reordered block editor header elements for better UX (LinkPageUrl now appears before artist switcher)

### Removed
- PreviewContext.js merged into EditorContext.js for simplified state management
- Analytics aggregation cron system (extrch_daily_analytics_aggregate_event)
- Daily view aggregation logic that calculated increments from total counts
- Aggregation-related functions: `extrch_aggregate_daily_link_page_views()`, `extrch_schedule_analytics_aggregation_cron()`, `extrch_unschedule_analytics_aggregation_cron()`
- Font Awesome icons from LinkPageUrl and QRCodeModal components

### Technical Improvements
- Consolidated state management with unified EditorContext providing all preview and editor state
- Improved analytics data flow with direct `INSERT ON DUPLICATE KEY UPDATE` writes
- Better separation of concerns with dedicated fonts utility module
- Enhanced preview styling with proper CSS variable defaults and font stack resolution
- Cleaner component composition with svg icon sprite system
- Simplified plugin deactivation hooks to only handle pruning cron
- More efficient analytics pipeline without background aggregation delays

### Block Version
- Gutenberg block remains at version 0.1.0 (independent from plugin version)

## [1.1.9] - 2025-12-06

### Added
- New `JumpToPreview` component for mobile-optimized block editor navigation
- New `LinkPageUrl` component for displaying canonical link page URLs in editor
- New `QRCodeModal` component for QR code generation and display in block editor
- Enhanced Preview component with comprehensive data synchronization
- Improved block editor styling with SCSS refactoring (542 lines updated)

### Changed
- Refactored Editor component with simplified state management and improved props handling
- Enhanced block render output with better error handling and data validation
- Updated various tab components (TabAdvanced, TabCustomize, TabInfo, TabLinks) with improved form handling
- Improved ImageUploader with better error handling and progress feedback
- Refactored EditorContext and PreviewContext for cleaner state management
- Updated advanced settings documentation to reflect removed weekly email feature

### Removed
- Removed `link-page-weekly-email.php` file (612 lines) - weekly email functionality no longer supported
- Removed weekly email UI from advanced settings tab
- Removed obsolete weekly email references from save system

### Technical Improvements
- Better separation of concerns in block component architecture
- Enhanced error handling throughout block editor interface
- Improved state synchronization between editor and preview panels
- Cleaner component composition and prop management
- Reduced code duplication in block editor styling

### Block Version
- Updated Gutenberg block version from 1.0.0 to 0.1.0 (initial feature version after base implementation)

## [1.1.8] - 2025-12-06

### Changed
- Refactored Gutenberg block registration from separate file to main plugin initialization
- Added file existence checks for admin asset enqueuing to prevent errors

### Technical Improvements
- Improved code organization by consolidating block registration logic
- Enhanced asset loading reliability with safety checks

## [1.1.7] - 2025-12-05

### Added
- Gutenberg block for link page editor management with React-based UI
- REST API image handling for background and profile image uploads
- New styling options: social icons positioning and profile image shape
- Google Tag Manager ID tracking support
- Webpack build configuration for Gutenberg block compilation
- WordPress build scripts integration for block asset compilation

### Changed
- Enhanced save system to handle REST API image ID assignments
- Improved image meta handling with direct ID assignment from media library
- Updated build configuration to include compiled block assets

### Removed
- Featured link highlighting feature and associated settings

### Technical Improvements
- Added modern React-based UI for link page editing alongside existing PHP management
- Improved build process with wp-scripts for block compilation
- Enhanced REST API integration for image uploads
- Better separation of concerns with block-based component architecture

## [1.1.6] - 2025-12-05

### Added
- Breadcrumb navigation for artist profile and link page management interfaces
- REST API nonce support for enhanced security in JavaScript requests

### Changed
- Migrated analytics data provision from AJAX to filter hooks for better integration
- Refactored link template rendering to remove AJAX dependencies
- Improved JavaScript error handling across fetch requests in artist profiles and subscription forms
- Standardized error propagation with Promise.reject() for better debugging

### Removed
- AJAX handlers for link template rendering and analytics fetching
- Redundant AJAX wrapper for programmatic link addition

### Technical Improvements
- Enhanced navigation UX with contextual breadcrumb trails
- Better separation of concerns between frontend and API layers
- Improved code maintainability with REST API integration
- More robust error handling preventing silent failures

## [1.1.5] - 2025-12-05

### Added
- Artist profile archive page at `/artists/` with dedicated template and routing
- Mobile-only jump-to-preview navigation button for link page management
- "Browse All Artists" links on homepage featured artist sections

### Changed
- Replaced AJAX pagination system with theme's native pagination for better consistency
- Updated artist grid display: 24 artists per page on archive, 12 on homepage
- Migrated error handling to use theme's notice system (`extrachill_set_notice()`)
- Standardized responsive breakpoint to 768px across all components
- Improved homepage layout with better navigation to full artist directory

### Removed
- AJAX pagination JavaScript (`artist-grid-pagination.js`) and handlers
- Legacy artist directory template (`artist-directory.php`)
- Obsolete forum activity tracking code (cleanup after bbPress removal)

### Technical Improvements
- Added archive template support in core template routing system
- Enhanced breadcrumb integration for artist profile archive pages
- Cleaned up event listeners and removed references to deleted following system
- Improved mobile user experience with contextual navigation buttons

## [1.1.4] - 2025-12-05

### Added
- Artist access approval redirect handler for join flow email confirmations
- Profile managers tab with user search and management functionality
- REST API endpoints for subscriber management and roster invitations
- Client-side CSV generation for subscriber exports

### Changed
- Migrated subscriber management from AJAX to REST API endpoints
- Migrated roster member invitations from AJAX to REST API endpoints
- Improved error handling using WordPress notices instead of query parameters
- Updated JavaScript to use fetch API with JSON payloads
- Enhanced asset localization for REST API integration
- Cleaned up profile management templates with proper CSS classes

### Removed
- Complete artist following system (functions, templates, CSS, database fields)
- AJAX handlers for subscriber and roster management
- Query parameter-based error messaging
- Legacy follow-related UI components and modals

### Technical Improvements
- Better separation of concerns between frontend and API layers
- Improved code maintainability with REST API integration
- Improved form validation and error handling
- **Note**: All AJAX handlers migrated to REST API starting in v1.1.3
- Streamlined codebase by removing unused following functionality

## [1.1.3] - 2025-12-04

### Changed
- Migrated subscription forms from admin-ajax.php to REST API endpoints
- Migrated analytics tracking from admin-ajax.php to REST API endpoints
- Updated JavaScript to use fetch API with JSON payloads
- Removed nonces from subscription forms, using data attributes instead
- Enhanced access control and error handling in link page management
- Standardized hook names from `extra_chill_*` to `extrachill_*`
- Added live preview support for subscription templates

### Removed
- AJAX handlers for subscription and analytics tracking
- `inc/link-pages/management/ajax/subscribe.php` file

### Technical Improvements
- Improved form validation and error handling
- Better separation of concerns between frontend and API layers
- Enhanced code maintainability with REST API integration

## [1.1.2] - 2025-12-04

### Added
- Navigation integration with secondary header artist management links
- URL normalization for analytics to strip Google Analytics auto-generated parameters
- High-resolution QR code download functionality (1000px)

### Changed
- Refactored QR code system to use REST API instead of AJAX
- Improved artist directory button logic and labeling
- Enhanced management interface layout with full-width breakout
- Updated analytics documentation with URL normalization details
- Shortened button labels for consistency ("Manage Artist" vs "Manage Artist Profile")

### Removed
- Legacy QR code AJAX handler (qrcode.php)
- Traditional manage-link-page PHP interface, assets, and AJAX endpoints (block now handles management at /manage-link-page)

### Technical Improvements
- Added debounced data attribute updates for better performance
- Enhanced asset localization for QR code functionality
- Improved code quality with better escaping and error handling

## [1.1.1] - 2025-12-04

### Fixed
- Form processing order preservation in social icons and link sections
- JSON data handling in AJAX social icon management
- CSS syntax error in management interface styles
- Social icon deletion index tracking for preview synchronization

### Changed
- Improved remove buttons in link and social editors (proper buttons with icons)
- Enhanced AJAX data validation and sanitization

### Technical Improvements
- Better DOM order preservation during form submissions
- Improved JavaScript-AJAX data exchange reliability
- Minor CSS layout improvements
- Comprehensive documentation cleanup removing outdated forum references

## [1.1.0] - 2025-12-04

### Removed
- Complete bbPress forum integration and dependency
- Artist forum creation and management functionality
- Forum notification system and notification cards
- Forum-related permissions and UI components
- Forum section overrides in artist profiles
- CLAUDE.md documentation file (consolidated with AGENTS.md)

### Changed
- Updated documentation references to use AGENTS.md
- Changed support forum link to community.extrachill.com/r/tech-support
- Removed forum-related fields from artist profile management
- Simplified artist profile templates by removing forum sections

### Technical Improvements
- Eliminated bbPress plugin dependency requirement
- Reduced plugin complexity and maintenance overhead
- Streamlined codebase by removing unused forum integration code
- Updated build configuration to reference AGENTS.md instead of CLAUDE.md

## [1.0.2] - 2025-12-04

### Changed
- Modernized JavaScript architecture: Converted jQuery AJAX calls to fetch API in artist-grid-pagination.js, manage-artist-profiles.js, analytics.js, and socials.js
- Complete refactor of manage-artist-profiles.js from jQuery to vanilla JavaScript with IIFE pattern
- Improved code organization: Moved plugin activation/deactivation hooks into class methods
- Removed redundant artist-members-admin.js legacy file
- Updated version constant and CSS version to align with plugin header

### Technical Improvements
- Eliminated jQuery dependency in core management interfaces
- Enhanced performance through native JavaScript APIs
- Better adherence to architectural principles
- Improved code maintainability and encapsulation
- **Note**: Migration from jQuery AJAX to REST API completed in subsequent versions

## [1.0.1] - 2025-11-29

### Fixed
- Edit icon display on live link pages for authenticated users with edit permissions
- Analytics database issues
- Artist profile picture uploads
- Background type switching and overlay functionality in live preview
- Add social icon and link section buttons on empty pages
- Save system and color controls
- Error handling for artist profile image uploads
- View count system to use core theme view counts

### Added
- Pagination for artist profile archives
- Drag and drop reordering with SortableJS dependency
- QR code dependency management
- Join flow moved from community plugin
- Static exclusions to cover ALL WordPress pages
- Breadcrumb standardization

### Changed
- Comprehensive refactor with enhanced social system and documentation
- Full artist platform refactor for stability and extensibility
- Refactored artist profiles, link pages, and removed deprecated features
- Modularized and cleaned up homepage functionality
- Updated documentation to align with current codebase architecture
- Removed default admin link page dashboard widget
- Cleaned up code comments
- Docs update for cross-domain logic
- Preparing for migration to standalone artist platform

## [1.0.0] - 2025-08-28

### Added
- Initial stable release of Extra Chill Artist Platform
- Comprehensive artist platform functionality for musicians
- Artist profile management with custom post types
- Link page system with live preview and management interface
- Analytics dashboard with click tracking and reporting
- Subscription management for email collection
- Cross-domain authentication system
- Forum integration with bbPress
- Roster management with invitation system
- Social platform integration (15+ platforms)
- Drag-and-drop link reordering
- QR code generation
- YouTube embed support
- Advanced styling with custom fonts and colors
- Permission system with role-based access
- Homepage integration with artist grid
- Join flow system for user onboarding
- Notification system for forum activity
- Breadcrumb integration
- Blog coverage linking
- Asset management and conditional loading
- Centralized data provider system
- AJAX-powered management interfaces
- Live preview functionality
- Export capabilities for subscriber data
- Migration system for data integrity