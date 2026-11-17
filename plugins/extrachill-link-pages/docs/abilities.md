# Extra Chill Link Pages abilities

Extra Chill Link Pages registers six abilities, all in the core `site` category:

- one that builds the Link Page editor configuration for the `/edit` shell on the public extrachill.link host;
- five that manage team-owned QR codes (`ec_qr_code` posts on the Link Pages storage site).

## Editor

### `extrachill/get-link-page-editor-configuration`

- **Purpose:** Returns the Link Page editor configuration for the current user: the owner adapter, editable identities, adapter scripts and optional exit `links`. The `/edit` shell uses it to mount the editor.
- **Input:** `link_page_id` (integer, optional, default 0).
- **Output:** a free-form object (`additionalProperties: true`).
  - The configuration comes from the `ec_link_page_editor_configuration` filter, which Artist Platform answers in `plugins/extrachill-artist-platform/inc/link-pages/editor-adapter.php`.
  - The ability keeps only `scripts` entries that have a valid http(s) `src`. It then adds `apiRoot` (the current `rest_url()`) and `available: true`.
  - It returns `{ available: false }` when no filter supplies both `adapter` and `identities`.
- **Permission:** `is_user_logged_in`. The ability does no page-level check itself. Which pages a user can edit is decided by the owner adapter answering the filter.
- **Exposure:** `show_in_rest` false. REST: `GET /wp-json/extrachill/v1/link-pages/editor-configuration` (`plugins/extrachill-api/inc/routes/link-pages/editor-configuration.php`; see [route docs](../../extrachill-api/docs/routes/link-pages/editor-configuration.md)). No `wp extrachill` command found. See the [README](../README.md) "Editor configuration: exit links" section for the `links` contract.

## Managed QR codes

All five QR abilities are registered in one loop in `inc/managed-qr/codes.php`. Names are built as `extrachill/` + `<operation>` + `-managed-qr-code`, where the operation is `create`, `list`, `update`, `delete` or `get-stats`.

The five abilities share one input schema. Every field is optional at the schema level, and each operation reads only some of them:

- `id` (integer ≥ 1)
- `team_type` (`artist` | `venue` | `promoter`)
- `team_id` (integer ≥ 1)
- `label` (string ≤ 200)
- `destination` (string ≤ 2000)

The output schema is free-form. Rules that apply to all five:

- **Permission:** `is_user_logged_in`. Each execute callback then switches to the Link Pages storage blog. Every operation except `list` requires an active membership on the owning team (`TeamMembershipRepository`) or a super admin. Failure returns `qr_forbidden` (403).
- **Exposure:** `show_in_rest` true, so the abilities can be run at `/wp-abilities/v1/abilities/<name>/run`. The QR Code Generator block's "My QR codes" panel calls them (`plugins/extrachill-content-blocks/src/blocks/qr-code-generator/managed.tsx`). No `extrachill-api` route or `wp extrachill` command found.
- **Record shape:** returned records are `{ id, label, code, short_url, destination, team_type, team_id, scans }`. `short_url` is `<link_pages site>/q/<code>`. `scans` is the sum of retained daily rows in the Analytics link-page clicks table, which covers about 90 days.
- **Common errors:**
  - `qr_storage_unavailable`: no Link Pages storage blog.
  - `qr_not_found` (404): the code is missing, not a published QR post, or not readable.
  - `qr_invalid_url` (400): the destination is not a full http(s) URL.

The full design is in [managed-qr-codes.md](managed-qr-codes.md).

### `extrachill/create-managed-qr-code`

- **Purpose:** Creates a QR code for a team.
- **Input:**
  - `team_type` and `team_id` (required in practice).
  - `destination` (required, must be a full http(s) URL).
  - `label` (optional).
- **Output:** the new record.
  - `qr_quota` (409) when the team has reached its quota. The error data includes `quota`, `used` and `unlimited`.
  - `qr_create_failed` when the new post cannot be read back.
- **Permission/Exposure:** see the shared rules above.
- **Side effects:**
  - Inserts an `ec_qr_code` post with a random 12-character hex slug, meta `team_type`, `team_id` and `destination`, and the label as post title.
  - Runs under the Link Pages advisory lock so parallel creates cannot exceed the quota.
  - The quota is 10 published codes per team by default. Teams with a supporter member have no limit. The `ec_managed_qr_team_quota` filter can change it (0 = unlimited).

### `extrachill/list-managed-qr-code`

- **Purpose:** Lists QR codes across every team where the current user has an active membership.
- **Input:** none used.
- **Output:** `{ items: [record...], teams: [{ team_type, team_id, quota, used, unlimited }] }`.
  - `qr_teams_unavailable` when the membership repository is missing.
  - Super admins also see only the teams they actively belong to.
- **Permission/Exposure:** see the shared rules above.

### `extrachill/update-managed-qr-code`

- **Purpose:** Renames a code and/or changes its destination. The slug and short URL never change.
- **Input:**
  - `id` (required in practice).
  - `label` (optional).
  - `destination` (optional, must be a full http(s) URL when present).
- **Output:** the updated record. Returns the `wp_update_post` error, or `qr_update_failed` when the destination meta cannot be saved.
- **Permission/Exposure:** see the shared rules above.
- **Side effects:** updates the post title and/or the `destination` meta. `/q/<code>` redirects use the new destination right away, because redirects are never cached.

### `extrachill/delete-managed-qr-code`

- **Purpose:** Deletes a code by moving it to the trash, which frees one unit of team quota.
- **Input:** `id` (required in practice).
- **Output:** `{ deleted: true }`, or `qr_delete_failed`.
- **Permission/Exposure:** see the shared rules above.
- **Side effects:** calls `wp_trash_post()`. After that, `/q/<code>` falls back to the Link Pages home URL.

### `extrachill/get-stats-managed-qr-code`

- **Purpose:** Returns one authorized record, including its retained scan count. There is no separate stats branch: it is the shared lookup plus `ec_managed_qr_present()`.
- **Input:** `id` (required in practice).
- **Output:** a record (see the shared rules above).
- **Permission/Exposure:** see the shared rules above.
