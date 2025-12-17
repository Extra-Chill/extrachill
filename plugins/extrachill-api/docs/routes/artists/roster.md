# Artist Roster Management

REST API endpoints for managing artist roster members and pending invitations.

## Endpoints

### List Roster Members and Invites

**Endpoint**: `GET /wp-json/extrachill/v1/artists/{id}/roster`

**Purpose**: Retrieve current roster members and pending invitations for an artist profile.

**Parameters**:
- `id` (integer, required) - Artist profile post ID (in URL path)

**Response** (HTTP 200):
```json
{
  "members": [
    {
      "id": 123,
      "display_name": "John Doe",
      "username": "johndoe",
      "email": "john@example.com",
      "avatar_url": "https://example.com/wp-content/uploads/avatar.jpg",
      "profile_url": "https://example.com/artists/johndoe"
    }
  ],
  "invites": [
    {
      "id": "abc123def456",
      "email": "jane@example.com",
      "of_existing_user": false,
      "status": "pending",
      "invited_on": 1704067200,
      "invited_on_formatted": "January 1, 2025"
    }
  ]
}
```

**Permission**: User must have permission to manage the artist (`ec_can_manage_artist()`)

**File**: `inc/routes/artists/roster.php`

### Invite Roster Member

**Endpoint**: `POST /wp-json/extrachill/v1/artists/{id}/roster`

**Purpose**: Send an invitation to join the artist roster.

**Parameters**:
- `id` (integer, required) - Artist profile post ID (in URL path)
- `email` (string, required) - Email address of person to invite

**Response** (HTTP 200):
```json
{
  "message": "Invitation successfully sent.",
  "invitation": {
    "id": "abc123def456",
    "email": "jane@example.com",
    "status": "pending"
  }
}
```

**Permission**: User must have permission to manage the artist

**File**: `inc/routes/artists/roster.php`

### Remove Roster Member

**Endpoint**: `DELETE /wp-json/extrachill/v1/artists/{id}/roster/{user_id}`

**Purpose**: Remove a user from the artist roster.

**Parameters**:
- `id` (integer, required) - Artist profile post ID (in URL path)
- `user_id` (integer, required) - User ID to remove from roster (in URL path)

**Response** (HTTP 200):
```json
{
  "removed": true,
  "user_id": 123,
  "artist_id": 456
}
```

**Permission**: User must have permission to manage the artist

**File**: `inc/routes/artists/roster.php`

### Cancel Pending Invitation

**Endpoint**: `DELETE /wp-json/extrachill/v1/artists/{id}/roster/invites/{invite_id}`

**Purpose**: Cancel a pending roster invitation.

**Parameters**:
- `id` (integer, required) - Artist profile post ID (in URL path)
- `invite_id` (string, required) - Invitation ID to cancel (in URL path)

**Response** (HTTP 200):
```json
{
  "cancelled": true,
  "invite_id": "abc123def456",
  "artist_id": 456
}
```

**Permission**: User must have permission to manage the artist

**File**: `inc/routes/artists/roster.php`

## Error Responses

**Invalid Artist** (HTTP 400):
```json
{
  "code": "invalid_artist",
  "message": "Invalid artist specified.",
  "data": { "status": 400 }
}
```

**Permission Denied** (HTTP 403):
```json
{
  "code": "permission_denied",
  "message": "You do not have permission to manage members for this artist.",
  "data": { "status": 403 }
}
```

**Invalid Email** (HTTP 400):
```json
{
  "code": "invalid_email",
  "message": "Please enter a valid email address.",
  "data": { "status": 400 }
}
```

## Dependencies

- BuddyPress integration for roster management
- `ec_can_manage_artist()` function for permission checks
- `bp_get_linked_members()` and `bp_get_pending_invitations()` for data retrieval
- `extrachill_artist_invite_member` filter for invitation handling

## Integration

Used by artist management interfaces to allow collaborative management of artist profiles by multiple team members.
