# Changelog

## [0.3.4] - 2025-12-23

### Added
- Post meta filtering to hide author name from Festival Wire post type display
- New `includes/core/post-meta.php` module with `festival_wire_hide_author_from_meta` filter
- Filters `extrachill_post_meta_parts` hook to remove 'author' element for festival_wire posts

## [0.3.3] - 2025-12-23

### Fixed
- Added `global $post;` declaration in `templates/home-wire.php` before foreach loop to ensure proper `setup_postdata()` function usage and prevent potential post data issues

## [0.3.2] - 2025-12-22

### Changed
- Refactored `templates/home-wire.php` to use WordPress template patterns
- Removed redundant wrapper divs (main-content, site-main) duplicating theme structure
- Replaced multiple echo statements with direct HTML/PHP mixture for improved readability
- Added final newline for file compliance

## [0.3.1] - 2025-12-22

### Added
- Breadcrumb customization for wire site (blog ID 11) with dedicated overrides
- Homepage breadcrumb trail showing "News Wire"
- Archive and taxonomy breadcrumb trails with proper wire context
- Single post breadcrumb trails using post titles
- Modified back-to-home link label to "← Back to News Wire" on non-homepage pages
- Breadcrumb rendering in wire hub homepage template

## [0.3.0] - 2025-12-22

### Added
- Wire hub homepage functionality with dedicated template (`templates/home-wire.php`)
- Homepage content rendering for wire site with festival wire post grid display
- Conditional asset loading for wire site homepage integration

### Changed
- Replaced hardcoded `1rem` font sizes with CSS variable `var(--font-size-base)` in festival-wire.css (7 instances)
- Updated `.related-wire-title` font-size to use `var(--font-size-xl)` variable
- Modified forum CTA in archive template: updated title to "Join our Community!", revised description, changed link to use `ec_get_site_url('community')` dynamic URL
- Enhanced asset enqueue logic to support wire site homepage detection

### Fixed
- Improved CSS variable usage for consistent font sizing across components

### Note
- Corresponds to wire transfer from main blog site to dedicated domain (wire.extrachill.com)

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
