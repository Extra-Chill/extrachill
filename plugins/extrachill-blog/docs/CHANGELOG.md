# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.0] - 2026-05-30

### Added
- add "From Around the Extra Chill Network" bridge on single posts

### Changed
- Rename coauthors REST field to coauthor_profiles to fix REST validation collision

### Fixed
- harden network-bridge type safety (json_encode guard, WP_Term check, cross-plugin function_exists)

## [0.3.15] - 2026-03-27

### Changed
- align homepage breakouts to theme edge shell
- remove shared blocks from blog plugin

## [0.3.14] - 2026-02-12

### Changed
- Remove vendor directory from git tracking
- Test message 1
- Bump version to 0.3.13 - Refactor homepage queries to use REST API
- Bump version to 0.3.12 - Remove redundant artist profile link logic
- Bump version to 0.3.11 - Refine homepage events section description
- Bump version to 0.3.10 - Restrict homepage event discovery to childless location terms
- Bump version to 0.3.9 - Implement dynamic network discovery grid on homepage
- Bump version to 0.3.8 - Refactor homepage CSS and cleanup redundant styles
- Bump version to 0.3.7 - Standardize CSS with theme variables
- Bump version to 0.3.6 - Add homepage search section
- Bump version to 0.3.5 - Refactor homepage to grid layout and add new content sections
- Bump version to 0.3.4 - Add single post share card
- Bump version to 0.3.3 - Add ads filtering, refactor artist profiles, and improve editor styles
- Bump version to 0.3.2 - Add artist profiles, co-authors integration, and admin customizations
- updated about section and some styles
- updated about section on homepage
- Bump version to 0.3.1 - Enhance block system with SSR and documentation
- gitignore node modules...
- Bump version to 0.3.0 - Add Gutenberg blocks system
- Bump version to 0.2.3
- Bump version to 0.2.2
- Bump version to 0.2.1
- Initial commit: extrachill-blog plugin v0.2.0

### Fixed
- Fix profile URL: rename ec_get_user_profile_url to extrachill_get_user_profile_url

## [0.3.13] - 2026-01-05

### Changed
- Refactored `extrachill_blog_get_location_event_counts()` and `extrachill_blog_get_wire_festival_counts()` to use internal REST API requests instead of direct multisite blog switching for better performance and reliability.

## [0.3.12] - 2026-01-05

### Changed
- Removed redundant `artist-profile-link.php` logic in favor of theme-level integration.

## [0.3.11] - 2026-01-05

### Changed
- Refined the events section description on the homepage to emphasize local event discovery and artist submissions.

## [0.3.10] - 2026-01-04

### Fixed
- Restricted homepage event discovery to childless location terms to prevent redundant parent category displays

## [0.3.9] - 2026-01-04

### Added
- Dynamic data-driven discovery components for the homepage network grid
- `extrachill_blog_get_location_event_counts()` function to fetch upcoming event counts from events.extrachill.com (Blog ID 7)
- `extrachill_blog_get_wire_festival_counts()` function to fetch festival wire post counts from wire.extrachill.com (Blog ID 11)
- Taxonomy badges with post counts for `section-events.php` and `section-wire.php`
- Dual-button CTA layouts with flexbox styling for `section-community.php` and `section-artist-platform.php`

### Changed
- Updated homepage architecture from static promotional cards to dynamic network discovery components
- Aligned documentation (CLAUDE.md, README.md, homepage-system.md) with new dynamic functionality

## [0.3.8] - 2026-01-02

### Changed
- Refactored homepage CSS for improved maintainability and adherence to theme standards
- Cleaned up redundant search and newsletter form styles in `assets/css/home.css`
- Standardized typography and spacing for 3x3 grid headers using theme variables
- Optimized homepage thumbnail hover effects and card transitions

## [0.3.7] - 2026-01-02

### Changed
- Migrated all homepage CSS styles to use theme-provided CSS custom properties (variables) for consistent typography, spacing, and colors
- Refactored `assets/css/home.css` with modernized layout patterns (CSS Grid) and improved responsiveness
- Updated homepage network cards with enhanced hover states and standardized padding/margins
- Standardized newsletter form styling within the homepage grid system

## [0.3.6] - 2026-01-02

### Added
- Homepage search section (`section-search.php`) with network-wide search functionality
- CSS styling for homepage search section in `assets/css/home.css`

### Changed
- Integrated search section into the homepage render hook after the grid layout

## [0.3.5] - 2026-01-02

### Added
- New homepage content sections: Artist Platform, Community, Events, Wire, and Docs
- Mobile-first CSS Grid layout for the homepage network section (`home-network-grid`)
- Unified card styling for homepage network components with hover effects and improved accessibility

### Changed
- Refactored homepage layout from flexbox to CSS Grid for better responsiveness and scalability
- Retired the legacy Extra Chill Link promo section in favor of the new Artist Platform component
- Updated About section styling and structure to align with the new grid system
- Updated all Gutenberg blocks to version 1.1.1

## [0.3.4] - 2026-01-02

### Added
- Share card component injected into single posts via `extrachill_after_post_content` (before the newsletter form)
- Frontend share card stylesheet `assets/css/share-card.css` registered on `wp_enqueue_scripts` and enqueued only on single posts

### Changed
- Homepage system documentation: updated archive filter bar ownership to the theme’s universal filter bar component

## [0.3.3] - 2025-12-20

### Added
- Mediavine blocklist rules for blog-specific ad filtering via `extrachill_should_block_ads` filter hook
- Blocks ads on homepage, pages, and search results; allows ads on archives

### Changed
- Refactored artist profile link integration to use centralized `ec_get_artist_profile_by_slug()` function for code reuse across sites
- Updated artist profile button hook from `extrachill_archive_filter_bar` to `extrachill_archive_header_actions` for improved theme integration
- Improved artist profile link component with enhanced type checking and security

### Fixed
- Placeholder text styling in trivia block editor using CSS variables for consistent appearance

## [0.3.2] - 2025-12-16

### Added
- Artist profile integration on taxonomy archives with cross-site profile linking to artist.extrachill.com
- Co-Authors Plus integration with REST API support and selective style loading
- Admin customizations removing navigation menus from theme customizer on blog sites
- Comprehensive homepage system documentation in docs/homepage-system.md

### Changed
- Updated about section bio text to highlight open-source platform positioning
- Simplified homepage about section CSS styling for cleaner presentation
- Removed unused section-more-recent-posts.php template from homepage system

### Fixed
- Proper integration hooks for new core functionality

## [0.3.1] - 2025-12-12

### Added
- Comprehensive block documentation in `docs/blocks.md` with usage guides for all 7 Gutenberg blocks
- Server-side rendering support with `render.php` files for all blocks
- Client-side interactivity with `view.js` scripts for enhanced user experiences
- Package dependency locking with `package-lock.json`

### Changed
- Migrated all blocks from `index.php` to `render.php` for proper WordPress server-side rendering
- Enhanced CLAUDE.md with detailed block descriptions and build process documentation
- Updated README.md to highlight Gutenberg blocks functionality
- Simplified webpack configuration by removing unnecessary copy operations
- Removed legacy assets directories and old block structure files

### Fixed
- Improved block asset management and loading performance
- Better separation of server-side and client-side block functionality

## [0.3.0] - 2025-12-11

### Added
- Complete Gutenberg blocks system with 7 interactive community engagement blocks
- Band Name Generator block for random band name generation
- Rapper Name Generator block for random rapper name generation
- Image Voting block with email capture functionality
- Trivia block with interactive questions and scoring
- AI Adventure block with branching text-based adventure game
- AI Adventure Path and Step components for adventure game structure
- Webpack configuration extending @wordpress/scripts defaults
- Package.json with WordPress scripts dependencies
- Enhanced newsletter form styling with input and button styles
- Block registration system supporting both development and production environments

### Changed
- Updated plugin description to include Gutenberg blocks functionality
- Enhanced .gitignore to exclude node_modules/, vendor/, and build/ directories
- Updated CLAUDE.md with comprehensive block documentation

## [0.2.3] - 2025-12-10

### Added
- .gitignore file to exclude build/ and vendor/ directories
- Responsive CSS styles for newsletter signup form on mobile devices
- Comprehensive newsletter grid section styles with proper typography and layout

### Changed
- Replaced hardcoded blog IDs with dynamic ec_get_blog_id() function calls for better multisite compatibility
- Replaced hardcoded URLs with ec_get_site_url() function calls in hero section navigation
- Updated newsletter archive link to use dynamic site URL function
- Added network-dropdown-target class to blog breadcrumb for enhanced navigation

### Fixed
- Proper blog switching logic with conditional restore_current_blog() to prevent errors when blog ID functions are unavailable

## [0.2.2] - 2025-12-07

### Added
- Breadcrumb integration for blog archive (/blog) with network dropdown support

### Changed
- Updated blog archive URL from /all to /blog across navigation and templates
- Updated CLAUDE.md version to 0.2.2

### Fixed
- Proper global $post handling in homepage template loops (section-3x3-grid.php, section-more-recent-posts.php)

## [0.2.1] - 2025-12-07

### Added
- Newsletter subscription form integration in homepage 3x3 grid
- Recent newsletters display from newsletter.extrachill.com (blog ID 9)
- Comprehensive README.md with features and development documentation

### Changed
- Replaced community activity section with newsletter-focused content
- Simplified extrachill.link promo section (removed features list, streamlined design)
- Reorganized homepage layout (moved extrachill.link promo to final section)
- Updated homepage CSS for new layout structure (renamed classes, adjusted spacing)
- Updated build artifacts and vendor metadata

### Removed
- Community activity rendering from homepage 3x3 grid
- Extrachill.link features list and expanded card styling

## [0.2.0] - 2025-12-05

### Added
- `/all` archive route for main blog with AI content exclusion
- Archive query modifications to exclude author ID 39 (AI-generated content)
- Archive template routing to theme's archive.php
- Query variable registration for `extrachill_blog_archive`

### Removed
- Blog button from homepage hero section

### Changed
- Updated archive routing @since tags to reflect 0.2.0

## [0.1.0] - 2025-11-XX

### Added
- Initial plugin release
- Secondary header navigation with category links (Latest, Interviews, Reviews)
- Homepage hero section with personalized welcome messages and site navigation buttons
- 3x3 grid layout for interviews and live music reviews
- Recent posts section with content exclusion logic
- Extrachill.link promotional section
- About section with community links
- Complete homepage styling with responsive design
- Conditional CSS loading for front page only
- Proper WordPress hooks integration
