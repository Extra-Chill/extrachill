# Resolve Instagram media

## POST /wp-json/extrachill/v1/instagram/resolve

**Source:** `inc/routes/giveaway/giveaway.php`.

**Permission:** closure requiring `current_user_can('edit_posts')`.

**Parameters:** required `input` string (Instagram post URL, shortcode or numeric media ID), sanitized with `sanitize_text_field`.

**Owner/ability:** Studio ability `extrachill/resolve-instagram-media`, executed with `{input}`.

**Response:** `{platform, media_id}` from the owner, unchanged (200). Missing ability returns `ability_missing` (500); ability errors propagate. This performs resolution, not a giveaway or a scheduled task.
