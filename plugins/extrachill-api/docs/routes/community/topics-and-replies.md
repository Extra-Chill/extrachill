# Topics and replies

Paths are relative to `/wp-json/extrachill/v1`. Source: `inc/routes/community/topics.php` and `replies.php`. Routes execute on Community through affinity. All abilities below belong to `extrachill-community`; results are returned directly (200), and ability errors propagate. Missing abilities return `ability_missing` (503).

## GET /community/topics

**Permission:** `__return_true` (public).

**Parameters:** optional `forum_id` integer, `per_page` integer (20), `page` integer (1), `orderby` (`date` [default], `modified`, `title`), `order` (`DESC` [default], `ASC`). The handler casts all fields, including an omitted forum ID to 0; it does not expose the ability's `author_id` filter.

**Ability:** `extrachill/community-list-topics`.

**Response:** `{topics: [...], total, pages, page, per_page}`. Topic records contain `topic_id`, `title`, `forum_id`, author ID/name, status, dates, reply/voice counts and URL, with public-voice/upvote data where supplied by the owner. Ability validation, not route declarations, supplies pagination bounds.

## POST /community/topics

**Permission:** `extrachill_api_community_topics_write_permission` requires login (401 `rest_forbidden` otherwise); domain posting checks remain in the ability.

**Parameters:** required `forum_id` integer (`absint`), `title` sanitized string, `content` string. Content is unslashed; `user_id` is always the current user.

**Ability:** `extrachill/community-create-topic`. **Response:** the ability's created-topic result, not a core WordPress post envelope.

## GET /community/topics/(?P<id>[\d]+)

**Permission:** `__return_true`.

**Parameters:** numeric path `id` mapped to `topic_id`; optional `include_replies` boolean (true), `replies_per_page` integer (30), `replies_page` integer (1).

**Ability:** `extrachill/community-get-topic`. **Response:** object containing `topic` and reply data (`replies`, `replies_total`, pagination fields as supplied by the ability). The API does not bypass ability-level visibility checks.

## GET /community/replies

**Permission:** `__return_true`.

**Parameters:** required `topic_id` integer (`absint`); optional `per_page` integer (30), `page` integer (1).

**Ability:** `extrachill/community-list-replies`. **Response:** paginated reply object from the owner. Reply records contain `reply_id`, `topic_id`, `forum_id`, author ID/name, HTML content, status, date, `reply_to` and URL, plus optional public-voice/upvote data.

## POST /community/replies

**Permission:** `extrachill_api_community_replies_write_permission` requires login (401 `rest_forbidden` otherwise).

**Parameters:** required `topic_id` integer (`absint`), `content` string; optional `reply_to` integer (0, `absint`). Content is unslashed; current `user_id` is supplied server-side.

**Ability:** `extrachill/community-create-reply`. **Response:** created-reply result from the owner, unchanged.
