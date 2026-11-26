# Upcoming event discovery (#1798)

## Summary / proposed PR body

Adds `wp-sitemap-upcoming-events-1.xml` (paginated at WordPress's configured
limit) before the core providers in the sitemap index. Only published events
matching Data Machine Events' `UpcomingFilter` enter this lane, including ongoing
events. Missing dates do not enter it. Newest-modified events lead, with descending
ID as the deterministic tie-break. Each URL includes its GMT modification time.
The existing recurring ranking SQL excludes occurrences beyond the rolling four.

Both upcoming pages and core event pages receive index `lastmod` via
`wp_sitemaps_index_entry`. Each timestamp is the newest `post_modified_gmt` in
that exact ordered page, not the subtype-wide maximum. WordPress builds the
query with the shared retention/recurring filters; a scoped `posts_pre_query`
callback substitutes one aggregate SELECT over that limited query. It avoids
loading posts or caching empty query results. Empty pages have no fabricated date.

### Decisions

- Keep upcoming URLs in the existing ID-ordered sitemap pages too. Cross-sitemap
  duplicates are valid; retaining them avoids disrupting existing discovery while
  the small upcoming lane gives crawlers a more focused entry point. At current
  volume this is roughly 31k upcoming URLs instead of 137k mixed URLs, paginated
  rather than truncated to a single file.
- Order by most recently modified rather than start date: freshly published weekly
  occurrences and corrected shows reach the head immediately, even when an older
  event starts sooner.
- Register the upcoming provider while core registers its posts provider, before
  core stores that provider. This ensures first position without modifying private
  registry state or initializing WordPress's sitemap server prematurely.
- Leave the 180-day past-event retention and robots policies unchanged. Shortening
  sitemap-only retention is worth a follow-up to reduce crawl volume, separately
  from whether past event pages remain indexable. Stable residency/series URLs
  remain a product decision for Chubes, outside this change.

### Tests

Targeted `UpcomingSitemapTest|ExpiredEventsTest|RecurringEventsTest`: **19 tests,
57 assertions passed** using locally available PHPUnit 9.6 (which warns that the
project's newer PHPUnit configuration contains unsupported attributes).
SQL-backed membership/order tests use SQLite with real core sitemap provider
fixtures and a minimal WP_Query SQL-building boundary. They exercise publication,
ongoing/recent/expired/undated events, recurring ranks, pagination, registration
order, per-page GMT timestamps, and one aggregate per index entry. Fixtures are
from WordPress wordpress-develop `src/wp-includes/sitemaps/` (GPL-2.0-or-later).

Homeboy owns the declared full test/lint gates; they were not run in this session.
No version or changelog changes. Chubes merges; do not auto-merge.

Closes Extra-Chill/extrachill#1798.
