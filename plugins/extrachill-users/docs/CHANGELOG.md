# Changelog

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
- Comprehensive AGENTS.md documentation file consolidating all architectural and development information
- Dynamic avatar menu labels that adapt based on number of artists and link pages

### Changed
- Improved error handling in avatar upload JavaScript with better fetch API response processing
- Refactored avatar menu system to leverage new artist utility functions for cleaner code
- Enhanced EC_Redirect_Handler integration with theme notice system for consistent messaging
- Simplified registration form by removing redundant explanatory text from checkboxes
- Updated README.md to reference new AGENTS.md documentation and highlight v0.2.0 features

### Fixed
- Removed redundant message displays from password reset forms to prevent duplicate notifications
- Streamlined login/register block by removing unused notice rendering code

### Documentation
- Migrated from CLAUDE.md to comprehensive AGENTS.md with complete plugin architecture documentation

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
- Updated site count references from 9 to 8 active sites (note: horoscope planned for Blog ID 10) in documentation

### Dependencies
- Added extrachill-api as required plugin