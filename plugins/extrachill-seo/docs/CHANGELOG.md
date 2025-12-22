# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2025-12-22

### Added
- Slug-based site suffix logic in `inc/core/meta-tags.php` using `ec_get_blog_slug_by_id()` function with fallback for unavailable function.
- Improved pagination title context for blog/home archives, categories, tags, and author pages with clearer "Page X" indicators.
- Front page title unsetting for non-paginated home/front pages to prevent redundant titles.
- Updated site suffixes mapping with string keys and refined naming (e.g., "Extra Chill Artist Platform" for artist sites).

### Changed
- Refactored `document_title_parts` filter in `inc/core/meta-tags.php` for better maintainability, consistency, and explicit type casting.
- Updated `composer.json` lint scripts (`lint:php` and `lint:fix`) to ignore `vendor/*` and `build/*` directories during PHP code sniffing.

### Fixed
- None