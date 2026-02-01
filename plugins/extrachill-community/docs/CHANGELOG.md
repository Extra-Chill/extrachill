# Changelog

## [1.3.7] - 2026-02-01

### Fixed
- Fix profile reply/topic counts using raw database queries

## [1.3.6] - 2026-01-31

### Changed
- Add edge-to-edge forum cards on small screens (≤400px)

### Fixed
- Fix user profile reply count and add pagination to user content pages

## [1.3.5] - 2026-01-30

### Fixed
- Fix revision log spacing override from content list rules

## [1.3.4] - 2026-01-30

### Fixed
- Fix revision log showing unlogged edits, refine styling

## [1.3.3] - 2026-01-28

### Changed
- Standardize forum cross-site links with centralized renderer

## [1.3.2] - 2026-01-28

### Fixed
- Fix printf argument count mismatch in forum location links

## [1.3.1] - 2026-01-28

### Changed
- Remove redundant label from forum location cross-site links

## [1.3.0] - 2026-01-28

### Changed
- Remove hardcoded URL fallback in schema breadcrumbs
- Add location taxonomy support for bbPress forums

## [1.2.5] - 2026-01-27

### Changed
- Remove redundant link styling that blocked theme hover states

## [1.2.4] - 2026-01-27

### Added
- Enhanced reply card UI with footer actions

### Changed
- Version 1.2.3: Add schema breadcrumbs for community pages
- Version 1.2.2: Modularize TinyMCE CSS and update documentation
- Version 1.2.1
- Version 1.2.0: Inline reply system and TinyMCE refactoring
- Version 1.1.4: Subforum activity improvements and settings UI refinement
- Version 1.1.3: Artist access request API modernization
- Version 1.1.2: Filter bar integration and UI refactoring
- Version 1.1.1: Gutenberg block addition and JavaScript modernization
- Version 1.1.0: bbPress drafts and UTF-8 hotfix
- Version 1.0.17: Activation setup, homepage modal, editor compatibility
- Merge branch 'experimental/blocks-everywhere'
- Update AGENTS.md: Add branch strategy and document new topic modal
- Add Blocks Everywhere integration (experimental)
- Add new topic modal for homepage topic creation
- Version 1.0.16: Forum freshness improvements and cache invalidation enhancements
- Version 1.0.15: UI improvements and form layout enhancements
- Version 1.0.14: CSS standardization and template fixes
- Version 1.0.13: CSS standardization, responsive design improvements, and JavaScript enhancements
- Version 1.0.12: JavaScript architecture refactoring and bbPress UI consolidation
- Version 1.0.11: Dynamic blog ID resolution and multisite configuration improvements
- Version 1.0.10: Dynamic blog ID resolution and multisite configuration improvements
- Version bump to 1.0.9
- Version 1.0.8: Cache invalidation system, asset refactoring, and performance improvements
- Version 1.0.7: JavaScript refactoring and script dependency fixes
- Version 1.0.6: REST API integration for media uploads and avatar upload UI
- Version 1.0.5: Artist platform access integration and JavaScript improvements
- Version bump to 1.0.4
- Version 1.0.3: Navigation integration and recent feed refactoring
- Version 1.0.2: Enhanced reply card UI with footer actions
- Version 1.0.1: JavaScript migration, UI improvements, and feature enhancements
- jquery -> vanilla javascript migration in the system
- breadcrumbs update
- back to home link update
- many updates
- documentation alignment
- aligned button classes with theme styles
- Refactor user profile features, remove custom avatar functionality, and enhance bbpress integration
- replaced standard logged out reply form with login-register block
- working on splittling out artist platform from community for cleaner separation of concerns
- finishing the migration from theme to plugin. just sent it live. final bugfixes underway
- continued cleanup of community plugin.
- more cleanup and removal of unneccesary functionality. not quite done yet, but getting closer.
- removed settings page template, converted to use hook based system
- pulled out login register and other elements into components
- Comprehensive code updates and documentation alignment
- continued cleanup of theme functions and templates, transforming into plugin. still much to do.
- started migrating this theme to plugin. removed header and footer. that is the point of no return.
- Remove QR code dependency from composer.json
- modified recent feed to fix topic starter not showing
- Reducing bbPress dependency, fixing some bugs and adjusting styles
- removed most active users. removed hardcoded menu from notification avatar menu in favor of filter system for the artist platform to use
- Complete artist platform migration: Remove all band/link page functionality
- total theme reorganization
- Major wp_surgeon cleanup: Phase 1 - Function prefixes and file organization
- Update repository for migration 🤖 Generated with [Claude Code](https://claude.ai/code)
- Priority 1 cleanup: Remove dead code and misleading comments
- Comprehensive documentation alignment and architectural improvements
- Update documentation and fix theme architecture references
- Pre-migration backup: Add CLAUDE.md project documentation
- added subscribers featture, featured link, and social icon position option
- refactored live preview, stablized logic, cleaned up and optimized for future expansion. this version is stable as far as i'm aware, just has lots of debug logs
- working version
- launched extrachill.link
- band platform & link page functional
- added bank link page and more
- lots of changes, more to come
- added some css modularity
- footer matches blog
- paginated search... more
- customized search template to mimic bbpress format
- moved custom forum descriptions to their own file
- test
- implemented cross site upvote sync
- removed topic form from 1494
- no changes realy
- cross site login should work seamlessly now
- quick post feature added
- notifications to editng
- made user id available to upvote script
- bolded username for logged out users
- made work better on phones
- deleted - typo file name
- maxes out at 5 retries now
- moved to new js file
- hide if user is not logged in
- created for non technologically inclined
- added quick post
- duplicated, mistake, was messing me up
- made deshboard more engaging with stats and more detailed content, removed back to previous page link
- commented out posting tips
- added more links to welcome email
- this is my custom generatepress child theme for bbpress.

### Fixed
- fixed templating and styles to properly align with our new system
- fixed recent feed template to properly show replies and topic openers
- fixed homepage bug with collapsable forum
- fixing all errors related to migrating the artist platform into a plugin. some reorganization, lots more to be done
- fixed some errors and optimized some queries
- fixed broken link saves
- fixed the save once and for all
- fixed custom avatar uploads
- fixed instagram oEmbeds
- fixed follows . unfollows
- fixed registration to properly log in and set 6 month session token like all logins
- fixed comment count display for both dashboard and profile

## [1.2.3] - 2026-01-06

### Added
- Added `extrachill_seo_breadcrumb_items` handler for community pages to align schema breadcrumbs with the theme breadcrumb UI, including bbPress forum/topic/reply/user contexts.

## [1.2.2] - 2026-01-02

### Added
- New `tinymce-page.css` file for TinyMCE toolbar and UI styling, ensuring dark mode consistency when Blocks Everywhere is inactive.

### Changed
- Improved asset loading logic in `inc/core/assets.php` to conditionally enqueue `tinymce-page.css` only when Blocks Everywhere is inactive.
- Refactored `inc/assets/css/bbpress.css` by moving TinyMCE-specific toolbar and UI styles to the new `tinymce-page.css` for better modularity.
- Updated documentation (README.md, CLAUDE.md, docs/core-features.md) to reflect the latest file structure, JavaScript architecture, and integration patterns.
- Synchronized documentation regarding cross-site data aggregation and homepage content injection hooks.

### Fixed
- Corrected various documentation inaccuracies regarding script loading counts and integration methods.

## [1.2.1] - 2025-12-22

### Changed
- Updated CLAUDE.md documentation to clarify build system creates only ZIP file, not directory
- Changed artist access tab button classes from `button` to `button-1 button-medium` for theme consistency

## [1.2.0] - 2025-12-20

### Added
- Inline reply form system for bbPress topics with smart context-aware form repositioning
- Reply depth tracking with CSS custom properties for visual indentation of threaded replies
- New reply shim template (`bbpress/loop-single-reply.php`) for seamless threaded reply support
- New dedicated TinyMCE functionality file (`inc/assets/js/bbpress-tinymce.js`) consolidating mentions and autosave
- User @mention display in reply form legend when replying to specific users
- Cancel button for inline reply form to restore bottom form position
- Responsive mobile styles for nested replies and inline reply forms

### Changed
- Major refactoring of `inc/assets/js/bbpress-ui.js`: removed TinyMCE autosave logic, added inline reply system with form repositioning
- Enhanced `bbpress/form-reply.php` with dynamic reply legend showing `@username` context and reply action wrapper
- Improved `bbpress/loop-single-reply-card.php` with depth tracking via `data-depth` attribute and CSS custom properties
- Updated CSS styling in `inc/assets/css/replies-loop.css` with nested reply compression and mobile responsiveness
- Consolidated reply author display in `bbpress/topic-sidebar.php` (removed explicit avatar size)
- Enhanced `inc/assets/css/home.css` to include `.bbp-forum-activity-location` selector
- TinyMCE mentions functionality now in separate `bbpress-tinymce.js` file for better organization

### Removed
- TinyMCE autosave handler from `bbpress-ui.js` (moved to `bbpress-tinymce.js`)
- `inc/assets/js/extrachill-mentions.js` (consolidated into `bbpress-tinymce.js`)
- `.bbp-submit-button` margin style (inline form actions handle spacing)

### Fixed
- Better visual hierarchy for nested replies using CSS custom property-based depth tracking
- Improved responsive design for reply cards on mobile devices
- Cleaner reply form context with @mention legend for targeted replies

## [1.1.4] - 2025-12-20

### Changed
- Improved subforum ordering to display most recently active subforums first using `_bbp_last_active_time` meta
- Enhanced forum activity display to show which subforum contains recent activity when nested

### Fixed
- Removed duplicate "Settings" page heading in user settings page

## [1.1.3] - 2025-12-18

### Changed
- Improved artist access request approval system to use REST API endpoint instead of admin-ajax.php for better security and modern API patterns
- Enhanced artist access request authentication with token-based system replacing nonce-based approach

## [1.1.2] - 2025-12-17

### Added
- New filter bar integration (`inc/core/filter-bar.php`) that provides sorting (Recent, Upvotes, Popular) and search functionality for bbPress topics using the theme's universal filter bar system
- Integration with theme's `extrachill_filter_bar_items` filter for consistent UI across the platform

### Changed
- Major refactoring of bbPress topics loop: removed hardcoded sorting/search UI from `bbpress/loop-topics.php` and replaced with theme filter bar integration
- Removed sorting/search CSS (~140 lines) from `inc/assets/css/topics-loop.css` as functionality moved to theme's filter bar
- Removed sort select JavaScript handler from `inc/assets/js/bbpress-ui.js` (handled by theme filter bar)
- Updated artist management URLs from `/manage-artist-profiles/` to `/manage-artist/` in `bbpress/form-user-edit.php` and `bbpress/user-profile.php`
- Updated button text from "Manage Artist Profiles" to "Manage Artist" for consistency
- Updated CLAUDE.md to reflect Blocks Everywhere as production-ready integration (removed experimental branch documentation)
- Enhanced `docs/content-editor.md` with comprehensive dual editor system documentation (Blocks Everywhere + TinyMCE fallback)

### Fixed
- Improved consistency between artist platform URLs and button text across user profile interfaces

## [1.1.1] - 2025-12-15

### Added
- New leaderboard Gutenberg block for enhanced WordPress editor integration
- Block-based leaderboard page content with configurable per-page settings

### Changed
- Complete JavaScript modernization to ES6+ standards across all frontend components
- Enhanced TinyMCE autosave functionality with improved draft handling and error management
- Standardized WordPress coding standards throughout codebase (spacing, formatting)
- Improved avatar upload UI with better error handling and user feedback
- Updated build system to include Gutenberg block compilation

### Fixed
- Better error handling in JavaScript components with async/await patterns
- Improved CSS styling for TinyMCE editor and user profile components

## [1.1.0] - 2025-12-14

### Added
- bbPress draft functionality with server-backed autosave for topics and replies
- UTF-8 display name hotfix for WordPress 6.9+ compatibility
- Enhanced TinyMCE autosave with improved draft handling and reduced save delay

### Changed
- Improved user profile author URL generation using centralized functions
- Updated plugin file loading counts (39 total files)
- Enhanced JavaScript initialization for better draft management

## [1.0.17] - 2025-12-11

### Added
- Plugin activation handler that auto-creates the core community pages and starter forums
- New Topic modal on the homepage (CSS + JS + template component) for creating bbPress topics without leaving the forum archive
- Blocks Everywhere compatibility layer to enable Gutenberg for bbPress when the Blocks Everywhere plugin is active

### Changed
- Homepage now enqueues modal assets on the front page and treats the editor as active for modal usage
- Avatar upload UI now displays the current avatar using `get_avatar()`
- User profile templates now link to the artist platform "create artist" flow when eligible
- Image inline-style stripping now removes DOMDocument XML preamble output

## [1.0.16] - 2025-12-10

### Changed
- Improved forum freshness display with direct timestamp resolution and proper author link handling
- Refactored forum freshness calculation to use recursive timestamp checking across subforum hierarchies
- Enhanced cache invalidation to update parent forum last active times when subforum activity occurs
- Separated artist access request processing from general settings form handling
- Removed unused CSS for community latest post lists and jump-to-latest button

### Fixed
- Better forum hierarchy freshness detection and display
- Improved settings form processing flow and validation

## [1.0.15] - 2025-12-10

### Changed
- Improved bbPress form layouts by removing line breaks from form labels for better responsive design
- Enhanced CSS styling for form controls and focus states across bbPress forms
- Simplified content filtering by removing Apple/Word markup cleanup functionality
- Added topic edit breadcrumb support for improved navigation
- Hidden topic and reply counts from subforum listings for cleaner UI

### Fixed
- Better responsive design for bbPress form elements

## [1.0.14] - 2025-12-09

### Changed
- Standardized CSS font sizes using CSS custom properties across all stylesheets for better consistency
- Improved CSS indentation and formatting consistency

### Fixed
- Removed unused variables in artist management URL construction in user profile template

## [1.0.13] - 2025-12-09

### Changed
- Improved forum display layout: converted from ul/li structure to CSS Grid for better responsive design
- Standardized CSS font sizes using CSS custom properties (var(--font-size-base)) across all stylesheets
- Enhanced JavaScript initialization with improved DOM ready state handling for reply functionality
- Consolidated CSS indentation and color variable usage for consistency
- Removed jQuery dependency from mentions script and improved TinyMCE plugin loading

### Fixed
- Better responsive design for forum cards on mobile and tablet devices
- Improved CSS Grid layout for homepage forum display

## [1.0.12] - 2025-12-08

### Added
- New bbpress-ui.js file consolidating bbPress UI handlers (jump-to-latest, sort auto-submit, TinyMCE autosave)
- Enhanced multisite configuration with improved error handling for blog ID resolution
- Consolidated TinyMCE editor setup with better dependency management

### Changed
- Major JavaScript architecture refactoring: separated reply handlers, removed jQuery dependencies
- Removed inline JavaScript from bbpress.php template, moved to dedicated bbpress-ui.js
- Updated plugin file counts: 36 total files loaded (core: 8, content: 6, social: 11, user-profiles: 7, home: 4)
- Improved content filtering with Apple/Word markup cleanup
- Enhanced multisite blog switching with null safety checks

### Removed
- Deprecated user-mention-api.php file (moved to extrachill-api plugin)
- Admin-ajax upvote handler (consolidated to REST API)
- Duplicate TinyMCE setup functions and jQuery dependency checks

## [1.0.11] - 2025-12-08

### Changed
- Replaced hardcoded blog IDs with dynamic lookups using `ec_get_blog_id()` function across all multisite integration points
- Added null safety checks and error handling for blog ID resolution in user profile editing, template routing, breadcrumb navigation, mention notifications, and notification cleanup
- Improved multisite configuration flexibility for artist platform, main site, and community site integrations
- Enhanced error handling in cross-site data aggregation and blog switching operations

### Added
- Comprehensive documentation for core plugin features and forum badges system
- Enhanced CSS styling for reply cards and content truncation in recent activity feeds

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
- Updated documentation references from CLAUDE.md to CLAUDE.md

### Removed
- CLAUDE.md documentation file (superseded by CLAUDE.md)
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
