# Extra Chill News Wire abilities

Extra Chill News Wire registers one ability, in the `extrachill-news-wire` category (registered by this plugin). It is a bounded, read-only projection of recent public Festival Wire stories for other network sites, without exposing Wire storage details.

### `extrachill-news-wire/get-recent-activity`

- **Purpose:** Returns the newest published Festival Wire (`festival_wire`) posts in a stable, versioned shape for cross-site composition.
- **Input:** (`additionalProperties: false`, defaults to `{}`)
  - `limit` (integer, optional, default 10, range 1–20). Out-of-range values are clamped.
- **Output:** `{ schema_version: "1", items: [...] }`, newest first, at most `limit` items.
  - Each item is `{ canonical_url, title, timestamp, image, summary, source, type }`.
  - `timestamp` is a W3C GMT timestamp.
  - `image` is the `medium_large` thumbnail URL or `null`.
  - `summary` is the plain-text excerpt or `null`.
  - `source` is always `"extra-chill-news-wire"` and `type` is always `"festival-wire"`.
  - Only published posts without a password are included.
- **Permission:** `__return_true`, so it is public.
- **Exposure:** `show_in_rest` true (annotations: readonly, idempotent, not destructive). No `extrachill-api` route or `wp extrachill` command found. In-repo caller: the Extra Chill Blog homepage (`extrachill_blog_get_wire_latest()` in `plugins/extrachill-blog/inc/home/homepage-queries.php`). It requests `GET /wp-abilities/v1/abilities/extrachill-news-wire/get-recent-activity/run` on the `wire` site through a cached cross-site loopback request.
