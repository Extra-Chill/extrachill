# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.32.0] - 2026-07-18

### Added
- generalize experiment analytics reporting

## [0.31.0] - 2026-07-18

### Added
- report geographic bridge experiment
- add Artist Dispatch event contracts
- attribute lifecycle conversion outcomes
- report first-party route transitions

### Changed
- canonicalize active analytics event contracts
- minimize retained email analytics data

### Fixed
- restore honest bridge event reporting
- align bridge report with viewport exposure
- protect public analytics writes
- enforce ordered activation funnel progression
- filter encoded search attack probes

## [0.30.2] - 2026-07-17

### Fixed
- measure non-singular route journeys

## [0.30.1] - 2026-07-17

### Fixed
- stitch outcomes on conversion routes

## [0.30.0] - 2026-07-16

### Added
- expose article conversion outcomes
- expose event source context detail

## [0.29.1] - 2026-07-16

### Fixed
- expose conversion article identity

## [0.29.0] - 2026-07-16

### Added
- add ad delivery coverage diagnostics

### Fixed
- scope revenue reports by canonical host
- exclude CLI eval failures from production health
- isolate editorial revenue format coverage

## [0.28.6] - 2026-07-15

### Fixed
- clarify conversion map journey scope
- calculate mature exact-week retention cohorts
- resolve analytics visitors from request cookies

## [0.28.5] - 2026-07-14

### Fixed
- respect ad eligibility in revenue reports

## [0.28.4] - 2026-07-14

### Fixed
- Fix canonical revenue path aggregation

## [0.28.3] - 2026-07-14

### Fixed
- preserve homepage revenue snapshots

## [0.28.2] - 2026-07-14

### Fixed
- attribute revenue paths across network

## [0.28.1] - 2026-07-14

### Fixed
- warn on low-volume revenue rates
- expand revenue format coverage

## [0.28.0] - 2026-07-13

### Added
- add page and diagnostic revenue abilities
- add idempotent revenue ingestion ability

## [0.27.3] - 2026-07-13

### Changed
- remove completed Artist Platform coexistence shim

### Fixed
- exclude attack probes from search-gap demand reports (#133)
- classify scanner path probes instead of actionable content 404s
- report recorded outbound click destinations

## [0.27.2] - 2026-07-13

### Fixed
- separate unresolved revenue routes from content rollups

## [0.27.1] - 2026-07-13

### Fixed
- count only recent PHP errors as active

## [0.27.0] - 2026-07-13

### Added
- define Local Scene prompt events

## [0.26.0] - 2026-07-13

### Added
- define onboarding funnel events

## [0.25.6] - 2026-07-12

### Fixed
- make crosslink targets inbound-only

## [0.25.5] - 2026-07-12

### Fixed
- pass explicit fgetcsv escape argument (#120)

## [0.25.4] - 2026-07-12

### Fixed
- register network pages under extrachill-network parent slug (#118)

## [0.25.3] - 2026-07-12

### Fixed
- stamp beacon pageviews human and backfill mis-stamped rows (#115)

## [0.25.2] - 2026-07-12

### Fixed
- compute demand slope from per-window organic sessions (#112)

## [0.25.1] - 2026-07-03

### Changed
- Internal improvements

## [0.25.0] - 2026-07-03

### Added
- backfill canonical is_bot flag onto legacy pre-classifier events

## [0.24.0] - 2026-06-30

### Added
- add get-bot-filter-impact analytics guardrail

### Fixed
- label crosslink-targets orphan count as zero-inbound to end ambiguity
- classify authenticated logged-in users as human #103

## [0.23.1] - 2026-06-28

### Changed
- run the fatal-rate alarm as a Data Machine system task

## [0.23.0] - 2026-06-27

### Added
- own link-page analytics store, write path, prune, and provider
- own shared Chart.js v4 asset as network-activated script handle
- relax reporting-ability gates to team-readable tier

### Fixed
- guard visitor-cookie setcookie() against empty name
- ignore non-production fatals in the fatal-rate alarm

## [0.22.0] - 2026-06-27

### Added
- capture normalized referrer_host on pageview events

## [0.21.0] - 2026-06-27

### Added
- stamp originating search surface on search analytics events

### Fixed
- correct no-op is_bot filter in get-search-gaps (MariaDB JSON-boolean)

## [0.20.1] - 2026-06-27

### Fixed
- scope ec_vid visitor cookie to network root for cross-subdomain identity

## [0.20.0] - 2026-06-22

### Added
- surface artist activation funnel friction signals

## [0.19.0] - 2026-06-22

### Fixed
- cast strtotime to int for gmdate in get-bridge-ctr to clear phpstan
- compute per-destination bridge CTR now that impressions carry dest_site (Fixes #75)

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
