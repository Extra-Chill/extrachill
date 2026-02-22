# Changelog

All notable changes to this project will be documented in this file.

This file records notable changes in this theme.

## [2.0.10] - 2026-02-22

### Fixed
- Fix pagination current page styling - skip button classes on non-clickable current page

## [2.0.9] - 2026-02-12

### Fixed
- Fix profile URLs: rename ec_get_user_profile_url to extrachill_get_user_profile_url

## [2.0.8] - 2026-01-30

### Fixed
- Fix archive title duplication and add artist name to filtered category titles

## [2.0.7] - 2026-01-28

### Changed
- Consolidate CSS - move button styles and components to style.css

## [2.0.6] - 2026-01-28

### Fixed
- Fix edge-to-edge layout for small screens

## [2.0.5] - 2026-01-27

### Changed
- Add changelog entry for 2.0.4
- Consolidate archive header styles from style.css to archive.css for conditional loading

## [2.0.3] - 2026-01-27

### Changed
- Add date, time, and search input styling to base form styles

## [2.0.2] - 2026-01-22

- Fixed mobile layout UX: Added 16px padding to breadcrumbs and card content at ≤400px breakpoint for comfortable reading margins while maintaining Mediavine ad compatibility

## [2.0.1] - 2026-01-22

- Small screens (≤400px) now go edge-to-edge on single posts for Mediavine 300px ad minimum compatibility

## [2.0.0] - 2026-01-19

### Changed
- Removed EC-specific taxonomy badge colors (moved to extrachill-multisite)
- Removed user badge CSS variables and classes (moved to extrachill-users)
- Removed festival_wire taxonomy registration (moved to extrachill-news-wire)
- Added extrachill_single_post_style_post_types filter for single post styling
- Added extrachill_sidebar_style_post_types filter for sidebar styling
- Added extrachill_sidebar_recent_posts_content filter for sidebar customization
- Added extrachill_filter_bar_category_items filter for filter bar customization
- Changed footer bottom menu default to empty array
- Changed related posts default taxonomies to category and post_tag
- Added function existence checks for graceful plugin degradation
- Updated README for standalone theme usage

## [1.3.13] - 2026-01-10

### Removed
- Theme-owned Google Tag Manager integration. GTM is now managed by the `extrachill-analytics` network plugin (and the `extrachill_gtm_container_id` network option).

## [1.3.12] - 2026-01-06

### Changed
- Refined taxonomy badge system (`inc/core/templates/taxonomy-badges.php`) to explicitly exclude internal/system taxonomies (WooCommerce product types, visibility, and shipping classes).
- Updated documentation across the codebase (README.md, docs/) to reflect the current platform architecture, including the addition of `horoscope.extrachill.com` (Blog ID 12).
- Simplified search and view-counting documentation to focus on the theme's role in the larger multisite ecosystem.
- Synchronized theme version constant and documentation references to v1.3.12.

## [1.3.11] - 2026-01-06

### Added
- Integrated the new `horoscope.extrachill.com` (Blog ID 12) into the multisite network documentation and blog context awareness systems.

### Changed
- Updated the social sharing system (`assets/js/share.js`) to use a more generic click tracking endpoint (`/wp-json/extrachill/v1/analytics/click`) and unified the data contract for share events.
- Refined the "Popularity" sorting mechanism to rely on `ec_post_views` meta keys managed by the `extrachill-analytics` plugin, removing the legacy theme-side async tracking script.
- Improved documentation throughout the codebase (README.md, AGENTS.md, docs/) to reflect the current platform architecture and remove legacy references to internal view tracking.

### Removed
- Legacy view-tracking script (`assets/js/view-tracking.js`) and theme-side tracking logic (`inc/core/view-counts.php`).
- Removed `network-dropdown.js` and consolidated lightweight dropdown behavior into `mini-dropdown.js`.

## [1.3.10] - 2026-01-04

### Changed
- Integrated share tracking into the social sharing system (`assets/js/share.js`). All social shares and clipboard copy actions are now tracked via the `/wp-json/extrachill/v1/analytics/share` REST API endpoint.

### Fixed
- Improved social sharing analytics by capturing share destination and source URL for all share actions.

## [1.3.9] - 2026-01-04

### Added
- Blocks Everywhere integration documentation in `AGENTS.md` with iframe asset enqueuing details for Gutenberg editor theming

### Removed
- View tracking system (`assets/js/view-tracking.js`, `inc/core/view-counts.php`)
- View tracking asset enqueuing from `inc/core/assets.php`
- View tracking include from `functions.php`

### Technical
- Removed `extrachill_enqueue_view_tracking()` function and related view tracking JavaScript localization
- Eliminated REST API endpoint dependency for async view counting
- Updated documentation to reflect 3 JavaScript files (down from 4)

## [1.3.8] - 2026-01-02

### Added
- Created `inc/core/analytics.php` to handle Google Tag Manager integration via `wp_head` and `wp_body_open` hooks
- Added support for `location-denver` taxonomy badge styling in `assets/css/taxonomy-badges.css`
- Added typography variables for line heights (`--line-height-tight`, `--line-height-base`, `--line-height-relaxed`) in `assets/css/root.css`

### Changed
- Moved GTM script and noscript snippet from `header.php` to `inc/core/analytics.php` for better modularity
- Updated `functions.php` to include `inc/core/analytics.php`
- Cleaned up `header.php` by removing inline GTM scripts and unnecessary dns-prefetch tags
- Refactored `README.md` to remove legacy "Troubleshooting" and "Installation" headers in favor of platform-aligned "Notes" and "Development Notes"
- Improved documentation clarity across `AGENTS.md`, `README.md`, and several files in `/docs` to reflect current multisite architecture (11 active sites)

### Fixed
- Corrected documentation regarding homepage template architecture and menu system behavior

## [1.3.7] - 2026-01-01

### Added
- `mini-dropdown.js` shared component for consistent dropdown and flyout behavior across the platform

### Changed
- Refactored search templates to utilize universal `archive.php` and `archive-header.php` for better consistency and reduced code duplication
- Updated share button JavaScript to depend on shared `mini-dropdown.js` component
- Improved filter bar form action to correctly target `home_url('/')` when on search results pages

### Removed
- Redundant `inc/archives/search/` directory and `network-dropdown.js` (functionality consolidated into shared components)

### Technical
- Enqueued and localized `extrachill-mini-dropdown` script in `inc/core/assets.php`
- Updated `template-router.php` to map search results to the primary archive template
- Synchronized theme version constant and documentation references to v1.3.7

## [1.3.6] - 2025-12-26

### Removed
- chill-custom.js (unused archive filtering functionality)
- extrachill_enqueue_archive_scripts() from asset loading

### Changed
- Updated documentation to reflect 4 JavaScript files (down from 5)

### Technical
- Removed assets/js/chill-custom.js (archive filtering code no longer needed)
- Removed archive script enqueuing function from inc/core/assets.php
- Updated AGENTS.md, README.md, and docs/asset-loading.md to reflect removed functionality

## [1.3.5] - 2025-12-26

### Added
- Athens location badge color (#BA0C2F) for Georgia taxonomy support
- Greenville location badge color (#2E5A3C) for South Carolina taxonomy support

### Removed
- Reading progress bar functionality (JavaScript, CSS, and enqueuing function)
- Notice system backward compatibility code for old singular transients and cookies

### Changed
- Simplified notice retrieval to only support plural system (extrachill_notices_)

### Technical
- Removed assets/js/reading-progress.js
- Removed extrachill_enqueue_reading_progress() from inc/core/assets.php
- Removed reading progress CSS from style.css
- Refactored extrachill_get_notices() to remove backward compatibility code
- Updated documentation to reflect removed functionality
- Fixed EXTRACHILL_THEME_VERSION sync in functions.php

## [1.3.4] - 2025-12-23

### Added
- News Wire link to network dropdown navigation for wire.extrachill.com access

### Changed
- Major documentation cleanup removing references to deprecated features (Spotify embeds, artist profile link, archive filter bar file)
- Consolidated filter bar component documentation to reflect current component-based architecture
- Removed Co-Authors Plus integration references from template components
- Updated blog ID references across documentation for network accuracy

### Technical
- Removed 472 lines of outdated documentation across 10 files
- Updated `docs/archive-functionality.md` to reflect filter bar component architecture
- Updated `docs/template-components.md` to document current filter bar API
- Updated `docs/action-hooks.md` to reflect current filter bar hooks

## [1.3.2] - 2025-12-20

### Removed
- Mediavine ad blocker settings from `header.php`, `inc/archives/archive.php`, and `inc/home/templates/front-page.php`

### Changed
- Improved main content grid layout by adding gap spacing via `gap: var(--spacing-lg)` in `style.css` for better visual separation between content and sidebar

### Added
- New location-savannah taxonomy badge color (`#13B554`) in `assets/css/taxonomy-badges.css`
- Comprehensive filter bar component documentation in `docs/template-components.md` including parameters, examples, and integration points
- Enhanced `docs/archive-functionality.md` to document filter bar component system and its reusability across the platform

### Technical
- Updated `AGENTS.md` to document 12 CSS files (including `filter-bar.css`) and components directory structure
- Updated `README.md` to reflect filter bar component system in feature overview and file structure
- Documentation alignment completed for filter bar component system introduced in v1.3.0

## [1.3.3] - 2025-12-22

### Removed
- Yoast SEO sitemap integration (`inc/core/yoast-stuff.php`)
- Noindex functionality for tags with fewer than 2 posts
- Image popup code from single post template

### Changed
- Enhanced single post template with proper featured image display and captions
- Synchronized theme version constant in `functions.php` with current version
- Updated documentation version references

### Technical
- Removed `yoast-stuff.php` from AGENTS.md file structure documentation

## [1.3.0] - 2025-12-17

### Added
- Universal filter bar component system in `/inc/components/filter-bar.php` for extensible archive filtering with plugin hooks
- Default filter bar items in `/inc/components/filter-bar-defaults.php` including category, child terms, artist, and sort dropdowns
- New filter bar CSS in `/assets/css/filter-bar.css` with responsive design and proper theming integration
- Admin menu cleanup: Hide Posts menu on non-main multisite blogs for improved admin UX

### Changed
- Refactored archive filtering system from hardcoded `/inc/archives/archive-filter-bar.php` and `/inc/archives/archive-child-terms-dropdown.php` to modular component-based architecture
- Updated `functions.php` to load new component files and include multisite admin menu logic
- Removed archive-specific sorting styles from `/assets/css/archive.css`

### Removed
- `/inc/archives/archive-child-terms-dropdown.php` (functionality relocated to components)
- `/inc/archives/archive-filter-bar.php` (replaced with universal component system)

### Technical
- Added `extrachill_filter_bar_items` filter for plugin extensibility
- Added `extrachill_archive_above_posts` action hook for filter bar placement
- Maintained backward compatibility through existing filter hooks and archive functionality

## [1.3.1] - 2025-12-19

### Changed
- Enhanced archive header layout with flexbox design for better title and action positioning
- Improved WooCommerce integration by preventing theme filter defaults on shop pages
- Updated archive functionality documentation to reflect current function names and plugin locations

### Technical
- Added `extrachill_archive_header_actions` hook for extensible archive header actions
- Restructured archive header HTML with responsive flex layout supporting WooCommerce taxonomies
- Added CSS for archive header row and actions with proper theming integration
- Updated artist profile integration documentation to use `ec_get_artist_profile_by_slug()` function

## [1.2.14] - 2025-12-15

### Removed
- Co-Authors Plus plugin integration from post meta and REST API
- Custom favicon functionality
- Content margin cleanup filter
- Auto-update theme prevention
- Password post filtering
- Admin menu hiding for Posts/Comments on non-main sites
- Customizer menu panel removal
- Artist profile link component
- Spotify embed support

### Changed
- Renamed SVG upload function for consistency
- Renamed sparse tags noindex function for consistency
- Added extrachill_post_meta_author filter for author display customization
- Updated documentation to remove content cleanup examples

### Technical
- Cleaned up functions.php by removing deprecated functionality
- Updated post-meta.php to use filter-based author display

## [1.2.13] - 2025-12-15

### Added
- Complete WordPress embed styling system with theme variables and responsive design
- Asset loading for embed iframes and blocks_everywhere plugin compatibility
- Support for bbPress embed script loading
- Number input type styling with spinner removal for better UX

### Changed
- Author link system now uses `ec_get_user_author_archive_url()` for article bylines with fallback
- Notice background color refined to neutral gray for better visual hierarchy
- Icon function code quality improvements and parameter spacing

### Technical
- Added `assets/css/embed.css` for embed iframe styling
- Enhanced `inc/core/assets.php` with embed and iframe asset management
- Updated template router to handle embed pages appropriately
- Documentation updated to reflect new author URL function usage

## [1.2.12] - 2025-12-13

### Added
- Enhanced post meta system with configurable components and improved forum post handling
- New filters: `extrachill_post_meta_parts` and `extrachill_post_meta_published_prefix`

### Changed
- Major refactor of post meta display logic with better security and structure
- Improved block editor CSS variable support

### Technical
- Refactored `inc/core/templates/post-meta.php` with comprehensive improvements
- Enhanced escaping and output sanitization in post meta functions

## [1.2.11] - 2025-12-13

### Added
- Block editor CSS variable support by enqueuing root styles on `enqueue_block_assets` hook
- Additional font format support with WilcoLoftSans-Treble.ttf and helvetica.ttf assets

### Technical
- Modified `inc/core/assets.php` to ensure CSS custom properties available in block editor
- Added TrueType font files to `assets/fonts/` directory

## [1.2.10] - 2025-12-12

### Added
- Filter hook for conditional jQuery frontend loading (`extrachill_dequeue_jquery_frontend`)
- Admin menu cleanup: Hide Posts and Comments menus on non-main multisite blogs

### Changed
- Refined dark mode accent hover color for better contrast
- Synchronized documentation version references

## [1.2.9] - 2025-12-11

### Changed
- Removed back home button styles from archive pages for cleaner design
- Improved editor title positioning and list styling for better content editing experience
- Updated card background color for improved visual hierarchy
- Enhanced 404 error page with breadcrumbs, documentation and tech support links
- Simplified pagination format for cleaner URLs
- Added Tech Support forum link to footer menu
- Updated form input backgrounds and error page link styling

### Technical
- Removed unused back home button styles from `assets/css/archive.css`
- Updated editor styles in `assets/css/editor-style.css` for better title centering and list display
- Changed `--card-background` from `#f8fafc` to `#f1f5f9` in `assets/css/root.css`
- Added `wp_dequeue_style( 'wp-block-library-theme' )` in `functions.php`
- Modified `inc/core/templates/404.php` to include breadcrumbs and helpful links
- Simplified pagination in `inc/core/templates/pagination.php` by removing format and add_args
- Added Tech Support link in `inc/footer/footer-main-menu.php`
- Updated form styles and added error-404-links in `style.css`

## [1.2.8] - 2025-12-09

### Changed
- Updated accent-2 color variable for improved visual consistency
- Standardized list-style from square to disc across editor and frontend styles
- Removed unused admin styles enqueuing function
- Updated README platform alignment documentation

### Technical
- Modified --accent-2 in assets/css/root.css from #a8c0d0 to #9fc5e8
- Changed list-style in assets/css/editor-style.css and style.css from square to disc
- Removed extrachill_enqueue_admin_styles function from inc/core/assets.php
- Updated README.md version references from 1.2.7 to 1.2.8

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
- Site masthead with sticky header
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
- Charleston Tin Roof venue badge color support
- Smooth hover transitions and subtle lift animation for taxonomy badges

### Changed
- Updated multisite network documentation with comprehensive site details
- Improved taxonomy badge user experience with CSS transitions

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
