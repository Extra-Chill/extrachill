# Extra Chill Docs abilities

Extra Chill Docs registers three operator abilities, all in the `extrachill-docs` category (registered in `inc/abilities/category.php`). They publish installed plugin help (`docs/user/*.md`) as pages on docs.extrachill.com through Markdown Database Integration (MDI), and they run the one-time migrations that moved existing pages onto MDI and monorepo source keys.

All three use the same permission callback, `extrachill_docs_upsert_doc_page_permission_callback()`. It returns true during WP-Cron and in any WP-CLI process. Otherwise it requires `current_user_can( 'manage_options' )`.

See the [README](../README.md) for the publishing path and deployment sequence. The public feedback ability below is separate from these operator abilities.

### `extrachill/docs-feedback-submit`

- **Purpose:** Sends a report from the “Did this help?” form on a published docs child page.
- **Input:** `post_id` (integer), `message` (required, up to 2,000 characters), `email` (optional for guests), and `turnstile_response`.
- **Exposure:** `POST /wp-abilities/v1/abilities/extrachill/docs-feedback-submit/run` with JSON `{ "input": { ... } }`. The browser includes the WordPress REST nonce for signed-in readers. Both guests and signed-in readers must pass network Turnstile verification. The ability permits up to five attempts per hour per guest IP or signed-in account.
- **Output:** `{ success: true }` after `wp_mail()` accepts the report, or a REST error if validation, verification, rate limiting, or mail fails.
- **Delivery:** Sends plain text to the docs site's `admin_email` by default. The `extrachill_docs_feedback_recipient` filter receives the address and article ID. Reply-To is the signed-in account's email or the guest's optional address.

### `extrachill-docs/publish-plugin-docs`

- **Purpose:** Imports the Markdown user docs shipped inside installed plugins as pages, using `WP_Markdown_CLI::import()` with the Extra Chill Docs MDI layout profile. Publishing is additive: pages whose source file is gone are reported as orphans and never deleted.
- **Input:**
  - `repo` (string, optional): restrict publishing to one component path, such as `plugins/extrachill-community`. Default: every repo in the platform map.
  - `dry_run` (boolean, optional, default false).
- **Output:** `{ success, rows }`.
  - `rows[0]` is the MDI import result.
  - Any further rows are orphans: `{ repo, path, action: "orphaned", page_id }`.
  - `success` is false if the import fails or skips any file.
  - When MDI is inactive, it returns `{ success: false, error: "mdi_unavailable", rows: [] }`.
  - When a parent page cannot be resolved, it returns `{ success: false, error, rows: [] }`.
- **Permission:** see above.
- **Exposure:** `show_in_rest` false (annotations: not readonly, idempotent, not destructive). No `extrachill-api` route or `wp extrachill` command found. In-repo caller: the hourly cron hook `extrachill_docs_publish_plugin_docs` (`extrachill_docs_publish_changed_plugin_docs()`). The cron runs the ability's execute function for each repo whose docs fingerprint changed. The README describes running it as a deploy step.
- **Side effects:**
  - Creates or updates doc pages through MDI.
  - May create, or update the status of, the managed platform parent pages (`_extrachill_docs_platform_parent`).
  - The cron wrapper, not the ability, records successful per-repo fingerprints in the `extrachill_docs_published_fingerprints` option.

### `extrachill-docs/adopt-mdi-pages`

- **Purpose:** One-time, metadata-only adoption of existing doc pages into MDI. For each page whose `_source_path` is a valid mapped user-doc source, it copies that path into `_markdown_source_path` and `_markdown_source_identity`.
- **Input:** `apply` (boolean, optional, default false). The ability is a dry run unless `apply` is true.
- **Output:** `{ success, dry_run, rows: [{ page_id, path, action }] }`.
  - `action` is `adopt`, `unchanged` or `conflict`.
  - A conflict means more than one page claims the path, or a page already has a different MDI identity. Any conflict sets `success` to false.
- **Permission:** see above.
- **Exposure:** `show_in_rest` not set (defaults to false). No `extrachill-api` route or `wp extrachill` command found. The README deployment notes describe running it manually after MDI is activated.
- **Side effects:** with `apply`, writes the two MDI meta keys on non-conflicting pages. Content and URLs are not changed.

### `extrachill-docs/migrate-source-keys`

- **Purpose:** Moves doc pages to monorepo source keys and retires legacy `ec_doc` posts. It never creates pages, and repeated runs are safe.
- **Input:** `apply` (boolean, optional, default false). The ability is a dry run unless `apply` is true.
- **Output:** `{ success, dry_run, rows }`. Row actions:
  - `adopt` and `rekey`: pages moved to `_source_repo = Extra-Chill/extrachill` with a `plugins/...` `_source_path`.
  - `conflict`: more than one page claims the same key.
  - `rekey_fingerprint`: a legacy `Extra-Chill/<plugin>` fingerprint key moved to its component path.
  - `redirect_and_trash`: a legacy `ec_doc` post matched to a page.
  - `unmatched_legacy`: a legacy post with no matching page. It is reported and left untouched.
  - `trash_failed`.
  - `success` is false if any `conflict` or `trash_failed` row is present.
- **Permission:** see above.
- **Exposure:** `show_in_rest` not set (defaults to false). WP-CLI: `wp extrachill docs migrate-source-keys [--apply|--dry-run]` (`plugins/extrachill-cli/inc/Commands/Docs/MigrateSourceKeysCommand.php`). It must run on the docs site. No `extrachill-api` route found.
- **Side effects:** with `apply`:
  - Updates `_source_repo` and `_source_path` page meta.
  - Rewrites keys in the `extrachill_docs_published_fingerprints` option.
  - Adds `/<platform>/<slug>/` → page ID entries to the `extrachill_docs_legacy_redirects` option.
  - Trashes the matched legacy `ec_doc` posts with a direct `$wpdb` update. The posts are never permanently deleted.
  - Calls `flush_rewrite_rules()`.
