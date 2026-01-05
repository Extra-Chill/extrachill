# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0).

## [0.5.2] - 2026-01-04

### Changed
- Updated breadcrumb home item from "Home" to "Extra Chill" to align with visual breadcrumbs.

### Added
- `extrachill_seo_breadcrumb_items` filter allowing site-specific plugins to override breadcrumb items for schema output to align with visual breadcrumbs.

## [0.5.1] - 2026-01-02

### Fixed
- Restricted event-specific archive and taxonomy title formats to only apply on the `events` subdomain (ID 7), preventing incorrect titles on other network sites that may share similar taxonomy names.

## [0.5.0] - 2026-01-02

### Added
- `MusicGroup` schema for Artist Profiles (`artist_profile`) including bio, genre, and social links.
- `ProfilePage` schema for Artist Link Pages (`artist_link_page`) referencing the associated `MusicGroup`.
- `subOrganization` support to Organization schema, listing all network sites derived from canonical blog-ids.php.
- Network-wide canonical and meta descriptions for login and password reset pages (canonicalized to community site).
- Article schema enhancements: description from excerpt and custom fields (`articleSection`, `keywords`) for `festival_wire` posts.

### Changed
- Simplified author breadcrumbs by removing the redundant "Authors" parent item.

## [0.4.1] - 2025-12-24

### Added
- Custom title format for datamachine_events post type archive: "Live Music Calendar"
- Custom title format for event-related taxonomies (location, festival, venue, promoter, artist): "{Term Name} Live Music Calendar"

## [0.4.0] - 2025-12-23

### Added
- Complete SEO audit system with REST API endpoints for running audits across the multisite network
- Tabbed network admin interface with Audit and Config tabs
- Six SEO health checks:
  - Posts missing excerpts (poor meta descriptions)
  - Images missing alt text
  - Posts without featured images
  - Broken images (missing featured attachments + 404 URLs in content)
  - Broken internal links (within network domains)
  - Broken external links (outside network domains)
- Full audit mode: synchronous audit of all sites (may timeout on large networks)
- Batch audit mode: progressive processing with real-time progress tracking for HTTP-based checks
- Continue audit functionality: resume long-running batch audits from where they left off
- Real-time dashboard cards with per-site breakdowns and severity color coding
- REST API endpoints:
  - `POST /extrachill/v1/seo/audit` - Start full or batch audit
  - `GET /extrachill/v1/seo/audit/status` - Get current audit status
  - `POST /extrachill/v1/seo/audit/continue` - Continue batch audit
- Audit results storage via network site options with status tracking
- Per-site post type support via `extrachill_get_site_post_types()` integration
- JavaScript admin interface for REST API communication with loading states and progress indicators
- Styling for audit dashboard cards, progress bar, and tabbed interface

### Changed
- Restructured network admin settings page with tabbed navigation
- Network admin menu item renamed from "SEO Settings" to "SEO"
- IndexNow error logging removed (cleanup)
- Audit components loaded unconditionally for REST API endpoint availability

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
