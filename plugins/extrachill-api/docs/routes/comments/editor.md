# Comment editor

Source: `inc/routes/comments/editor.php`. Paths are relative to `/wp-json/extrachill/v1`. Both read and write permission callbacks (`extrachill_api_comments_editor_read_permission`, `extrachill_api_comments_editor_write_permission`) require login, returning `rest_forbidden` (401) otherwise. The owning editor abilities enforce actual edit authority.

## GET /comments/(?P<id>[\d]+)/editor

**Parameters:** numeric path `id` mapped to integer `comment_id`; optional integer `blog_id` (`absint`, cast to 0 when omitted).

**Ability:** `extrachill/comment-get-for-editor`. **Response:** `{id, type: "comment", content, raw, status, post_id, permalink, updated_at, context: {blog_id, post_id}, permissions, draft}` returned unchanged (200). Permissions include `canSave`, `canUploadMedia`, `canDelete`; draft can be null.

## POST, PUT, PATCH /comments/(?P<id>[\d]+)/editor

Registered with `WP_REST_Server::EDITABLE`, not POST alone.

**Parameters:** path `id`, required `content` string, optional integer `blog_id`. Content is unslashed; current `user_id` is supplied server-side.

**Ability:** `extrachill/comment-update`. **Response:** owner-defined comment update result, unchanged (200).

The actual owner is `extrachill-network` (`inc/Abilities/CommentEditorAbilities.php`), despite the stale API file header naming multisite. The API contains no comment mutation logic. Missing ability returns `ability_missing` (503); ability errors propagate.
