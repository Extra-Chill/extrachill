# Changelog

## [1.1.1] - 2025-11-29

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
- Updated site count references from 9 to 10 sites in documentation

### Dependencies
- Added extrachill-api as required plugin