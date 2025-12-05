# Artist Roster Invitation Endpoint

## Route
`POST /wp-json/extrachill/v1/artist/roster/invite`

## Purpose
Lets authenticated artist managers invite collaborators to their artist roster directly from the link-page dashboard.

## Authentication
- Requires a logged-in WordPress user (`permission_callback` uses `is_user_logged_in`).
- Additional capability gate comes from `ec_can_manage_artist( current_user_id, artist_id )` inside the handler; the invite fails with `403` when the user lacks control of the artist profile.

## Request Body
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `artist_id` | integer | Yes | Must reference an `artist_profile` post. |
| `email` | string | Yes | Sanitized via `sanitize_email`. Must pass `is_email`. |

## Processing Flow
1. Validates email format; returns `400` with `invalid_email` if it fails.
2. Confirms the post exists and has type `artist_profile`.
3. Uses `ec_can_manage_artist()` to ensure the current user is a manager.
4. Calls `apply_filters( 'extrachill_artist_invite_member', null, $artist_id, $email )` so downstream plugins handle invite creation (e.g., storing invitations, sending emails).
5. Responds with:
```
{
  "success": true,
  "message": "Invitation successfully sent.",
  "invitation": { ...filter response... }
}
```

## Failure Modes
| Code | Status | When |
| --- | --- | --- |
| `invalid_email` | 400 | Email missing or malformed. |
| `invalid_artist` | 400 | Post ID not an artist profile. |
| `permission_denied` | 403 | User is not allowed to manage the artist. |
| `invitation_failed` | 500 | Filter handler returned unexpected data. |

## Integration Notes
- Implementers must hook into `extrachill_artist_invite_member` to actually create the invite record and optionally email the collaborator.
- Hook handlers should return a structured array containing at least an `id` key so the REST response can surface invite metadata.
