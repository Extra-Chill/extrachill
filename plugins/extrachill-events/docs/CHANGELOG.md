# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2025-11-26

### Added
- Added `inc/single-event/related-events.php` to handle related events display.
- Integrated with `extrachill` theme's `extrachill_override_related_posts_display` hook to replace default related posts with `datamachine-events` calendar cards.
- Added logic to exclude the current event's venue from the related events query to encourage discovery.

## [0.1.0] - 2023-10-25

### Added
- Initial release.
- Integration with `datamachine-events` plugin.
- Homepage and Archive template overrides for events subdomain.
- Breadcrumb system.
- Share button functionality.
