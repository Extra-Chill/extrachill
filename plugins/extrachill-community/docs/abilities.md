# Extra Chill Community Abilities

This page lists every ability the Community plugin registers with the WordPress Abilities API. They cover bbPress forum reading and writing (topics, replies, editor loads, drafts), upvotes, points and contribution history, member moderation (blocking and content reports), forum infrastructure (stats, forum archive visibility, cache flushing), and read-only projections that other plugins consume. Every ability uses the `extrachill-community` category, which is registered in `inc/content/editor/draft-abilities-category.php`. REST routes in `extrachill-api` and `wp extrachill community` CLI commands are thin wrappers that call these abilities. Abilities with `show_in_rest: true` can also be run through core `/wp-abilities/v1/abilities/{name}/run`.

Shared helpers used below:

- **Forum readability** (`extrachill_community_can_read_forum()` in `inc/core/ability-helpers.php`): a forum and all of its ancestors must be public. Private or hidden forums count only when the viewer has `read_forum`.
- **Public forum** (`extrachill_community_is_public_forum()`): a forum and all of its ancestors must be strictly public.
- **Trusted forum actor** (`extrachill_community_authorize_forum_action()` in `inc/content/topic-reply-write.php`): the acting user is the Agents API execution principal when one is present, and the principal must match the logged-in user and stay within its capability ceiling. Otherwise the acting user is the current user. Under WP-CLI with no authenticated user, `input.user_id` can choose the author (create only). The bbPress capability is checked on the community blog.

## Topics and replies

Registered in `inc/content/topic-reply-abilities.php`. The callbacks live in `topic-reply-read.php`, `topic-reply-write.php`, `topic-reply-editor.php` and `topic-reply-editor-permissions.php`. For full route documentation, see [Topics and replies routes](../../extrachill-api/docs/routes/community/topics-and-replies.md) and [Editor routes](../../extrachill-api/docs/routes/community/editors.md).

### `extrachill/community-list-topics`

- **Purpose:** Returns a paginated list of published or closed topics, either for one forum or across all forums. With `author_id`, it returns only that member's topics in public forums, newest first.
- **Input:** `forum_id` (integer ≥1, optional), `author_id` (integer ≥1, optional; forces date DESC order), `per_page` (integer 1–100, default 20), `page` (integer ≥1, default 1), `orderby` (`date`|`modified`|`title`, default `date`), `order` (`ASC`|`DESC`, default `DESC`). No additional properties are allowed.
- **Output:** `{topics: Topic[], total, pages, page, per_page}`. A Topic has `topic_id`, `title`, `forum_id`, `author_id`, `author_name`, `status` (publish/closed), `date`, `modified`, `reply_count`, `voice_count` and `url`, plus optional `content`, `public_voice` and `upvote_count`.
- **Permission:** `__return_true`. The execute callback returns a 404 `forum_not_found` error for forums the viewer can't read (or, with `author_id`, forums that aren't public). Topics in such forums are left out.
- **Exposure:** `show_in_rest: true`. Called by `GET /extrachill/v1/community/topics` and `wp extrachill community topics`.

### `extrachill/community-get-topic`

- **Purpose:** Returns one published or closed topic, with a page of its replies if requested.
- **Input:** `topic_id` (integer ≥1, required), `include_replies` (boolean, default true), `replies_per_page` (integer 1–100, default 30), `replies_page` (integer ≥1, default 1).
- **Output:** `{topic: Topic, replies?: Reply[], replies_total?, replies_pages?}`. A Reply has `reply_id`, `topic_id`, `forum_id`, `author_id`, `author_name`, `content`, `status`, `date`, `reply_to` and `url`, plus optional `public_voice` and `upvote_count`.
- **Permission:** `__return_true`. The callback returns 404 when the post isn't a topic, isn't published or closed, or sits in a forum the viewer can't read.
- **Exposure:** `show_in_rest: true`. Called by `GET /extrachill/v1/community/topics/{id}` and `wp extrachill community topic`.

### `extrachill/community-list-replies`

- **Purpose:** Returns a paginated list of replies for a readable published or closed topic.
- **Input:** `topic_id` (integer ≥1, required), `per_page` (integer 1–100, default 30), `page` (integer ≥1, default 1).
- **Output:** `{replies: Reply[], total, pages, page, per_page}`.
- **Permission:** `__return_true`. The callback checks topic status and forum readability and returns 404 `topic_not_published` if either fails.
- **Exposure:** `show_in_rest: true`. Called by `GET /extrachill/v1/community/replies`. No CLI command found.

### `extrachill/community-create-topic`

- **Purpose:** Creates a published topic in a forum. Content is HTML (sanitized with `wp_kses_post`) or Markdown (converted with `bfb_convert()` first).
- **Input:** `forum_id` (integer, required), `title` (string, required), `content` (string, required), `format` (`html`|`markdown`), `public_voice` (string matching `^(artist|venue):N$`, optional). A legacy `user_id` is accepted only if it matches the trusted actor, or as the WP-CLI author override.
- **Output:** `{topic_id, title, url, forum_id, author_id, public_voice}`.
- **Permission:** The trusted forum actor must have `publish_topics` (`extrachill_community_ability_create_topic_permission`).
- **Exposure:** `show_in_rest: true`. Called by `POST /extrachill/v1/community/topics` and `wp extrachill community create-topic`.
- **Side effects:** Inserts the topic with `bbp_insert_topic()` and saves the public voice. Fires `bbp_new_topic`, which triggers notifications, points, cache invalidation and draft cleanup listeners.

### `extrachill/community-create-reply`

- **Purpose:** Posts a reply to a topic, optionally threaded under another reply. Content is HTML or Markdown, as for topics.
- **Input:** `topic_id` (integer, required), `content` (string, required), `format` (`html`|`markdown`), `reply_to` (integer, optional), `public_voice` (string, optional). Legacy `user_id` follows the same rule as create-topic.
- **Output:** `{reply_id, topic_id, forum_id, url, author_id, public_voice}`.
- **Permission:** The trusted forum actor must have `publish_replies`.
- **Exposure:** `show_in_rest: true`. Called by `POST /extrachill/v1/community/replies` and `wp extrachill community create-reply`.
- **Side effects:** Inserts the reply with `bbp_insert_reply()` and saves the public voice. Fires `bbp_new_reply`, which triggers mention and reply notifications, points recalculation and cache invalidation.

### `extrachill/community-get-topic-for-editor`

- **Purpose:** Loads a topic for editing. Returns the stored block markup and a permissions envelope. Edit autosaves are handled by core `/autosaves`, not by this ability.
- **Input:** `topic_id` (integer, required), `blog_id` (integer, optional).
- **Output:** `{id, type: "forum_topic", title, content, raw, status, forum_id, permalink, updated_at, context: {blog_id, forum_id}, permissions: {canSave, canUploadMedia, canDelete}}`. `canSave` is false once the bbPress edit lock has expired.
- **Permission:** The caller must be logged in and have `current_user_can( 'read_topic', topic_id )`.
- **Exposure:** `show_in_rest: false`. Called by `GET /extrachill/v1/community/topics/{id}/editor`.

### `extrachill/community-get-reply-for-editor`

- **Purpose:** Loads a reply for editing, with its parent topic and forum context and a permissions envelope.
- **Input:** `reply_id` (integer, required), `blog_id` (integer, optional).
- **Output:** `{id, type: "forum_reply", content, raw, status, topic_id, forum_id, reply_to, permalink, updated_at, context: {blog_id, topic_id, forum_id}, permissions: {canSave, canUploadMedia, canDelete}}`.
- **Permission:** The caller must be logged in and have `current_user_can( 'read_reply', reply_id )`.
- **Exposure:** `show_in_rest: false`. Called by `GET /extrachill/v1/community/replies/{id}/editor`.

### `extrachill/community-update-topic`

- **Purpose:** Updates a topic's content and optionally its title, author and public voice.
- **Input:** `topic_id` (integer, required), `content` (string, required), `title` (string; can't be empty if supplied), `format` (`html`|`markdown`), `blog_id` (integer), `user_id` (integer; reassigns the author), `public_voice` (canonical voice, or `""` to clear it).
- **Output:** `{id, status, title, content, permalink, updated_at, public_voice}`.
- **Permission:** The trusted forum actor must have `edit_topic` on the post. Reassigning the author also requires `edit_others_topics`. If `bbp_past_edit_lock()` reports the edit window has closed, the callback returns 403 `edit_lock_expired`.
- **Exposure:** `show_in_rest: false`. Called by `PUT/PATCH /extrachill/v1/community/topics/{id}/editor`.
- **Side effects:** Calls `wp_update_post()`, saves the public voice and fires `bbp_edit_topic`, which triggers cache invalidation and points listeners.

### `extrachill/community-update-reply`

- **Purpose:** Updates a reply's content and optionally its author and public voice.
- **Input:** `reply_id` (integer, required), `content` (string, required), `format` (`html`|`markdown`), `blog_id` (integer), `user_id` (integer; requires `edit_others_replies`), `public_voice` (canonical voice, or `""` to clear it).
- **Output:** `{id, status, content, permalink, updated_at, public_voice}`.
- **Permission:** The trusted forum actor must have `edit_reply`. Reassigning the author also requires `edit_others_replies`. Expired edit windows return 403 `edit_lock_expired`.
- **Exposure:** `show_in_rest: false`. Called by `PUT/PATCH /extrachill/v1/community/replies/{id}/editor`.
- **Side effects:** Calls `wp_update_post()`, saves the public voice and fires `bbp_edit_reply`.

## Drafts

Registered in `inc/content/editor/draft-abilities.php`. Drafts are stored in the per-blog `{prefix}ec_bbpress_drafts` table (`draft-storage.php`). For route details, see [Drafts routes](../../extrachill-api/docs/routes/community/drafts.md).

All three draft abilities share one permission callback, `extrachill_community_ability_bbpress_draft_permission`. The caller must be logged in. If `user_id` is supplied for a different user, the caller also needs `edit_others_posts`.

### `extrachill/get-bbpress-draft`

- **Purpose:** Returns the stored topic or reply draft for a user and context. With `prefer_unassigned`, a topic lookup that finds nothing falls back to the draft with no forum assigned (`forum_id = 0`).
- **Input:** `type` (`topic`|`reply`, required), `user_id`, `blog_id`, `forum_id`, `topic_id`, `reply_to` (integers, optional), `prefer_unassigned` (boolean, optional). `user_id` defaults to the current user and `blog_id` to the current blog.
- **Output:** The draft row object, or `null`.
- **Permission:** Shared draft permission (see above).
- **Exposure:** `show_in_rest: false`. Called by `GET /extrachill/v1/community/drafts`. It's also used in-process by `inc/content/editor/drafts.php`.

### `extrachill/save-bbpress-draft`

- **Purpose:** Creates or updates (upserts) the draft for a user and context.
- **Input:** `type` (`topic`|`reply`, required), `user_id`, `blog_id`, `forum_id`, `topic_id`, `reply_to` (integers), `title`, `content` (strings).
- **Output:** The stored draft row, or a `draft_save_failed` / `invalid_user` error.
- **Permission:** Shared draft permission.
- **Exposure:** `show_in_rest: false`. Called by `POST /extrachill/v1/community/drafts`.
- **Side effects:** Upserts a row into `ec_bbpress_drafts`. The table's UNIQUE key on the context makes concurrent saves safe.

### `extrachill/delete-bbpress-draft`

- **Purpose:** Deletes the draft for a user and context. Deleting a draft that doesn't exist also returns true.
- **Input:** `type` (`topic`|`reply`, required), `user_id`, `blog_id`, `forum_id`, `topic_id`, `reply_to` (integers).
- **Output:** boolean.
- **Permission:** Shared draft permission.
- **Exposure:** `show_in_rest: false`. Called by `DELETE /extrachill/v1/community/drafts`. It's also used in-process by `inc/content/editor/drafts.php`.
- **Side effects:** Deletes a row from `ec_bbpress_drafts`.

## Upvotes, points and contributions

For the feature background, see [Upvoting System](social/upvoting.md) and [Rank System](social/rank-system.md).

### `extrachill/community-upvote`

- **Purpose:** Toggles the current user's upvote on a topic or reply by calling `extrachill_process_upvote()`. Defined in `inc/abilities/community-upvote.php`.
- **Input:** `post_id` (integer, required), `type` (`topic`|`reply`, required).
- **Output:** `{message, new_count, upvoted}`.
- **Permission:** `is_user_logged_in()`. The vote is always recorded for `get_current_user_id()`.
- **Exposure:** `show_in_rest: true`. Called by `POST /extrachill/v1/community/upvote` ([Upvote route](../../extrachill-api/docs/routes/community/upvote.md)) and `wp extrachill community upvote`.
- **Side effects:** Updates the user's `upvoted_posts` meta and the post's `upvote_count` meta, then fires `custom_upvote_action`. That action adjusts the author's `extrachill_upvotes_received` and adds or removes 0.5 points.

### `extrachill/community-get-upvotes`

- **Purpose:** Returns a post's upvote count and whether a given user has upvoted it. Defined in `inc/social/upvote-abilities.php`.
- **Input:** `post_id` (integer, required), `user_id` (integer, optional; defaults to the current user).
- **Output:** `{post_id, upvote_count, user_upvoted}`.
- **Permission:** `__return_true`. Any caller can pass any `user_id`.
- **Exposure:** `show_in_rest: false`. No route or command calls it.

### `extrachill/community-get-user-points`

- **Purpose:** Returns a user's total points and rank name. Defined in `inc/social/rank-system/rank-abilities.php`.
- **Input:** `user_id` (integer, required).
- **Output:** `{user_id, user_login, display_name, total_points (number), rank}`.
- **Permission:** `__return_true`.
- **Exposure:** `show_in_rest: false`. Called by `wp extrachill community points <user>`.
- **Side effects:** None directly. `extrachill_get_user_total_points()` may recompute and cache the total if it isn't already cached.

### `extrachill/community-recalculate-points`

- **Purpose:** Recalculates points for one user, or for every user who has stored `extrachill_total_points`.
- **Input:** `user_id` (integer, optional; omit to recalculate everyone).
- **Output:** `{recalculated: integer}`.
- **Permission:** `__return_true`. There's no capability check, so only in-process callers can reach it because REST is off.
- **Exposure:** `show_in_rest: false`. Called by `wp extrachill community points <user> --recalculate` and `wp extrachill community recalculate-all`.
- **Side effects:** Deletes the `user_topic_count_*`, `user_reply_count_*` and `user_points_*` transients, then recomputes points. A recalculation for all users also clears the leaderboard cache.

### `extrachill/get-user-contribution-calendar`

- **Purpose:** Returns a user's contributions per day for the GitHub-style profile heatmap. The data comes from the `ec_get_contribution_events()` seam owned by extrachill-users. If that seam is missing, the result is an all-zero calendar. Defined in `inc/social/rank-system/contribution-calendar-ability.php`.
- **Input:** `user_id` (integer, required), `days` (integer, default 365; ignored when `year` is set), `year` (integer, a past calendar year; 0, omitted or the current year means the trailing window).
- **Output:** `{user_id, days, weeks, year, first_sunday, window_start, window_end, total_contributions, max_day_count, current_streak, longest_streak, counts: {"YYYY-MM-DD": int}}`. `counts` only includes days with at least one contribution.
- **Permission:** `__return_true` (public profile data).
- **Exposure:** `show_in_rest: true`. The contribution heatmap block (`src/blocks/contribution-heatmap/view.tsx`) calls `/wp-abilities/v1/abilities/extrachill/get-user-contribution-calendar/run` for past-year tabs (see [Block ability clients](block-abilities.md)). No extrachill-api route or CLI command found.
- **Side effects:** Caches the 365-day view and past-year views in the `ec_contrib_calendar_*` transient for one hour.

## Moderation

### `extrachill/community-report-content`

- **Purpose:** Lets a member report a topic, a reply or a member profile to moderators. Reports are stored as private `ec_report` posts and reviewed in wp-admin. Defined in `inc/moderation/content-reports.php`.
- **Input:** Exactly one of `post_id` (integer ≥1, topic or reply) or `user_id` (integer ≥1, member). `reason` (`spam`|`harassment`|`hate`|`sexual`|`other`, required), `note` (string, max 1000). No additional properties are allowed.
- **Output:** `{report_id, status: "open", deduplicated}`.
- **Permission:** `is_user_logged_in()`. The execute callback rejects self-reports and content the reporter can't read.
- **Exposure:** `show_in_rest: true`. No extrachill-api route or CLI command found.
- **Side effects:** If the same reporter already has an open report on the same target, that report is returned with `deduplicated: true` and the rate limit isn't charged. Otherwise the report is admitted through `extrachill_rate_limit_admit()` (10 per hour, filterable) and saved with `wp_insert_post()` in the `ec_report_open` status. It then sends in-app `content_report` notifications to up to 25 administrators, keymasters and moderators (`ec_users_notify_with_receipts`), emails `admin_email` (filterable), and fires `extrachill_community_content_reported`.

### `extrachill/community-block-user`

- **Purpose:** Adds a member to the current user's own block list, which hides that member's topics and replies from the user's community views. The blocked member isn't notified. Defined in `inc/moderation/member-block-abilities.php`.
- **Input:** `user_id` (integer ≥1, required). No additional properties are allowed.
- **Output:** `{user_id, blocked: true, total}`.
- **Permission:** `is_user_logged_in()`. It only ever acts on the caller's own list, and the callback rejects attempts to block yourself.
- **Exposure:** `show_in_rest: true`. No extrachill-api route or CLI command found.
- **Side effects:** Updates the blocking user's block-list user meta (capped at 500 entries; at the cap it returns `block_limit_reached`) and fires `extrachill_community_member_blocked`. Blocking an already blocked member changes nothing.

### `extrachill/community-unblock-user`

- **Purpose:** Removes a member from the current user's block list.
- **Input:** `user_id` (integer ≥1, required).
- **Output:** `{user_id, blocked: false, total}`.
- **Permission:** `is_user_logged_in()`. It only acts on the caller's own list.
- **Exposure:** `show_in_rest: true`. No extrachill-api route or CLI command found.
- **Side effects:** Updates the block-list user meta (deleting it once the list is empty) and fires `extrachill_community_member_unblocked` when the member was on the list.

### `extrachill/community-list-blocked-users`

- **Purpose:** Lists the members the current user has blocked. Accounts that have been deleted are left out.
- **Input:** None (empty object; no additional properties).
- **Output:** `{users: [{user_id, display_name, profile_url|null}], total}`.
- **Permission:** `is_user_logged_in()`.
- **Exposure:** `show_in_rest: true`. No extrachill-api route or CLI command found.

## Forum infrastructure

Registered in `inc/core/infrastructure-abilities.php`.

### `extrachill/community-get-stats`

- **Purpose:** Returns overall community counts. See the [stats route](../../extrachill-api/docs/routes/community/discovery-and-notifications.md).
- **Input:** None.
- **Output:** `{forums, topics, replies, active_users, total_upvotes}`. Forums, topics and replies are published counts. `active_users` is the number of users with `extrachill_total_points` meta. `total_upvotes` is the sum of `upvote_count` post meta.
- **Permission:** `__return_true`.
- **Exposure:** `show_in_rest: false`. Called by `GET /extrachill/v1/community/stats`, by extrachill-network's `CommunityStatsProvider` (in-process, or a loopback through the stats route), and by `wp extrachill community stats` and `engagement`.

### `extrachill/community-list-forums`

- **Purpose:** Lists the forums the viewer can read, with topic and reply counts and whether each forum is shown in the forum archive (`/forums/`).
- **Input:** `archive_only` (boolean, optional; filters on the legacy `_show_on_homepage` meta). No additional properties are allowed.
- **Output:** `{forums: [{forum_id, title, parent_id, status, topic_count, reply_count, show_in_archive, url}]}`.
- **Permission:** `__return_true`. The callback filters forums with `extrachill_community_can_read_forum()`.
- **Exposure:** `show_in_rest: true`. Called by `GET /extrachill/v1/community/forums` ([discovery routes](../../extrachill-api/docs/routes/community/discovery-and-notifications.md)), `wp extrachill community forums` and `engagement`, and referenced in extrachill-mcp tests.

### `extrachill/community-toggle-forum-archive`

- **Purpose:** Turns a forum's forum-archive visibility on or off. Visibility is stored in the legacy `_show_on_homepage` post meta.
- **Input:** `forum_id` (integer, required).
- **Output:** `{forum_id, title, show_in_archive}` (the new state).
- **Permission:** `__return_true`. There's no capability check, so only in-process callers can reach it because REST is off.
- **Exposure:** `show_in_rest: false`. Called by `wp extrachill community toggle-archive` (alias `toggle-homepage`).
- **Side effects:** Adds or deletes the forum's `_show_on_homepage` meta.

### `extrachill/community-flush-cache`

- **Purpose:** Clears the community leaderboard cache and edge or page caches.
- **Input:** None.
- **Output:** `{flushed: true}`.
- **Permission:** `__return_true`. Only in-process callers can reach it because REST is off.
- **Exposure:** `show_in_rest: false`. Called by `wp extrachill community flush-cache`.
- **Side effects:** Calls `extrachill_delete_leaderboard_cache()` and fires `extrachill_cache_flush`. extrachill-cache hooks that action to purge the current blog.

## Cross-plugin projections

### `extrachill/community-recent-public-activity`

- **Purpose:** Returns a bounded, portable view of the one activity feed (`extrachill_build_activity_feed()` in `inc/content/recent-feed.php`), newest first: public topics ("discussions"), replies, and approved comments on articles and events (the `comments` source registered by extrachill-users on `ec_activity_feed_sources`). Omitting `activity_types` returns every item type; it only narrows. With `artist_slug`, it returns discussions tagged with that artist and comments on articles and events tagged with that artist (replies carry no artist and are omitted). Authors blocked by the viewer are excluded. Defined in `inc/content/recent-public-activity.php`; it holds no query or merge logic of its own.
- **Input:** `limit` (integer 1–20, default 5), `artist_slug` (slug string, max 200, optional), `activity_types` (array of `discussion`, `reply`, `comment`; default all). No additional properties are allowed.
- **Output:** `{schema_version: "1", items: [...]}`. Forum items: `{canonical_url, title (topic), timestamp, activity_type: "discussion"|"reply", actor: {user_id, display_name, profile_url|null}, relationships: {forum: {name, slug, canonical_url}, artists: [≤10 terms]}}`. The actor is the post's public voice when one is set. Comment items: `{canonical_url (comment anchor), title ("Comment on <article or event>"), timestamp, activity_type: "comment", actor, relationships: {post: {name, kind: "article"|"event", canonical_url}}}`.
- **Permission:** `__return_true`. Items are dropped unless the post, its topic and the full forum chain are all public.
- **Exposure:** `show_in_rest: true`. Read cross-site by extrachill-blog (`inc/archive/artist-pillar.php`, artist-scoped, every item type) and by extrachill-network (`inc/community-activity/community-activity.php`: the sidebar widget and the blog homepage community card, every item type). No extrachill-api route or CLI command found.

### `extrachill/community-resolve-subscription-entities`

- **Purpose:** Looks up display names and URLs for account subscription identities by calling each identity's owning ability: `extrachill/artist-public-projections` for artists, `extrachill/events-public-entity-projections` for venues and locations, and `extrachill/blog-public-entity-projections` for festivals. Defined in `inc/core/subscription-inventory.php`.
- **Input:** `schema_version` (`"1"`, required), `identities` (array of 1–100 unique items, required). Each item is `{entity_type, taxonomy, slug}`, using one of the fixed pairs: artist/artist, festival/festival, venue/venue, location/location, local_scene_digest/location.
- **Output:** `{schema_version: "1", entities: [...]}` in input order. Each entity repeats the identity and adds `status` (`resolved`|`not_found`|`provider_unavailable`|`provider_error`|`malformed_response`), `name`, `url` and `resolved`. `name` and `url` are empty unless the entity resolved.
- **Permission:** `is_user_logged_in`.
- **Exposure:** `show_in_rest: true`. Called by the user-settings block (`src/blocks/user-settings/view.tsx`) through core abilities. No extrachill-api route or CLI command found.

### `extrachill/community-taxonomy-counts`

- **Purpose:** Returns the topic archive URL and published topic count for a taxonomy term, or `null` if the taxonomy or term doesn't exist, no topics use the term, or the term link fails. The source marks it as kept for existing consumers: cross-site linking now uses the generic `extrachill/taxonomy-post-counts`. Defined in `inc/abilities/community-taxonomy-counts.php`.
- **Input:** `taxonomy` (string, required), `slug` (string, required).
- **Output:** `{slug, name, count, url}` or `null`.
- **Permission:** `__return_true`.
- **Exposure:** `show_in_rest: true`. No caller found in the repository.
