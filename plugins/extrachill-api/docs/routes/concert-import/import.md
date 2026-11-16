# Concert import

Paths are relative to `/wp-json/extrachill/v1`. Source: `inc/routes/events/concert-import.php` (despite its source folder, the route segment is `concert-import`). All routes use `permission_callback: is_user_logged_in`. Owner: `extrachill-users`. Current-user scoping is resolved by the abilities, not a client user ID.

Successful results are returned unchanged (200); ability errors propagate. Missing abilities return `ability_not_found` (500).

## GET /concert-import/sources

**Parameters:** none; does not forward the ability-only `include_unconfigured` switch.

**Ability:** `extrachill/list-concert-import-sources`.

**Response:** `{sources: [...]}`. Source records contain `slug`, `label`, `configured`, saved `username`, and `rate_limit` (`requests_per_second`, `requests_per_day`).

## POST /concert-import/preview

**Parameters:** required sanitized string `source` and `username`.

**Ability:** `extrachill/preview-concert-import`.

**Response:** `{source, username, total}` after probing the external account; this does not start an import.

## POST /concert-import/start

**Parameters:** required sanitized string `source` and `username`.

**Ability:** `extrachill/start-concert-import`.

**Response:** `{run_id}` identifying the queued background run. Import orchestration belongs to Users, not this HTTP handler.

## GET /concert-import/status

**Parameters:** optional `limit` integer (default 20, `absint`).

**Ability:** `extrachill/get-concert-import-status`.

**Response:** `{runs: [...]}` for the current user. Runs include ID, source slug, status, username, page progress, next attempt time, daily request counts, event seen/matched/created/unmatched/skipped counters, timestamps and nullable error message.
