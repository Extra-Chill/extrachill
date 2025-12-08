# Team Member Management

Manage team member status synchronization across the multisite network. Control automatic sync and manual overrides for team member assignment.

## Endpoints

### Sync Team Members

**Endpoint**: `POST /wp-json/extrachill/v1/admin/team-members/sync`

**Purpose**: Synchronize team member status for all network users based on main site account presence.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**: None

**Response** (HTTP 200):
```json
{
  "total_users": 45,
  "users_updated": 12,
  "users_skipped_override": 3,
  "users_with_main_site_account": 15
}
```

**Response Fields**:
- `total_users` - Total number of network users processed
- `users_updated` - Number of users whose status changed during this sync
- `users_skipped_override` - Users with manual overrides who were skipped
- `users_with_main_site_account` - Total users with accounts on the main site

**Error Responses**:
- `403` - Permission denied (not a network administrator)
- `500` - Required function `ec_has_main_site_account()` not available

**Implementation Details**:
- Automatically detects users with main site accounts via `ec_has_main_site_account()`
- Respects manual override status - won't override user-set preferences
- Updates `extrachill_team` meta to 1 (team member) or 0 (not team member)
- Skips users with manual `extrachill_team_manual_override` meta set

**File**: `inc/routes/admin/team-members.php`

---

### Manage Team Member Status

**Endpoint**: `PUT /wp-json/extrachill/v1/admin/team-members/{user_id}`

**Purpose**: Manually manage team member status for a specific user.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**:
- `user_id` (integer, required) - The user ID to manage
- `action` (string, required) - One of: `force_add`, `force_remove`, `reset_auto`

**Request Example**:
```json
{
  "action": "force_add"
}
```

**Response** (HTTP 200):
```json
{
  "message": "User forced to team member.",
  "user_id": 123,
  "is_team_member": true,
  "source": "Manual: Add"
}
```

**Action Options**:

| Action | Effect | Source |
|--------|--------|--------|
| `force_add` | User set as team member with manual override | Manual: Add |
| `force_remove` | User set as non-team member with manual override | Manual: Remove |
| `reset_auto` | User status determined by automatic sync logic | Auto |

**Error Responses**:
- `400` - Missing user_id or invalid action
- `404` - User not found
- `403` - Permission denied (not a network administrator)

**Implementation Details**:
- `force_add`: Sets both `extrachill_team_manual_override` and `extrachill_team` to 1
- `force_remove`: Sets override to 'remove' and team status to 0
- `reset_auto`: Clears manual override and re-runs automatic detection
- Manual overrides prevent changes during sync operations

**File**: `inc/routes/admin/team-members.php`

---

## Usage Notes

**Team Member Status**:
- Team members are typically site editors and contributors with main site accounts
- Status affects access to admin features and dashboard panels
- Stored as WordPress user meta under `extrachill_team` (0 or 1)

**Manual Overrides**:
- Set via `extrachill_team_manual_override` user meta
- Values: 'add' (force team member), 'remove' (force non-team member), or empty (auto)
- Prevents automatic sync from changing user status

**Integration Pattern**:
```php
// Check if user is team member
$is_team_member = (bool) get_user_meta( $user_id, 'extrachill_team', true );

// Check if override is set
$override = get_user_meta( $user_id, 'extrachill_team_manual_override', true );
```

**Related Endpoints**:
- [User Management](../users/users.md) - Get user profile with team status
