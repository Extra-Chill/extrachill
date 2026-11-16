# Bridge impressions

## POST /wp-json/extrachill/v1/analytics/impression

**Source:** `inc/routes/analytics/impression.php` and `telemetry-validation.php`.

**Permission callback:** `__return_true`, but the handler enforces telemetry admission: per-client rate limit, valid first-party source on the current site, safe source path, and matching Origin host when Origin is supplied. Anonymous browser beacons are supported.

**Parameters:** required `impression_type` string (only `bridge`), `source_url` string (maximum 2048 characters); optional `source_post` integer (0, `absint`), `source_site`, `dest_site` strings (empty, `sanitize_key`), `term` string (empty, maximum 200, text-sanitized).

The handler strips query/fragment from source data and forwards only its normalized path. It rejects unsafe term payloads, mismatched source sites, and invalid or same-site destinations; absent source site is filled from the current blog when available.

**Owner/ability:** `extrachill-analytics`, `extrachill/track-analytics-event`; called with `event_type: bridge_impression`, normalized `source_url`, and `event_data: {dest_site, source_post, source_site, term}`.

**Response:** `{ "recorded": true }` (200). Invalid impression/source/payload/site data returns 400 or 403 as appropriate; admission can return 429. Missing ability is `ability_missing` (500); false/empty event ID is `tracking_failed` (500); ability errors propagate. This is an exposure denominator, not a click event.
