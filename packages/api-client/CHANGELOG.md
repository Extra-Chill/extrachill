# Changelog

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
