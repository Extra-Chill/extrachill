# Extra Chill Analytics

Network-wide analytics tracking and reporting for the ExtraChill Platform WordPress multisite network.

## Overview

The ExtraChill Analytics plugin provides comprehensive view tracking and analytics reporting across all sites in the ExtraChill Platform multisite network. It tracks eligible public routes through a signed, asynchronous browser request to the WordPress Abilities API, excluding previews and rejecting writes that do not match the rendered host, path, route family, and optional post. A network admin dashboard displays analytics data across all sites.

## Documentation

- [Abilities reference](docs/abilities.md) — inputs, outputs, permissions, transport exposure, and side effects for all registered abilities.

## Features

- **Unified Event Tracking**: Network-wide custom table for tracking newsletters, registrations, and more
- **Network-Wide Activation**: Plugin activated across all sites in the multisite network
- **Async View Tracking**: Uses `navigator.sendBeacon` and `fetch` with `keepalive` for non-blocking tracking
- **Signed Ability Writes**: Tracks browser views through the Core Abilities runner at `/wp-json/wp-abilities/v1/abilities/extrachill/track-page-view/run`
- **Preview Exclusion**: Automatically excludes preview mode from view counting
- **Post Meta Storage**: Stores view counts in WordPress post meta (`ec_post_views`)
- **Network Admin Dashboard**: React-powered analytics dashboard under Extra Chill Multisite menu
- **Asset Versioning**: Automatic cache busting using `filemtime()` on all enqueued assets
- **Conditional Loading**: Frontend tracking loads on eligible public routes, while the admin dashboard loads only on the analytics page

## Beacon authentication

The shared PHP beacon URL builder adds a `wp_rest` nonce as `_wpnonce` only
for logged-in renders, allowing Core cookie authentication to attribute browser
events to `user_id`. Anonymous HTML contains no nonce. Extra Chill Cache bypasses
HTML caching for `wordpress_logged_in_*` cookies, so per-user nonces are not
served from the public page cache.

If Core rejects an expired/invalid nonce, a late `rest_authentication_errors`
filter clears the current user and permits anonymous recording **only** for
POSTs to the explicitly listed public collectors (pageview, click, network,
CTA, form-submit and content-link). Existing origin, proof, privacy and rate
checks still apply. Other routes, methods and authentication errors remain
rejected. This server-side fallback avoids losing sendBeacon events, whose
responses cannot be inspected for a client-side retry.

## Requirements

- WordPress Multisite
- PHP 7.4+
- WordPress 5.0+
- WordPress Abilities API support

## Notes

This plugin is a network plugin used inside the Extra Chill Platform multisite network.


## Browser Pageview Contract

The signed Analytics-owned tracker is the sole browser pageview path:

- **Track View** - `POST /wp-json/wp-abilities/v1/abilities/extrachill/track-page-view/run`
- **Envelope** - `{"input":{"source_path":"/story/","route_family":"singular","proof":"<server-rendered proof>","post_id":123}}`
- `source_path`, `route_family`, and `proof` are required. `post_id` is present only for post-backed views.
- The server renders the proof into the tracker configuration. Browser or API consumers must not construct or substitute it.
- Analytics validates the signed blog/host/path/family/post tuple before incrementing `ec_post_views` or storing a pageview event.
- Route views store only the pageview event; post-backed views also retain the legacy post-meta counter.

The former public post-only `/extrachill/v1/analytics/view` path is not compatible and must not be documented or reintroduced. Its coordinated removals are tracked in Extra-Chill/extrachill-artist-platform#133, Extra-Chill/extrachill-api#118, and Extra-Chill/extrachill-api-client#14. Extra Chill API remains responsible for its click/impression HTTP adapters; those responsibilities do not move into Analytics.

## Development


## Notes

This repo is a network plugin used inside the Extra Chill Platform multisite network. All analytics data is stored per-site using WordPress post meta, but can be aggregated network-wide via the analytics dashboard.
