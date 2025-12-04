# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0/).

## [1.1.0] - 2025-12-04

### Changed
- **Homepage System Refactor**: Complete architectural shift from theme-provided homepage sections to plugin-provided content via single `extrachill_homepage_content` action hook
- **Navigation Restructure**: Reordered main navigation menu and completely restructured footer menu with Network/Explore/About organization
- **Template Router**: Removed homepage template filter override capability for cleaner plugin integration
- **Secondary Header System**: New extensible secondary header component with `extrachill_secondary_header_items` filter
- **Footer Bottom Menu**: Converted to filter-based system with `extrachill_footer_bottom_menu_items` filter
- **Taxonomy Badges**: Added `extrachill_taxonomy_badges_skip_term` filter for term exclusion control

### Removed
- Homepage section templates: hero.php, section-3x3-grid.php, section-about.php, section-extrachill-link.php, section-more-recent-posts.php
- Homepage queries functionality (homepage-queries.php)
- Home.css stylesheet (homepage styles now plugin responsibility)
- Default homepage action handlers from actions.php

### Added
- Secondary header component (`inc/header/secondary-header.php`) with priority-based item sorting
- Enhanced filter documentation for new extensibility points
- Updated asset loading and action hooks documentation

### Technical
- Simplified front-page.php template to single action hook container
- Removed home.css enqueuing from asset loading system
- Added secondary header rendering via `extrachill_after_header` action

## [1.0.9] - 2025-12-02

### Added
- Online Users Stats widget displaying network-wide online users count and total members in footer
- Intelligent taxonomy badge display ordering system with defined priority sequence
- Susto artist badge with custom color scheme (#20B3A9 background, #F4FFFF text)

### Changed
- Improved archive card title line height for better readability
- Enhanced taxonomy badge organization with systematic sorting (category, location, festival, venue, artist, post_tag)
- Updated documentation to reflect current version

### Technical
- Added `inc/footer/online-users-stats.php` for community statistics display
- Enhanced `inc/core/templates/taxonomy-badges.php` with sorting algorithm
- Integrated online users stats via `extrachill_before_footer` action hook

## [1.0.8] - 2025-12-01

### Added
- Cache busting for SVG icon sprites with filemtime() versioning

### Changed
- Improved mobile responsiveness for archive card titles
- Optimized homepage card padding for better visual hierarchy
- Enhanced breadcrumb display logic for pages vs posts
- Updated community forum navigation URLs
- Added frontend jQuery dequeue for performance optimization
- Improved CSS selector specificity for full-width-breakout elements

### Technical
- Updated icon system documentation to reflect ec_icon() helper usage
- Updated asset loading documentation for SVG sprite changes

## [1.0.7] - 2025-11-30

### Added
- SVG icon system with `ec_icon()` helper function
- Enhanced taxonomy badge hover effects and transitions

### Changed
- Refactored badge styling from `badge-colors.css` to comprehensive `taxonomy-badges.css`
- Updated multisite documentation to reflect 8 active sites
- Removed FontAwesome CDN dependency in favor of SVG sprites
- Cleaned up redundant CSS rules and improved consistency

### Technical
- Added `inc/core/icons.php` for centralized icon management
- Updated asset loading to conditionally load taxonomy badges CSS
- Removed fontawesome.svg and updated extrachill.svg sprite

## [1.0.6] - 2025-11-30

### Added
- Async view tracking system using REST API with navigator.sendBeacon() and fetch keepalive for reliable client-side analytics
- Charleston Tin Roof venue badge color support
- Smooth hover transitions and subtle lift animation for taxonomy badges

### Changed
- Enhanced view counting system documentation to reflect async implementation
- Updated multisite network documentation with comprehensive site details
- Improved taxonomy badge user experience with CSS transitions

### Technical
- Added assets/js/view-tracking.js for client-side view tracking
- Modified inc/core/assets.php to conditionally load view tracking script
- Updated inc/core/view-counts.php to remove wp_head hook in favor of async tracking

## [1.0.5] - 2025-11-29

### Changed
- Improved CSS variable consistency across all stylesheets by replacing hardcoded colors with CSS custom properties
- Simplified template structures in post cards, comments, search forms, and single post/page headers
- Enhanced accessibility by removing absolute positioning overlays from archive card titles
- Streamlined taxonomy badge display by removing unnecessary wrapper styles
- Updated artist profile button container to use CSS classes instead of inline styles
- Added venue badge colors for The Bounty Bar and Lo-Fi Brewing venues
- Cleaned up CSS comments and improved code organization in root.css
- Standardized color variable usage throughout navigation, search, sidebar, and single post stylesheets

## [1.0.4] - 2025-11-28

### Added
- Added Events Calendar link to main navigation menu before Festival Wire.
- Added Events Calendar link to footer menu in "The Latest" column.
- Added Events button to homepage hero section.

### Changed
- Default taxonomy badge colors changed to black and white for neutral styling.

## [1.0.3] - 2025-11-26

### Removed
- Removed `wpshapere_remove_dashicons_wordpress` function that was deregistering dashicons for non-logged-in users, preventing plugins from loading them.
- Removed dashicons dequeue from `extrachill_prevent_admin_styles_on_frontend` function.

## [1.0.2] - 2025-11-26

### Added
- Added `extrachill_override_related_posts_display` filter and `extrachill_custom_related_posts_display` action in `inc/single/related-posts.php` to allow plugins to completely override the related posts section.

### Changed
- Documentation updates and cleanup.

## [1.0.1] - 2025-11-25

### Removed
- Removed `add_target_blank_to_external_links` filter from `functions.php` to fix pagination issues and improve UX.
