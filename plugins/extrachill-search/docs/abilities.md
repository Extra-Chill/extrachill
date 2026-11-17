# Extra Chill Search abilities

Extra Chill Search registers one ability, in the `extrachill-search` category (registered by this plugin). It wraps the network-wide search primitive `extrachill_network_search()`, which also powers the frontend results page.

### `extrachill/multisite-search`

- **Purpose:** Searches posts across all network sites, or a chosen subset, and ranks the results by relevance.
- **Input:**
  - `search_term` (string, required).
  - `site_urls` (string[], optional): site URLs or domains to limit the search to. When empty, all network sites are searched.
  - `limit` (integer, default 10).
  - `offset` (integer, default 0).
  - `post_status` (string[], default `["publish"]`).
  - `orderby` (string, default `date`).
  - `order` (string, default `DESC`).
  - `return_count` (boolean, default false).
- **Output:**
  - When `return_count` is false: an array of result objects. Each has `ID`, `post_title`, `post_content`, `post_excerpt`, `post_date`, `post_modified`, `post_type`, `post_name`, `post_author`, `site_id`, `site_name`, `site_url` (host), `permalink`, `taxonomies`, `thumbnail` (`thumbnail_id`, `thumbnail_url`, `thumbnail_srcset`, `thumbnail_sizes`, `thumbnail_alt`) and `_search_score`.
  - When `return_count` is true: `{ results, total }`.
  - Returns an empty array outside multisite or when no sites resolve.
  - Errors: `missing_search_term` (400); `search_function_unavailable` (500).
- **Permission:** `current_user_can( 'read' )`, so any logged-in user with the `read` capability on the current site can call it.
  - `post_status` is passed through to the per-site `WP_Query` unchanged. The ability itself does not stop a `read`-level caller from asking for non-public statuses.
  - The ability does not support the primitive's `meta_query` and `tax_query` arguments.
- **Exposure:** `show_in_rest` true (annotations: readonly, idempotent, not destructive, with agent `instructions`). No `extrachill-api` route or `wp extrachill` command found that calls the ability. Those surfaces call `extrachill_network_search()` directly where they need search.
- **Side effects:**
  - Fires the `extrachill_search_performed` action. Its analytics listener ignores REST, cron, admin and WP-CLI requests, so calls through the ability do not record search analytics.
  - Uses the plugin's object-cache candidate slots and the noise-word transient.
