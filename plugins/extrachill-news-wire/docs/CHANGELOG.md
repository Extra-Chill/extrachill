# Changelog

## [0.2.1] - 2025-12-05

### Changed
- Replaced AJAX "Load More" button with native WordPress pagination using theme's `extrachill_pagination()` function
- Archive pages now use standard `?paged=2` URLs for better SEO and simpler architecture

### Removed
- Deleted `includes/festival-wire-ajax.php` - AJAX load more handler no longer needed
- Removed `initLoadMore()` function from `festival-wire.js`
- Removed AJAX localization (`festivalWireParams`) from asset enqueue

## [0.2.0] - 2025-12-04

### Removed
- Category and data_source taxonomy support from Festival Wire post type

### Changed
- Author archive integration is the only remaining cross-post-type inclusion for Festival Wire queries

### Fixed
- Category archive listings can no longer pull festival_wire posts

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
