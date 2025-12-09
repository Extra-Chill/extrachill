# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0/).

## [1.2.7] - 2025-12-09

### Changed
- Updated error color variable for improved visual consistency
- Added border styling to post content H2 headings for better hierarchy
- Improved form input styling with consistent padding and color properties
- Enhanced textarea styling with proper spacing and line height

### Technical
- Modified --error-color in assets/css/root.css from #ff6b6b to #ef4444
- Added .entry-content h2 styling in assets/css/single-post.css
- Updated form input and textarea styles in style.css for better consistency

## [1.2.6] - 2025-12-09

### Changed
- Refined color scheme with updated accent colors and button styling
- Improved responsive layout for single post cards on mobile devices
- Enhanced taxonomy badge padding for better visual consistency
- Updated footer menu to use centralized URL generation functions
- Added conditional page title display for template flexibility
- Minor CSS cleanup in 404 error page styling

### Added
- Documentation for data contracts and integration patterns

### Technical
- Modified CSS variables in root.css for improved color consistency
- Updated footer-main-menu.php to use ec_get_site_url() for multisite compatibility
- Added extrachill_show_page_title filter support in single-page.php

## [1.2.5] - 2025-12-08

### Changed
- Major CSS architecture refactor to use CSS custom properties for improved maintainability
- Added comprehensive design tokens for fonts, spacing, colors, and typography
- Restructured footer menu navigation and improved accessibility
- Added AGENTS.md development guidelines for coding standards

### Technical
- Refactored all CSS files to consume variables from root.css
- Added font family, size, and spacing variables for consistent theming
- Updated .gitignore to include AGENTS.md in version control

## [1.2.4] - 2025-12-08

### Added
- Expanded SVG icon sprite system with comprehensive UI and social media icons (chevron-down, share, cart, reply, bell, circle, users, at, plus, globe, link, video, calendar, clock, guitar, briefcase, igloo, facebook, instagram, x-twitter, youtube, pinterest, github, tiktok, spotify, soundcloud, bandcamp, twitch, qrcode)

### Changed
- Improved multisite flexibility by replacing hardcoded blog IDs with dynamic `ec_get_blog_id()` function calls in artist profile link and online users stats components

### Technical
- Updated `assets/fonts/extrachill.svg` with 30+ new icon symbols for enhanced UI capabilities
- Modified `inc/archives/artist-profile-link.php` to use `ec_get_blog_id('artist')` instead of hardcoded blog ID 4
- Modified `inc/footer/online-users-stats.php` to use `ec_get_blog_id('community')` instead of hardcoded blog ID 2

## [1.2.3] - 2025-12-07

### Changed
- Centralized theme version constant for consistent cache busting across assets
- Updated icon sprite versioning to use EXTRACHILL_THEME_VERSION instead of filemtime()
- Cleaned up documentation duplication in asset-loading.md

### Technical
- Added EXTRACHILL_THEME_VERSION constant in functions.php
- Modified ec_icon() function in inc/core/icons.php to use theme version for cache busting
- Removed duplicate asset directory structure sections from docs/asset-loading.md

## [1.2.2] - 2025-12-07

### Changed
- Simplified tag archive breadcrumbs by removing "Tags ›" prefix
- Added Documentation site to network dropdown navigation
- Added Documentation link to footer menu under About section
- Cleaned up footer newsletter CSS styles

### Technical
- Updated breadcrumb logic in `inc/core/templates/breadcrumbs.php`
- Added docs.extrachill.com to network dropdown in `inc/core/templates/network-dropdown.php`
- Modified footer menu structure in `inc/footer/footer-main-menu.php`
- Streamlined footer newsletter styles in `style.css`

## [1.2.1] - 2025-12-07

### Added
- Complete search overlay system with modal, close button, and accessibility features
- Site masthead with sticky header and reading progress indicator
- Secondary header navigation component with responsive design
- Enhanced breadcrumb styling and network dropdown integration
- Footer newsletter repositioned below menu columns
- New footer "About" menu column with contact and press links

### Changed
- Restructured footer menu layout with Network/Explore/About organization
- Improved input field styling by removing bottom margins
- Updated breadcrumb logic to target specific network dropdown spans
- Enhanced footer menu spacing and responsive behavior

### Removed
- nav.css stylesheet (consolidated into root.css)
- Unused navigation asset enqueuing

### Technical
- Added comprehensive search overlay CSS in style.css
- Added secondary header navigation styles
- Updated footer-main-menu.php with new structure and newsletter hook
- Modified breadcrumb template for improved network dropdown targeting
- Cleaned up asset loading in inc/core/assets.php

## [1.2.0] - 2025-12-07

### Added
- Network dropdown component for multisite navigation in homepage breadcrumbs
- QR code and download icons to SVG sprite system
- User, close, and chevron-down icons to SVG sprite system

### Changed
- Completely removed hamburger menu system and navigation toggle functionality
- Restructured footer menu with Network/Explore/About organization
- Reordered main navigation menu and added Events Calendar link
- Updated archive filter bar to use query vars instead of page templates
- Renamed `inc/header/navigation-menu.php` to `inc/header/header-search.php` for accurate naming
- Removed empty navigation template files `nav-main-menu.php` and `nav-bottom-menu.php`
- Cleaned up navigation hook documentation for removed functionality
- Streamlined SVG icon sprite by removing unused symbols (cart, reply, bell, etc.)
- Updated multisite site count from 8 to 9 active sites in documentation

### Removed
- Hamburger menu toggle container and associated JavaScript
- Navigation menu items container and menu toggle functionality
- Unused SVG icons from sprite system

### Technical
- Added `inc/core/templates/network-dropdown.php` with multisite site switcher functionality
- Added `assets/css/network-dropdown.css` for dropdown styling
- Added `assets/js/network-dropdown.js` for toggle and accessibility handling
- Updated footer menu structure in `inc/footer/footer-main-menu.php`
- Updated main navigation in `inc/header/nav-main-menu.php`
- Modified archive filter bar in `inc/archives/archive-filter-bar.php` to use query vars
- Refactored `assets/js/nav-menu.js` to remove hamburger menu logic and focus on search panel
- Updated SVG sprite opening tag for better compatibility

## [1.1.8] - 2025-12-07

### Added
- Network dropdown component for multisite navigation in homepage breadcrumbs
- QR code and download icons to SVG sprite system

### Changed
- Restructured footer menu with Network/Explore/About organization
- Reordered main navigation menu and added Events Calendar link
- Updated archive filter bar to use query vars instead of page templates
- Renamed `inc/header/navigation-menu.php` to `inc/header/header-search.php` for accurate naming
- Removed empty navigation template files `nav-main-menu.php` and `nav-bottom-menu.php`
- Cleaned up navigation hook documentation for removed functionality

### Technical
- Added `inc/core/templates/network-dropdown.php` with multisite site switcher functionality
- Added `assets/css/network-dropdown.css` for dropdown styling
- Added `assets/js/network-dropdown.js` for toggle and accessibility handling
- Updated footer menu structure in `inc/footer/footer-main-menu.php`
- Updated main navigation in `inc/header/nav-main-menu.php`
- Modified archive filter bar in `inc/archives/archive-filter-bar.php` to use query vars

## [1.1.7] - 2025-12-06

### Added
- QR code and download icons to SVG sprite system

### Changed
- Removed edit link from single page template for cleaner layout

### Technical
- Added `qrcode` and `download` symbols to `assets/fonts/extrachill.svg`
- Removed `.entry-footer` section from `inc/single/single-page.php`

## [1.1.6] - 2025-12-06

### Changed
- **View Tracking**: Removed editor/admin user capability exclusion to enable view counting for all user roles
- Updated docblock in `inc/core/view-counts.php` to reflect view tracking applies to all users, not just visitors
- Removed capability check guard in `extrachill_enqueue_view_tracking()` function in `inc/core/assets.php`

### Technical
- Modified `inc/core/assets.php` to allow editors and admins to participate in view count statistics
- Updated `inc/core/view-counts.php` function `ec_track_post_views()` to remove `current_user_can('edit_others_posts')` check
- View tracking now applies uniformly across all user roles for more accurate metrics collection

## [1.1.5] - 2025-12-05

### Added
- Array-based pagination support for custom queries with dynamic item labels

### Changed
- Enhanced pagination button styling with consistent CSS classes

## [1.1.4] - 2025-12-05

### Added
- Enhanced notice system supporting multiple notices, anonymous user cookies, and action buttons
- Improved 404 page with contact link suggestion
- Rewrote emergency fallback template with detailed error information and contact button

### Changed
- Documentation updates and footer menu refinements

### Technical
- Refactored inc/core/notices.php for multiple notice support
- Updated inc/core/templates/404.php with better UX
- Redesigned index.php fallback template

## [1.1.3] - 2025-12-04

### Added
- Share button JavaScript functionality with clipboard copy and social sharing
- Centralized notice system for user feedback across the platform

### Changed
- Improved shared tabs CSS padding for better mobile display
- Updated archive filter bar to use query vars instead of page templates
- Enhanced documentation across multiple files for accuracy
- Minor code formatting improvements in core files

### Technical
- Added assets/js/share.js for share button interactions
- Added inc/core/notices.php for unified notice display
- Updated share template to use new JavaScript functionality
- Comprehensive documentation updates in 12+ files

## [1.1.2] - 2025-12-04

### Added
- New `extrachill_after_homepage_content` action hook in homepage template for enhanced plugin extensibility

### Changed
- Improved notice system CSS with rem units and consistent spacing
- Updated homepage documentation to reflect unified content hook architecture
- Enhanced secondary header navigation documentation with practical filter examples

### Removed
- CLAUDE.md development guidance file (development artifact)

### Technical
- Added action hook integration in `inc/home/templates/front-page.php`
- Updated CSS units from em to rem for better consistency
- Comprehensive documentation updates across 12 files, including root README and `/docs/`

## [1.1.1] - 2025-12-04

### Changed
- **Archive System Refactor**: Replaced custom page template system with query var-based blog archive functionality
- **Community Activity Optimization**: Simplified activity queries to only fetch from community.extrachill.com
- **Asset Loading**: Updated archive CSS loading conditions to use `extrachill_blog_archive` query var

### Removed
- `page-templates/all-posts.php` custom page template
- `CLAUDE.md` development guidance file
- Page template specific CSS styles

### Technical
- Replaced `is_page_template('page-templates/all-posts.php')` checks with `get_query_var('extrachill_blog_archive')`
- Updated archive filter bar to use `/all/` URL structure
- Simplified community activity data fetching logic

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
- Updated multisite documentation to reflect 9 active sites
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
