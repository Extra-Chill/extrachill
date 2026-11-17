# Extra Chill Content Blocks abilities

Extra Chill Content Blocks registers 13 abilities, all in the `extrachill-content` category ("Extra Chill Content", registered in `extrachill-content-blocks.php`). They back the plugin's interactive blocks:

- band and rapper name generators;
- image voting;
- photo contests;
- trivia authoring.

Each ability is registered from its own file in `inc/abilities/`. The domain logic lives in `src/blocks/*` and `inc/photo-contest/*`.

Public anonymous writes (photo contest submit and vote) are deliberately kept off core `/wp-abilities/v1` (`show_in_rest` false). Their only transport is the `extrachill/v1` route in `extrachill-api`, which handles rate limiting for public writes.

Related docs:

- route docs: [generators and image voting](../../extrachill-api/docs/routes/content-blocks/generators-and-image-voting.md) and [photo contest](../../extrachill-api/docs/routes/content-blocks/photo-contest.md);
- user guides in [docs/user/](user/photo-contests.md);
- [manual photo contest phases](photo-contest-manual-phases.md).

## Generators

### `extrachill/generate-band-name`

- **Purpose:** Generates a random band name from genre-specific word lists. It wraps `extrachill_content_blocks_generate_band_name()`. User guide: [band and rapper name generators](user/band-and-rapper-name-generators.md).
- **Input:**
  - `input` (seed word, required).
  - `genre` (`rock` | `country` | `metal` | `indie` | `punk` | `jam` | `electronic` | `random`, default `rock`).
  - `number_of_words` (2–4, default 2).
  - `first_the` and `and_the` (booleans).
- **Output:** `{ name }`. Errors: `invalid_input` (empty seed) or `function_missing`.
- **Permission:** `__return_true` (public).
- **Exposure:** `show_in_rest` true. REST: `POST /wp-json/extrachill/v1/content-blocks/band-name` (see [route docs](../../extrachill-api/docs/routes/content-blocks/generators-and-image-voting.md)). No `wp extrachill` command found.

### `extrachill/generate-rapper-name`

- **Purpose:** Generates a random rapper name from style-specific word lists. It wraps `extrachill_content_blocks_generate_rapper_name()`.
- **Input:**
  - `input` (seed name, required).
  - `style` (`old school` | `trap` | `grime` | `conscious` | `random`).
  - `gender` (`male` | `female` | `non-binary`).
  - `number_of_words` (2 or 3, default 2).
- **Output:** `{ name }`. Errors: `invalid_input` or `function_missing`.
- **Permission:** `__return_true` (public).
- **Exposure:** `show_in_rest` true. REST: `POST /wp-json/extrachill/v1/content-blocks/rapper-name` (see [route docs](../../extrachill-api/docs/routes/content-blocks/generators-and-image-voting.md)). No `wp extrachill` command found.

## Image voting

User guide: [image voting](user/image-voting.md).

### `extrachill/image-voting-list`

- **Purpose:** Returns the current vote count for one image-voting block instance. The count is the `image_vote` comments plus any legacy `voteCount`/`voters` block attributes.
- **Input:** `post_id` (integer, required) and `instance_id` (block `uniqueBlockId`, required).
- **Output:** `{ vote_count }`. Errors: `invalid_post_id`, `invalid_instance_id`, `post_not_found` or `function_missing`.
- **Permission:** `__return_true` (public).
- **Exposure:** `show_in_rest` true. REST: `GET /wp-json/extrachill/v1/content-blocks/image-voting/vote-count/{post_id}/{instance_id}`. No `wp extrachill` command found.

### `extrachill/image-voting-vote`

- **Purpose:** Casts one vote on an image-voting block instance. Each email address can vote once per post and instance.
- **Input:** `post_id`, `instance_id` and `email_address` (all required).
- **Output:** `{ message, vote_count }`. Errors: `invalid_post_id`, `invalid_instance_id`, `invalid_email`, `function_missing`, `already_voted` or `vote_failed`.
- **Permission:** `is_user_logged_in`.
- **Exposure:** `show_in_rest` true. No `wp extrachill` command found.
  - The public route `POST /wp-json/extrachill/v1/content-blocks/image-voting/vote` (`__return_true`) does **not** call this ability. It calls `extrachill_content_blocks_process_image_vote()` directly, so anonymous voters never pass through this permission check.
- **Side effects:**
  - Inserts an approved `image_vote` comment with `instance_id` comment meta.
  - Subscribes the email to the newsletter with the `image_voting` context through `extrachill_network_subscribe()` (failures are logged, not blocking).

## Photo contests

Moderator-only abilities use `extrachill_content_blocks_photo_contest_can_moderate()`, which is `current_user_can( 'edit_others_posts' )`. Domain errors are `WP_Error`s with an HTTP status in their data. User guide: [photo contests](user/photo-contests.md).

### `extrachill/photo-contest-submit`

- **Purpose:** Submits a photo contest entry. Uploads are anonymous and require an email. Each email gets one entry per contest, and exact duplicate photos (matched by content hash, hidden entries included) are rejected.
  - Moderators can add an entry without an email (team-added entries).
- **Input:**
  - `post_id`, `contest_id`, `display_name` and `photo` (the `$_FILES` array passed through by the route), all required.
  - `email` (required unless the caller can moderate).
  - `instagram`.
  - `website` (honeypot; must be empty).
- **Output:** `{ message, entry }`, where `entry` is the public entry data, including `share_url`.
  - Errors include:
    - `invalid_email`, `missing_name`;
    - `entries_closed`;
    - `already_entered` (409; returns the existing `entry`, so the entrant gets their share link back);
    - `duplicate_photo` (409);
    - photo validation and storage errors.
- **Permission:** `__return_true` (public).
- **Exposure:** `show_in_rest` false. REST: `POST /wp-json/extrachill/v1/content-blocks/photo-contest/submit` (multipart; see [route docs](../../extrachill-api/docs/routes/content-blocks/photo-contest.md)). No `wp extrachill` command found.
- **Side effects:**
  - Stores the photo as an attachment and writes entry meta: contest ID, email, name, photo hash, Instagram handle and, for team-added entries, `_photo_contest_added_by`.
  - Subscribes the email with the `photo_contest` context through `extrachill_network_subscribe()`.
  - Fires `extrachill_content_blocks_photo_contest_participant` (role `entrant`). Its listeners:
    - resolve or create an Extra Chill account through `extrachill/resolve-account-by-email`, an ability owned by extrachill-users;
    - queue the contest's welcome email when the block has entrant copy.

### `extrachill/photo-contest-list`

- **Purpose:** Lists the visible entries of a contest with live vote counts and share URLs, together with the contest's phase state. Entries come back in insertion order; the frontend shuffles them.
- **Input:** `post_id` and `contest_id` (required), plus `include_hidden` (default false).
- **Output:**
  - `entries[]`: each entry has `id`, `display_name`, `instagram`, `image_url`, `thumbnail_url`, `vote_count` and `share_url`, plus `hidden` when hidden entries were included. Entrant emails are never returned.
  - `phase`: `{ entries_closed, voting_closed, entries_manually_closed, voting_manually_closed }`.
  - Errors: `invalid_post_id`, `invalid_contest_id` or `contest_not_found` (404).
- **Permission:** `__return_true` (public). `include_hidden` is honoured only for moderators and ignored for everyone else.
- **Exposure:** `show_in_rest` true.
  - REST: `GET /wp-json/extrachill/v1/content-blocks/photo-contest/entries`.
  - WP-CLI: `wp extrachill content photo-contest list`.
  - Also called by the block frontend (`PhotoContest.tsx`).

### `extrachill/photo-contest-vote`

- **Purpose:** Casts a vote on a contest entry. Each email gets one vote per entry. Votes are refused when voting is closed or the entry is hidden.
- **Input:** `post_id`, `contest_id`, `entry_id` and `email_address` (all required).
- **Output:** `{ message, vote_count }`. Errors: `invalid_post_id`, `invalid_contest_id`, `invalid_entry_id` or `invalid_email` (400), plus status-coded domain errors (voting closed, entry not found, already voted).
- **Permission:** `__return_true` (public). The real checks are inside the domain logic: the one-vote-per-email rule and the voting close dates.
- **Exposure:** `show_in_rest` false. REST: `POST /wp-json/extrachill/v1/content-blocks/photo-contest/vote`. No `wp extrachill` command found.
- **Side effects:**
  - Inserts an `image_vote` comment on the contest post, with instance `<contest_id>:entry-<id>`.
  - Subscribes the email with the `photo_contest` context.
  - On the email's first vote in the contest, fires `extrachill_content_blocks_photo_contest_participant` (role `voter`), which triggers account resolution and the welcome email.

### `extrachill/photo-contest-entry-qr`

- **Purpose:** Generates a QR code for a valid entry's share URL. The server builds the URL itself (`get_permalink( $post_id )` plus `?entry=<id>`) and never accepts one from the client.
- **Input:** `post_id`, `contest_id` and `entry_id` (all required).
- **Output:** `{ image, mime_type }` (base64 image).
  - Errors:
    - `invalid_entry_input` (400).
    - `contest_not_found` or `entry_not_found` (404).
    - `qr_unavailable` or `qr_generation_failed` (503).
- **Permission:** `__return_true` (public).
- **Exposure:** `show_in_rest` false. REST: `GET /wp-json/extrachill/v1/content-blocks/photo-contest/entry-qr`. No `wp extrachill` command found.
- **Side effects:**
  - Calls `extrachill/generate-qr-code`. A temporary `wp_ability_permission_result` filter grants permission only for that exact share URL.
  - Caches the result in an `ec_pc_qr_<md5>` transient for one week.

### `extrachill/photo-contest-top`

- **Purpose:** Returns the top N entries ranked by vote count, with ties going to the earliest entry, so staff can pick finalists. The public "Finalists" view does not use it.
- **Input:** `post_id` and `contest_id` (required), `count` (default 10, minimum 1), `include_hidden`.
- **Output:** `{ entries[] }`, highest votes first. Errors: `invalid_post_id` or `invalid_contest_id` (400).
- **Permission:** `edit_others_posts` (moderator).
- **Exposure:** `show_in_rest` true. WP-CLI: `wp extrachill content photo-contest top`. No `extrachill-api` route found.

### `extrachill/photo-contest-hide`

- **Purpose:** Hides or restores a contest entry for moderation. Hidden entries leave the public grid and cannot receive votes; their existing votes are kept.
- **Input:** `entry_id` (attachment ID, required) and `hidden` (default true; false restores the entry).
- **Output:** `{ message }`. Errors: `invalid_entry_id` (400) or `entry_not_found` (404).
- **Permission:** `edit_others_posts` (moderator).
- **Exposure:** `show_in_rest` true. It is annotated idempotent and not destructive, so core runs it with POST.
  - WP-CLI: `wp extrachill content photo-contest hide <id> [--unhide]`.
  - Also called by the moderator controls (`EntryCard.tsx`).
  - No `extrachill-api` route found.
- **Side effects:** sets or deletes the entry's hidden post meta.

### `extrachill/photo-contest-set-phase`

- **Purpose:** Closes entries or voting immediately, or clears that manual close so the scheduled window applies again. Scheduled closes always still apply. See [manual phases](photo-contest-manual-phases.md).
- **Input:** `post_id`, `contest_id`, `phase` (`entries` | `voting`) and `closed` (strict boolean), all required.
- **Output:** `{ message }` ("Phase closed." or "Scheduled window restored."). Errors: `invalid_phase` (400) or `contest_not_found` (404).
- **Permission:** `edit_others_posts` (moderator).
- **Exposure:** `show_in_rest` true. Called by the moderator controls (`PhotoContest.tsx`). No `extrachill-api` route or `wp extrachill` command found.
- **Side effects:** adds the per-phase post meta close timestamp, keeping the first one if repeated, or deletes it when reopening.

## Trivia

User guide: [trivia](user/trivia.md).

### `extrachill/trivia-create`

- **Purpose:** A deterministic serializer. It turns fully written questions into `extrachill/trivia` block markup that matches editor output, then creates a new post or appends the blocks to an existing one. It does not generate questions.
- **Input:**
  - `questions[]` (required, at least 1). Each question has:
    - `question` (required);
    - `options` (at least 2 non-empty, required);
    - `correctAnswer` (zero-based index, required);
    - optional `answerJustification`, `resultMessages` and `scoreRanges`.
  - `post_id` (append target). Without it, a new post is created:
    - `post_title` is required;
    - `post_status` (`draft` | `pending` | `publish` | `private`, default `draft`);
    - `post_type` (default `post`).
  - `result_messages`: quiz-wide defaults, merged over the block defaults.
- **Output:** `{ post_id, post_url, edit_url, questions_added, created }`.
  - Errors:
    - `no_questions`, `invalid_question`, `invalid_options`, `invalid_correct_answer`.
    - `post_not_found`.
    - `missing_post_title`.
    - `invalid_post_type`.
- **Permission:** `edit_post` on `post_id` when one is given, otherwise `edit_posts`. Returns `rest_forbidden` (403).
- **Exposure:** `show_in_rest` true. WP-CLI: `wp extrachill content quiz`. No `extrachill-api` route found.
- **Side effects:** inserts a post, or updates the target post's content (appending after a blank line).

### `extrachill/trivia-list`

- **Purpose:** Reads the top-level `extrachill/trivia` blocks of a post and returns their attributes in document order.
- **Input:** `post_id` (required).
- **Output:** `{ post_id, count, questions[] }`. Errors: `invalid_post_id` or `post_not_found`.
- **Permission:** `edit_post` on `post_id`. Returns `rest_forbidden` (403).
- **Exposure:** `show_in_rest` true. WP-CLI: `wp extrachill content quiz-list`. No `extrachill-api` route found.
