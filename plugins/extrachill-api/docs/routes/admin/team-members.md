# Team Member Management

Manage team membership across the multisite network. Team membership is stored as the `extra_chill_team` WordPress role, registered on every site and assigned/removed directly via this API.

## Endpoints

### List Team Members

**Endpoint**: `GET /wp-json/extrachill/v1/admin/team-members`

**Purpose**: Retrieve a paginated list of network users with their team membership status.

**Permission**: Requires `manage_network_options` capability (network administrators only)

**Parameters**:
- `search` (string, optional) - Search term for username, email, or display name
- `page` (integer, optional) - Page number (default: 1)

**Response** (HTTP 200):
```json
{
  "users": [
    {
      "ID": 123,
      "user_login": "username",
      "user_email": "user@example.com",
      "is_team_member": true
    }
  ],
  "total": 45,
  "total_pages": 3
}
```

**Implementation Details**:
- `is_team_member` is true iff the user holds the `extra_chill_team` role on the main blog (extrachill.com). The role is synced network-wide, so the main-blog check is the canonical answer.

**File**: `inc/routes/admin/team-members.php`

---

### Sync Team Members

**Endpoint**: `POST /wp-json/extrachill/v1/admin/team-members/sync`

**Purpose**: Re-grant the team role on every subsite for every user who already has it on the main blog. Useful after adding a new subsite to the network, or after manual role changes that should be propagated.

**Permission**: Requires `manage_network_options` capability

**Parameters**: None

**Response** (HTTP 200):
```json
{
  "total_team_users": 47,
  "sites_processed": 9
}
```

**Implementation Details**:
- Wraps the `extrachill/sync-team-members` ability registered by `extrachill-admin-tools`. Backed by `ec_users_grant_team_role()` from `extrachill-users`.

**File**: `inc/routes/admin/team-members.php`

---

### Manage Team Member Status

**Endpoint**: `PUT /wp-json/extrachill/v1/admin/team-members/{user_id}`

**Purpose**: Grant or revoke the team role for a specific user, network-wide.

**Permission**: Requires `manage_network_options` capability

**Parameters**:
- `user_id` (integer, required) - The user ID to manage
- `action` (string, required) - One of: `force_add`, `force_remove`

**Request Example**:
```json
{
  "action": "force_add"
}
```

**Response** (HTTP 200):
```json
{
  "message": "Team role granted on 9 site(s).",
  "user_id": 123,
  "is_team_member": true
}
```

**Action Options**:

| Action | Effect |
|--------|--------|
| `force_add` | Grants the `extra_chill_team` role on every site in the network |
| `force_remove` | Removes the `extra_chill_team` role from every site in the network |

**Error Responses**:
- `400` - Missing user_id or invalid action
- `404` - User not found
- `403` - Permission denied

**Implementation Details**:
- Wraps the `extrachill/manage-team-member` ability registered by `extrachill-admin-tools`. Backed by `ec_users_grant_team_role()` / `ec_users_revoke_team_role()` from `extrachill-users`.
- The previous `reset_auto` action is gone — there is no auto-derivation step anymore. The role IS the state.

**File**: `inc/routes/admin/team-members.php`

---

## Architecture Notes

**Source of truth**: The `extra_chill_team` WordPress role on every site in the network. Role assignments are written directly by the management endpoints above. There is no auxiliary `user_meta` layer.

**Capabilities granted by the role**:
- Standard WP: `read`, `upload_files`, `edit_posts`, `edit_published_posts`, `edit_others_posts`, `delete_posts`
- Custom EC: `access_studio`, `access_roadie`, `access_transcribe`, `access_events_admin`, `access_admin_bar`, `submit_for_review`

**Integration pattern**:
```php
// Check if user is a team member (via shim — recommended for forward-compat)
$is_team = function_exists( 'ec_is_team_member' ) && ec_is_team_member( $user_id );

// Or check directly via capability
$is_team = user_can( $user_id, 'access_studio' );

// Or check role membership directly
$user    = new WP_User( $user_id );
$is_team = in_array( 'extra_chill_team', (array) $user->roles, true );
```

**Related Endpoints**:
- [User Management](../users/users.md) - Get user profile with team status
