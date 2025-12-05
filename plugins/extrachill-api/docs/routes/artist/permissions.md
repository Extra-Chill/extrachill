# Artist Permissions Endpoint

## Route
`GET|POST /wp-json/extrachill/v1/artist/permissions`

## Purpose
Tells extrachill.link whether the logged-in user can edit a specific artist profile, powering the client-side "Edit" button.

## Request Parameters
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `artist_id` | integer | Yes | Artist profile post ID. Can be provided via GET or POST.

## Response
```
{
  "success": true,
  "data": {
    "can_edit": bool,
    "manage_url": "https://artist.extrachill.com/manage-link-page/?artist_id=123",
    "user_id": 45
  }
}
```
- `can_edit` becomes `true` when `ec_can_manage_artist( current_user_id, artist_id )` returns true.
- `manage_url` points to the artist management screen for link pages (empty string when unauthorized).

## CORS Behavior
- If the request originates from `https://extrachill.link`, the endpoint injects:
  - `Access-Control-Allow-Origin: https://extrachill.link`
  - `Access-Control-Allow-Credentials: true`
so frontend fetches can include cookies.

## Usage Notes
- Authentication relies on WordPress cookies; ensure the cross-domain cookie helper is active.
- Permission logic is delegated to the shared helper `ec_can_manage_artist()` which must be available in the network.
