# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0).

## Unreleased

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
