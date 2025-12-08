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