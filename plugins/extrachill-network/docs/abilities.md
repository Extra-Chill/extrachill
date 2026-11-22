# Network abilities

Extra Chill Network provides cross-site taxonomy, editorial media, comment editing, experiments, mail routing, policy, statistics, migration, and image-generation primitives in the `extrachill-network` category. The shared stories registrar instead uses the calling publication's category (`extrachill-blog` or `extrachill-news-wire`). Abilities are the domain layer; REST, CLI, MCP, and core `/wp-abilities/v1` are transports. Inputs below are object fields; fields marked optional are not schema-required. Outputs describe successful results; callbacks may also return `WP_Error`.

## Network taxonomy

Registrations and callbacks: [`NetworkTermAbilities.php`](../inc/Abilities/NetworkTermAbilities.php). Cross-site calls use authenticated HTTP to the target runtime rather than relying only on `switch_to_blog()`; see [cross-site routing](cross-site-routing.md).

### `extrachill/search-network-terms`

- **Purpose:** Search approved network taxonomy identities for an eligible site/post-type/taxonomy combination, without creating local terms.
- **Input:** Required `site`, `post_type`, `taxonomy`, `query` (strings; query nonempty). Optional `limit` (integer, 1–50; callback default 20).
- **Output:** Object with `site`, `post_type`, `taxonomy` (strings), `terms` (array).
- **Permission:** `__return_true`; execution validates the site and eligible taxonomy.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.

### `extrachill/project-network-term`

- **Purpose:** Resolve or create a local projection of an approved taxonomy/slug identity. See [cross-site routing](cross-site-routing.md) for target-runtime dispatch.
- **Input:** Required `site`, `taxonomy`, `slug` (strings), `post_id` (integer, ≥1). Optional `dry_run` (boolean, default false).
- **Output:** Object with `taxonomy`, `slug` (strings), `term_id` (integer), `created`, `dry_run` (booleans).
- **Permission:** Requires a known site and logged-in user. In the target runtime, checks the target post's `edit_post`, or community update-topic permission (fallback `edit_topic`) for topics. A different source runtime initially checks only login; execution rechecks target permission there.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.
- **Side effects:** May create local terms (including projections needed by the resolver), unless dry-run; cross-site calls make authenticated HTTP requests.

### `extrachill/classify-post-terms`

- **Purpose:** Schedule classification or a forced rerun for one eligible network post.
- **Input:** Required `site` (string), `post_id` (integer, ≥1). Optional `taxonomies` (unique string array), `force`, `dry_run` (booleans, default false).
- **Output:** Object with `scheduled` (boolean), `job_id` (integer), `fingerprint` (string), `taxonomies` (array), `reason` (string).
- **Permission:** Same known-site/login and target-post edit checks as projection above; execution rechecks in the target runtime.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.
- **Side effects:** Schedules background classification, which can assign terms; dry-run reports instead. Cross-site dispatch makes authenticated HTTP requests.

### `extrachill/taxonomy-post-counts`

- **Purpose:** Count published posts per term on a named site, sorted by count descending. See the existing [blog route contract](../../extrachill-api/docs/routes/blog/taxonomy-counts.md).
- **Input:** Required `taxonomy`, `site` (strings). Optional `post_type` (string; otherwise taxonomy's registered types), `slug` (string or null; scopes to one term).
- **Output:** Object with `site`, `taxonomy` (strings), `total` (integer, number of returned terms), `terms` (array of `{term_id: integer, name: string, slug: string, count: integer, url: string}`). Empty/no-content single-term results return an empty terms array.
- **Permission:** `__return_true`.
- **Exposure:** `show_in_rest: true`; extrachill-api GET `/blog/taxonomy-counts`, `/wire/taxonomy-counts`, `/shop/taxonomy-counts`, `/community/taxonomy-counts`, and bulk community discovery queries documented in [discovery and notifications](../../extrachill-api/docs/routes/community/discovery-and-notifications.md), under `/extrachill/v1`; `wp extrachill` command: none found.

## Comment editing

Source: [`CommentEditorAbilities.php`](../inc/Abilities/CommentEditorAbilities.php). Existing transport details: [comment editor routes](../../extrachill-api/docs/routes/comments/editor.md).

### `extrachill/comment-get-for-editor`

- **Purpose:** Load stored comment block markup and an editor permissions envelope.
- **Input:** Required `comment_id` (integer). Optional `blog_id` (integer, defaults to current blog).
- **Output:** Object with `id`, `post_id` (integers), `type` (`comment`), `content`, `raw`, `status`, `permalink`, `updated_at` (strings; timestamp date-time), `context` (`blog_id`, `post_id` integers), `permissions` (`canSave`, `canUploadMedia`, `canDelete` booleans), and `draft` (object or null; callback supplies null). Approved status maps to `publish`.
- **Permission:** Logged-in user, positive existing comment ID, and either comment authorship (nonzero user ID) or `edit_comment` for that ID on the resolved blog.
- **Exposure:** `show_in_rest: false`; extrachill-api GET `/extrachill/v1/comments/{id}/editor`; `wp extrachill` command: none found.

### `extrachill/comment-update`

- **Purpose:** Save sanitized comment content through WordPress's normal update path.
- **Input:** Required `comment_id` (integer), `content` (string, serialized block markup). Optional `blog_id` (integer).
- **Output:** Object with `id` (integer), `status`, `content`, `permalink`, `updated_at` (strings; timestamp date-time).
- **Permission:** Logged-in user, positive comment ID, and `edit_comment` for that ID on the resolved blog. Authorship alone does not grant this callback's permission.
- **Exposure:** `show_in_rest: false`; extrachill-api POST/PUT/PATCH `/extrachill/v1/comments/{id}/editor`; `wp extrachill` command: none found.
- **Side effects:** Applies `wp_kses_post()` and rejects empty sanitized content; `wp_update_comment()` writes content and triggers normal edit/cache/notification hooks.

## Editorial media and image tools

### `extrachill/network-media-list`

- **Purpose:** List attachments from the main editorial library (blog 1 only, not all network sites). Source: [`NetworkMediaAbilities.php`](../inc/Abilities/NetworkMediaAbilities.php).
- **Input:** All optional: `media_type` (string: `image`, `video`, `audio`), `search` (string), `per_page` (integer, 1–100, default 20), `page` (integer, ≥1, default 1).
- **Output:** Object with `items` (array), `total`, `total_pages`, `page`, `per_page` (integers). Each item has `id`, `blog_id`, `width`, `height` (integers) and `sourceId` (`blog:id`), `url`, `previewUrl`, `title`, `alt`, `caption`, `mime_type`, `media_type`, `date` (strings).
- **Permission:** Logged in and `upload_files` on blog 1, checked after switching blog context.
- **Exposure:** `show_in_rest: true`; extrachill-api GET `/extrachill/v1/network-media` (also used by its permission check); `wp extrachill` command: none found.

### `extrachill/network-media-upload`

- **Purpose:** Upload a PHP file descriptor into the main editorial library. Source: [`NetworkMediaAbilities.php`](../inc/Abilities/NetworkMediaAbilities.php).
- **Input:** Required `tmp_name`, `name`, `type` (strings), `size` (integer bytes). Optional `title`, `alt` (strings). REST marshals the multipart upload into this descriptor.
- **Output:** Schema declares `id`, `blog_id` (integers), `sourceId`, `url`, `previewUrl`, `title`, `mime_type` (strings); callback returns the full normalized media item described above.
- **Permission:** Logged in and `upload_files` on blog 1.
- **Exposure:** `show_in_rest: true`; extrachill-api POST `/extrachill/v1/network-media`; `wp extrachill` command: none found.
- **Side effects:** Validates file type, moves the upload, inserts an attachment on blog 1, generates image metadata/derivatives, and optionally writes alt-text metadata.

### `extrachill/generate-qr-code`

- **Purpose:** Generate a print-ready base64 PNG for an HTTP(S) URL. Source: [`QRCodeAbility.php`](../inc/Abilities/QRCodeAbility.php); existing [QR route documentation](../../extrachill-api/docs/routes/tools/qr-code.md). Registration yields to an already-registered retiring owner during rollout; this ability itself is not retired.
- **Input:** Required `url` (string, URI, maximum 2048 characters). Optional `size` (integer; default 1000, execution clamps absolute value to 100–2000).
- **Output:** Object with `image` (base64 string), `mime_type`, `url` (strings), `size` (integer).
- **Permission:** Callback always returns true. Execution validates URL and limits both source IP and signed-in user to 30 requests/hour; defined `WP_CLI` or an Action Scheduler execution bypasses throttling.
- **Exposure:** `show_in_rest: true`; extrachill-api POST `/extrachill/v1/tools/qr-code`; `wp extrachill tools qr generate`.
- **Side effects:** Updates hashed-IP/user rate-limit transients. Generation itself does not persist an image or fetch the encoded URL.

### `extrachill/regenerate-og-card`

- **Purpose:** Inspect or rerender OG cards, optionally bypassing their content-signature cache with `force`. Bulk selection uses existing card metadata or files; single-post mode can render an eligible post even without an existing card. Source: [`OgCardRegenerationAbility.php`](../inc/Abilities/OgCardRegenerationAbility.php); see [release history](CHANGELOG.md).
- **Input:** All schema-optional: `post_id` (integer ≥1), `post_type` (string), `on_disk` (boolean, false), `blog_id` (integer ≥1), `force`, `dry_run` (booleans, false), `limit` (integer 1–200, default 25), `offset` (integer ≥0, default 0). Execution requires at least one selector (`post_id`, `post_type`, true `on_disk`, or `blog_id`); priority is single post, disk, post type, then blog.
- **Output:** Object with `dry_run`, `force`, `has_more` (booleans), `mode` (string), `blog_id`, `requested`, `regenerated`, `reused`, `skipped_ineligible`, `next_offset` (integers), `cards`, `errors` (arrays). Cards have `post_id` (integer), `post_type`, `old_url`, `new_url`, `reason` (strings), `regenerated`, `reused`, `skipped` (booleans, as applicable). Errors have `post_id` and string `error`. Resume with `next_offset` only when `has_more` is true; otherwise it is 0.
- **Permission:** Logged in, with `manage_network` on multisite or fallback `manage_options` in the current context (not a target-blog capability check).
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill network regenerate-og-card`.
- **Side effects:** Rendering writes cached image files and card post metadata and may delete previous files. Dry-run only inspects and predicts URLs.

## Experiments

Source: [`ExperimentAssignmentAbility.php`](../inc/Abilities/ExperimentAssignmentAbility.php). See [experiments](experiments.md) for definitions, privacy, signed proofs, lifecycle bounds, and consumer integration. Identifier strings match `^[a-z0-9][a-z0-9_-]{0,63}$`; context objects allow at most ten scalar string/integer/number/boolean values. Input schemas reject additional fields.

### `extrachill/resolve-experiment-assignment`

- **Purpose:** Resolve a deterministic variant for an eligible, privacy-permitted subject.
- **Input:** Required `experiment_key`, `surface` (identifier strings). Optional `context` (bounded object as above).
- **Output:** Required `experiment_key`, `assignment_policy`, `variant`, `surface`, `exposure_token` (strings), `definition_version` (bounded integer ≥0), `measurement_eligible` (boolean). Token is empty when not measurement-eligible.
- **Permission:** `__return_true`; subject/privacy/eligibility checks occur in the assignment resolver.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.

### `extrachill/record-experiment-exposure`

- **Purpose:** Validate a signed assignment proof and emit a trusted viewport-exposure hook.
- **Input:** Required `experiment_key`, `variant`, `surface` (identifier strings), `definition_version` (bounded integer ≥1), `assignment_policy` (string, current policy constant), `exposure_token` (signed-token string). Optional `context` (bounded object).
- **Output:** Object with required `accepted` (boolean); invalid/expired or already-consumed proofs return errors.
- **Permission:** `__return_true`; execution validates subject/context-bound proof and rejects replay.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.
- **Side effects:** Consumes the exposure token and fires `extrachill_experiment_exposure`; analytics consumers can persist the trusted event.

### `extrachill/list-experiments`

- **Purpose:** List normalized code definitions and effective lifecycle states, including bounded orphan samples.
- **Input:** None; no input schema registered.
- **Output:** Object with required `items` (bounded array), `registered_count`, `orphan_count` (integers), `orphan_samples_truncated`, `lifecycle_over_bound` (booleans). Items contain `key`, `assignment_policy`, `default_state`, `state`, `default_variant`, `control_variant` (strings), `registered`, `orphaned` (booleans), `definition_version` (integer), `variants` (object of positive integer weights), `surfaces` (unique identifier-string array).
- **Permission:** `extrachill_experiment_admin_permission()` allows truthy defined `WP_CLI`, otherwise requires `manage_network_options`.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill experiments list`, `get`, `report`, and before/after reads in `state`.

### `extrachill/transition-experiment-state`

- **Purpose:** Transition a registered experiment's current definition version to a valid lifecycle state.
- **Input:** Required `experiment_key` (identifier string), `definition_version` (bounded integer ≥1), `state` (string: `inactive`, `active`, `paused`, `completed`).
- **Output:** Required `experiment_key`, `previous_state`, `state` (strings), `definition_version` (integer).
- **Permission:** `extrachill_experiment_admin_permission()` allows truthy defined `WP_CLI`, otherwise requires `manage_network_options`.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill experiments state`.
- **Side effects:** Updates network lifecycle state for the definition version; validation rejects invalid/stale transitions.

## Public stories

Source: [`core/stories.php`](../inc/core/stories.php). Network owns the shared implementation, not an unconditional network registration: Blog registers for `post` in `extrachill-blog`; News Wire registers for `festival_wire` in `extrachill-news-wire`, in their respective runtimes. Both expose only published, non-password-protected stories.

### `extrachill/list-stories`

- **Purpose:** List current-publication stories newest-first with AND-combined taxonomy filters.
- **Input:** All optional: `page` (integer ≥1, default 1), `per_page` (integer 1–50, default 10), `category`, `tag`, `festival`, `artist`, `venue`, `location` (integer term ID ≥1 or string slug, length 1–200). Additional fields rejected.
- **Output:** Required `items` (array), `total`, `pages`, `page`, `per_page` (integers). Each item has `id` (integer), `title`, `excerpt`, `date`, `url` (strings; excerpt plain text, date GMT ISO 8601), `author` (`id` integer, `name` string), `featured_image` (null or `{url: string, width: integer, height: integer, alt: string}`), `site` (`blog_id` integer, `host` string).
- **Permission:** `__return_true`; query and result checks enforce public visibility.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.

### `extrachill/get-story`

- **Purpose:** Read one public story with rendered content and public taxonomy terms; missing/nonpublic/wrong-type posts return 404.
- **Input:** Required `id` (integer ≥1); additional fields rejected.
- **Output:** All summary fields described above, plus required `content_html` (string, `the_content` output), `terms` (object keyed by taxonomy, each value an array of `{id: integer, name: string, slug: string, url: string}`).
- **Permission:** `__return_true`; execution enforces publication type, published status, and no password.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.

## Policy, statistics, mail, and migration

### `extrachill/get-ad-policy`

- **Purpose:** Resolve ad-serving policy for a site and request context. Source: [`AdPolicyAbility.php`](../inc/Abilities/AdPolicyAbility.php).
- **Input:** All optional: `blog_id`, `post_id` (integers), `post_type` (string), `is_front_page`, `is_home`, `is_page`, `is_search`, `is_archive`, `is_singular`, `is_post_type_archive` (booleans).
- **Output:** Required `blog_id` (integer), `site_enabled`, `serve_ads`, `integration_available`, `delivery_detected` (booleans), `reason` (string: `enabled`, `site_disabled`, `route_blocked`, `member_benefit`, `integration_unavailable`), `drift` (string: `none`, `enabled_without_delivery`, `disabled_with_delivery`).
- **Permission:** `__return_true`.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.

### `extrachill/set-post-ad-free`

- **Purpose:** Turn ads off (or back on) for one post on the current site, for every visitor, by setting the `_extrachill_ad_free` post meta honored by the ad policy. Works for any post type. Source: [`PostAdFreeAbility.php`](../inc/Abilities/PostAdFreeAbility.php).
- **Input:** Required `post_id` (integer, current site); optional `ad_free` (boolean, default `true`). No other keys.
- **Output:** Required `post_id` (integer), `ad_free`, `serve_ads` (booleans); `reason` (string, the resulting policy reason).
- **Permission:** `edit_post` on the target post.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.

### `extrachill/get-network-stats`

- **Purpose:** Resolve composable, individually cached network metrics; unavailable sources yield null, never fabricated zero. Source: [`NetworkStats/bootstrap.php`](../inc/NetworkStats/bootstrap.php).
- **Input:** Optional `keys` (string array); omit for all registered metrics.
- **Output:** Object keyed by metric name, each value `{key: string, label: string, value: integer|object|array|null, available: boolean}`.
- **Permission:** `__return_true`.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.

### `extrachill/mail-site-id`

- **Purpose:** Resolve the nearest SMTP-configured blog for outgoing mail; this does not send mail. Source: [`MailAbilities.php`](../inc/Abilities/MailAbilities.php); existing [mail routing documentation](../README.md).
- **Input:** Empty object, no fields required.
- **Output:** Object with `blog_id`, `current_blog_id` (integers), `configured_sites` (integer array). Missing helpers yield 0/empty configured sites.
- **Permission:** `__return_true`.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found.

### `extrachill/migrate-post`

- **Purpose:** Copy a post and its media across blogs using `ec_migrate_post()`, optionally deleting verified source content. Source: [`CrossSiteContentMigrationAbilities.php`](../inc/Abilities/CrossSiteContentMigrationAbilities.php).
- **Input:** Required `source_blog_id`, `post_id`, `dest_blog_id` (integers ≥1). Optional `status` (string, default preserves source status), `delete_source`, `dry_run` (booleans, default false).
- **Output:** Object with `dry_run`, `source_deleted` (booleans), `source_blog_id`, `source_post_id`, `dest_blog_id`, `dest_post_id`, `attachments_total`, `attachments_migrated`, `featured_image_id` (integers), `dest_status` (string), `missing_files` (object array), `attachment_map`, `url_map` (objects).
- **Permission:** Logged in, then multisite `manage_network` or fallback `manage_options` in the current runtime. The callback does not switch to the destination for its capability check.
- **Exposure:** `show_in_rest: true`; extrachill-api route: none found; `wp extrachill` command: none found in the current registry (the source comment mentions a wrapper, but it is not registered).
- **Side effects:** Creates destination post/media and metadata, copies files, rewrites migrated references, and optionally deletes source post/attachments only after successful verification. Dry-run performs no writes.
