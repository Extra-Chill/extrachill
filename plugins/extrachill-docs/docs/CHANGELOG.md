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