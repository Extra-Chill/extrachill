# Changelog

All notable changes to the ExtraChill API plugin are documented here. This file is the single source of truth for release history.

## 0.1.1

### Added
- `POST /extrachill/v1/event-submissions` endpoint that validates Cloudflare Turnstile tokens, stores optional flyers via Data Machine file storage, and queues the configured flow.
- `extrachill_event_submission` action hook so downstream consumers can react to queued jobs (notifications, analytics, moderation).
- README/CLAUDE documentation describing the new endpoint and hook.

## 0.1.0

### Added
- Initial release with automatic route discovery, community user search, and blocks endpoints (image voting + AI adventure).
