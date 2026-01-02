# Auth Me

Retrieve current authenticated user data including onboarding completion.

## Endpoints

### Get Current User

**Endpoint**: `GET /wp-json/extrachill/v1/auth/me`

**Purpose**: Return the currently authenticated user's profile data.

**Permission**: Requires logged-in user

**Parameters**: None

**Response** (HTTP 200):
```json
{
  "id": 123,
  "username": "chris",
  "email": "chris@example.com",
  "display_name": "Chris Huber",
  "avatar_url": "https://example.com/wp-content/uploads/avatars/avatar-123.jpg",
  "profile_url": "https://community.extrachill.com/forums/users/chris/",
  "registered": "2024-01-01T12:00:00Z",
  "onboarding_completed": true
}
```

**Response Fields**:

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | User ID |
| `username` | string | User login (username) |
| `email` | string | User email address |
| `display_name` | string | User's display name |
| `avatar_url` | string | Avatar image URL (96px) |
| `profile_url` | string | User profile page URL |
| `registered` | string | User registration date (ISO 8601) |
| `onboarding_completed` | boolean | Whether user finished onboarding flow |

**Error Responses**:
- `401` - User not authenticated

**Implementation Details**:
- Returns current user via `wp_get_current_user()`
- Avatar URL generated at 96px size
- Profile URL from `ec_get_user_profile_url()` if available
- Onboarding status from `ec_is_onboarding_complete()` if available
- Response filterable via `extrachill_auth_me_response` filter

**File**: `inc/routes/auth/me.php`

---

## Usage Examples

### Get Current User (JavaScript)

```javascript
async function getCurrentUser() {
  const response = await fetch('/wp-json/extrachill/v1/auth/me', {
    method: 'GET',
    headers: {
      'Authorization': 'Bearer ' + localStorage.getItem('access_token')
    }
  });

  if (!response.ok) {
    throw new Error('Not authenticated');
  }

  const user = await response.json();
  console.log(`Logged in as: ${user.display_name}`);
  console.log(`Onboarding completed: ${user.onboarding_completed}`);
  
  return user;
}
```

### Onboarding Flow Check

```javascript
async function checkOnboardingStatus() {
  const user = await getCurrentUser();

  if (!user.onboarding_completed) {
    // Redirect to onboarding flow
    window.location.href = '/onboarding';
  } else {
    // Continue to dashboard
    window.location.href = '/dashboard';
  }
}
```

### React Hook for Auth

```javascript
function useCurrentUser() {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetch('/wp-json/extrachill/v1/auth/me', {
      headers: {
        'Authorization': 'Bearer ' + localStorage.getItem('access_token')
      }
    })
      .then(res => res.json())
      .then(data => {
        setUser(data);
        setLoading(false);
      })
      .catch(err => {
        setError(err);
        setLoading(false);
      });
  }, []);

  return { user, loading, error };
}
```

---

## Usage Notes

**Authentication Verification**:
- Use this endpoint to verify access token validity (Bearer auth)
- Catches expired or invalid tokens early
- Returns 401 if token is invalid or missing

**Onboarding Flow**:
- Check `onboarding_completed` status after login
- Redirect to onboarding if incomplete
- After onboarding completion, use [Users Onboarding](../users/onboarding.md) endpoint

**Profile Data**:
- Avatar is 96px Gravatar or custom avatar
- Profile URL links to user's profile page on community site
- Email visible only to authenticated current user

**Related Endpoints**:
- [Auth Login](login.md) - User login
- [Auth Google](google.md) - Google OAuth login
- [Auth Logout](logout.md) - End session
- [Users Onboarding](../users/onboarding.md) - Complete user onboarding
- [User Profile](../users/users.md) - Get any user's profile
