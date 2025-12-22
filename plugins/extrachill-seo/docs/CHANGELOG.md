# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
