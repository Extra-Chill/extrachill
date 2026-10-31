# Auth Register

Create a new user account with token-based authentication. Supports artist profile creation, professional status, and invitation-based registration.

## Endpoints

### User Registration

**Endpoint**: `POST /wp-json/extrachill/v1/auth/register`

**Purpose**: Register a new user account with optional artist profile creation and token generation.

**Permission**: Public (no authentication required)

**Parameters**:
- `email` (string, required) - Valid email address (validated for uniqueness)
- `password` (string, required) - User password (minimum requirements enforced)
- `password_confirm` (string, required) - Password confirmation (must match password)
- `turnstile_response` (string, required) - Cloudflare Turnstile verification token
- `device_id` (string, required) - UUID v4 device identifier for session tracking
- `device_name` (string, optional) - Human-readable device name
- `set_cookie` (boolean, optional) - Whether to set WordPress authentication cookie
- `remember` (boolean, optional) - Whether to extend token expiry
- `registration_page` (string, optional) - URL of the registration page
- `registration_source` (string, optional) - Source label (e.g. `web`, `extrachill-app`)
- `registration_method` (string, optional) - Method label (e.g. `standard`, `google`)
- `success_redirect_url` (string, optional) - URL to redirect after successful registration
- `invite_token` (string, optional) - Invitation token for roster invites
- `invite_artist_id` (integer, optional) - Artist ID for roster membership
- `user_is_artist` (boolean, optional) - Whether user should have artist status
- `user_is_professional` (boolean, optional) - Whether user should have professional status
- `from_join` (string, optional) - Source/context of registration

**Request Example**:
```json
{
  "email": "newuser@example.com",
  "password": "SecurePass123!",
  "password_confirm": "SecurePass123!",
  "turnstile_response": "0.abc123...",
  "device_id": "550e8400-e29b-41d4-a716-446655440000",
  "device_name": "Chrome on MacBook Pro",
  "user_is_artist": true,
  "invite_token": "abc123def456"
}
```

**Response** (HTTP 200):
```json
{
  "access_token": "...",
  "access_expires_at": "2025-01-15T10:30:00Z",
  "refresh_token": "...",
  "refresh_expires_at": "2025-02-15T10:30:00Z",
  "user": {
    "id": 123,
    "username": "user12345",
    "email": "newuser@example.com",
    "display_name": "New User"
  },
  "artist_created": true,
  "roster_invitation_accepted": true
}
```

**Error Responses**:
- `400` - Validation errors, password mismatch, invalid email, duplicate username/email, invalid device_id
- `409` - Username or email already exists
- `422` - Turnstile verification failed
- `500` - Registration service unavailable

**Implementation Details**:
- Validates email format and uniqueness
- Enforces password strength requirements
- Validates device_id as UUID v4 format
- Verifies Cloudflare Turnstile token
- Calls `extrachill_users_register_with_tokens()` from extrachill-users plugin
- Supports optional artist profile creation
- Handles roster invitations if invite_token provided

**File**: `inc/routes/auth/register.php`

---

## Usage Examples

### Basic Registration

```javascript
async function registerUser(userData) {
  const response = await fetch('/wp-json/extrachill/v1/auth/register', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      username: userData.username,
      email: userData.email,
      password: userData.password,
      password_confirm: userData.passwordConfirm,
      turnstile_response: userData.turnstileToken,
      device_id: generateUUID(),
      device_name: navigator.userAgent.substring(0, 50),
      user_is_artist: false
    })
  });

  const data = await response.json();

  if (response.ok) {
    // Store tokens and redirect
    localStorage.setItem('access_token', data.access_token);
    localStorage.setItem('refresh_token', data.refresh_token);
    return data.user;
  } else {
    throw new Error(data.message || 'Registration failed');
  }
}
```

### Artist Registration with Invitation

```javascript
async function registerArtistWithInvite(userData, inviteToken, artistId) {
  const response = await fetch('/wp-json/extrachill/v1/auth/register', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      username: userData.username,
      email: userData.email,
      password: userData.password,
      password_confirm: userData.passwordConfirm,
      turnstile_response: userData.turnstileToken,
      device_id: generateUUID(),
      user_is_artist: true,
      invite_token: inviteToken,
      invite_artist_id: artistId
    })
  });

  const data = await response.json();

  if (response.ok && data.roster_invitation_accepted) {
    // User successfully joined artist roster
    showSuccess('Welcome to the team!');
  }

  return data;
}
```

---

## Usage Notes

**Validation**:
- Username must be unique and follow WordPress username rules
- Email must be valid format and unique
- Passwords must match and meet strength requirements
- Turnstile verification prevents automated registrations

**Artist Creation**:
- Setting `user_is_artist: true` creates an artist profile
- Optional professional status for enhanced features
- Artist profiles enable link page management

**Invitations**:
- `invite_token` and `invite_artist_id` work together
- Accepts roster invitations during registration
- Bypasses some validation for invited users

**Security**:
- All input is sanitized server-side
- Turnstile prevents spam registrations
- Device tracking for session management

**Post-Registration**:
- User is automatically logged in after successful registration
- Tokens are returned for immediate API access
- Email verification may be required depending on configuration

**Related Endpoints**:
- [Auth Login](login.md) - Authenticate existing users
- [Auth Refresh](refresh.md) - Refresh expired tokens</content>
<parameter name="filePath">docs/routes/auth/register.md