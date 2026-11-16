# Analytics reports

Paths are relative to `/wp-json/extrachill/v1`. Sources: matching files in `inc/routes/analytics/`. All four GET routes use `extrachill_api_analytics_reports_permission_check`: any of `manage_network_options`, `manage_options` or `access_studio` grants admission. These are team-readable reports, not anonymous reads or exclusively network-admin endpoints.

Owner: `extrachill-analytics`. Results are returned unchanged (200); missing ability returns `ability_not_found` (500), and ability errors propagate. Optional date strings are forwarded only when non-null; date-window semantics/validation belong to the ability.

## GET /analytics/summary

**Parameters:** optional `days` integer (28), `event_type` string (empty, `sanitize_key`), `blog_id` integer (0, `absint`).

**Ability:** `extrachill/get-analytics-summary`. **Response:** event-count summary grouped by type for the selected rolling window and filters.

## GET /analytics/retention

**Parameters:** optional `days` integer (28), `blog_id` integer (0, `absint`), `cohort_weeks` integer (8), `start_date`, `end_date` strings.

**Ability:** `extrachill/get-retention-stats`. **Response:** deterministic bot-filtered retention object (return rate, weekly cohorts, cross-site return and session depth) from first-party pageviews.

## GET /analytics/conversion-map

**Parameters:** optional integers `days` (28), `session_gap_mins` (30), `top_articles` (25), `min_entry_sessions` (1), `author_id` (0, minimum 0); optional `start_date`, `end_date` strings. Author ID is additionally clamped nonnegative in the handler.

**Ability:** `extrachill/get-conversion-map`. **Response:** bot-filtered editorial-entry-to-platform conversion report, ranked by article/category, including same-session and return-visit conversions.

## GET /analytics/surface-growth

**Parameters:** optional `weeks` integer (4), `start_date`, `end_date` strings.

**Ability:** `extrachill/get-surface-growth`. **Response:** normalized supply/inventory and organic-session demand growth report per live surface, including fastest-growing ranking.
