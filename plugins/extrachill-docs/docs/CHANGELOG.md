# 0.3.2 - Dec 31, 2025

### Added
- Artist platform documentation: artist access requirements, creating artist merch store, managing roster, managing artist profile
- Community documentation: getting started guide, notifications system
- Events calendar documentation: submitting an event, using calendar filters

### Changed
- Post meta filter now uses `extrachill_post_meta_parts` hook returning `['published', 'updated']` instead of hiding all metadata
- Improved documentation formatting: removed YAML frontmatter, expanded content with better structure
- Updated ec_docs/README.md with clearer file format guidelines and platform directory reference
- Enhanced artist platform docs with detailed join flow, Google sign-in, and editor instructions
- Expanded community docs with block editor features, social platform list, and sharing guidelines

### Maintenance
- Standardized documentation structure across all platform guides
- Added support sections with forum and contact links to all docs

# 0.3.1 - Dec 9, 2025

### Changed
- Simplified table of contents to h2-only headers for cleaner navigation
- Split taxonomy registration into focused files for better code organization
- Updated CSS styling for improved visual consistency

### Maintenance
- Cleaned up Composer dependencies and autoload files
- Removed outdated documentation files via sync process

# 0.3.0 - Dec 9, 2025

### Added
- Dynamic table of contents sidebar with scroll tracking and smooth scrolling
- Recent documentation preview in homepage platform cards
- TOC JavaScript for progressive enhancement on documentation pages
- Platform-based related posts for documentation articles

### Changed
- Homepage platform cards now show recent articles instead of just counts
- Replaced related docs sidebar with table of contents on single doc pages
- Improved documentation upload script with H1 header title parsing
- Updated getting started guide content
- Changed rewrite rule priorities to 'top' for better precedence

### Maintenance
- Updated Composer dependencies

# 0.2.5 - Dec 8, 2025

### Added
- Related documentation sidebar displaying posts from the same platform
- Enhanced CSS styling with design tokens for improved visual consistency
- Dynamic site URL support in breadcrumb navigation
- Custom back-to-home label for documentation pages

### Changed
- Updated breadcrumb functions to use dynamic blog ID detection
- Improved theme integration with sidebar content filters

### Maintenance
- Updated Composer dependencies

# 0.2.4 - Dec 8, 2025

### Added
- Custom URL structure `/{platform-slug}/{doc-slug}/` with new rewrite rules system
- Theme integration filters to hide author meta and related posts for documentation posts
- Enhanced breadcrumb navigation with dynamic blog ID detection and support for single docs and platform archives
- "Getting Started on the Artist Platform" user documentation guide
- 'blog' platform to default platform seeding

### Changed
- Simplified platform seeding by removing descriptions and streamlining data structure
- Disabled default post type/taxonomy rewrites in favor of custom rewrite rules
- Improved sync script `.env` parsing to properly handle quoted values
- Updated breadcrumb functions to use dynamic blog ID instead of hardcoded values

### Maintenance
- Updated Composer dependencies

# 0.2.3 - Dec 8, 2025

### Added
- Homepage intro section with title and description for better user experience
- Breadcrumb navigation integration on documentation homepage

### Changed
- Reorganized homepage rendering code from `inc/home/` to `inc/core/homepage.php` for better file structure
- Updated 'events' platform taxonomy slug to 'events-calendar' for improved URL clarity
- Updated AGENTS.md with REST API endpoint documentation

### Maintenance
- Updated Composer dependencies

# 0.2.2 - Dec 7, 2025

### Added
- Breadcrumb integration for docs pages with "Extra Chill → Documentation" navigation
- Theme filter hooks for custom breadcrumb trails on homepage

### Changed
- Updated AGENTS.md with current architecture and file structure documentation
- Removed outdated artist platform documentation files
- Minor formatting updates to main plugin file

### Maintenance
- Updated Composer dependencies

# 0.2.1 - Dec 7, 2025

### Changed
- Simplified platform seeding by removing descriptions and unused platforms (Getting Started, Your Account)
- Updated homepage to only display platforms with published documentation for cleaner navigation
- Added .gitignore to exclude build/, vendor/, and .env files
- Updated AGENTS.md documentation to reflect platform changes

# 0.2.0 - Dec 7, 2025

### Added
- Documentation sync system for publishing user docs to WordPress
- Bash sync script (`scripts/sync.sh`) with environment variable support
- PHP upload script (`scripts/upload.php`) with frontmatter parsing
- WordPress REST API client (`scripts/WordPressClient.php`) for authenticated requests
- File finder utility (`scripts/FileFinder.php`) for recursive markdown discovery
- User documentation structure (`ec_docs/`) as source of truth
- Artist platform guides: link page creation and advanced features
- Documentation guidelines and sync process instructions

# 0.1.1 - Dec 7, 2024

### Changed
- Version bump to 0.1.1 following initial 0.1.0 release

# 0.1.0 - Dec 7, 2024

### Added
- Complete documentation platform implementation
- ec_doc custom post type with hierarchical support
- ec_doc_platform taxonomy with default platform seeding
- Homepage platform cards for navigation
- Clean URL structure (/platform-slug/doc-slug/)
- Conditional asset loading with cache busting
- Plugin activation hook with rewrite rule flushing