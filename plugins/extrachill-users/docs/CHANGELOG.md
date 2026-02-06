# Changelog

## Unreleased

### Changed
- Add forgot password link and register prompt to login form

## [0.6.0] - 2026-01-27

### Changed
- Add Abilities API integration for welcome email timing fix

## [0.5.15] - 2026-01-25

### Fixed
- Fixed analytics tracking with correct WordPress 6.9 Abilities API

## [0.5.14] - 2026-01-25

### Fixed
- Fixed analytics tracking timing issue with Abilities API

## [0.5.13] - 2026-01-23

- Add direct analytics tracking via Abilities API

## [0.5.11] - 2026-01-22

- Fixed shared-tabs assets not loading for login-register block on non-singular pages

## [0.5.10] - 2026-01-19

### Added
 - Added user badge CSS with color variables (--artist-badge-color, --team-badge-color, --professional-badge-color)
 - Added online users stats footer component
- Added PHPUnit test suite with bearer auth, user creation, password validation, tokens, and username generation tests
- Improved rank tier system with expanded level structure
- Refactored avatar display, menu items, and menu rendering
- Improved auth token service and bearer authentication
- Enhanced Google OAuth integration and JWT RS256 handling
- Improved admin access control and shop permissions
- Refactored registration emails and comment auto-approval
- Replaced AGENTS.md with CLAUDE.md documentation

## [0.5.9] - 2026-01-06

### Changed
- **Shop Management Permissions**: Restricted `ec_can_manage_shop()` to users with `manage_options` capability until public release.
- **Shop Product Count**: Replaced dummy count with a native cross-site `WP_Query` to the shop blog (Blog ID 3) for accurate user-artist product tracking.

### Documentation
- Updated `CLAUDE.md` with current cross-site artist linking architecture details.

## [0.5.8] - 2026-01-05

### Changed
- **Decommissioned Profile URL Resolution**: Removed the `inc/author-links.php` logic as profile resolution has been migrated to the `extrachill-multisite` plugin for better architectural consolidation.
- **Artist Profile Lookup**: Migrated `ec_get_artist_profile_by_slug()` to `extrachill-multisite` plugin; added a pointer comment in `inc/artist-profiles.php`.

## [0.5.7] - 2026-01-04

### Changed
- **Decommissioned Legacy Membership UI**: Removed the legacy `network-memberships.php` administrative interface.
- **Admin Tools Migration**: Lifetime Membership management is now handled exclusively by the `extrachill-admin-tools` plugin's React-based interface via REST API.

## [0.5.6] - 2025-12-22

### Added
- Registration source and method tracking across all authentication flows (standard, Google OAuth)
- User meta storage for `registration_source` (web, extrachill-app) and `registration_method` (standard, google)
- Enhanced admin notification emails to include registration source and method details

### Changed
- Browser handoff system now uses site transients for network-wide compatibility
- Browser handoff handler updated to use admin_post hooks with proper redirect security
- Registration data processing enhanced with new metadata fields and app client defaults

### Technical
- Added `registration_source` and `registration_method` parameters to user creation workflow
- Improved browser handoff token security with allowed_redirect_hosts filtering
- Enhanced payload validation and sanitization for registration metadata

## [0.5.5] - 2025-12-22

### Added
- Browser handoff token system for mobile app authentication integration
- Single-use tokens with 60-second expiration for secure cookie bootstrapping
- Browser handoff handler with URL validation and automatic redirect

### Changed
- Updated site references in documentation: Blog ID 11 designated as "wire" and Blog ID 12 designated as "horoscope"

## [0.5.4] - 2025-12-21

### Added
- Avatar menu items builder function `extrachill_users_get_avatar_menu_items()` for centralized menu construction with priority-based sorting
- Shop permission helpers: `ec_can_manage_shop()` and `ec_get_shop_product_count_for_user()` for unified shop access control
- Mediavine ad blocklist output with global ad-free checking and site-specific override via `extrachill_should_block_ads` filter

### Changed
- Refactored avatar menu display to use new canonical menu items builder instead of inline logic
- Improved REST root URL detection in auth-utils.js with fallback chain and URL normalization for robust multisite support
- Added REST request detection guards to login handlers to prevent interference with REST API authentication endpoints
- Simplified admin notification emails by removing obsolete onboarding status tracking fields

### Technical
- New `inc/avatar-menu-items.php` file with centralized menu item builder logic
- New `inc/shop-permissions.php` file with shop permission checking functions
- Enhanced auth-utils.js with `normalizeRestRoot()` helper for proper URL handling across different multisite configurations

## [0.5.3] - 2025-12-20

### Added
- Minimum password length requirement (8 characters) across registration forms
- Turnstile captcha bypass for mobile app clients and local development environments
- Auto-login functionality after onboarding completion

### Changed
- Standardized CSS variable usage in onboarding block styles
- Refined Google sign-in button styling (fixed width, improved centering)

### Fixed
- Enhanced turnstile widget validation with existence checks
- Added minlength attribute to password input fields

### Documentation
- Updated CLAUDE.md with comprehensive Google OAuth and onboarding system documentation
- Enhanced README.md feature descriptions
- Updated implementation status and added user management documentation

## [0.5.2] - 2025-12-19

### Fixed
- Removed redundant "Sign up here" link from login tab in login/register block
- Added editor preview check in onboarding block to prevent rendering in admin/REST contexts
- Corrected Google OAuth API endpoint path in authentication utilities
- Added fallback refresh token table creation for existing plugin installations

## [0.5.1] - 2025-12-19

### Changed
- Refactored onboarding block to use WordPress build system with ES modules instead of legacy inline registration
- Updated build process to compile onboarding block and generate build assets
- Added automatic onboarding page creation during plugin activation on community site

## [0.5.0] - 2025-12-19

### Added
- Google OAuth authentication system with Sign-In buttons and RS256 ID token verification
- User onboarding system with post-registration username and artist/professional flag setup
- Onboarding Gutenberg block for streamlined user setup flow
- Shared authentication utilities (auth-utils.js) for common auth functions
- Enhanced avatar menu with artist management, link pages, and shop options
- OAuth infrastructure with RS256 ID token verification and Google service integration

### Changed
- Simplified registration form to email/password only (username and artist flags moved to onboarding)
- Registration flow now redirects to onboarding page after account creation
- Auto-generated usernames from email addresses
- Updated admin notification emails to reflect onboarding status

### Technical
- Added `inc/oauth/` directory with Google OAuth services
- Added `inc/onboarding/` directory with onboarding handlers
- Added `blocks/onboarding/` Gutenberg block
- New JavaScript files: `assets/js/auth-utils.js`, `assets/js/google-signin.js`
- Documentation: `docs/PLAN-onboarding-system.md`

## [0.4.3] - 2025-12-18

### Added
- `ec_get_artist_profile_by_slug()` function for network-wide artist profile lookup by taxonomy term slug
- Enhanced artist profile resolution with proper multisite blog switching and input validation

### Technical
- Improved artist profile relationship functions with canonical slug-to-post mapping

## [0.4.1] - 2025-12-17

### Added
- Network-wide automatic login page creation on plugin activation
- Fallback login page creation for new sites added after initial activation

### Changed
- Refactored plugin activation logic into dedicated `inc/core/activation.php` file
- Replaced BuddyPress artist invitation functions with native ExtraChill functions (`ec_get_pending_invitations`, `ec_add_artist_membership`, `ec_remove_pending_invitation`)
- Updated invitation URL parameter from `bp_accept_invite` to `ec_accept_invite`

### Technical
- Improved code organization by extracting activation logic to separate file
- Removed BuddyPress dependency for artist invitation handling
- Enhanced multisite setup automation with automatic login page creation

## [0.4.0] - 2025-12-16

### Added
- Auth token system with access tokens (15min TTL) and refresh tokens (30 days TTL)
- Device-based authentication with UUID v4 device tracking
- Network-wide refresh token storage in dedicated database table
- User badges system for artists, professionals, and team members
- Point-based rank system with 22 tiers from "Dew" to "Frozen Deep Space"
- REST API integration for login, register, and token refresh endpoints
- Mobile app authentication foundation with device management
- "Remember me" checkbox in login form
- Centralized notice system for authentication forms
- Refresh token table auto-installation on plugin activation

### Changed
- Login and registration now use REST API instead of admin-post forms
- Password reset auto-login now sets persistent auth cookie (matches registration)
- Profile URL resolution now prioritizes community profiles over author archives
- Authentication JavaScript completely rewritten for token-based auth
- CSS cleaned up to rely on theme form tokens
- Password reset error handling uses centralized notices and redirects

### Technical
- Added `inc/auth-tokens/` directory with database, service, and token helpers
- Added `inc/badges/user-badges.php` for structured user badge resolution
- Added `inc/rank-system/rank-tiers.php` for point-based ranking
- Refactored profile URL functions with explicit community and author archive helpers
- Updated plugin dependencies to include extrachill-api

## [0.3.4] - 2025-12-10

### Added
- Validation requiring artist or professional selection in join flow registration

### Changed
- Simplified avatar menu management URLs by removing pre-selection of latest artist

## [0.3.5] - 2025-12-11

### Added
- `ec_can_manage_artist()` function for network-wide artist profile permission checking

### Changed
- Updated avatar menu "Create Artist Profile" link to use dynamic site URL resolution
- Enhanced welcome email with improved link organization and added help resources (Contact Us, Tech Support)

### Technical
- Improved URL maintainability in avatar menu system
- Enhanced user onboarding experience in registration emails

## [0.3.3] - 2025-12-08

### Changed
- Replaced all hardcoded site URLs with dynamic `ec_get_site_url()` function calls for improved maintainability
- Updated CSS to use CSS variables for font sizes instead of hardcoded values
- Enhanced avatar display system with null safety checks for community blog ID
- Simplified avatar menu icon rendering by removing unused CSS classes

### Added
- New user management system overview documentation (`docs/user-management.md`)

### Technical
- Refactored URL handling in: login-register block, password reset system, author links, avatar menu, and registration emails
- Improved CSS maintainability with variable-based font sizing

## [0.3.2] - 2025-12-08

### Added
- `extrachill_new_user_registered` action hook fired after successful user creation for plugin extensibility

### Changed
- Removed fallback handling for `ec_icon()` function in avatar menu system - now always uses `ec_icon('user', 'avatar-default-icon')`
- Eliminated redundant fallback code following architectural principles

### Fixed
- PHP syntax error in avatar-display.php (removed unmatched closing brace)

## [0.3.1] - 2025-12-08

### Changed
- Replaced hardcoded blog IDs with dynamic `ec_get_blog_id()` lookups across all multisite operations for improved maintainability
- Enhanced security in redirect handler with proper `wp_unslash()` usage on REQUEST_URI
- Added validation for registration source URL with user-friendly error messaging
- Improved admin notification emails with better artist/professional flag handling
- Reordered user meta updates to prioritize registration page tracking

### Technical
- Refactored blog switching in: artist-profiles.php, author-links.php, avatar-display.php, online-users.php, user-creation.php, team-members.php
- Added fallback handling for missing `ec_get_blog_id()` function to maintain backward compatibility

## [0.3.0] - 2025-12-07

### Added
- Avatar menu now displays for logged-out users with login/register links
- Comprehensive accessibility improvements to avatar menu (ARIA attributes, keyboard navigation, screen reader support)
- Default user icon display for logged-out state using ec_icon() function

### Changed
- Refactored avatar menu from link-based to button-based toggle with proper semantic markup
- Removed redundant headings and descriptions from login/register block forms
- Reduced form container margins for better spacing (30px → 10px)
- Updated CLAUDE.md to reflect 9 active sites (Blog IDs 1–5, 7–11) with docs at Blog ID 10

### Accessibility
- Added aria-expanded attribute to avatar menu toggle button
- Implemented keyboard support (Enter/Space) for menu activation
- Added screen reader text for menu toggle
- Enhanced focus management and visual focus indicators
- Added role="menu" to dropdown container

## [0.2.4] - 2025-12-05

### Changed
- Relocated avatar upload UI functionality to extrachill-community plugin for better architectural separation of concerns
- Removed `inc/avatar-upload.php` file (avatar upload UI now provided by extrachill-community plugin)
- Removed `assets/js/avatar-upload.js` (avatar upload JavaScript now provided by extrachill-community plugin)
- Updated plugin initialization to remove require_once for avatar-upload.php
- Refactored include file loading order in main plugin file to prioritize authentication handlers

### Refactoring
- Consolidated avatar handling: extrachill-users provides network-wide display logic, extrachill-community provides upload UI
- Both plugins now use unified REST API endpoint `/wp-json/extrachill/v1/media` from extrachill-api for all avatar operations
- Cleaner separation of concerns between plugins following KISS (Keep It Simple, Stupid) principle
- Reduced modular organization from 18 to 17 include files

### Documentation
- Updated CLAUDE.md to reflect consolidated avatar system architecture
- Updated plugin loading order documentation to reflect include file reorganization
- Clarified that avatar upload interface now lives exclusively in extrachill-community plugin
- Added note that both plugins use centralized REST API endpoint for upload operations

## [0.2.3] - 2025-12-05

### Changed
- Refactored avatar upload system to use unified REST media endpoint (`/wp-json/extrachill/v1/media`) instead of dedicated user avatar endpoint
- Removed `extrachill_process_avatar_upload()` function from `inc/avatar-upload.php` - processing logic now handled by unified media endpoint in extrachill-api plugin
- Updated avatar upload JavaScript to include context and target_id parameters for unified endpoint
- Enhanced error handling in avatar upload script with additional safety checks

### Technical
- Consolidated media upload handling across plugins for better maintainability
- Updated `inc/avatar-upload.php` to focus solely on UI rendering and asset loading

## [0.2.2] - 2025-12-05

### Fixed
- Improved avatar upload nonce handling by using dedicated REST nonce instead of wpApiSettings
- Removed unnecessary wp-api script enqueue from avatar upload assets

## [0.2.1] - 2025-12-05

### Added
- `ec_get_latest_artist_for_user()` function to determine most recently active artist profile based on link page modification times
- `ec_get_link_page_count_for_user()` function to count link pages across all user artist profiles
- Enhanced logged-in user display in login/register block with avatar card and improved action buttons
- Comprehensive CLAUDE.md documentation file consolidating all architectural and development information
- Dynamic avatar menu labels that adapt based on number of artists and link pages

### Changed
- Improved error handling in avatar upload JavaScript with better fetch API response processing
- Refactored avatar menu system to leverage new artist utility functions for cleaner code
- Enhanced EC_Redirect_Handler integration with theme notice system for consistent messaging
- Simplified registration form by removing redundant explanatory text from checkboxes
- Updated README.md to reference new CLAUDE.md documentation and highlight v0.2.0 features

### Fixed
- Removed redundant message displays from password reset forms to prevent duplicate notifications
- Streamlined login/register block by removing unused notice rendering code

### Documentation
- Migrated from CLAUDE.md to comprehensive CLAUDE.md with complete plugin architecture documentation

## [0.2.0] - 2025-12-02

### Version Reset & Strategy
**Version Reset**: Intentionally reset from 1.1.1 to 0.1.1 to establish proper semantic versioning foundation. Previous versioning was inconsistent with semantic versioning principles. Starting fresh allows us to properly track architectural improvements and feature additions going forward.

### Added
- EC_Redirect_Handler class for centralized authentication flow management
- Direct block rendering for login/register and password reset forms
- Enhanced roster invitation token validation and artist profile integration
- REST API-compatible avatar upload system with vanilla JavaScript
- Improved mobile responsiveness with proper icon sprite handling

### Changed
- Major architectural refactor: moved authentication files to inc/auth/ directory
- Moved core business logic to inc/core/ directory for better organization
- Replaced jQuery AJAX with native fetch API for avatar uploads
- Removed CSS variable fallback values throughout stylesheets
- Enhanced error handling with centralized message system
- Improved nonce verification and input sanitization security

### Deprecated
- Flat file structure for authentication files (now organized in auth/ and core/ subdirectories)

### Fixed
- Improved mobile icon display using proper sprite system
- Enhanced form validation and error messaging consistency

### Security
- Enhanced CSRF protection with improved nonce verification
- Strengthened input sanitization across authentication forms
- Better security headers in REST API avatar upload endpoint

### Architecture
- **OOP Implementation**: Began implementing Object-Oriented Programming patterns with EC_Redirect_Handler class
- **Single Responsibility**: Improved code organization by separating authentication handlers from core business logic
- **Maintainability**: Enhanced code structure for better long-term maintenance and extensibility

## [0.1.1] - 2025-11-29

### Added
- Registration page and timestamp tracking for user analytics
- REST API-compatible avatar upload function for extrachill-api integration
- Team member access to wp-admin and admin bar

### Changed
- Refactored avatar upload system to use WordPress REST API instead of AJAX
- Updated plugin description to emphasize single source of truth role
- Enhanced admin notification emails with registration page information
- Improved CSS to use CSS variables without fallbacks
- Added consistent button styling across login/registration forms

### Fixed
- Removed jQuery dependency from avatar upload JavaScript
- Updated site count references to the active multisite sites (docs at Blog ID 10; wire at Blog ID 11; horoscope at Blog ID 12) in documentation

### Dependencies
- Added extrachill-api as required plugin
