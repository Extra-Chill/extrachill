# Extra Chill Studio abilities

Extra Chill Studio registers seven abilities, all in the shared `extrachill` category:

- a read-only view of the main-site editorial review queue;
- Instagram giveaway tooling;
- a token minter for the Sweatpants transcription worker;
- state and retry operations for a social draft's cross-post delivery.

All seven set `show_in_rest: true`. The Studio React tabs call the first five through core `/wp-abilities/v1/abilities/<name>/run`.

## Editorial review queue

Both review abilities are registered in one loop in `inc/abilities/review-submissions.php`. Names are built as `extrachill/` + `<action>` + `-review-submissions`, where the action is `list` or `read`.

Rules that apply to both:

- **Permission:** `ec_studio_can_review_submissions()`, which wraps Extra Chill Users' `ec_can_review_submissions()`. That function switches to the main site and checks `current_user_can( 'review_submissions' )` or `edit_others_posts`.
- **Exposure:** `show_in_rest` true (annotations: readonly, idempotent, not destructive). The Studio Review tab calls them (`src/blocks/studio/tabs/review/index.tsx`). No `extrachill-api` route or `wp extrachill` command found.
- **Queue rules:** the queue is every `pending` `post` on the main site whose author has the `extra_chill_team` or `administrator` role, newest modified first (`inc/review-queue.php`).

### `extrachill/list-review-submissions`

- **Purpose:** Lists the pending team articles on the main site, so reviewers can see them without editor access.
- **Input:** none (empty object).
- **Output:** an array of rows `{ id, author_id, title, author, submitted_at, modified_gmt, edit_url }`. `preview_url` is always removed, and `edit_url` is removed unless the user can edit on the main site. Returns `review_forbidden` (403) when the permission check fails.
- **Permission/Exposure:** see the shared rules above.

### `extrachill/read-review-submissions`

- **Purpose:** Reads one article from the pending queue. It is rendered through `the_content` so a reviewer can read it in Studio.
- **Input:** `id` (integer ≥ 1, required).
- **Output:** the queue row plus `content` (rendered and filtered with `wp_kses_post`), `featured_image` (the `large` size URL), `categories` (names) and `tags` (names). Returns `review_forbidden` (403), or `submission_not_found` (404) when the post is not in the pending team queue.
- **Permission/Exposure:** see the shared rules above.

## Giveaways

### `extrachill/resolve-instagram-media`

- **Purpose:** Turns an Instagram post URL (`/p/`, `/reel/` or `/tv/`), a bare shortcode, or a numeric media ID into the numeric Graph API media ID. The conversion is pure base64-alphabet math with no API calls.
- **Input:** `input` (string, required).
- **Output:** `{ platform: "instagram", media_id }`. Errors: `missing_param` (400) or `resolve_failed` (400).
- **Permission:** `current_user_can( 'edit_posts' )`.
- **Exposure:** `show_in_rest` true.
  - REST: `POST /wp-json/extrachill/v1/instagram/resolve` (see [route docs](../../extrachill-api/docs/routes/instagram/resolve.md)).
  - WP-CLI: `wp extrachill giveaway resolve`.
  - Also called internally by `extrachill/run-giveaway`.

### `extrachill/run-giveaway`

- **Purpose:** Runs a whole Instagram giveaway in one call:
  1. resolves the post;
  2. fetches every comment;
  3. filters entries by the rules;
  4. draws distinct winners with `random_int()`;
  5. optionally replies to each winning comment.
- **Input:**
  - `media_input` (string, required).
  - `entry_mode` (`per_comment` | `per_user`, default `per_comment`).
  - `require_tag` (boolean, default true).
  - `min_tags` (integer ≥ 1, default 1).
  - `winner_count` (integer 1–50, default 1).
  - `announce` (boolean, default false).
  - `message` (string; `{username}` placeholder; defaults to a congratulations message).
- **Output:** `{ media_id, platform, entry_mode, winners, stats, partial, pages }`.
  - Each winner is `{ rank, username, comment_id, comment_text, mentions, announced, reply_id, announce_error }`.
  - `stats` is `{ entry_mode, total_comments, valid_entries, filtered_out, unique_users, entries_by_user }`.
  - Errors:
    - `ability_missing` (500): a dependency ability is not available.
    - `resolve_failed`.
    - `no_comments` (404).
    - `no_valid_entries` (404, with `stats`).
    - Upstream errors are passed through.
- **Permission:** `current_user_can( 'edit_posts' )`.
- **Exposure:** `show_in_rest` true.
  - REST: `POST /wp-json/extrachill/v1/giveaway/run` (see [route docs](../../extrachill-api/docs/routes/giveaway/giveaway.md)). `POST /giveaway/schedule` queues a Data Machine `giveaway` task instead of running the ability; the task (`inc/tasks/giveaway-task.php`) runs it later.
  - WP-CLI: `wp extrachill giveaway run`. `wp extrachill giveaway schedule` queues the same task.
- **Side effects:**
  - Calls `extrachill/resolve-instagram-media`.
  - Reads comments from the Instagram Graph API through Data Machine `datamachine/instagram-read` (`comments_all`).
  - When `announce` is true, posts a public reply on each winner's comment through `datamachine/instagram-comment-reply`.

## Transcription

### `extrachill/sweatpants-token`

- **Purpose:** Mints a short-lived, HMAC-signed bearer token. The Studio Transcribe tab presents it directly to the Sweatpants worker (`sweatpants.chubes.net`) to upload audio and create and poll jobs.
- **Input:**
  - `scope` (string, required): space-separated scopes. Every scope must be on the allowlist: `read`, `jobs:read`, `jobs:write`, `uploads:read`, `uploads:write`, `callback:write`.
  - `ttl` (integer 1–3600, optional, default 900).
- **Output:** `{ token, expires_at, scope, callback_url, callback_secret, callback_issuer, callback_user_id }`.
  - The `callback_*` fields are null unless `callback:write` was requested. In that case:
    - `callback_url` is `rest_url( 'extrachill/v1/transcribe/callback' )`;
    - `callback_secret` is the network signing secret itself, returned on purpose (see the code comments);
    - `callback_issuer` is `extrachill-studio`;
    - `callback_user_id` is the current user.
  - Errors:
    - `signer_unavailable` (500): wp-native-auth is missing.
    - `not_logged_in` (401).
    - `secret_not_configured` (500).
    - `scope_required` (400).
    - `scope_not_allowed` (403).
    - `sign_failed` (500).
- **Permission:** `current_user_can( 'manage_options' )` or `ec_is_team_member()`.
- **Exposure:** `show_in_rest` true. Called by the Transcribe tab (`src/blocks/studio/tabs/transcribe/tokenManager.ts`) at `/wp-abilities/v1/abilities/extrachill/sweatpants-token/run`. No `extrachill-api` route or `wp extrachill` command found.
- **Side effects:** none on the site. It reads the `sweatpants_signed_token_secret` network option and signs `{ iss: "extrachill-studio", sub, scope, exp, jti }` with `wp_native_auth_sign_external_token()`. The token grants the requested scopes on the external worker until it expires.

## Social publish delivery

Both abilities are registered in one loop in [`inc/social-drafts.php`](../inc/social-drafts.php). When a regular `post` with social platforms and a caption first transitions to `publish`, Studio hands it to Data Machine Socials (`datamachine/enqueue-social-publish`) and stores only the opaque owner receipt (`dop_` + 64 hex characters) in post meta. These abilities read or retry that Socials-owned delivery; Studio keeps no copy of delivery state.

Rules that apply to both:

- **Input:** `post_id` (integer ≥ 1, required); no other properties.
- **Output:** the Socials result `{ success, delivery?, error? }`. Failures use `{ success: false, error: { code, message, retryable } }` rather than `WP_Error`, including `social_publish_forbidden` when the user cannot `edit_post` the given post and `social_publish_capability_unavailable` (retryable) when the Socials ability is missing.
- **Permission:** `current_user_can( 'edit_posts' )`; the callback also requires `edit_post` on `post_id`.
- **Exposure:** `show_in_rest` true (no annotations). No Studio tab, `extrachill-api` route or `wp extrachill` command calls them.

### `extrachill/get-social-publish-state`

- **Purpose:** Reads the current Socials delivery for a Studio post.
- **Output:** the result of `datamachine/get-social-publish` for the stored receipt. Returns `social_publish_delivery_pending` (retryable) when no receipt has been stored yet.

### `extrachill/retry-social-publish`

- **Purpose:** Explicitly retries a failed delivery, or the failed handoff when no receipt was stored.
- **Output:** the Socials retry or enqueue result. Returns `social_publish_post_unavailable` unless the post is a published `post`.
- **Side effects:** With a stored receipt, calls `datamachine/retry-social-publish`; otherwise re-runs `datamachine/enqueue-social-publish` with the post's caption, assets and platforms. On success, stores the returned receipt and clears logged publish errors.
