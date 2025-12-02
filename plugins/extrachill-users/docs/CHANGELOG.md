# Changelog

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