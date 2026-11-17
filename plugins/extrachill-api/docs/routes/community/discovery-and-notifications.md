# Community discovery and notifications

Paths are relative to `/wp-json/extrachill/v1`; source files are under `inc/routes/community/`. Affinity selects Community. Results are returned with `rest_ensure_response`; ability errors propagate.

## GET /community/forums

**Source:** `forums.php`. **Permission:** `__return_true`.

**Parameters:** optional `archive_only` boolean (false); forwarded as the ability's `archive_only` input only when true. `homepage_only` is accepted as a deprecated alias for `archive_only`.

**Owner:** Community ability `extrachill/community-list-forums`. **Response:** `{forums: [{forum_id, title, parent_id, status, topic_count, reply_count, show_in_archive, url}]}`, filtered by owner visibility checks. Missing ability: `ability_missing` (503).

## GET /community/stats

**Source:** `stats.php`. **Permission:** `__return_true`. **Parameters:** none.

**Owner:** Community ability `extrachill/community-get-stats`, executed with an empty input array. **Response:** `{forums, topics, replies, active_users, total_upvotes}` (integer counts), unchanged. Missing ability: `ability_unavailable` (503).

## GET /community/taxonomy-counts

**Source:** `taxonomy-counts.php`. **Permission:** `__return_true`.

**Parameters:** required `taxonomy` string; optional `slug` string (sanitized as title), `limit` integer (default 8, range 1–50).

**Owner:** bulk queries delegate to network ability `extrachill/taxonomy-post-counts` with `site: community`, `post_type: topic`. Bulk **response** is the terms array sliced to `limit`, not the ability's envelope. Results cache by taxonomy for six hours; absent ability returns `[]`.

With `slug`, the callback does **not** invoke an ability. It finds a published forum tagged with that term and counts its published child topics. **Response:** `{slug, name, count, url}` where URL is the forum permalink, or null for nonexistent taxonomy/term/forum or zero topics. These are not ordinary taxonomy archive URLs.

## GET /community/notifications

**Source:** `notifications.php`. **Permission:** `extrachill_api_community_notifications_permission` (login required; 401 `rest_forbidden`).

**Parameters:** optional `unread` boolean (false), `limit` integer (50), `page` integer (1).

**Owner:** `extrachill/get-notifications`; current `user_id` is server-resolved. `limit` maps to ability `per_page` only when positive; positive page and true unread are forwarded.

**Response:** notification listing/pagination result from the owner, unchanged. Missing ability: `ability_missing` (503).

## POST /community/notifications/mark-read

**Source/permission:** same as notification listing. **Parameters:** none.

**Owner:** `extrachill/mark-notifications-read` with current `user_id` and no notification ID: marks **all** unread notifications, not one selected notification.

**Response:** ability result unchanged. Missing ability: `ability_missing` (503).
