# User Onboarding

Manage the user onboarding flow for new account setup, profile completion, and artist status configuration.

## Endpoints

### Get Onboarding Status

**Endpoint**: `GET /wp-json/extrachill/v1/users/onboarding`

**Purpose**: Retrieve the current onboarding status and completion state for the authenticated user.

**Permission**: Requires logged-in user

**Parameters**: None

**Response** (HTTP 200):
```json
{
  "completed": false,
  "current_step": "profile",
  "username": "newuser",
  "user_is_artist": false,
  "user_is_professional": false,
  "profile_image_complete": false
}
```

**Response** (completed onboarding) (HTTP 200):
```json
{
  "completed": true,
  "current_step": "complete",
  "username": "newuser",
  "user_is_artist": true,
  "user_is_professional": false,
  "profile_image_complete": true
}
```

**Error Responses**:
- `401` - User not logged in
- `503` - Onboarding service not available

### Complete Onboarding

**Endpoint**: `POST /wp-json/extrachill/v1/users/onboarding`

**Purpose**: Complete the user onboarding flow with profile and artist status configuration.

**Permission**: Requires logged-in user

**Parameters**:
- `username` (string, required) - Final username for profile
- `user_is_artist` (boolean, optional) - Whether user is an artist (default: false)
- `user_is_professional` (boolean, optional) - Whether user is a professional (default: false)

**Request Example**:
```json
{
  "username": "coolartist",
  "user_is_artist": true,
  "user_is_professional": false
}
```

**Response** (HTTP 200):
```json
{
  "completed": true,
  "user_id": 123,
  "username": "coolartist",
  "user_is_artist": true,
  "user_is_professional": false,
  "profile_url": "https://community.extrachill.com/forums/users/coolartist/"
}
```

**Error Responses**:
- `400` - Invalid parameters or username already exists
- `401` - User not logged in
- `503` - Onboarding service not available

**Implementation Details**:
- Calls `ec_get_onboarding_status()` for GET requests
- Calls `ec_complete_onboarding()` for POST requests
- Username must be unique and valid
- Creates artist profile if `user_is_artist` is true
- Sets professional status flag for verification purposes

**File**: `inc/routes/users/onboarding.php`

---

## Usage Examples

### Check Onboarding Status

```javascript
async function getOnboardingStatus() {
  const response = await fetch('/wp-json/extrachill/v1/users/onboarding', {
    method: 'GET',
    headers: {
      'Authorization': 'Bearer ' + localStorage.getItem('access_token')
    }
  });

  const status = await response.json();

  if (!status.completed) {
    console.log(`Current step: ${status.current_step}`);
    // Redirect to onboarding UI
    window.location.href = '/onboarding?step=' + status.current_step;
  }

  return status;
}
```

### Complete Onboarding Flow

```javascript
async function completeOnboarding(username, isArtist, isProfessional) {
  const response = await fetch('/wp-json/extrachill/v1/users/onboarding', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + localStorage.getItem('access_token')
    },
    body: JSON.stringify({
      username: username,
      user_is_artist: isArtist,
      user_is_professional: isProfessional
    })
  });

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message);
  }

  const result = await response.json();
  console.log(`Onboarding complete! Profile: ${result.profile_url}`);
  
  return result;
}
```

### React Onboarding Form

```javascript
function OnboardingForm() {
  const [username, setUsername] = useState('');
  const [isArtist, setIsArtist] = useState(false);
  const [isProfessional, setIsProfessional] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const response = await fetch('/wp-json/extrachill/v1/users/onboarding', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + localStorage.getItem('access_token')
        },
        body: JSON.stringify({
          username,
          user_is_artist: isArtist,
          user_is_professional: isProfessional
        })
      });

      if (!response.ok) {
        const data = await response.json();
        setError(data.message);
        return;
      }

      const result = await response.json();
      // Redirect to dashboard
      window.location.href = '/dashboard';
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <input
        type="text"
        placeholder="Choose a username"
        value={username}
        onChange={(e) => setUsername(e.target.value)}
        required
      />
      
      <label>
        <input
          type="checkbox"
          checked={isArtist}
          onChange={(e) => setIsArtist(e.target.checked)}
        />
        I'm an artist
      </label>

      <label>
        <input
          type="checkbox"
          checked={isProfessional}
          onChange={(e) => setIsProfessional(e.target.checked)}
        />
        Professional account
      </label>

      <button type="submit" disabled={loading}>
        {loading ? 'Completing...' : 'Complete Onboarding'}
      </button>

      {error && <div className="error">{error}</div>}
    </form>
  );
}
```

---

## Usage Notes

**Flow**:
1. User registers new account
2. Check onboarding status with GET request
3. Display onboarding UI based on current step
4. Complete onboarding with POST request
5. Redirect to dashboard or create artist profile

**Username Rules**:
- Must be unique across the platform
- Should be URL-safe and memorable
- Typically lowercase with hyphens or underscores

**Artist vs Professional**:
- `user_is_artist` - Account managing artist profiles/link pages
- `user_is_professional` - Verification flag for business accounts
- Both flags independent and optional

**Related Endpoints**:
- [Auth Me](../auth/me.md) - Check onboarding status after login
- [User Profile](users.md) - View user profile with onboarding status
- [Artists](../artists/artist.md) - Manage artist profiles
