# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.1] - 2025-12-12

### Added
- Comprehensive block documentation in `docs/blocks.md` with usage guides for all 7 Gutenberg blocks
- Server-side rendering support with `render.php` files for all blocks
- Client-side interactivity with `view.js` scripts for enhanced user experiences
- Package dependency locking with `package-lock.json`

### Changed
- Migrated all blocks from `index.php` to `render.php` for proper WordPress server-side rendering
- Enhanced AGENTS.md with detailed block descriptions and build process documentation
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
- Updated AGENTS.md with comprehensive block documentation

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
- Updated AGENTS.md version to 0.2.2

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