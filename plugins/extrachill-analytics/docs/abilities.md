# Analytics abilities

The plugin registers event collection, traffic and journey reports, experiments, revenue diagnostics, and operational health abilities in the `extrachill-analytics` category. These are the domain layer: API routes and CLI commands below are adapters, while `show_in_rest` controls the core `/wp-abilities/v1` runner.

Inputs below reflect `input_schema`; fields are optional unless marked required. Callbacks may additionally validate combinations or return `WP_Error` rather than the success schema. API paths are relative to `/wp-json/extrachill/v1`; “none found” means no direct API route or registered CLI caller was found, not that PHP callers cannot execute the ability. Registrations and callbacks live in [inc/core/abilities](../inc/core/abilities/) and [inc/core/abilities.php](../inc/core/abilities.php).

## Event collection

### `extrachill/track-analytics-event`

- **Purpose:** Record an analytics event to the network-wide events table. See the [plugin overview](../README.md).
- **Input:** `event_type` (string, required): Event type identifier (e.g., newsletter_signup, user_registration, search).; `event_data` (object, optional): Flexible payload data stored as JSON. [default []]; `source_url` (string, optional): URL of the page where the event occurred. [default ""]; `visitor_id` (string, optional): Optional anonymous first-party visitor UUID v4. Stored only if well-formed; never PII. Empty when the visitor opted out (GPC/DNT). [default ""].
- **Output:** `integer`: Event ID on success, 0 on failure. May return WP_Error on admission/validation failure.
- **Permission:** `__return_true` (no authentication/capability check); execute-time public event admission still applies.
- **Exposure:** `show_in_rest`: false. API: `/analytics/click`, `/analytics/impression`. CLI: none found.
- **Side effects:** Writes an admitted event to the network-wide analytics events table.

### `extrachill/track-page-view`

- **Purpose:** Record a pageview for an eligible public route and increment legacy counters for valid post-backed views. See the [plugin overview](../README.md).
- **Input:** `post_id` (integer, optional): Optional post ID for a singular post-backed view. Zero is accepted as the legacy route-view sentinel. [minimum 0]; `source_path` (string, required): Normalized query-free browser route path. [maxLength 512]; `route_family` (string, required): Bounded browser route family. [one of singular, home, archive, search, auth, directory, other]; `referrer` (string, optional): Optional raw client-side referrer (document.referrer). Normalized server-side to a host-only `referrer_host` (no query strings, no PII) on the pageview event. Empty for direct traffic. [default ""]; `utm_source` (string, optional): Optional utm_source from the landing URL. Normalized to a bounded slug; anything else is dropped. [maxLength 128]; `utm_medium` (string, optional): Optional utm_medium from the landing URL. Normalized to a bounded slug; anything else is dropped. [maxLength 128]; `utm_campaign` (string, optional): Optional utm_campaign from the landing URL. Normalized to a bounded slug; anything else is dropped. [maxLength 128]; `color_scheme` (string, optional) [one of dark, light]; `reduced_motion` (boolean, optional); `contrast` (string, optional) [one of more, less, no-preference]; `proof` (string, required): Cache-safe proof binding the rendered host, path, route family, and post. [maxLength 64].
- **Output:** `object`: Confirmation object with recorded flag. May return WP_Error on admission/validation failure.
- **Permission:** `__return_true`; execution rejects invalid signed host/path/route/post proofs and ineligible routes. Known crawler/headless UAs return recorded=false before admission or persistence.
- **Exposure:** `show_in_rest`: true. API: none found. CLI: none found.
- **Side effects:** Writes human pageview events, increments legacy post-meta view counters for post-backed views, and fires extrachill_link_page_view_recorded for link pages. Reads browser-minted first-party identity (anonymous on opt-out/custom-domain beacons); does not set cookies.

### `extrachill/track-cta-click`

- **Purpose:** Record a cta_click event for a design-system button or explicitly-tracked element, with no per-button emit code required.
- **Input:** `cta_override` (string, optional): Explicit CTA id from the clicked element's data-ec-track attribute. Overrides the automatic hash when present. [default ""; maxLength 80]; `label` (string, required): The clicked element's visible text, trimmed. [maxLength 80]; `dest` (string, required): Destination host+path (query string and fragment stripped), or the current page when the element has no href. [maxLength 512]; `placement` (string, required): The closest HTML5 landmark (header, main, footer, nav, aside), or the nearest ancestor data-ec-track-placement override. [maxLength 40]; `route` (string, required): Route family of the page the click occurred on, classified server-side at enqueue time. [one of singular, home, archive, search, auth, directory, other]; `source_url` (string, required): URL of the page where the click occurred. [maxLength 2048].
- **Output:** `integer`: Event ID on success, 0 on failure. May return WP_Error on admission/validation failure.
- **Permission:** `__return_true` (no authentication/capability check); execute-time public event admission still applies.
- **Exposure:** `show_in_rest`: true. API: none found. CLI: none found.
- **Side effects:** Writes an admitted event to the network-wide analytics events table.

### `extrachill/track-form-submit`

- **Purpose:** Record a form_submit event for a named form. Never carries field values.
- **Input:** `form` (string, required): The submitted form's identity: data-ec-track, then id, then name. [maxLength 80]; `placement` (string, required): The closest HTML5 landmark (header, main, footer, nav, aside), or the nearest ancestor data-ec-track-placement override. [maxLength 40]; `route` (string, required): Route family of the page the submit occurred on, classified server-side at enqueue time. [one of singular, home, archive, search, auth, directory, other]; `source_url` (string, required): URL of the page where the submit occurred. [maxLength 2048].
- **Output:** `integer`: Event ID on success, 0 on failure. May return WP_Error on admission/validation failure.
- **Permission:** `__return_true` (no authentication/capability check); execute-time public event admission still applies.
- **Exposure:** `show_in_rest`: true. API: none found. CLI: none found.
- **Side effects:** Writes an admitted event to the network-wide analytics events table.

### `extrachill/track-network-click`

- **Purpose:** Record a link click to another network site.
- **Input:** `dest_host` (string, required) [maxLength 253]; `dest_path` (string, required) [maxLength 512]; `label` (string, required) [maxLength 80]; `placement` (string, required) [maxLength 40]; `route` (string, required) [one of singular, home, archive, search, auth, directory, other]; `source_url` (string, required) [maxLength 2048].
- **Output:** `integer`: Event ID on success, 0 on failure. May return WP_Error on admission/validation failure.
- **Permission:** `__return_true` (no authentication/capability check); execute-time public event admission still applies.
- **Exposure:** `show_in_rest`: true. API: none found. CLI: none found.
- **Side effects:** Writes an admitted event to the network-wide analytics events table.

### `extrachill/track-content-link-click`

- **Purpose:** Record a click on a network link inside a post body.
- **Input:** `post_id` (integer, required) [minimum 1]; `proof` (string, required) [maxLength 128]; `dest_host` (string, required) [maxLength 253]; `dest_path` (string, required) [maxLength 512]; `label` (string, required) [maxLength 80]; `source_url` (string, required) [maxLength 2048].
- **Output:** `integer`: Event ID on success, 0 on failure. May return WP_Error on admission/validation failure.
- **Permission:** `__return_true` (no authentication/capability check); execute-time public event admission still applies. Execution additionally validates the source-post proof and destination.
- **Exposure:** `show_in_rest`: true. API: none found. CLI: none found.
- **Side effects:** Writes an admitted event to the network-wide analytics events table.

## Traffic, journeys and growth

### `extrachill/get-analytics-meta`

- **Purpose:** Returns analytics filter options: distinct event types and the blogs that have events.
- **Input:** No fields (empty object).
- **Output:** `object`: Object with event_types (string array) and blogs (array of {id, name}).
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: `/analytics/meta`. CLI: none found.

### `extrachill/get-analytics-summary`

- **Purpose:** Returns event counts grouped by type, plus date, source, and context detail when one event type is requested. See [API reports](../../extrachill-api/docs/routes/analytics/reports.md).
- **Input:** `days` (integer, optional): Number of days to look back. 0 for all time. [default 28]; `event_type` (string, optional): Filter to a specific event type. Empty for all types. [default ""]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0].
- **Output:** `object`: Summary with event_types and total count. An event_type filter also adds by_date, by_source, and by_context arrays.
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: `/analytics/events/summary`, `/analytics/summary`. CLI: `wp extrachill analytics summary`.

### `extrachill/get-retention-stats`

- **Purpose:** Returns first-party visitor-retention metrics (return rate, cohort retention, cross-site return, session depth) and attribution breakdowns, excluding bot-stamped pageviews while keeping unclassified legacy rows as human. See [API reports](../../extrachill-api/docs/routes/analytics/reports.md).
- **Input:** `start_date` (string, optional): Inclusive UTC window start in Y-m-d format. Must be paired with end_date and takes precedence over days/end_days_ago.; `end_date` (string, optional): Inclusive UTC window end in Y-m-d format. Must be paired with start_date.; `days` (integer, optional): Number of days the window spans. Default 28. [default 28]; `end_days_ago` (integer, optional): How many days ago the window ENDS. 0 (default) means the window ends now. A positive value shifts the whole window into the past, enabling an exact prior-period read (e.g. days=28, end_days_ago=28 reads the 28-day window immediately before the most recent 28 days). Applies to return rate, cross-site return, and session depth; the cohort curve anchors at now for relative reads and at end_date for exact reads. [default 0]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for network-wide (required for cross-site return). [default 0]; `cohort_weeks` (integer, optional): Number of weekly cohorts to compute for the retention curve. Default 8. [default 8].
- **Output:** `object`: Object with return_rate, cohort_retention, cross_site_return, session_depth, and the exact UTC window.
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: `/analytics/retention`. CLI: `wp extrachill analytics retention`.
- **Side effects:** May populate the shared report cache; does not mutate source analytics.

### `extrachill/get-display-preferences`

- **Purpose:** Dark, light and unknown counts and shares for pageviews and unique visitors, overall and by blog. See [display preferences](display-preferences.md).
- **Input:** `start_date` (string, optional): Inclusive UTC Y-m-d; must be paired with end_date.; `end_date` (string, optional): Inclusive UTC Y-m-d.; `days` (integer, optional) [default 28]; `end_days_ago` (integer, optional) [default 0]; `blog_id` (integer, optional) [default 0].
- **Output:** `object`: UTC window, overall and by_blog splits. Shares are fractions (0–1).
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics display-preferences`.

### `extrachill/get-route-transitions`

- **Purpose:** Returns bounded same-session route transitions, sequences, entries, and terminals from first-party pageviews across the multisite network. See [route transitions](route-transitions.md).
- **Input:** `days` (integer, optional): UTC lookback days, clamped to 1-90. Default 28. [default 28]; `blog_id` (integer, optional): Optional blog ID filter. Zero reports the network. Default 0. [default 0]; `session_gap_mins` (integer, optional): Inactivity gap in minutes that ends a session, clamped to 1-120. Default 30. [default 30]; `sequence_length` (integer, optional): Exact number of route observations per sequence, clamped to 2-5. Default 3. [default 3]; `cohort` (string, optional): Session-entry acquisition cohort. Default all. [default "all"; one of all, first_time, returning]; `limit` (integer, optional): Maximum rows in each ranking, clamped to 1-100. Default 25. [default 25]; `max_pageviews` (integer, optional): Maximum identified pageviews loaded, clamped to 100-25000. Default 10000. [default 10000].
- **Output:** `object`: Ranked transitions, exact-length sequences, entries, terminals, cohort counts, coverage, bounds, and UTC period.
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics route-transitions`.

### `extrachill/get-conversion-map`

- **Purpose:** First-party, bot-filtered editorial-to-platform conversion map: for visitors whose first eligible journey starts on a published blog-1 post, the share that reach an eligible collected route on events/community/artist same-session or on a return visit. Ranked per entry article and category. See [API reports](../../extrachill-api/docs/routes/analytics/reports.md).
- **Input:** `start_date` (string, optional): Inclusive UTC window start in Y-m-d format. Must be paired with end_date and takes precedence over days.; `end_date` (string, optional): Inclusive UTC window end in Y-m-d format. Pageviews and outcomes are strictly capped at this day.; `days` (integer, optional): Number of days to look back for the window. Default 28. [default 28]; `entry_surface` (string, optional): Entry surface to measure: blog_editorial (default) or published events-site singular pages. [default "blog_editorial"; one of blog_editorial, events]; `session_gap_mins` (integer, optional): Inactivity gap (minutes) that ends a session. Default 30 (GA-standard). [default 30]; `top_articles` (integer, optional): Number of top entry articles to rank. Default 25. [default 25]; `min_entry_sessions` (integer, optional): Minimum entry sessions for an article/category to appear in the ranked output. Default 1. [default 1]; `return_observation_days` (integer, optional): Minimum completed days after an entry journey before it enters the denominator. Excludes late-window entries with unequal return opportunity. Default 7. [default 7]; `author_id` (integer, optional): Optional primary WordPress author ID. Zero includes all authors. [default 0].
- **Output:** `object`: Object with overall conversion, per-article ranking, per-category ranking, and the exact UTC window.
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: `/analytics/conversion-map`. CLI: `wp extrachill analytics conversion`.
- **Side effects:** May populate the shared report cache; does not mutate source analytics.

### `extrachill/get-surface-growth`

- **Purpose:** Compare inventory growth and GA4 organic-session demand slopes across live surfaces, keeping supply and demand separate. Missing instruments are not_instrumented, not zero. See [API reports](../../extrachill-api/docs/routes/analytics/reports.md).
- **Input:** `start_date` (string, optional): Inclusive UTC window start in Y-m-d format. Must be paired with end_date and takes precedence over weeks.; `end_date` (string, optional): Inclusive UTC window end in Y-m-d format. The comparison is the immediately preceding equal-length window.; `weeks` (integer, optional): Number of weeks the growth window spans. Default 4. The demand slope compares this window against the immediately-preceding window of equal length. [default 4].
- **Output:** `object`: Object with per-surface supply + demand growth, a normalized comparable figure per surface, and a ranked fastest-growing surface, plus the exact UTC window.
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: `/analytics/surface-growth`. CLI: `wp extrachill analytics growth`.
- **Side effects:** May populate the shared report cache and calls the Data Machine Google Analytics primitive for demand reads; may make external GA4 requests.

### `extrachill/get-surface-stickiness`

- **Purpose:** Returns a per-surface ENGAGEMENT scorecard for community, artist, and events, plus authoritative network-owned ad-policy context. Community and artist are intentionally ad-free; Events is ad-enabled. Unmeasurable cells return a not_instrumented marker, never a zero.
- **Input:** `days` (integer, optional): Length of the current window in days. The trend compares this window against the immediately-preceding window of equal length. Default 28. [default 28].
- **Output:** `object`: Object with per-surface stickiness (engagement core + activity context) as current-vs-prior trends, a network-wide cross-surface traversal figure, and the exact UTC windows.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics stickiness`.

### `extrachill/get-demand-drill`

- **Purpose:** Rank per-page/query GSC click decliners and risers against the preceding equal window, ending three days ago. This is a lagged click delta, not the GA4 organic-session slope reported by get-surface-growth.
- **Input:** `surface` (string, optional): Surface key to drill (events, wire, community, artist, blog). Resolves to the surface host from the canonical surface map and scopes the GSC pull to that host. Omit when passing an explicit host/url_filter.; `host` (string, optional): Explicit host to scope the drill to (e.g. events.extrachill.com). Used as the GSC url_filter. Overrides the surface-derived host when both are given.; `weeks` (integer, optional): Number of weeks the current window spans. Default 4. The prior window is the immediately-preceding window of equal length. [default 4]; `dimension` (string, optional): Which contributor dimension(s) to return: "page", "query", or "both" (default). [default "both"]; `limit` (integer, optional): Max decliners and max risers to return per dimension (default 25). [default 25]; `min_clicks` (integer, optional): A page/query must have at least this many clicks in EITHER window to be considered a contributor (default 1). Filters out single-impression noise. [default 1]; `site_url` (string, optional): GSC property URL (sc-domain: or https://). Defaults to the configured property. Passed through to datamachine/google-search-console.; `query_filter` (string, optional): Restrict the drill to queries containing this string (passed through to GSC)..
- **Output:** `object`: Object with per-dimension (page, query) decliners and risers ranked by net click change, each carrying current/prior clicks and current/prior position, plus the surface/host and both UTC date windows.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics demand-drill`.
- **Side effects:** Calls the Data Machine Google Search Console ability, which may make external GSC requests.

### `extrachill/get-search-gaps`

- **Purpose:** Returns bot-filtered zero-result and low-result on-site search terms over a window — a content-demand report computed from existing search analytics.
- **Input:** `days` (integer, optional): Number of days to look back. 0 for all time. [default 28]; `limit` (integer, optional): Maximum terms to return per bucket. 0 for unlimited. [default 25]; `max_results` (integer, optional): Result-count ceiling that defines a "gap" term. 0 returns only zero-result searches; higher values include low-result near-misses (e.g. 3 = result_count between 1 and 3). [default 0]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0].
- **Output:** `object`: Report with zero_result and low_result term arrays, volume/health totals, and period metadata.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics search-gaps`.

### `extrachill/get-bot-filter-impact`

- **Purpose:** Deterministic guardrail: counts analytics events stamped is_bot:true that nonetheless carry a non-zero logged-in user_id, broken down by event_type — measuring how much authenticated human activity the visitor classifier is filtering out.
- **Input:** `days` (integer, optional): Number of days to look back. 0 for all time. [default 28]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0]; `examples` (integer, optional): Number of example rows to return for spot-checking. 0 to skip. [default 10].
- **Output:** `object`: Object with by_event_type breakdown, totals, example rows, and the exact UTC window.
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics bot-filter-impact`.

## Funnels, links and experiments

### `extrachill/get-activation-funnel`

- **Purpose:** Returns the artist-signup activation funnel as an ordered per-person funnel (user_id with visitor_id fallback) with step-to-step and overall conversion and the biggest abandon step.
- **Input:** `days` (integer, optional): Number of days to look back. 0 for all time. [default 28]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for network-wide. [default 0].
- **Output:** `object`: Object with separate upstream access_paths, a chronologically ordered per-person steps array (event_type, people, conversion_from_prev, conversion_from_top), the biggest_abandon_step, an anomalies array of friction signals (duplicate profile creation, re-registration attempts) each with DISTINCT people and raw event counts, and the exact UTC window.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: none found.

### `extrachill/get-cta-funnel`

- **Purpose:** Per-CTA funnel for a page path and/or route: pageviews, distinct clicking visitors per CTA, and later conversions (user_registration, newsletter_signup, artist_signup_started) by those same visitors, ordered strictly after their click.
- **Input:** `path` (string, optional): Normalized page path to scope the funnel to, e.g. /power/. Matches the stored pageview/cta_click source_url exactly. [default ""]; `route` (string, optional): Route family to scope the funnel to, instead of or alongside a path. [default ""]; `cta` (string, optional): Limit the report to a single CTA id. [default ""]; `days` (integer, optional): Number of days to look back for pageviews and clicks. 0 for all time. Conversions are never bounded by this window (see the ability description). [default 28]; `blog_id` (integer, optional): Filter pageviews and clicks to a specific blog ID. 0 for network-wide. [default 0]. Execution requires path or route despite neither being schema-required.
- **Output:** `object`: Object with pageviews, a ctas array (cta, label, dest, placement, clicks, visitors, conversions, converted_visitors, conversion_rate), and the window.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: none found.

### `extrachill/get-bridge-ctr`

- **Purpose:** Returns independently stored, bot-filtered bridge click and impression event counts and their potentially unbounded ratio.
- **Input:** `days` (integer, optional): Number of days to look back. 0 for all available history within max_events. [default 28]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0]; `max_events` (integer, optional): Maximum stored bridge rows to inspect. [default 50000].
- **Output:** `object`: Stored bridge click and impression counts, their ratio, destination aggregates, and coverage diagnostics.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: none found.

### `extrachill/get-outbound-clicks`

- **Purpose:** First-party outbound-click report: where readers exit the network to external domains, grouped by category, top destination host, and top source page. Deterministic from the sendBeacon outbound_click events.
- **Input:** `days` (integer, optional): Number of days to look back. 0 for all time. [default 28]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0]; `category` (string, optional): Filter to a single destination category (spotify|social|ticketing|artist-site|merch|other). Empty for all. [default ""]; `limit` (integer, optional): Number of rows to return for the destination and source rankings. [default 25]; `include_bots` (boolean, optional): Include bot-stamped outbound events for diagnostics. Human-only by default. [default false].
- **Output:** `object`: Object with total, by_category, by_destination, by_source, the window, and a diagnostic when stored rows lack destination dimensions.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics outbound`.

### `extrachill/get-crosslink-clicks`

- **Purpose:** Per source post: in-article network link clicks, unique visitors, and click rate over human pageviews, with each destination marked inserted (Data Machine) or manual.
- **Input:** `days` (integer, optional): Number of days to look back. 0 for all time. [default 28]; `blog_id` (integer, optional): Site to report on. 0 for the current site. [default 0]; `post_id` (integer, optional): Limit the report to one source post. 0 for all. [default 0]; `limit` (integer, optional): Number of source posts to return. [default 25]; `include_bots` (boolean, optional): Include bot-stamped events for diagnostics. Human-only by default. [default false].
- **Output:** `object`: Object with totals, posts (per source post rows with destinations), the window, and the site.
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics crosslink-clicks`.

### `extrachill/get-crosslink-targets`

- **Purpose:** Join returning entry-article journeys to the Data Machine inbound link graph to rank publication pages needing links TO them. It neither identifies source pages nor inserts links.
- **Input:** `days` (integer, optional): Look-back window (days) for the conversion-map funnel. Default 28. [default 28]; `session_gap_mins` (integer, optional): Inactivity gap (minutes) that ends a session, passed through to get-conversion-map. Default 30. [default 30]; `scan_articles` (integer, optional): How many top entry articles to pull from get-conversion-map before the link-graph join. Default 100. [default 100]; `limit` (integer, optional): Maximum number of ranked crosslink targets to return after the join. Default 25. [default 25]; `max_inbound` (integer, optional): Inbound-link ceiling for a page to count as a crosslink target. 0 = orphans only; 2 = orphans plus thinly-linked pages. Default 2. [default 2]; `min_returned` (integer, optional): Minimum returned (2nd-session) journeys for an article to qualify. Default 1. [default 1]; `force_audit` (boolean, optional): Force a fresh link-graph audit instead of using the cached graph. Default false. [default false].
- **Output:** `object`: Object with ranked high-value publication pages needing contextual links TO them, the join inputs (conversion + link-graph), and the exact window. Target rows do not identify source-page candidates or prescribe outbound destinations. The link_graph block reports inbound_orphan_count (zero-INBOUND orphans, orphan_definition=zero_inbound) — NOT comparable to the zero-OUTBOUND posts_without_links count from `wp datamachine links diagnose`.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics crosslink-targets`.
- **Side effects:** May refresh the Data Machine link-graph audit cache (force_audit requests a fresh audit); does not modify article links.

### `extrachill/get-link-page-analytics`

- **Purpose:** Returns aggregated analytics data for an artist link page. See [link-page analytics](../../extrachill-api/docs/routes/analytics/link-page.md).
- **Input:** `link_page_id` (integer, required): The link page post ID.; `date_range` (integer, optional): Number of days to query. Defaults to 30. [default 30]; `start_date` (string, optional): Inclusive start date in Y-m-d format. Requires end_date.; `end_date` (string, optional): Inclusive end date in Y-m-d format. Requires start_date..
- **Output:** `object`: Aggregated analytics data for the link page. The callback returns the array supplied by the `extrachill_get_link_page_analytics` filter unchanged; it does not itself enforce nested fields. Invalid ownership, dates or missing provider return WP_Error.
- **Permission:** `is_user_logged_in`; execution validates the stored link page, resolves its owning artist, and requires `ec_can_manage_artist` for that artist.
- **Exposure:** `show_in_rest`: true. API: `/analytics/link-page`. CLI: none found.

### `extrachill/get-experiment-summary`

- **Purpose:** Report bounded assignment, exposure, outcome, lift, confidence, identity, privacy, and version coverage for one code-registered experiment. See [experiment summary](experiment-summary.md).
- **Input:** `experiment_key` (string, required) [pattern ^[a-z0-9_-]+$]; `definition_version` (integer, optional) [minimum 1; maximum 1000000]; `control_variant` (string, required) [pattern ^[a-z0-9_-]+$]; `variants` (array[string], required) [minItems 2; maxItems 8]; `outcome_event_types` (array[string], optional) [maxItems 10]; `days` (integer, optional) [default 28; minimum 1; maximum 90]; `attribution_window_days` (integer, optional) [default 28; minimum 1; maximum 90]; `session_gap_mins` (integer, optional) [default 30; minimum 1; maximum 120]; `max_events` (integer, optional) [default 1000000; minimum 100; maximum 2000000].
- **Output:** `object`: Descriptive intent-to-treat and exposure-conditioned results. No winner is selected.
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: none found.

### `extrachill/get-geo-bridge-experiment`

- **Purpose:** Report the bounded geo-bridge-holdout assignment, viewport exposure, bridge click, route transition, and lifecycle outcome experiment. See [geo bridge experiment](geo-bridge-experiment.md).
- **Input:** `days` (integer, optional): UTC assignment and attribution lookback, clamped to 1-90 days. Default 28. [default 28]; `session_gap_mins` (integer, optional): Pageview inactivity gap separating same and later sessions, clamped to 1-120 minutes. Default 30. [default 30]; `max_events` (integer, optional): Maximum relevant event rows inspected, clamped to 100-100000. Default 50000. [default 50000].
- **Output:** `object`: Control and treatment results with explicit attribution, coverage, privacy, and query bounds.
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: none found.

## Revenue and delivery

### `extrachill/get-content-revenue`

- **Purpose:** Roll up imported per-URL Mediavine revenue by editorial format/category or chronological time bucket, separating non-editorial content and unresolved routes. Revenue is imported, never estimated; lifetime exports are time-blind.
- **Input:** `group_by` (string, optional): Rollup axis: "format" (default), "category", "both", or "timeseries" (the revenue ARC — revenue/views per time bucket, chronologically, to see month-over-month and the HCU cliff in dollars). [default "format"]; `period` (string, optional): Scope the category/format rollup to one time bucket, e.g. "2026-05" or "all-time". Empty = every bucket combined. Ignored for the timeseries axis (which spans all buckets). [default ""]; `period_start` (string, optional): Inclusive window start (Y-m-d). With period_end, restricts to snapshots imported for that window (the recent lens). Empty = all snapshots (lifetime). [default ""]; `period_end` (string, optional): Inclusive window end (Y-m-d). See period_start. [default ""]; `include_alltime` (boolean, optional): For the timeseries axis, include the cumulative "all-time" flat-file bucket (default false — it is a lifetime total, not a point on the arc). [default false]; `import_batch` (string, optional): Restrict to one import batch so multiple imports are never double-counted. Empty = all batches. [default ""]; `blog_id` (integer, optional): Blog ID whose revenue to read. 0 = current blog. [default 0]; `hostname` (string, optional): Optional hostname scope. Resolved content matches its canonical owner host; unresolved rows require an absolute source URL with matching host. Empty = all hosts. [default ""].
- **Output:** `object`: Rollups keyed by axis, each with pages, views, revenue, rpm, dollars_per_page, plus totals and caveats.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics revenue rollup` / `wp extrachill analytics revenue arc`.

### `extrachill/get-content-revenue-pages`

- **Purpose:** Rank resolved posts and unresolved routes with imported revenue, delivery metrics, metadata and distinct derived/source RPM. Defaults to the freshest dated period and batch, preserving zero-view rows.
- **Input:** `period` (string, optional): Scope to one time bucket, e.g. "2026-05" or "all-time". Empty = default to the freshest dated period (see window contract). [default ""]; `period_start` (string, optional): Inclusive window start (Y-m-d). With period_end, restricts to snapshots imported for that window. [default ""]; `period_end` (string, optional): Inclusive window end (Y-m-d). See period_start. [default ""]; `import_batch` (string, optional): Restrict to one import batch. Empty = all batches in the scope. [default ""]; `blog_id` (integer, optional): Blog ID whose revenue to read. 0 = current blog. Cross-blog reads require manage_network_options. [default 0]; `hostname` (string, optional): Optional hostname scope. Resolved content matches its canonical owner host; unresolved rows require an absolute source URL with matching host. Empty = all hosts. [default ""]; `cohort` (string, optional): Page cohort: "resolved" (published content only, default), "unresolved" (route cohort), or "all". [default "resolved"; one of resolved, unresolved, all]; `min_views` (integer, optional): Exclude pages with fewer than this many views. Default 0 (no floor). [default 0]; `sort_by` (string, optional): Sort key. Default derived_rpm. [default "derived_rpm"; one of views, revenue, derived_rpm, source_rpm, cpm, viewability, fill_rate, impressions_per_pageview, dollars_per_page, benchmark_opportunity]; `order` (string, optional): Sort direction. Default desc. [default "desc"; one of desc, asc]; `limit` (integer, optional): Maximum pages to return. 0 = unlimited. Default 50. [default 50]; `include_post_meta` (boolean, optional): Include post metadata (title, URL, path, category, format, publication date) on resolved pages. Default true. [default true].
- **Output:** `object`: Event ID on success, 0 on failure. Top-level fields: `success` (boolean), `rows` (integer), `blog_id` (integer), `window` (string), `cohort` (string), `sort_by` (string), `order` (string), `ad_policy` (object), `scope` (object), `pages` (array[object]), `totals` (object), `sample` (object), `caveat` (string), `error` (string).
- **Permission:** `manage_options` or `manage_network_options` capability, or active `WP_CLI`. Execution requires target-site authorization; cross-blog reads require network administration (CLI allowed).
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics revenue pages`.

### `extrachill/get-content-revenue-diagnostics`

- **Purpose:** Check revenue-store freshness, missing/duplicate periods, independent totals reconciliation, resolution, metric ranges and provenance. Checks report pass/warning/fail; an empty scope never passes.
- **Input:** `period` (string, optional): Scope row-level checks to one time bucket, e.g. "2026-05" or "all-time". Empty = whole store (the integrity view). Freshness/missing/duplicate checks always consider the whole per-blog history. [default ""]; `period_start` (string, optional): Inclusive window start (Y-m-d). [default ""]; `period_end` (string, optional): Inclusive window end (Y-m-d). [default ""]; `import_batch` (string, optional): Restrict row-level checks to one import batch. Empty = all batches. [default ""]; `blog_id` (integer, optional): Blog ID whose revenue to diagnose. 0 = current blog. Cross-blog reads require manage_network_options. [default 0]; `hostname` (string, optional): Optional hostname for resolving relative slugs to posts. Empty = the target blog hostname. [default ""].
- **Output:** `object`: Event ID on success, 0 on failure. Top-level fields: `success` (boolean), `blog_id` (integer), `window` (string), `overall_status` (string), `summary` (object), `checks` (array[object]), `scope` (object), `caveat` (string), `error` (string).
- **Permission:** `manage_options` or `manage_network_options` capability, or active `WP_CLI`. Execution requires target-site authorization; cross-blog reads require network administration (CLI allowed).
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics revenue diagnose`.

### `extrachill/get-delivery-coverage`

- **Purpose:** Compare GA4 screenPageViews and imported Mediavine pageviews over the same inclusive window for policy-confirmed ad-eligible sites. Missing sources/policy or non-exact snapshots are unknown, not zero.
- **Input:** `start_date` (string, required): Inclusive window start in Y-m-d format.; `end_date` (string, required): Inclusive window end in Y-m-d format.; `blog_ids` (array[integer], optional): Optional blog IDs to inspect. Empty means every active network site; policy decides applicability. [default []].
- **Output:** `object`: Exact window, source contracts, thresholds, per-site status and evidence, and status summary.
- **Permission:** `extrachill_analytics_can_read_reports`: `manage_options` or `access_studio` capability, or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: none found.
- **Side effects:** Calls the Data Machine Google Analytics primitive; may make external GA4 requests. Does not mutate revenue or ad policy.

### `extrachill/ingest-revenue`

- **Purpose:** Idempotently replace a deterministic source/site/blog/period revenue snapshot, or add a separately identified snapshot. Requires non-empty rows, supports dry-run, and never adopts or deletes legacy batches.
- **Input:** `rows` (array[object], required): REQUIRED, non-empty. Normalized per-URL revenue rows. Each row carries a slug/url plus metrics (views, revenue, rpm, cpm, viewability, fill_rate, impressions_per_pageview) and an optional pre-resolved post_id. Rows sharing a normalized slug are deterministically aggregated into one canonical row with summed views/revenue and derived or weighted rates. [minItems 1]; `blog_id` (integer, optional): Blog the rows belong to. 0 = current blog. Replace authorizes against this target blog (target-blog capability) or requires network-level capability. [default 0; minimum 0]; `hostname` (string, optional): Hostname for slug->post resolution (default extrachill.com). [default "extrachill.com"]; `source` (string, optional): Source provenance slug, e.g. "mediavine". Part of the deterministic snapshot identity. Default mediavine. [default "mediavine"]; `source_site` (string, optional): Source site / host the rows were pulled for. Part of the snapshot identity so the same period for different sites never collides. Defaults to the hostname. [default ""]; `period` (string, optional): Period token: YYYY-MM (monthly), YYYY (yearly), or "" for the flat lifetime file. Part of the snapshot identity and the canonical period_label the ARC groups by. [default ""; pattern ^(?:\d{4}(?:-(?:0[1-9]|1[0-2]))?)?$]; `period_start` (string, optional): Explicit window start (Y-m-d) override. Must be a valid date when non-empty. Defaults to the start derived from period. [default ""; pattern ^(?:\d{4}-\d{2}-\d{2})?$]; `period_end` (string, optional): Explicit window end (Y-m-d) override. Must be a valid date when non-empty. Defaults to the end derived from period. [default ""; pattern ^(?:\d{4}-\d{2}-\d{2})?$]; `mode` (string, optional): "replace" (default) updates only the deterministic snapshot in place and removes stale rows only from that snapshot; "additive" never deletes and requires an explicit snapshot identity plus network-level authorization. Existing legacy batches require an explicit operator migration or purge outside this ability. [default "replace"; one of replace, additive]; `snapshot` (string, optional): Explicit snapshot identity (batch label). REQUIRED for additive mode (must differ from the deterministic replace identity). Ignored for replace, which always uses the deterministic identity. [default ""; maxLength 64; pattern ^(?:[a-z0-9][a-z0-9_-]{0,63})?$]; `dry_run` (boolean, optional): If true, resolve + plan but write nothing. Reported counts reflect what would happen within the exact target snapshot. [default false]. Each row is an object with slug or url required, plus optional post_id (integer), views (integer), and revenue, rpm, cpm, viewability, fill_rate, impressions_per_pageview (numbers).
- **Output:** `object`: Result with success, written, mode, dry_run, counts (rows, input_rows, duplicate_rows_deduped, resolved, unresolved, inserted, replaced, deleted, and would_delete on dry run), and the stable identity (import_batch, period_label, period_start/end, source, source_site, blog_id). On failure: success=false, written=false, error. Top-level fields: `success` (boolean), `written` (boolean), `mode` (string), `dry_run` (boolean), `rows` (integer), `input_rows` (integer), `duplicate_rows_deduped` (integer), `resolved` (integer), `unresolved` (integer), `inserted` (integer), `replaced` (integer), `deleted` (integer), `would_delete` (integer), `error` (string), `identity` (object).
- **Permission:** `manage_options` or `manage_network_options` capability, or active `WP_CLI`. Execution authorizes replace against the target blog (or network); additive requires network-level authorization (CLI allowed).
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics revenue fetch`.
- **Side effects:** Unless dry_run, resolves and persists revenue rows inside a transaction and per-period advisory lock; replace updates/inserts rows and deletes stale rows only in its exact snapshot. Additive never deletes.

## 404s and attacks

### `extrachill/get-404-summary`

- **Purpose:** Returns 404 error summary statistics with totals, unique counts, and daily breakdown.
- **Input:** `days` (integer, optional): Number of days to look back. [default 7]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0].
- **Output:** `object`: Summary with total, unique URLs/IPs, daily average, and daily breakdown.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics 404 summary`.

### `extrachill/get-404-top-urls`

- **Purpose:** Returns the most frequently hit 404 URLs with counts and categories.
- **Input:** `days` (integer, optional): Number of days to look back. [default 7]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0]; `limit` (integer, optional): Maximum number of URLs to return. [default 30]; `min_hits` (integer, optional): Minimum number of hits to include a URL. [default 2].
- **Output:** `array`: Array of top 404 URLs with url, hits, last_seen, and category.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics 404 top`.

### `extrachill/get-404-top-ips`

- **Purpose:** Returns the top IP addresses (hashed) generating 404 errors with hit counts and metadata.
- **Input:** `days` (integer, optional): Number of days to look back. [default 7]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0]; `limit` (integer, optional): Maximum number of IPs to return. [default 15].
- **Output:** `array`: Array of top IP hashes with hits, unique_urls, first_seen, last_seen, and top_ua.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics 404 top-ips`.

### `extrachill/get-404-patterns`

- **Purpose:** Aggregates 404 URLs by pattern category with hit counts and percentages.
- **Input:** `days` (integer, optional): Number of days to look back. [default 7]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0].
- **Output:** `array`: Array of pattern categories with hits, unique_urls, pct, and actionable flag.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics 404 patterns`.

### `extrachill/drill-404-category`

- **Purpose:** Drills into a specific 404 category showing individual URLs with optional redirect suggestions.
- **Input:** `category` (string, required): Category name to drill into (e.g. legacy-html, content, bot-probe).; `days` (integer, optional): Number of days to look back. [default 7]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0]; `limit` (integer, optional): Maximum number of URLs to return. [default 20].
- **Output:** `array`: Array of URLs in the category with hits, last_seen, and optional slug/post_id/fixable fields.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics 404 drill`.

### `extrachill/list-404-events`

- **Purpose:** Returns raw 404 event records with URL, referer, user agent, IP hash, and date.
- **Input:** `days` (integer, optional): Number of days to look back. [default 7]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0]; `limit` (integer, optional): Maximum number of events to return. [default 50].
- **Output:** `array`: Array of 404 event records. Each record has url, referer, user_agent, ip_hash, date, blog_id.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics 404 list`.

### `extrachill/purge-404-events`

- **Purpose:** Deletes old 404 error events from the analytics database. Supports dry-run mode.
- **Input:** `days` (integer, optional): Delete events older than this many days. [default 30]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0]; `dry_run` (boolean, optional): If true, only count without deleting. [default true].
- **Output:** `object`: Object with count of affected events and whether deletion occurred. `{count: integer, deleted: boolean}`.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics 404 purge`.
- **Side effects:** With dry_run=false, deletes matching 404 rows. days<=0 removes the age restriction; default dry_run=true only counts.

### `extrachill/get-attack-summary`

- **Purpose:** Summarizes search_attack events by pattern, day, IP, or URL.
- **Input:** `days` (integer, optional): Number of days to look back. 0 for all time. [default 28]; `group_by` (string, optional): Grouping dimension: pattern, day, ip, or url. [default "pattern"; one of pattern, day, ip, url]; `limit` (integer, optional): Maximum rows to return. 0 for unlimited. [default 25]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0].
- **Output:** `object`: Summary with rows array, totals, and period metadata.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics attacks`.

### `extrachill/get-scanner-404-summary`

- **Purpose:** Summarizes scanner/attack-shaped 404 events (secret/config probes, plugin/wp-includes probing, user enumeration, SQLi-in-URL) by category, day, IP, or URL. Companion to get-attack-summary, which covers on-site search injection.
- **Input:** `days` (integer, optional): Number of days to look back. 0 for all time. [default 28]; `group_by` (string, optional): Grouping dimension: category, day, ip, or url. [default "category"; one of category, day, ip, url]; `limit` (integer, optional): Maximum rows to return. 0 for unlimited. [default 25]; `blog_id` (integer, optional): Filter to a specific blog ID. 0 for all sites. [default 0].
- **Output:** `object`: Summary with rows array, scanner total, benign total, and period metadata.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: none found.

## PHP and HTTP health

### `extrachill/get-php-error-summary`

- **Purpose:** Buckets WordPress PHP debug.log errors by normalized signature with stable per-day rates that survive log rotation.
- **Input:** `days` (integer, optional): Number of days to look back. 0 for all available history. [default 7]; `seconds` (integer, optional): Overrides days with a trailing duration in seconds (0 = all history). Live entries are timestamp-filtered; persisted daily rows are filtered by last_seen but cannot be split at the cutoff. `window_seconds` and `window_note` report the duration and this limitation; per_day rates normalize to fractional days. `severity` (string, optional): Filter by severity. [default "all"; one of all, fatal, warning, notice, deprecated, strict, parse]; `limit` (integer, optional): Maximum signatures to return. 0 for unlimited. [default 25]; `snapshot` (boolean, optional): Run a live snapshot pass before reporting so the current log tail is captured. [default false].
- **Output:** `object`: Summary with rows array (signature, severity, file:line, count, per_day, sample), totals, and window metadata.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics errors`.
- **Side effects:** snapshot=true runs a live log snapshot pass, persisting daily signature counts before reporting.

### `extrachill/get-rest-5xx-summary`

- **Purpose:** Counts REST responses with status >= 500 by matched route and error code over a trailing window (max 7 days).
- **Input:** `hours` (integer, optional) [default 24; minimum 1; maximum 168]; `limit` (integer, optional) [default 10; minimum 0; maximum 8400].
- **Output:** `object`: total, per_day, window_hours, top, and rows (route, code, count, per_day, peak_per_hour).
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics errors`.

### `extrachill/get-http-5xx-summary`

- **Purpose:** Counts web server responses with status >= 500 (including gateway errors that never reach WordPress) by path class and status over a trailing window (max 7 days).
- **Input:** `hours` (integer, optional) [default 24; minimum 1; maximum 168]; `limit` (integer, optional) [default 10; minimum 0; maximum 8400].
- **Output:** `object`: total, per_day, window_hours, top, and rows (route = path class, code = HTTP status, count, per_day, peak_per_hour).
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: `wp extrachill analytics errors`.

### `extrachill/check-fatal-alarm`

- **Purpose:** Evaluate the live debug.log tail for new or spiking PHP fatal signatures. With dry_run, reports what would alarm without delivering. With test_delivery, sends a labelled synthetic alarm to verify delivery.
- **Input:** `dry_run` (boolean, optional): Compute and return what would alarm without delivering or mutating dedupe state. [default true]; `test_delivery` (boolean, optional): Send one clearly labelled synthetic alarm through the configured delivery channels. Overrides dry_run; never writes dedupe state. [default false].
- **Output:** `object`: Evaluation result: alarms[], candidates[], threshold, window, and beacon delivery status.
- **Permission:** `manage_options` capability or an active `WP_CLI` context.
- **Exposure:** `show_in_rest`: false. API: none found. CLI: none found.
- **Side effects:** Reads the live log; non-dry evaluation can deliver configured alarm beacons and update network dedupe state. test_delivery overrides dry_run to send a synthetic alarm without updating dedupe state.
