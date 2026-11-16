# Artist relationships

Paths are relative to `/wp-json/extrachill/v1`. Source: `inc/routes/admin/artist-relationships.php`. All routes use `extrachill_api_artist_relationships_permission_check`, requiring `manage_network_options`; otherwise `rest_forbidden` (403). Abilities belong to `extrachill-artist-platform`. Callbacks are shims, not the domain implementations. Results pass through unchanged (200), ability errors propagate, and missing abilities return `ability_not_found` (500).

## GET /admin/artist-relationships

**Parameters:** optional `view` (`artists` [default] or `users`), `search` string (empty).

**Ability:** `extrachill/admin-list-artist-relationships`. **Response:** relationship listing for the selected view, unchanged from the owner.

## POST /admin/artist-relationships/link

**Parameters:** required integer `user_id`, `artist_id`, converted with `absint` in the handler.

**Ability:** `extrachill/admin-link-artist-relationship`. **Response:** link operation result from the owner.

## POST /admin/artist-relationships/unlink

**Parameters:** required integer `user_id`, `artist_id`, converted with `absint`.

**Ability:** `extrachill/admin-unlink-artist-relationship`. **Response:** unlink operation result from the owner.

## GET /admin/artist-relationships/orphans

**Parameters:** none; empty ability input.

**Ability:** `extrachill/admin-list-orphan-artist-relationships`. **Response:** orphaned relationship listing from the owner.

## POST /admin/artist-relationships/cleanup

**Parameters:** required integer `user_id`, `artist_id`, converted with `absint`.

**Ability:** `extrachill/admin-cleanup-artist-relationships`. **Response:** cleanup result from the owner. Uses the canonical membership remover to keep both sides in sync; this is a targeted pair cleanup, not an unbounded network sweep.
