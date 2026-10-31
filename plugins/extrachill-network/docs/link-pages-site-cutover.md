# Link Pages site cutover (`extrachill.link` → blog 13)

Operator runbook for the reversible cutover of `extrachill.link` from Artist blog 4 to the dedicated
Link Pages site (blog 13). Code: [inc/core/blog-ids.php](../inc/core/blog-ids.php) (gate + storage
filter) and [deploy/sunrise.php](../deploy/sunrise.php) (gated drop-in template). Epic:
Extra-Chill/extrachill-link-pages#18. Cutover issue: #174.

## What the gate controls

Network option `ec_link_pages_site_cutover` (bool, **default off**). Merging and deploying this
plugin never cuts over by itself; the flip is an explicit operator action.

| Concern | Gate off (default) | Gate on |
| --- | --- | --- |
| `sunrise.php` mapping | `extrachill.link` + `www.` → blog 4, artist rewrite injection | `extrachill.link` → blog 13, `www.` 301 → apex, no artist rewrite injection |
| `ec_link_page_storage_blog_id` | resolves to blog 4 (historical storage) | resolves to blog 13 |
| `ec_get_blog_id( 'link_pages' )` | 13 (canonical key, independent of gate) | 13 |
| Artist Platform blog-4 routing | owns `extrachill.link` rendering | unnecessary: requests land on blog 13, and the runtime handoff stops Artist from loading its link-domain routing once the standalone runtime is active |

Inspect / flip / revert:

```sh
wp site option get   ec_link_pages_site_cutover
wp site option update ec_link_pages_site_cutover 1   # cut over
wp site option update ec_link_pages_site_cutover 0   # roll back
```

There is deliberately no UI and no ability for this: it is a one-shot operator switch, and
`wp site option update` is the whole interface.

## Sunrise ownership finding

`wp-content/sunrise.php` is **not owned by this repo**. The canonical source lives in the
`Extra-Chill/.github` repository and is deployed to `wp-content/sunrise.php` on the live install
(see [cross-domain-auth.md](cross-domain-auth.md)). WordPress includes the drop-in only from
`wp-content/`, so the gated mapping cannot ship inside the plugin.

This repo therefore ships the exact replacement drop-in as
[deploy/sunrise.php](../deploy/sunrise.php). It reads the same network option through a direct
`wp_sitemeta` query (sunrise runs before `option.php` loads) and degrades to the legacy blog-4
mapping if the gate is on but blog 13 is missing or deleted.

**Operator step:** copy `deploy/sunrise.php` into the `Extra-Chill/.github` repo and deploy it to
`wp-content/sunrise.php`. Deploying it while the gate is off is a no-op — the legacy branch is behaviorally
identical to the current drop-in. Deploy the drop-in **before** flipping the gate.

## Phase D sequence

Prerequisites: Phases A–C shipped — `extrachill-link-pages` released, deployed and
network-activated (runtime handoff active); artist-platform/analytics/cli migration tooling merged
and deployed; this plugin deployed with the gate off.

1. **Migrate storage** (source untouched, rollback journal kept):
   `wp extrachill link-pages migrate-storage plan` → `apply` → `validate`.
2. **Set blog 13's theme** to Extra Chill (network admin or `wp --url=https://extrachill.link site option update stylesheet extrachill` plus matching `template`).
3. **Deploy the gated sunrise drop-in** (no-op until step 4).
4. **Flip the gate**: `wp site option update ec_link_pages_site_cutover 1`.
5. **Verify**:
   - `curl -sI https://www.extrachill.link/<slug>/` → `301` → `https://extrachill.link/<slug>/`.
   - `curl -s https://extrachill.link/<slug>/` → 200, byte-equivalent body, `extrachill-view-tracking` present with the same post ID.
   - Edit + save one existing artist link page from the artist site admin (writes must land on blog 13); create one new page.
   - View/click analytics continue to key on the same IDs (see the analytics participant PR).
   - A non-link host (e.g. `artist.extrachill.com`) is unaffected.
6. **Rollback** = flip the gate back: `wp site option update ec_link_pages_site_cutover 0`. No
   sunrise redeploy needed; the drop-in's legacy branch is the pre-cutover behavior. Storage reads
   and sunrise mapping both return to blog 4 immediately.

Caveats:

- **Writes during the cutover window** land on blog 13. After a rollback they are invisible until
  the gate is re-flipped (or re-migrated). Keep the cutover window short.
- The migration keeps the blog-4 source intact, so rollback after `apply` is safe by design.
- Anonymous full-page cache may serve pre-flip HTML for a short window; content is identical across
  the cutover, so this is benign. If the `www.` 301 seems stale, purge the affected URLs at the
  edge (Cloudflare).

## Post-cutover cleanup (Artist Platform follow-up)

Not part of this repo — track on extrachill-artist-platform after the cutover has soaked:

- Remove the bundled Link Pages runtime copy and the `inc/link-pages/runtime-handoff.php`
  delegation (the standalone runtime is then the only runtime).
- Remove `inc/core/artist-platform-rewrite-rules.php`'s link-domain paths
  (`extrachill_resolve_link_domain_query()`, `extrachill_handle_link_domain_routing()`, the
  `artist_link_page` catch-all registration). Note these are already inert once the standalone
  runtime is network-active — the handoff gate stops Artist from loading the file — so removal is
  hygiene, not a cutover dependency.
- Retire the legacy branch of the sunrise drop-in only once the gate is considered permanent;
  while a rollback path exists, the blog-4 branch must stay.
