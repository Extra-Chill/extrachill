# Changelog

## [0.6.0] - 2026-04-27

### Added
- add social job wait helper
- normalize async publish contract and add job status types

## [0.5.0] - 2026-04-26

### Added
- add NetworkMediaResource for /extrachill/v1/network-media list and upload

## [0.3.1] - 2026-04-03

### Changed
- update socials API paths to datamachine/v1/socials namespace

## [0.3.0] - 2026-03-25

### Added
- add auth fields to SocialPlatformConfig type

### Changed
- Add Homeboy component config
- Add typed methods for user settings, profile, subscriptions, and artist access
- drop activity resource, types, and exports
- add socials resource to universal API client

### Fixed
- Fix LeaderboardResponse types to match actual REST endpoint

## [0.2.2] - 2026-03-02

- fix: updateSocials pass-through body, generateQrCode size param

## [0.2.1] - 2026-03-02

- Align AdminResource, SeoResource, and AnalyticsResource methods with actual REST API signatures

## [0.2.0] - 2026-03-02

- Add AuthFetchTransport with full token lifecycle (proactive refresh, 401 retry, refresh lock, auth failure callback)

## [0.1.2] - 2026-03-02

### Changed
- Align events types with live wrapper endpoints (locations filter, search/past params, venue geo proximity)

## [0.1.1] - 2026-03-02

### Added
- Initial release: universal typed API client with FetchTransport and WpApiFetchTransport
- 12 typed resource modules: auth, artists, blog, community, events, analytics, media, shop, users, admin, activity, seo
- Separate WordPress entry point to keep main bundle dependency-free

### Changed
- Moved all events endpoints to extrachill/v1 namespace — no more direct datamachine/v1 calls
- Events data (calendar, venues, geocode) now accessed through Extra Chill wrapper endpoints

## [0.1.0] - 2026-03-02
- Initial release
