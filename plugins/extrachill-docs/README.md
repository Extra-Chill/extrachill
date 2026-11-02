# Extra Chill Docs

Documentation hub at **docs.extrachill.com** (Blog ID 10).

## Canonical publishing path

Each feature plugin owns its end-user help in `docs/user/*.md`:

`docs/user/*.md` → deploy → `extrachill-docs/publish-plugin-docs` → WordPress pages.

Edit help in the owning plugin, not in the published page. The fingerprint cron reads installed files and imports through Markdown Database Integration (MDI v0.15.0+), using the `extrachill-plugin-docs` layout and built-in `markdown-h1-title` profile. MDI owns conversion and source identity; the existing publish ability remains the dry-run/repo-filter entry point. No application password or REST upload is required.

`runner-configs/platform-map.yml` maps each plugin to its parent page. The publisher is additive: missing source files are reported as orphans, not deleted automatically. Review those reports when retiring articles.

The MCP connection guide lives at `docs/user/connect.md`. Source keys are repo-root paths, such as `plugins/extrachill-docs/docs/user/connect.md`; fingerprints use component paths.

## Writing and validation

Follow [the writing rules](runner-configs/writing-rules.md). Help is for end users, not developers. Check changed help before deployment:

```bash
php scripts/check-user-docs-voice.php ../extrachill-artist-platform/docs/user
php scripts/check-user-docs-voice.php ../extrachill-community/docs/user
php scripts/check-user-docs-voice.php ../extrachill-events/docs/user
```

## Runtime

- `inc/abilities/publish-plugin-docs.php` discovers installed help and publishes pages.
- `inc/core/mdi-layout.php` maps installed sources, visibility, hierarchy and internal links for MDI.
- `inc/abilities/adopt-mdi-pages.php` adopts existing pages using metadata only (dry-run by default; `apply: true` to write).
- `inc/core/platform-map.php` reads parent-page identities from the platform map.
- `inc/core/sync-cleanup.php` performs one-shot cleanup of old scheduled jobs and page metadata; it does not publish documentation.
- `extrachill-api/inc/routes/docs/docs-info.php` provides the separate live public metadata route.

The theme supplies shared styling and navigation. Team-only pages use WordPress private-page visibility.

## Tests

Run the standalone PHP smoke tests in `tests/`, and the Python configuration tests:

```bash
for test in tests/*-smoke.php; do php "$test"; done
python3 -m unittest discover -s tests -p 'test_*.py'
```

## Deployment

Deploy MDI as a plain plugin and activate it on the docs site only (no `db.php`, no `MARKDOWN_DB_BACKEND`). After deployment, operators activate MDI, run `extrachill-docs/adopt-mdi-pages` dry-run then apply, and run `extrachill-docs/publish-plugin-docs` dry-run before apply. Expect existing IDs/URLs to update rather than duplicate; spot-check pages and legacy redirects. Inactive MDI skips cron safely.

Export safety: extrachill-docs never invokes MDI export. Future site-to-repo sync must export only to staging, never beneath `WP_PLUGIN_DIR`, `wp-content/themes`, or `wp-content/mu-plugins`.

Deploy through Homeboy. The deploy publishing step runs `extrachill-docs/publish-plugin-docs` against installed help. Review publishing errors and orphan reports after deploy. Changelogs and version strings are release-owned.

Run `wp --url=https://docs.extrachill.com extrachill docs migrate-source-keys` to preview key rewrites, adoption, fingerprint changes, and legacy redirects/trashing. Add `--apply` to write. Unmatched legacy posts are reported and left untouched. Repeated runs are safe.
