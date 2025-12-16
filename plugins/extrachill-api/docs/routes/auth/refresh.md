# Auth Refresh

Refresh an expired access token using a valid refresh token. Maintains user session without requiring full re-authentication.

## Endpoints

### Refresh Access Token

**Endpoint**: `POST /wp-json/extrachill/v1/auth/refresh`

**Purpose**: Generate new access token using a valid refresh token.

**Permission**: Public (no authentication required)

**Parameters**:
- `refresh_token` (string, required) - Valid refresh token from previous login
- `device_id` (string, required) - UUID v4 device identifier used during login
- `remember` (boolean, optional) - Whether to extend token expiry (default: false)
- `set_cookie` (boolean, optional) - Whether to set WordPress authentication cookie (default: false)

**Request Example**:
```json
{
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "device_id": "550e8400-e29b-41d4-a716-446655440000",
  "remember": true,
  "set_cookie": false
}
```

**Response** (HTTP 200):
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "id": 123,
    "username": "user",
    "email": "user@example.com",
    "display_name": "User Name"
  }
}
```

**Error Responses**:
- `400` - Missing refresh_token, invalid device_id format, or validation errors
- `401` - Invalid or expired refresh token
- `403` - Device mismatch or token revoked
- `500` - Token refresh service unavailable

**Implementation Details**:
- Validates device_id as UUID v4 format
- Calls `extrachill_users_refresh_tokens()` from extrachill-users plugin
- Maintains same user session and permissions
- Can extend token expiry with `remember` parameter
- Returns new access and refresh tokens

**File**: `inc/routes/auth/refresh.php`

---

## Usage Examples

### JavaScript Token Refresh

```javascript
async function refreshToken() {
  const refreshToken = localStorage.getItem('refresh_token');
  const deviceId = localStorage.getItem('device_id');

  if (!refreshToken || !deviceId) {
    throw new Error('No refresh token available');
  }

  const response = await fetch('/wp-json/extrachill/v1/auth/refresh', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      refresh_token: refreshToken,
      device_id: deviceId,
      remember: false
    })
  });

  const data = await response.json();

  if (response.ok) {
    // Update stored tokens
    localStorage.setItem('access_token', data.access_token);
    localStorage.setItem('refresh_token', data.refresh_token);
    return data.user;
  } else {
    // Refresh failed - redirect to login
    localStorage.removeItem('access_token');
    localStorage.removeItem('refresh_token');
    throw new Error('Session expired');
  }
}
```

### Automatic Token Refresh

```javascript
// Intercept API calls and refresh token on 401
async function apiRequest(url, options = {}) {
  const response = await fetch(url, {
    ...options,
    headers: {
      ...options.headers,
      'Authorization': `Bearer ${localStorage.getItem('access_token')}`
    }
  });

  if (response.status === 401) {
    // Token expired - try refresh
    try {
      await refreshToken();
      // Retry original request with new token
      return apiRequest(url, options);
    } catch (error) {
      // Refresh failed - redirect to login
      window.location.href = '/login';
    }
  }

  return response;
}
```

---

## Usage Notes

**Token Lifecycle**:
- Access tokens have short expiry (typically 1 hour)
- Refresh tokens have longer expiry (days to weeks)
- Use refresh endpoint when access token expires
- Store refresh tokens securely

**Device Validation**:
- `device_id` must match the device used during login
- Prevents token theft and unauthorized access
- Allows per-device token management

**Security**:
- Refresh tokens should be treated as sensitive
- Store in httpOnly cookies when possible
- Implement automatic cleanup of expired tokens

**Error Handling**:
- 401 errors indicate expired refresh token
- 403 errors indicate device mismatch or revocation
- Handle gracefully by redirecting to login

**Related Endpoints**:
- [Auth Login](login.md) - Initial user authentication
- [Auth Register](register.md) - Create new user accounts</content>
<parameter name="filePath">docs/routes/auth/refresh.md