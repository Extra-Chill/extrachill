# Changelog

## [1.0.10] - 2025-12-08

### Changed
- Replaced hardcoded blog IDs with dynamic lookups using `ec_get_blog_id()` function across all multisite integration points
- Added null safety checks and error handling for blog ID resolution
- Improved multisite configuration flexibility for artist platform, main site, and community site integrations

## [1.0.9] - 2025-12-07

### Added
- REST nonce setup in TinyMCE editor for improved security (`window.extrachillCommunityEditor` object)

### Changed
- Consolidated editor script loading in assets.php (removed duplicate enqueues and separate nonce function)
- Added `network-dropdown-target` CSS class to homepage breadcrumb for enhanced navigation

### Fixed
- Optimized TinyMCE editor dependency loading and nonce handling

## [1.0.8] - 2025-12-06

### Added
- New cache invalidation system (cache-invalidation.php) that automatically clears caches on bbPress events
- Comprehensive transient management for leaderboard, user points, forum stats, and recent feeds
- Edge cache purging support for Breeze/Varnish caching engines
- New `extrachill_get_leaderboard_users()` function with 5-minute transient caching for performance
- New `extrachill_get_leaderboard_total_users()` function with built-in caching
- Content cleanup permission check system to allow moderators/keymasters to bypass automatic content cleanup
- Enhanced TinyMCE editor dependency management with explicit script dependencies

### Changed
- Refactored asset loading in assets.php with improved editor dependency handling
- Extracted TinyMCE editor initialization to use inline scripts instead of wp_localize_script
- Simplified mentions.js reply click handler with cleaner data attribute usage
- Moved leaderboard caching logic from template to point-calculation.php for better code organization
- Improved permission handling for content cleanup with `ec_can_bypass_content_cleanup()` function

### Fixed
- TinyMCE editor now properly enqueues all required WordPress dependencies (editor, utils, underscore, wp-i18n, etc.)
- Editor availability detection now properly checks for function existence before calling bbPress conditionals
- Leaderboard pagination now benefits from proper caching and performance optimization

## [1.0.7] - 2025-12-05

### Changed
- Refactored TinyMCE image upload JavaScript to remove IIFE wrapper for cleaner initialization
- Added proper script dependencies to extrachill-mentions and custom-tinymce-plugin scripts
- Improved button styling in user settings with button-1 and button-large classes for better theme consistency

### Fixed
- Ensured proper script loading order with correct dependency declarations
- Simplified TinyMCE image upload plugin initialization (removed internal retry logic)

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