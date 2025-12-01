# Changelog

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