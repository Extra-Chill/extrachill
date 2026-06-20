# Artist Permissions Endpoint

## Route
`GET /wp-json/extrachill/v1/artists/{id}/permissions`

## Purpose
Tells extrachill.link whether the logged-in user can edit a specific artist profile, powering the client-side "Edit" button.

## Request Parameters
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | integer | Yes | Artist profile post ID (in URL path)

## Response
```json
{
  "can_edit": true,
  "manage_url": "https://artist.extrachill.com/manage-link-page/",
  "user_id": 45
}
```
- `can_edit` becomes `true` when `ec_can_manage_artist( current_user_id, artist_id )` returns true.
- `manage_url` points to the artist management screen for link pages (empty string when unauthorized).

## Authentication
- The extrachill.link edit button sends a **wp-native bearer token** in the
  `Authorization: Bearer <token>` header, resolved network-wide by wp-native's
  `determine_current_user` filter. It does **not** use cross-site cookies.
- The token is bootstrapped on the artist site (where the `.extrachill.com`
  cookie is first-party) via the mint handoff endpoint
  (`inc/auth/extrachill-link-token-handoff.php`) and returned to the client in a
  URL fragment. See the artist-platform edit-button JS for the client flow.

## CORS Behavior
- Handled by WordPress core's default REST CORS (`rest_send_cors_headers` +
  `rest_handle_options_request`), which echoes the request `Origin` into
  `Access-Control-Allow-Origin` and lists `Authorization` in
  `Access-Control-Allow-Headers`. The cross-origin GET from extrachill.link and
  its OPTIONS preflight succeed without route-level CORS headers.
- This route no longer requests credentialed (cookie) CORS — the bearer header
  carries identity, so `Access-Control-Allow-Credentials` is not needed.

## File
`inc/routes/artists/permissions.php`

## Usage Notes
- Permission logic is delegated to the shared helper `ec_can_manage_artist()`
  (defined in `extrachill-users`), which must be available in the network. It
  treats artist owners and roster editors identically.
