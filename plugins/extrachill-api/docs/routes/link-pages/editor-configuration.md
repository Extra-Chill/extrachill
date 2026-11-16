# Link Page editor configuration

## GET /wp-json/extrachill/v1/link-pages/editor-configuration

**Source:** `inc/routes/link-pages/editor-configuration.php`.

**Permission callback:** `is_user_logged_in`; owner ability applies Link Page access checks. Used by the extrachill.link edit shell with WordPress-native bearer authentication.

**Parameters:** optional `link_page_id` integer, default 0, sanitized with `absint` and cast to integer.

**Owner/ability:** `extrachill-link-pages`, `extrachill/get-link-page-editor-configuration`.

**Response:** owner-defined configuration object (owner adapter, identities, fonts and adapter scripts), returned unchanged (200).

Missing ability or ability API returns `ability_not_found` (500). Ability errors propagate, with status 400 added when upstream error data has no status.
