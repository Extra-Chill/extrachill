# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.18.0] - 2026-06-22

### Added
- crosslink-targets ability — join conversion-map per-article ranking with the data-machine orphan link-graph
- demand-drill ability — attribute a surface demand slope to per-page/per-query click changes

### Fixed
- clear phpstan/phpcs lint findings in crosslink-targets ability

## [0.17.0] - 2026-06-21

### Added
- outbound-click tracking

## [0.16.0] - 2026-06-21

### Added
- content-category revenue + RPM lens
- platform-surface stickiness instrument
- first-party cross-surface conversion map

### Changed
- consolidate bot/human determination into one canonical classifier

### Fixed
- exclude already-redirected URLs from 404 reports
- add active-window lens to php-error-summary so health rate reflects current errors

## [0.15.0] - 2026-06-20

### Added
- near-real-time PHP fatal-rate alarm with Discord beacon

### Fixed
- resolve phpcs/phpstan lint findings in growth + fatal-alarm
- stop demand slope truncation from date_stats 25-row cap

## [0.14.1] - 2026-06-20

### Fixed
- assign $cookie_name before setcookie in visitor-id mint (closes #46)

## [0.14.0] - 2026-06-20

### Added
- add artist-signup activation funnel event types

### Fixed
- stitch visitor_id to non-pageview events + filter bot search demand

## [0.13.0] - 2026-06-20

### Added
- add get-surface-growth ability for normalized cross-surface growth-rate reads

## [0.12.0] - 2026-06-18

### Added
- surface search-quality gaps via get-search-gaps ability

## [0.11.1] - 2026-06-17

### Fixed
- mint ec_vid visitor cookie on template_redirect before output starts

## [0.11.0] - 2026-06-17

### Added
- deterministic visitor retention — ec_vid cookie, pageview events, get-retention-stats

## [0.10.1] - 2026-06-17

### Fixed
- surface exact UTC window bound in analytics summary for reproducible counts

## [0.10.0] - 2026-06-17

### Changed
- own the canonical team/funnel analytics event-name contract

## [0.9.0] - 2026-06-16

### Added
- add bot-filtered bridge CTR ability

## [0.8.0] - 2026-06-15

### Added
- add PHP debug.log signature CLI with rotation-safe per-day rates
- add extrachill/get-analytics-meta ability
- register tracking + link-page analytics abilities

### Changed
- pin ajv ^8 to fix wp-scripts webpack build on Node 25
- migrate from @extrachill/api-client to wp-native-client

### Fixed
- guard ability category registration against double-fire _doing_it_wrong notice
- add scanner-404 counter for the URL/path attack storm

## [0.7.0] - 2026-05-01

### Added
- add 404 analysis abilities — summary, top-urls, patterns, drill, list, purge, top-ips

### Changed
- Classify SQLi/XSS scanner probes as search_attack event_type

## [0.6.2] - 2026-03-29

### Changed
- Add defer strategy to view-tracking script

## [0.6.1] - 2026-03-17

### Changed
- Add wp_mail observability — log all email send attempts and failures
- v0.5.0
- Add 404 error tracking as analytics events with bot filtering, dashboard rendering, and badge styles
- Add CLAUDE.md documentation for extra-chill-analytics plugin
- add homeboy.json for release/deploy automation
- add blog_id filtering to get-analytics-summary ability
- Add extrachill/get-analytics-summary ability for read-side event querying
- migrate API client to @extrachill/api-client

## [0.4.5] - 2026-01-25

- Fix React crash when events have null event_type values by adding null safety checks

## [0.4.4] - 2026-01-24

### Fixed
- Add missing category property to WP Abilities API registration

## [0.4.3] - 2026-01-23

- Remove redundant listeners - source plugins now call ability directly

## [0.4.1] - 2026-01-23

- Add generic extrachill_should_track_analytics_event filter for event exclusion at ability level

## [0.4.0] - 2026-01-23

- Add Abilities API integration with extrachill/track-analytics-event ability
- Update listeners to use wp_execute_ability() for event tracking

## [0.3.1] - 2026-01-10

### Added
- Network admin Tracking settings page for configuring Google Tag Manager.
- GTM output via `wp_head` and `wp_body_open` when `extrachill_gtm_container_id` is set.

### Changed
- GTM configuration moved to a single network option: `extrachill_gtm_container_id`.

## [0.3.0] - 2026-01-07

### Changed
- **BREAKING**: Renamed analytics events table from `ec_events` to `extrachill_analytics_events` for clarity.
- Renamed helper function from `ec_events_get_table_name()` to `extrachill_analytics_events_table()`.
- Renamed table creation function from `ec_events_create_table()` to `extrachill_analytics_events_create_table()`.
- Updated DB version constants to use `EXTRACHILL_ANALYTICS_EVENTS_` prefix.

### Migration Required
Run SQL: `RENAME TABLE c8c_ec_events TO c8c_extrachill_analytics_events;`

## [0.2.1] - 2026-01-07

### Added
- Added `search` filtering to `ec_get_events()` to allow substring matching against `event_data` JSON.
- Added `ec_count_events()` to return total event counts for filtered queries.
- Added React admin dashboard source build pipeline (`src/`, `webpack.config.js`) that outputs script/style assets to `build/`.

### Changed
- Updated network admin asset loading to enqueue `build/analytics.js` with dependency/version data from `build/analytics.asset.php`.
- Updated admin styles to load from `build/analytics.css`.

## [0.2.0] - 2026-01-04

### Added
- Unified event tracking system with custom table `{base_prefix}_extrachill_analytics_events`.
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
