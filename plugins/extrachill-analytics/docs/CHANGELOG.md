# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-01-04

### Added
- Unified event tracking system with custom table `{base_prefix}_ec_events`.
- `ec_track_event()` function for recording flexible JSON analytics data.
- Event listeners for newsletter signups (`extrachill_newsletter_subscribed`).
- Event listeners for user registrations (`extrachill_new_user_registered`).
- Event listeners for site searches.
- Data querying and aggregation functions (`ec_get_events`, `ec_get_event_stats`).
- Detailed data flow documentation in AGENTS.md.

### Fixed
- React 18 initialization pattern in admin dashboard with `createRoot()`.
- Added `DOMContentLoaded` and `wp.element` availability checks for admin scripts.
- Corrected admin asset hook matching to match WordPress generated hook name.

## [0.1.0] - 2026-01-04

### Added
- Initial release.
- Asynchronous post view tracking via REST API.
- WordPress post meta storage for view counts.
- React admin dashboard scaffold.
- Preview mode exclusion for accurate tracking.
