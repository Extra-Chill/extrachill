# Newsletter management

Paths are relative to `/wp-json/extrachill/v1`. Source: `inc/routes/newsletter/management.php`. Every endpoint's permission closure requires `current_user_can('manage_options')`; these are not public subscription routes. Owning abilities belong to `extrachill-newsletter`. Results pass through unchanged (200); missing abilities return `ability_not_available` (500). Ability errors propagate (the settings GET passes its result directly to `rest_ensure_response`).

## GET /newsletter/campaigns

**Parameters:** optional `per_page` integer (20), `offset` integer (0), `status` string. Integers use `absint`, status uses `sanitize_text_field`.

**Ability:** `extrachill/list-campaigns`. **Response:** campaign listing result from the owner, unchanged.

## GET /newsletter/campaigns/(?P<id>\d+)

**Parameters:** required integer path `id` (`absint`), mapped to `campaign_id`.

**Ability:** `extrachill/get-campaign`. **Response:** campaign detail object from the owner.

## DELETE /newsletter/campaigns/(?P<id>\d+)

**Parameters:** same path ID mapping as GET.

**Ability:** `extrachill/delete-campaign`. **Response:** deletion result from the owner (not an empty HTTP 204 response).

## GET /newsletter/settings

**Parameters:** none. **Ability:** `extrachill/get-newsletter-settings` with empty input.

**Response:** newsletter settings object from the owner.

## POST /newsletter/settings

**Parameters:** optional `from_name`, `from_email`, `reply_to`, `brand_id` strings and `list_ids` object. Email fields use `sanitize_email`; other strings use text sanitization. Only non-null fields are forwarded; `list_ids` is forwarded only when represented as an array in PHP.

**Ability:** `extrachill/update-newsletter-settings`. **Response:** settings update result from the owner.

## POST /newsletter/sync

**Parameters:** required `integration` sanitized string; optional `emails` array, `since` sanitized string, `dry_run` boolean (false).

**Ability:** `extrachill/sync-subscribers`. The adapter renames `integration` to `context`; optional values are forwarded when non-null.

**Response:** synchronization result from the owner, including dry-run behavior as determined by that ability.
