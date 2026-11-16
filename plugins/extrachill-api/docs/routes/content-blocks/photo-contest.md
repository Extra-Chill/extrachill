# Photo contest

Paths are relative to `/wp-json/extrachill/v1`. Source: `inc/routes/content-blocks/photo-contest.php`. Owner: `extrachill-content-blocks`. All routes require positive integer `post_id` and string `contest_id`. IDs use `absint`; contest IDs are text-sanitized. Missing abilities return `ability_missing` (500).

## POST /content-blocks/photo-contest/submit

**Permission:** contest moderators admitted by `extrachill_content_blocks_photo_contest_can_moderate` bypass public admission. Others use Turnstile when its wrapper is available, then `extrachill_api_content_blocks_photo_contest_submit_permission` (shared per-client write budget, default 20/minute).

**Parameters:** common IDs; required `display_name` string; optional `email` (email string, maximum 254 characters; required for public entrants by the ability), `instagram`, `website` (honeypot; keep empty), `turnstile_response`. Submit multipart `photo` file; it is passed unchanged from file parameters. Empty email is omitted, allowing moderator entries.

**Ability:** `extrachill/photo-contest-submit`. Writes refuse REST-visible abilities (`show_in_rest` truthy) with `photo_contest_unavailable` (503).

**Response:** `{message, entry}` (missing values default to empty string/null). Invalid submission/email/name/photo/upload/type: 400; oversized photo: 413; rate limit: 429; unknown contest: 404; closed entries: 403; already entered: 409 with error `data.entry` preserved for recovery of the share link.

## GET /content-blocks/photo-contest/entries

**Permission:** `__return_true`; anonymous cacheable read. **Parameters:** common IDs only.

**Ability:** `extrachill/photo-contest-list`.

**Response:** `{entries: [...]}` plus `phase` when provided as an array by the owner. Accepts either an ability entries envelope or a bare array and normalizes indices. `Cache-Control: public, max-age=15`. Unknown contest: 404.

## GET /content-blocks/photo-contest/entry-qr

**Permission:** `extrachill_api_content_blocks_photo_contest_entry_qr_permission`, shared public-read budget (default 120/minute).

**Parameters:** common IDs and required positive integer `entry_id`.

**Ability:** `extrachill/photo-contest-entry-qr`.

**Response:** JSON `{image, mime_type}`, not streamed image bytes. `Cache-Control: public, max-age=60`. Unknown contest/entry: 404.

## POST /content-blocks/photo-contest/vote

**Permission:** `extrachill_api_content_blocks_photo_contest_vote_permission`, shared public-write budget (default 120/minute); no Turnstile wrapper on this route.

**Parameters:** common IDs, required positive integer `entry_id`, required `email_address` (email string, maximum 254 characters, `sanitize_email`). JSON body is supported.

**Ability:** `extrachill/photo-contest-vote`; same hidden-write-ability requirement as submit.

**Response:** `{message, vote_count}` (defaults empty string/0). Unknown entry/contest: 404; closed voting: 403; duplicate vote: 409; invalid email: 400; rate limit: 429.

## Error and telemetry behavior

Explicit transport error mappings override upstream statuses while preserving other error data. Unmapped errors retain an integer upstream status or default to 500. Submit and vote attempts, including admission refusals, emit best-effort analytics outcomes without email or other personal data; telemetry failures do not change the response. Rate-limit defaults are filterable.
