# Changelog

## [0.1.1] - 2025-12-03

### Removed
- Homepage ticker feature (moved to theme responsibility)
- jQuery dependency - plugin now uses vanilla JavaScript

### Changed
- Converted all JavaScript to vanilla JS for better performance
- Single post template now uses theme action hooks for consistent layout
- Related posts section uses theme's `.related-tax-section` pattern
- Simplified CSS by removing redundant styles inherited from theme
- Updated archive button styling to use theme's `button-1` class
- Updated forum CTA link to new URL structure

### Fixed
- Festival Wire posts now appear in author archives
- Festival Wire posts now excluded from location taxonomy archives

### Documentation
- Corrected build output documentation

## [0.1.0] - Initial Release
- Extracted from ExtraChill theme
- Custom post type for festival wire content
- AJAX-powered archive with load more functionality
- Community tip submission system
- Template override system
- Query filters for taxonomy integration
