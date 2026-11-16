# Community editors

Paths are relative to `/wp-json/extrachill/v1`. Source: `inc/routes/community/topic-editor.php` and `reply-editor.php`. All methods require login through the matching `extrachill_api_community_{topic,reply}_editor_{read,write}_permission` callback (401 `rest_forbidden` otherwise). Login is only the transport gate: the Community abilities own edit authorization, sanitization and persistence.

`WP_REST_Server::EDITABLE` means **POST, PUT and PATCH**, not only POST. Numeric path `id` becomes `topic_id` or `reply_id`; optional `blog_id` is an integer sanitized with `absint` and cast to 0 when omitted. Results are returned unchanged with `rest_ensure_response`; missing abilities return `ability_missing` (503), and ability errors propagate.

## GET /community/topics/(?P<id>[\d]+)/editor

**Parameters:** path `id`, optional `blog_id`.

**Ability:** `extrachill/community-get-topic-for-editor` (`extrachill-community`). **Response:** `{id, type: "forum_topic", title, content, raw, status, forum_id, permalink, updated_at, context: {blog_id, forum_id}, permissions}`. Content/raw are stored block markup, not the public topic representation.

## POST, PUT, PATCH /community/topics/(?P<id>[\d]+)/editor

**Parameters:** required `content` string; optional `title` string, `format` (`html` [default] or `markdown`), `blog_id`. Only a supplied title is forwarded. Content and title are unslashed. `user_id` is the authenticated user.

**Ability:** `extrachill/community-update-topic`. **Response:** owner-defined update result, unchanged.

## GET /community/replies/(?P<id>[\d]+)/editor

**Parameters:** path `id`, optional `blog_id`.

**Ability:** `extrachill/community-get-reply-for-editor`. **Response:** `{id, type: "forum_reply", content, raw, status, topic_id, forum_id, reply_to, permalink, updated_at, context: {blog_id, topic_id, forum_id}, permissions}`. Content/raw are stored block markup.

## POST, PUT, PATCH /community/replies/(?P<id>[\d]+)/editor

**Parameters:** required `content` string; optional `format` (`html` [default] or `markdown`), `blog_id`. Content is unslashed; `user_id` is server-resolved. This route does not accept a title field.

**Ability:** `extrachill/community-update-reply`. **Response:** owner-defined update result, unchanged.
