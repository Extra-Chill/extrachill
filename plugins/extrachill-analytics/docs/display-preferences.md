# Display preference reporting

`extrachill/get-display-preferences` is the read ability for the CLI follow-up in **extrachill-cli**. No CLI wiring is included here.

Inputs follow retention reporting: paired inclusive UTC `start_date` / `end_date` (`Y-m-d`), or `days` (default 28) with `end_days_ago` (default 0). Optional `blog_id` limits the report; 0 means network-wide. Permission is `extrachill_analytics_can_read_reports`, and the ability is not REST-exposed.

The response contains `since` (inclusive), `until` (exclusive), `overall`, and `by_blog` keyed by blog ID. Each scope contains `pageviews` and `unique_visitors`, each with `total`, `counts`, and fractional `shares` for `dark`, `light`, and `unknown`. Empty scopes have zero counts and shares; an empty window has no blog entries.

Unique visitors exclude NULL/empty visitor IDs. Each visitor is assigned their **latest pageview's** scheme within the window, breaking timestamp ties by event ID. This is computed independently overall and per blog: overall visitors are not a sum of blog visitors. A visitor changing preferences therefore occupies exactly one bucket per scope. A latest observation without a valid scheme counts as unknown, not the visitor's previous known scheme.

Legacy rows without `color_scheme` count as unknown. Anonymous/GPC/DNT pageviews remain in raw counts but not visitor counts. Collection adds only low-entropy preferences to existing pageview JSON; no tables, cookies, rendered HTML, bot gates, or identity rules change.
