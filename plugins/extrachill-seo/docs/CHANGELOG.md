# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.2] - 2025-12-23

### Added
- Comprehensive error logging for IndexNow API submissions (status transitions, configuration errors, request/response details)
- Configuration section in README documenting network settings for Default OG Image and IndexNow Key

### Changed
- Clarified IndexNow documentation to explicitly state static key file requirement at domain root

## [0.3.1] - 2025-12-22

### Added
- Media picker UI for default OG image selection in network settings (replaces number input with interactive WordPress media library picker)
- Support for additional post types in article schema: 'festival_wire' (NewsArticle) and 'ec_doc' (TechArticle) with configurable schema type mapping
- Search URL handling in breadcrumb schema for proper search result breadcrumbs

### Changed
- Removed Yoast SEO disable code from main plugin file (no longer needed post-Yoast removal)
- Updated IndexNow integration: removed the dynamic key file endpoint (still requires hosting static `/{key}.txt` at the domain root) and added pings on published post updates
- Refactored schema base URLs to distinguish site-specific URLs (per-domain) from organization URLs (extrachill.com) for accurate cross-site schema references
- Updated PLAN.md documentation: version bump to 0.3.0, removed Yoast references, streamlined deployment checklist

### Fixed
- Schema website name now uses site-specific blog name instead of global organization name for better per-site accuracy

## [0.3.0] - 2025-12-22

### Added
- IndexNow integration with network setting for API key, verification endpoint, and automatic URL pings on content changes
- Network admin settings page under existing multisite menu for SEO configuration
- Configurable default OG image via network setting instead of hardcoded URL
- Enhanced front page title formatting with site tagline support
- Improved blog page title handling for better SEO context

### Changed
- Updated documentation (AGENTS.md, PLAN.md) to reflect implemented features
- Refactored title generation logic in meta-tags.php for consistency

## [0.2.0] - 2025-12-22

### Added
- IndexNow integration (`inc/core/indexnow.php`): `/{key}.txt` endpoint and URL pings on publish/unpublish/delete.
- Network SEO settings page under the existing `extrachill-multisite` menu (`inc/admin/network-settings.php`).
- Network settings storage via site options (`inc/core/settings.php`) for IndexNow key and default OG image attachment ID.
- Slug-based site suffix logic in `inc/core/meta-tags.php` using canonical multisite helpers.

### Changed
- Front page titles now output `Site Title - Tagline` (if tagline exists).
- Default OG image is now controlled via a network setting instead of a hardcoded URL.
- Updated `composer.json` lint scripts (`lint:php` and `lint:fix`) to ignore `vendor/*` and `build/*` directories during PHP code sniffing.
