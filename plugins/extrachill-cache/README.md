# Extra Chill Cache

A lean, network-activated **full-page HTML cache** for the Extra Chill WordPress
multisite. It exists to **replace Breeze entirely** by adopting only the two
caching features the platform actually uses, and to **fix the Breeze logged-in
serve bug by design** ([extrachill-users#161](https://github.com/Extra-Chill/extrachill-users/issues/161),
mitigated by [extrachill-multisite#84](https://github.com/Extra-Chill/extrachill-multisite/issues/84)).

Addresses **extrachill-users#161** and **extrachill-multisite#80** (owned
full-page cache to replace Breeze).

---

## What it replaces

Breeze (`wp-content/plugins/breeze/`) was doing a great deal, but a
config audit of this server found exactly **one meaningful enabled feature**:
the anonymous full-page HTML cache (plus its purge/invalidation). Everything
else Breeze shipped was off, redundant, or dead. This plugin adopts the one real
feature, drops the rest, and is a fraction of the size.

## Features adopted (and why)

| Feature | Adopted | Rationale |
|---|---|---|
| **Full-page HTML cache** (desktop + mobile, TTL) | ✅ | The one real feature. Serves cached anonymous HTML fast, regenerates on miss, 24h TTL (Breeze used 1440 min). Modeled on `breeze_serve_cache()` / `breeze_cache()` in `inc/cache/execute-cache.php`. |
| **Cache purge / invalidation** | ✅ | Purge on content changes (save/trash post, comment, term, theme, customizer). Multisite-aware — clears only the current blog's partition. Modeled on `inc/cache/purge-cache.php` hooks. |

## Features deliberately dropped (out of scope)

| Feature | Why dropped |
|---|---|
| **gzip / PHP compression** | nginx handles compression at the edge. |
| **Browser-cache headers** | nginx / Cloudflare set these. |
| **Minification** (HTML/CSS/JS) | All OFF in the live Breeze config. |
| **CDN integration** | OFF; Cloudflare fronts the site. |
| **Varnish purge** | DEAD — Breeze's Varnish endpoint points at `127.0.0.1`; there is no Varnish (the site left Cloudways). |
| **Cloudflare purge** | Cloudflare manages its own edge TTL/purge; not the page cache's job. |
| **Object-cache clearing** | Redis object cache owns its own invalidation. |
| **Lazy-load** | WordPress core does native lazy-loading. |
| **Store fonts / GA locally** | Marginal, unused. |
| **Per-role cache variants** | Not needed. (Anonymous cache + unconditional logged-in bypass is sufficient — and the role-cookie mechanism is the exact source of the bug being fixed.) |

## The logged-in bypass invariant (the whole point)

> **Any request carrying a `wordpress_logged_in_` cookie is NEVER served a
> cached anonymous page. Presence of that cookie alone = bypass, full stop.**
> No secondary role cookie is consulted. Ever.

### Why this makes extrachill-users#161 structurally impossible

Breeze's serve gate (`inc/cache/execute-cache.php:33-67`) only bypassed the
anonymous cache for a logged-in user when **both** the `wordpress_logged_in_*`
cookie **and** the `breeze_folder_name` role cookie were present and in sync. If
the role cookie desynced (different domain/path, cleared, or never set), an
authenticated user was served the stored anonymous page and got trapped in a
logged-out-looking view.

This plugin's serve gate (`inc/dropin-template.php`) checks the
`wordpress_logged_in_` cookie **first, before anything else**, and bypasses on
its presence with **no second condition**. There is no role cookie in the code
path at all — so there is nothing to desync. The bug cannot recur because the
mechanism that caused it does not exist here. The WP-layer buffer callback
(`inc/page-cache.php`) applies the same `is_user_logged_in()` guard as
belt-and-braces on the store side.

## Architecture

Two entry points share one cache store:

1. **`inc/dropin-template.php` — the SERVE gate.** Installed as
   `wp-content/advanced-cache.php`. WordPress includes it very early (before
   plugins load) when `WP_CACHE` is true. It does the logged-in bypass, resolves
   `blog_id` from the request host (multisite), reads a fresh cached payload,
   and emits it + `exit`s before WordPress boots. On a miss it returns and lets
   WordPress load normally.
2. **`inc/page-cache.php` — the STORE path.** Runs inside WordPress. On a
   cacheable anonymous front-end miss it buffers the rendered HTML and writes it
   to disk for next time.
3. **`inc/cache-store.php` — the shared store.** Path resolution, sha512 keying
   (device-bucketed), TTL-checked read, atomic write, recursive delete. Free of
   plugin-API dependencies so both entry points can use it.
4. **`inc/purge.php` — invalidation.** Hooks content-change actions and flushes
   the current blog's partition.
5. **`inc/dropin-installer.php` — drop-in management.** Composes the
   `advanced-cache.php` file (injected constants + host→blog_id map + template)
   and toggles `WP_CACHE`. Only runs on activation/deactivation.

On-disk layout: `wp-content/cache/extrachill-cache/{blog_id}/{sha512}.html`,
each file a serialized `array( 'body' => ..., 'headers' => ... )` (same shape
Breeze used).

## Cutover plan (owner-controlled — DOCS, not performed by this PR)

This PR **only builds** the plugin. It does **not** activate it, does not touch
Breeze, and does not write any drop-in on production. When ready to cut over:

1. **Deploy** this plugin to `wp-content/plugins/extrachill-cache/` (do NOT
   network-activate yet).
2. **Deactivate Breeze** (`wp plugin deactivate breeze --network`).
3. **Remove Breeze's drop-in**: delete `wp-content/advanced-cache.php` (the
   Breeze-generated one) and the `wp-content/breeze-config/` directory if no
   longer referenced. The installer refuses to overwrite a foreign drop-in, so
   this step is required before activation.
4. **Network-activate Extra Chill Cache** (`wp plugin activate extrachill-cache
   --network`). Activation writes the new `wp-content/advanced-cache.php` and
   ensures `WP_CACHE` is `true`.
5. **Verify**: hit a public URL twice as an anonymous user and confirm the
   second response carries `X-Extrachill-Cache: HIT`; then hit the same URL
   while logged in and confirm you get a fresh, authenticated page (no HIT
   header).
6. **Remove Breeze** entirely once validated.

## Verification performed in this PR

- `php -l` on every PHP file (syntax-clean).
- Serve gate re-read to confirm the logged-in-cookie bypass is unconditional and
  is the first check.

Not verified in this PR (no production activation by design): live serve/store
round-trip, `WP_CACHE` toggle against a real `wp-config.php`, cross-site purge
isolation under real traffic. These are part of the cutover verification above.
