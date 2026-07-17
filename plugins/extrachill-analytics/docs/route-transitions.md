# Route Transition Report

`extrachill/get-route-transitions` is a bounded, read-only report over the existing network pageview event table. It does not create sessions or journeys in storage.

## Semantics

- A route is `(blog_id, route_family)`. This preserves multisite surface identity without introducing a second surface registry.
- A session ends only when the gap between adjacent pageviews is greater than `session_gap_mins`, matching `extrachill/get-conversion-map` exactly.
- The reader requests one inactivity gap before the lower window boundary. Sessions that began before the requested window are excluded rather than reported as partial entries.
- A transition is every adjacent A -> B pair in a session. Same-route loops are retained.
- A sequence is every sliding window of exactly `sequence_length` route observations. The accepted length is 2 through 5.
- An entry is the first route in a session. A terminal is the last. A one-page session is both an entry and a direct terminal.
- `first_time` means the session contains the visitor's first observed pageview in all available history. Later sessions are `returning`. The acquisition lookup uses the existing `(visitor_id, created_at)` index.
- Pageviews without a visitor identity cannot be sessionized. They are excluded from rankings and disclosed in coverage.
- Historical post-backed rows without `route_family` are inferred as `singular`. Rows lacking both route family and post identity remain `unclassified`.

## Complexity Limits

- `days`: 1-90
- `session_gap_mins`: 1-120
- `sequence_length`: 2-5
- ranked `limit`: 1-100
- loaded identified pageviews: 100-25,000, default 10,000
- acquisition lookups: batches of at most 500 identities

The indexed event read selects the most recent `max_pageviews + 1` rows to detect truncation. When `coverage.truncated` is true, `period.ranking_since` advances one complete inactivity gap beyond the sample cutoff so a partially loaded session cannot enter the report. Rankings describe that bounded recent stream and are not represented as complete-window totals. Coverage remains an explicit aggregate for the requested period.

The ability uses the standard `extrachill_analytics_can_read_reports` permission callback and is marked read-only, idempotent, non-destructive, and unavailable through REST.
