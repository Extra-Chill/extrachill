# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- Enhanced code maintainability with REST API integration
- Improved form validation and error handling
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
- Redundant manage-link-page.css file (consolidated into management.css)

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