# Auth Logout

Revoke user session tokens and end authentication for a specific device.

## Endpoints

### User Logout

**Endpoint**: `POST /wp-json/extrachill/v1/auth/logout`

**Purpose**: Revoke refresh token for a device to end session without logging out on other devices.

**Permission**: Requires logged-in user

**Parameters**:
- `device_id` (string, required) - UUID v4 device identifier to revoke

**Request Example**:
```json
{
  "device_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Response** (HTTP 200):
```json
{
  "success": true,
  "message": "Logged out successfully."
}
```

**Response** (if no active session found) (HTTP 200):
```json
{
  "success": false,
  "message": "No active session found for this device."
}
```

**Error Responses**:
- `400` - Invalid device_id format (not a valid UUID v4)
- `401` - User not logged in
- `500` - Dependency or token revocation service unavailable

**Implementation Details**:
- Validates device_id as UUID v4 format
- Calls `extrachill_users_revoke_refresh_token()` from extrachill-users plugin
- Revokes only the specified device's token, leaving other sessions active
- Device tracking allows multi-device logout without affecting other logins

**File**: `inc/routes/auth/logout.php`

---

## Usage Examples

### JavaScript Logout

```javascript
async function logoutUser(deviceId) {
  const response = await fetch('/wp-json/extrachill/v1/auth/logout', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + localStorage.getItem('access_token')
    },
    body: JSON.stringify({
      device_id: deviceId
    })
  });

  const data = await response.json();

  if (response.ok && data.success) {
    localStorage.removeItem('access_token');
    localStorage.removeItem('refresh_token');
    window.location.href = '/login';
  } else {
    console.error('Logout failed:', data.message);
  }
}
```

### Mobile App Logout with All Devices

```javascript
// Logout from all devices (requires multiple API calls)
async function logoutFromAllDevices(devices) {
  for (const device of devices) {
    await fetch(API_URL + '/auth/logout', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + accessToken
      },
      body: JSON.stringify({
        device_id: device.id
      })
    });
  }
  
  // Clear local storage
  await AsyncStorage.removeItem('access_token');
  await AsyncStorage.removeItem('refresh_token');
}
```

---

## Usage Notes

**Single Device Logout**:
- Revokes only the specified device's token
- Other logged-in devices remain active
- Useful for security when device is compromised or shared

**Multi-Device Logout**:
- Call endpoint for each device to logout completely
- Device IDs obtained from user's session management UI

**Token Revocation**:
- Refresh token becomes immediately invalid
- Access token remains valid until expiration (but cannot be refreshed)
- User must provide new credentials to re-authenticate

**Device Tracking**:
- Requires valid UUID v4 `device_id` parameter
- Device ID should be stored securely on client
- Each client maintains its own device ID

**Related Endpoints**:
- [Auth Login](login.md) - Login with username/password
- [Auth Google](google.md) - Login with Google OAuth
- [Auth Refresh](refresh.md) - Refresh access tokens
- [Auth Me](me.md) - Get current authenticated user
