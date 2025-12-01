# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2025-12-01

### Changed
- Refactored JavaScript from jQuery to vanilla JS for better performance and reduced dependencies
- Switched subscription forms from admin-ajax.php to REST API endpoints for improved security and maintainability
- Optimized asset loading with on-demand enqueuing strategy to reduce page load times
- Cleaned up CSS variables by removing fallback values for cleaner, more maintainable stylesheets
- Updated documentation for new architecture patterns and asset loading strategy
- Removed unused AJAX handlers file as functionality moved to REST API endpoints

### Fixed
- Improved error handling in subscription forms with better user feedback
- Enhanced CSS variable consistency across all stylesheet files

## [1.0.0] - 2025-11-XX

### Added
- Initial release with organized modular architecture
- Extracted newsletter functionality from ExtraChill theme
- Complete Sendy integration with centralized API configuration
- Multiple subscription forms (navigation, homepage, archive, content, footer)
- Template override system with plugin fallback
- Homepage-as-archive pattern for newsletter.extrachill.com
- Admin campaign management with push-to-Sendy functionality
- Integration system with declarative form registration
- Enhanced security with nonce verification and input sanitization
- Festival wire tip submission system with anti-spam protection
- Cloudflare Turnstile integration for spam prevention
- Rate limiting for tip submissions
- Centralized asset management with conditional loading
- Custom breadcrumb and post meta customization
- Sidebar widget for recent newsletters
- Responsive design and mobile optimization</content>
<parameter name="filePath">docs/CHANGELOG.md