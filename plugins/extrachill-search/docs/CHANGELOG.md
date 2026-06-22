# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0).

## [0.3.2] - 2026-06-22

### Fixed
- only track search analytics for real frontend searches, not programmatic ability/pipeline callers

## [0.3.1] - 2026-06-15

### Fixed
- guard ability category registration against double-fire _doing_it_wrong notice

## [0.3.0] - 2026-06-03

### Added
- scope front-end search to current site by default

### Changed
- derive search post-type map from ec_get_blog_id slugs (closes #4)
- Remove stream.extrachill.com from search post type map

### Fixed
- strip BOOLEAN MODE operators from search terms to prevent SQL syntax errors

## [0.2.10] - 2026-04-02

### Changed
- eliminate ec-edge-shell from search
- align search results to edge contract

## [0.2.9] - 2026-03-25

### Changed
- remove chat.extrachill.com (blog 5) from search scope

## [0.2.8] - 2026-03-23

### Changed
- replace LIKE search with MySQL FULLTEXT indexes

## [0.2.7] - 2026-03-01

### Fixed
- Update event post type from `datamachine_events` to `data_machine_events` to match upstream rename

## [0.2.6] - 2026-01-25

### Fixed
- Fixed analytics tracking with correct WordPress 6.9 Abilities API

## [0.2.5] - 2026-01-25

### Fixed
- Fixed analytics tracking timing issue with Abilities API

## [0.2.4] - 2026-01-24

### Fixed
- Add missing category property to WP Abilities API registration

## [0.2.3] - 2026-01-23

- Add direct analytics tracking via Abilities API

## [0.2.2] - 2026-01-23

- Fix ability namespace from extrachill-search/ to extrachill/ prefix

## [0.2.0] - 2025-12-23

### Added
- WordPress 6.9+ Abilities API integration for AI agent discoverability (data-retrieval category)
- Wire.extrachill.com (Blog ID 11) to network search coverage

### Changed
- WordPress requirement bumped to 6.9 minimum and tested up to 6.9
- Moved site-badge.php and template-functions.php from inc/templates/ to templates/
- Updated build documentation to reflect ZIP-only production builds
- Refined post type mapping with improved formatting and clarity

### Removed
- Mediavine ad network integration from search template

## [0.1.2] - 2025-12-10

### Enhanced
- Dynamic network site discovery using extrachill-multisite plugin domain mapping
- Improved blog switching safety by verifying blog existence before switching
- Simplified plugin file loading with direct require_once statements

### Changed
- Removed hardcoded site map in favor of dynamic discovery
- Removed back home link from search results template

### Removed
- Deleted inc/core/filter-pagination.php file

## [0.1.1] - 2025-12-10

### Fixed
- Multisite post type filtering in search results
- Author permalink generation for cross-site search results
- Thumbnail/srcset handling for multisite results
- Taxonomy badge display and URLs in search results
- Pagination logic and styling for search results

### Enhanced
- Moved search scoring algorithm to dedicated file for maintainability
- Added contextual excerpt generation with search term highlighting
- Improved search result template integration
- Added pagination URL filter for multisite search context

### Changed
- Renamed claude.md to CLAUDE.md for system compatibility
- Updated documentation and code organization
