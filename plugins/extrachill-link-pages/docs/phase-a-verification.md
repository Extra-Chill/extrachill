# Phase A Verification Runbook — `extrachill-link-pages` v0.1.0 runtime adoption

Phase A of [Extra-Chill/extrachill-link-pages#18](https://github.com/Extra-Chill/extrachill-link-pages/issues/18):
release, deploy, and network-activate the standalone Link Pages runtime with **no data moves and no routing change**.
Artist Platform hands off automatically; existing artists must see zero change — same URLs, same post IDs, same editor,
same analytics identities.

**Budget:** ~15 minutes. **Requires:** production WP-CLI access; the release deployed (operator runs `homeboy release` /
`homeboy deploy` — not part of this runbook).

**Test slugs** (all published on blog 4 today): `delhi-2-dublin`, `extra-chill`, `chaz-minivan`, `ouisee`, `qrisg`.

---

## Step 0 — Capture baseline HTML (BEFORE touching anything)

```bash
mkdir -p /tmp/lp-phase-a
for s in delhi-2-dublin extra-chill chaz-minivan ouisee qrisg; do
  curl -s -o "/tmp/lp-phase-a/before-$s.html" -w "%{http_code} $s\n" "https://extrachill.link/$s/"
done
```

Every slug must return `200` with a non-empty file. Stop here if any slug does not resolve — establish why before
continuing; Phase A must not change resolution behavior.

## Step 1 — Pre-configure the canonical storage blog (required)

The network option `ec_link_page_storage_blog_id` is **unset** in production. Without it, network activation fails
closed with `link_page_network_storage_unconfigured`, and even a forced activation would leave the runtime without a
canonical storage blog (every `extrachill.link` request would 500). Phase A keeps storage on blog 4 (the artist blog
holding all existing records — no data moves):

```bash
wp site option get ec_link_page_storage_blog_id            # expect 0 / empty
wp site option update ec_link_page_storage_blog_id 4
```

Note: deactivation deliberately **preserves** this option so a later re-activation rediscovers the same storage. After
a rollback you may optionally `wp site option delete ec_link_page_storage_blog_id` — the bundled artist runtime never
reads it.

## Step 2 — Network-activate

```bash
wp plugin activate extrachill-link-pages --network
wp plugin list --status=active --network | grep link-pages
```

Activation registers the storage post type on blog 4, flushes blog 4 rewrite rules once, and persists the storage
blog. Check immediately:

```bash
tail -50 /var/www/extrachill.com/wp-content/debug.log | grep "Extra Chill Link Pages"
```

Any `Extra Chill Link Pages:` line here is a runtime boot error — roll back (Step 7) and investigate before retrying.
The first `extrachill.link` request after activation also performs a one-time version-gated rewrite flush
(`ec_link_pages_rewrite_version`); expect one slow request at most.

## Step 3 — Per-slug render diff

For each slug, fetch the after-HTML and diff against baseline, normalizing the two known cosmetic deltas — the asset
base path moves from the artist platform's bundled `live/assets/` to the standalone plugin's `assets/`, and asset
version query strings change:

```bash
norm() { sed -e 's#?ver=[0-9a-zA-Z.\-]*##g' \
             -e 's#wp-content/plugins/extrachill-artist-platform/inc/link-pages/live/assets/#ASSETS/#g' \
             -e 's#wp-content/plugins/extrachill-link-pages/assets/#ASSETS/#g' "$1"; }
for s in delhi-2-dublin extra-chill chaz-minivan ouisee qrisg; do
  curl -s "https://extrachill.link/$s/" -o "/tmp/lp-phase-a/after-$s.html"
  diff <(norm "/tmp/lp-phase-a/before-$s.html") <(norm "/tmp/lp-phase-a/after-$s.html") > "/tmp/lp-phase-a/diff-$s.txt" \
    && echo "IDENTICAL $s" || echo "DIFFERS $s ($(wc -l < "/tmp/lp-phase-a/diff-$s.txt") lines)"
done
```

**Acceptable residue:** only `<body>` tag attribute *ordering* (same attribute set) and the custom CSS-vars `<style>`
element id. Everything else — title, canonical, meta/OG, schema JSON, link buttons, GTM snippets, pixel code — must be
identical. Any other diff: stop, roll back, investigate.

Spot-check invariants on each `after` file:

```bash
grep -c 'extrachill-view-tracking'  /tmp/lp-phase-a/after-delhi-2-dublin.html   # >= 1
grep -o 'ecViewTracking = {[^<]*'   /tmp/lp-phase-a/after-delhi-2-dublin.html   # "postId":<link page ID>
grep -o 'data-extrch-link-page-id="[0-9]*"' /tmp/lp-phase-a/after-*.html
```

The view tracker is enqueued by Extra Chill Analytics on the historical
`extrachill_artist_link_page_minimal_head` hook, which the standalone head still fires. `postId` must equal the same
post ID as before activation (compare with `wp --url=https://artist.extrachill.com post list --post_type=artist_link_page
--name=delhi-2-dublin --field=ID`).

## Step 4 — View + click tracking fire

1. Open `https://extrachill.link/delhi-2-dublin/` in a browser (or `curl` it once more), wait ~5s.
2. Confirm the pageview landed:

   ```bash
   wp --url=https://artist.extrachill.com extrachill artists stats
   ```

3. Click one outbound link, then re-run the stats command and confirm the click count moved.

## Step 5 — Editor and creation flows (as a real artist owner)

1. Log in as an artist owner at `https://artist.extrachill.com/manage-link-page/?artist_id=<id>`.
2. The portable editor must mount and render exactly as before (it is served from the standalone plugin now). Edit a
   link label, save, and confirm the public page reflects the change (`curl https://extrachill.link/<slug>/ | grep <new label>`).
3. Undo the edit and save again.
4. Create a **new** link page for a test artist and confirm `https://extrachill.link/<new-slug>/` resolves with 200.
5. Confirm the edited/created posts keep their expected IDs: `wp --url=https://artist.extrachill.com post list
   --post_type=artist_link_page --posts_per_page=5 --fields=ID,post_name,post_status`.

## Step 6 — Hygiene

```bash
wp --url=https://artist.extrachill.com extrachill artists stats        # exits clean
tail -100 /var/www/extrachill.com/wp-content/debug.log | grep -iE "link.pages|link_page"   # no new signatures
```

Watch for new `debug.log` signatures for ~15 minutes of normal traffic: `extrachill_link_pages_runtime_in*`,
`link_page_storage_*`, `link_page_public_*`, or PHP fatals mentioning `extrachill-link-pages`.

## Step 7 — Rollback

```bash
wp plugin deactivate extrachill-link-pages --network
```

Artist Platform resumes its bundled runtime on the next request (the handoff is evaluated per-request; no cache
warm-up needed beyond normal page cache expiry). Verify:

```bash
for s in delhi-2-dublin extra-chill chaz-minivan ouisee qrisg; do
  curl -s "https://extrachill.link/$s/" -o "/tmp/lp-phase-a/rollback-$s.html"
  diff <(norm "/tmp/lp-phase-a/before-$s.html") <(norm "/tmp/lp-phase-a/rollback-$s.html") >/dev/null \
    && echo "RESTORED $s" || echo "CHECK $s"
done
```

Then optionally `wp site option delete ec_link_page_storage_blog_id` (see Step 1). No content, IDs, or URLs change in
either direction — Phase A moves no data.
