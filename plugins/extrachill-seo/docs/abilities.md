# SEO abilities

Extra Chill SEO registers audit, URL analysis, configuration, IndexNow, sitemap-health, and redirect abilities in the `extrachill-seo` category. Abilities are domain operations consumed through transports such as REST, CLI, MCP, and core `/wp-abilities/v1`. Inputs below are object fields; required fields are explicitly marked, and all others are optional. Output shapes describe successful returns; some callbacks also return `WP_Error`.

## Auditing and URL analysis

Registrations: [`class-seo-abilities.php`](../inc/abilities/class-seo-abilities.php). Execution: [`audit-abilities.php`](../inc/abilities/audit-abilities.php), [`analysis-abilities.php`](../inc/abilities/analysis-abilities.php), and [`sitemap-health-abilities.php`](../inc/abilities/sitemap-health-abilities.php).

### `extrachill/run-seo-audit`

- **Purpose:** Run a full network SEO audit or start/continue a batch audit. `blog_id` and `checks` filter the returned results after execution; they do not restrict what the audit engine scans.
- **Input:** All optional: `blog_id` (integer; omitted/0 returns all sites), `mode` (string: `full` or `batch`, default `full`), `checks` (string array: `excerpts`, `alt_text`, `featured_images`, `broken_images`, `broken_links`, `redirect_links`; schema default includes all). Batch mode continues an existing `in_progress` audit, otherwise starts one.
- **Output:** Object with `success` (boolean), `mode` (string), `blog_id` (integer), `status` (string: `complete` or `in_progress` per schema), `results` (object by check), `message` (string). Broken-link filtering includes both internal and external check keys.
- **Permission:** `current_user_can('manage_network_options')`.
- **Exposure:** `show_in_rest: true`; extrachill-api POST `/extrachill/v1/seo/audit`, documented in [audit route](../../extrachill-api/docs/routes/seo/audit.md); `wp extrachill` command: none found.
- **Side effects:** Runs network queries and HTTP checks for linked resources; persists audit results/progress through the audit engine.

### `extrachill/get-seo-results`

- **Purpose:** Read the latest stored audit with optional site/check filtering. An unknown `check` does not narrow results because filtering occurs only when the key exists.
- **Input:** Optional `blog_id` (integer), `check` (string, stored result key).
- **Output:** Object with `status` (string), `timestamp` (integer), `last_run` (date-time string, or empty before any run), `results` (object), `summary` (`total_issues` integer, `by_check` object of counts).
- **Permission:** `current_user_can('manage_network_options')`.
- **Exposure:** `show_in_rest: true`; extrachill-api GET `/extrachill/v1/seo/audit/status`, documented in [status route](../../extrachill-api/docs/routes/seo/status.md); `wp extrachill` command: none found.

### `extrachill/analyze-url`

- **Purpose:** Analyze one URL's redirects, metadata, JSON-LD, robots directives, and social tags, returning a bounded score and recommendations.
- **Input:** Required `url` (string, URI). Optional `checks` (string array: `redirect`, `meta`, `schema`, `robots`, `social`; defaults to all five).
- **Output:** Object with `url`, `final_url` (strings), `analysis` (object keyed by selected checks), `score` (integer 0–100), `recommendations` (array). Redirect analysis includes `redirects`, `status_code`, `chain`, `hops`; other checks report extracted tags/directives/schema types and issues.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.
- **Side effects:** Makes HTTP requests to inspect the URL and redirect chain. Meta/schema/robots/social analysis each fetches the final URL independently; no content writes.

### `extrachill/check-sitemap-health`

- **Purpose:** Check sampled WordPress core sitemap sub-files as a crawler would see them, or return the last stored result. With `cached: true`, a missing cache falls back to a live check; an existing cached result is returned as stored, not refiltered by `blog_id`.
- **Input:** Optional `blog_id` (integer; default all sites), `cached` (boolean, default false).
- **Output:** Object with `healthy` (boolean), `checked` (integer), `failures` (array), `by_site` (object), `timestamp` (date-time string), `message` (string).
- **Permission:** `current_user_can('manage_network_options')`.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.
- **Side effects:** Live checks make HTTP GET requests and store the result in the network sitemap-health option. A cache hit only reads the stored result.

## Configuration and indexing

Registrations: [`class-seo-abilities.php`](../inc/abilities/class-seo-abilities.php). Callbacks: [`config-abilities.php`](../inc/abilities/config-abilities.php) and [`analysis-abilities.php`](../inc/abilities/analysis-abilities.php).

### `extrachill/get-seo-config`

- **Purpose:** Read the default OG image and IndexNow configuration, including the configured key.
- **Input:** Empty object; no fields required.
- **Output:** Object with `default_og_image_id` (integer), `default_og_image_url`, `indexnow_key` (strings), `indexnow_enabled` (boolean).
- **Permission:** `current_user_can('manage_network_options')`.
- **Exposure:** `show_in_rest: true`; extrachill-api GET `/extrachill/v1/seo/config` (registered in the API's `inc/routes/seo/config.php`); `wp extrachill` command: none found.

### `extrachill/update-seo-config`

- **Purpose:** Update supplied SEO settings and return the resulting configuration. Unspecified settings remain unchanged.
- **Input:** Optional `default_og_image_id` (integer), `indexnow_key` (string). No fields required; an empty update is accepted.
- **Output:** Object with `success` (boolean), `updated` (array of updated field names), `config` (object matching `get-seo-config`).
- **Permission:** `current_user_can('manage_network_options')`.
- **Exposure:** `show_in_rest: true`; extrachill-api POST/PUT/PATCH `/extrachill/v1/seo/config` (registered in the API's `inc/routes/seo/config.php`); `wp extrachill` command: none found.
- **Side effects:** Persists supplied OG-image/IndexNow settings; sanitizes the IndexNow key with `sanitize_text_field()`.

### `extrachill/ping-indexnow`

- **Purpose:** Submit explicit URLs and/or post permalinks to IndexNow, combining, filtering, and deduplicating them before submission.
- **Input:** Optional `urls` (URI-string array, maximum 10,000), `post_ids` (integer array). Neither is schema-required, but execution errors if no valid URLs remain.
- **Output:** Object with `success` (boolean), `submitted` (integer), `urls` (array), `response_code` (integer), `message` (string); submission errors can return `WP_Error`.
- **Permission:** `current_user_can('publish_posts')`.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.
- **Side effects:** Calls the IndexNow submission integration, which can send URLs to the external indexing service.

## Redirect management

Registrations and execution: [`redirect-abilities.php`](../inc/abilities/redirect-abilities.php). These abilities are not core-REST-visible. Existing `wp extrachill seo redirects add`, `delete`, and `list` commands call the underlying SEO functions directly, not these abilities.

### `extrachill-seo/add-redirect`

- **Purpose:** Create a redirect rule from a source URL/path to a destination. The schema describes 301/302 status codes but declares no status-code enum.
- **Input:** Required `from_url`, `to_url` (strings). Optional `status_code` (integer, default 301), `note` (string, default empty).
- **Output:** Object with `success` (boolean), `id` (integer), `message` (string); failed insertion returns `success: false`, `id: 0`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: false`; extrachill-api route: none found; `wp extrachill` command calling this ability: none found.
- **Side effects:** Inserts a redirect-table row with creator `ability` through `extrachill_seo_add_redirect()`.

### `extrachill-seo/delete-redirect`

- **Purpose:** Delete a redirect rule by ID.
- **Input:** Required `id` (integer).
- **Output:** Object with `success` (boolean), `message` (string); missing rule reports false and `Redirect not found.`
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: false`; extrachill-api route: none found; `wp extrachill` command calling this ability: none found.
- **Side effects:** Deletes the redirect-table row through `extrachill_seo_delete_redirect()`.

### `extrachill-seo/list-redirects`

- **Purpose:** Query redirect rules with filtering, sorting, and pagination.
- **Input:** All optional: `search` (string, default empty), `active` (integer, default -1), `status_code` (integer, default 0), `orderby` (string, default `created_at`), `order` (string, default `DESC`), `limit` (integer, default 100), `offset` (integer, default 0).
- **Output:** Array of redirect-rule objects returned by `extrachill_seo_get_redirects()`; includes rule ID, from/to URLs, status, active state, note, hit counts, timestamps, and creator metadata.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: false`; extrachill-api route: none found; `wp extrachill` command calling this ability: none found.

## Redirect outcomes and opportunities

### `extrachill-seo/get-redirect-conversion-stats`

- **Purpose:** Rank redirect outcomes by engagement rate using first-party `redirect_fire` events joined to the same visitor's subsequent pageviews. Measured counts are fires with usable visitor IDs (not deduplicated distinct people); null/empty IDs are excluded. Source: [`redirect-conversion-abilities.php`](../inc/abilities/redirect-conversion-abilities.php).
- **Input:** All optional integers: `days` (default 90), `rule_id` (default 0, all), `min_fires` (default 1), `window_minutes` (default 30), `limit` (default 50). Execution clamps days/window/limit to ≥1 and min_fires to ≥0.
- **Output:** Object with `rules` (array), `totals` (object), `days`, `window_minutes` (integers), `period`, `since`, `as_of`, `note` (strings), `definitions` (object). Rule rows contain integer `rule_id`, `hits`, `measured`, `landed`, `engaged`, `onward`; string `from_url`, `to_url`; numeric `landed_rate`, `engaged_rate`, `onward_rate`. Totals contain outcome counts/rates and `rules_measured`; counts aggregate before the returned rule list is limited, while `rules_measured` counts that limited list. Without Analytics, returns `error` (string), empty `rules`/`totals`, and `days`.
- **Permission:** `manage_options` capability OR truthy defined `WP_CLI`.
- **Exposure:** `show_in_rest: false`; extrachill-api route: none found; `wp extrachill seo redirects stats`.

### `extrachill-seo/redirect-opportunities`

- **Purpose:** Find unserved legacy “live music in {city}” search demand and propose existing events-location destinations, ranked by impressions then clicks. Ambiguous cities without state hints are skipped; no redirect is created. Source: [`redirect-opportunities.php`](../inc/abilities/redirect-opportunities.php).
- **Input:** Optional `days` (integer, default 90), `min_impressions` (integer, default 1), `limit` (integer, default 100), `verify` (boolean, default true). Execution clamps days/limit to ≥1 and min_impressions to ≥0.
- **Output:** Object with `success` (boolean), `analyzed` (integer), `opportunities` (array), `skipped` (diagnostic-count object), `message` (string). Opportunities contain `legacy_url`, `destination`, `scope`, `city` (strings), `verified` (boolean), `impressions`, `clicks` (integers), `queries` (string array). Unavailable GSC/no demand returns false with empty opportunities; skipped counters explain missing/ambiguous locations, low impressions, non-200 destinations, and existing rules.
- **Permission:** `manage_options` capability OR truthy defined `WP_CLI`.
- **Exposure:** `show_in_rest: false`; extrachill-api route: none found; `wp extrachill seo redirects opportunities`.
- **Side effects:** Calls `datamachine/google-search-console` for query data and, by default, HTTP GETs proposed destinations with no redirect following to require a direct 200. `verify: false` skips destination HTTP verification. Does not write redirect rules.
