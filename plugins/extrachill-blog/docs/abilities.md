# Extra Chill Blog abilities

Extra Chill Blog registers one ability, in the `extrachill-blog` category (registered by this plugin). It is a read-only, versioned projection that lets other network plugins resolve Blog-owned public entities without reading Blog storage directly.

### `extrachill/blog-public-entity-projections`

- **Purpose:** Resolves an ordered batch of entity slugs into their canonical public name and URL on the main site. Festivals are the only entity type it supports today.
- **Input:** (`additionalProperties: false`)
  - `schema_version` (string, required, enum `"1"`).
  - `items` (array, required, 1–100 items): each item is `{ entity_type: "festival", slug }`. `slug` is a lowercase kebab-case string of at most 200 characters, and both fields are required.
- **Output:** `{ schema_version: "1", items: [...] }`. Items come back in input order.
  - A resolved item is `{ entity_type, slug, status: "resolved", name, url }`. `name` is the decoded term name and `url` is the `get_term_link()` permalink.
  - An unresolved item is `{ entity_type, slug, status: "not_found", name: "", url: "" }`. This is returned when the `festival` taxonomy is not public, or when the term is missing or has no posts.
  - Errors:
    - `extrachill_blog_projection_owner_unavailable`: the ability was run on a site other than the main site.
    - `extrachill_blog_projection_taxonomy_unavailable`: the `festival` taxonomy is missing.
    - `extrachill_blog_projection_permalink_unavailable`: the term link is empty. A `get_term_link()` error is also passed through.
- **Permission:** `__return_true`, so it is public. It returns only public term data.
- **Exposure:** `show_in_rest` true (annotations: readonly, idempotent, not destructive). No `extrachill-api` route or `wp extrachill` command found. In-repo caller: Extra Chill Community's subscription inventory (`plugins/extrachill-community/inc/core/subscription-inventory.php`) runs it through `wp_get_ability()` to resolve festival subscriptions.
