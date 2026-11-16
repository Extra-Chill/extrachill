# Generators and image voting

Paths are relative to `/wp-json/extrachill/v1`. All four routes use `permission_callback: __return_true` (no login requirement). Owner: `extrachill-content-blocks`. Sources: corresponding files under `inc/routes/content-blocks/`. Ability results are returned unchanged except where noted; ability errors propagate.

## POST /content-blocks/band-name

**Source:** `band-name.php`. **Parameters:** required `input` string; optional `genre` string, `number_of_words` integer (`absint`), `first_the` boolean, `and_the` boolean. Strings are text-sanitized. The handler forwards every key, including null optional values.

**Ability:** `extrachill/generate-band-name`. **Response:** generated-name result from the owner. Missing ability: `ability_not_found` (500).

## POST /content-blocks/rapper-name

**Source:** `rapper-name.php`. **Parameters:** required `input` string; optional `gender`, `style` strings, `number_of_words` integer (`absint`). Strings are text-sanitized. Null optional values are forwarded.

**Ability:** `extrachill/generate-rapper-name`. **Response:** generated-name result from the owner. Missing ability: `ability_not_found` (500).

## GET /content-blocks/image-voting/vote-count/(?P<post_id>\d+)/(?P<instance_id>[a-zA-Z0-9\-]+)

**Source:** `image-voting.php`. **Parameters:** path `post_id` integer (`absint`), `instance_id` string (letters, numbers and hyphens, text-sanitized).

**Ability:** `extrachill/image-voting-list`. **Response:** `{vote_count}` from the owner, unchanged. `post_not_found` is explicitly mapped to 404; missing ability returns `ability_not_found` (500).

## POST /content-blocks/image-voting/vote

**Source:** `image-voting-vote.php`. **Parameters:** required positive integer `post_id`, `instance_id` string, `email_address` string (email format, maximum 254 characters, `sanitize_email`).

**Delegation:** directly calls `extrachill_content_blocks_process_image_vote`; this callback does **not** execute a WordPress ability.

**Response:** `{message, vote_count}`. A failed vote returns the owner's code (or `vote_failed`) and message with HTTP 400. Missing function returns `function_missing` (500).
