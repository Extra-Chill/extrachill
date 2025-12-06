# Changelog

## [1.0.6] - 2025-12-05

### Added
- New avatar upload UI component for bbPress profile editing (avatar-upload.php)
- Avatar upload JavaScript using unified REST API endpoint

### Changed
- Refactored TinyMCE image upload to use unified REST endpoint (/wp-json/extrachill/v1/media)
- Removed admin-ajax handlers for image uploads (moved to REST API)
- Updated plugin file count documentation (34 files total: core 7, content 6, social 11, user-profiles 7, home 4)

### Fixed
- Better REST API integration for media uploads across all contexts (content embeds, user avatars)

## [1.0.5] - 2025-12-05

### Added
- New artist platform access tab in user settings with request system
- Admin email notifications for artist access requests
- Artist access request form with artist/professional type selection

### Changed
- Improved error handling in TinyMCE image upload JavaScript
- Enhanced error handling and response validation in upvote system
- Refactored settings form handler to use notice system instead of transients

### Fixed
- Better error handling for failed AJAX requests in image upload and upvoting

## [1.0.4] - 2025-12-04

### Added
- Enhanced search button with icon and improved accessibility in topics loop
- Better artist management UI with dynamic labeling based on profile count

### Changed
- Simplified artist platform button logic to show consistent CTA for all users
- Improved form styling and search button design in topics loop
- Refactored artist profile management to use centralized function for latest artist retrieval

### Removed
- Unused share button CSS loading from theme integration

## [1.0.3] - 2025-12-04

### Added
- Navigation integration with secondary header links for Recent, Local Scenes, and Music Discussion
- New inc/core/nav.php file for community navigation features

### Changed
- Major refactoring of recent feed system to use single-blog queries instead of multisite cross-blog queries
- Improved search button styling in topics loop with theme-consistent button classes
- Updated homepage rendering to use action hooks for better extensibility
- Updated documentation references from CLAUDE.md to AGENTS.md

### Removed
- CLAUDE.md documentation file (superseded by AGENTS.md)
- Redundant CSS styling for search buttons and sticky topic icons

## [1.0.2] - 2025-12-01

### Added
- New reply card footer with improved action button layout
- Better separation of admin and user actions in reply cards

### Changed
- Major refactoring of reply card UI with action buttons moved to footer
- Removed jQuery dependency from shared-tabs script for better performance
- Updated CSS styling to support new reply card layout

### Fixed
- Improved reply link handling in mentions system
- Removed duplicate edit/reply links from admin area

## [1.0.1] - 2025-11-30

### Added
- Comprehensive feature documentation (content-editor.md, core-features.md, home.md, user-profiles.md)
- Enhanced bbPress template system with improved user forms and reply cards
- New content expansion functionality (renamed from home-collapse.js)

### Changed
- Complete migration from jQuery to vanilla JavaScript across all frontend components
- Major CSS optimization and styling improvements across 11 stylesheet files
- Enhanced notification system with improved UI and cleanup mechanisms
- Refactored user profile management with better social link handling
- Improved breadcrumb navigation and forum display logic

### Removed
- Deprecated avatar system components (moved to extrachill-users plugin)
- Online users count functionality
- Unused utility functions and legacy code
- QR code dependency from composer.json

### Fixed
- Various bbPress template rendering issues
- JavaScript compatibility and performance optimizations
- User profile editing and verification flows
- Content filtering and recent feed display