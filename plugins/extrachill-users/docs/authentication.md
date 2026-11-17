# Authentication System

Core authentication functionality providing login, registration, password reset, and cross-site authentication for the Extra Chill Platform.

## Login System

### User Login Process
```php
// Handle login request
if (isset($_POST['extrachill_login'])) {
    $email = sanitize_email(wp_unslash($_POST['email']));
    $password = $_POST['password'];
    
    $user = wp_authenticate($email, $password);
    if (!is_wp_error($user)) {
        wp_set_auth_cookie($user->ID);
        wp_set_current_user($user->ID);
    }
}
```

### Login Block Rendering
Gutenberg block provides unified login interface with:
- Email-based authentication
- Password validation
- Error handling
- Redirect management

### Two-Factor Authentication

Members who use the Two Factor plugin enter their code in the app or in the
login block, never on `wp-login.php`. `inc/auth-tokens/two-factor.php`
registers a `two-factor` challenge policy with wp-native-auth, and every login
entry point runs wp-native-auth's challenge policies before a session exists:

| Entry point | Path to the policy loop |
|---|---|
| Password (`/auth/login`, login block) | `extrachill_users_login_with_tokens()` → `extrachill_users_maybe_challenge_login()` |
| Google (`/auth/google`, login block button) | `ec_oauth_complete_token_login()` → `extrachill_users_maybe_challenge_login()` |
| Apple (`/auth/apple`) | `ec_oauth_complete_token_login()` → `extrachill_users_maybe_challenge_login()` |
| OAuth authorize | Sends signed-out users to the login block, which uses the password or Google path |
| wp-native-auth's own login ability | Runs the loop itself; this plugin only lifts Two Factor's API-login block for the password check |

A challenged login returns
`{ challenge_required, challenge_policy, challenge, continuation_token, continuation_expires_at }`
untouched. `POST /extrachill/v1/auth/login/continue` calls
`extrachill_users_continue_login_with_tokens()`, which completes it with the
same response shape and destination as the route that started it. The login
block shows the code step inline, with a provider switcher, resend for email
codes, and errors for wrong codes, rate limits, and forced password resets.

Bearer sessions report their 2FA time to Two Factor through
`two_factor_current_session_two_factor_time`, and a Two Factor revalidation
marks the bearer session verified through `two_factor_user_revalidated`.

The policy needs Two Factor's non-rendering login API
(`Two_Factor_Core::verify_authentication_code()` and `get_login_challenge()`)
and wp-native-auth's challenge loop. Until both are installed, the policy is
not registered and password logins for 2FA members keep returning
`{ requires_2fa, redirect_url }` to the Two Factor page. Google and Apple
sign-in have no such fallback, so for a 2FA member they fail closed with
`two_factor_required` (403) instead of skipping the second factor.

Completing a password reset signs the member in, except for 2FA members: the
reset link proves only access to the inbox, so they are sent to the login page
with a notice and enter their code there.

## Registration System

### User Registration Flow
```php
// Handle user registration
if (isset($_POST['extrachill_register'])) {
    $email = sanitize_email(wp_unslash($_POST['email']));
    $password = $_POST['password'];
    $display_name = sanitize_text_field(wp_unslash($_POST['display_name']));
    
    $user_id = wp_create_user($email, $password, $email);
    if (!is_wp_error($user_id)) {
        wp_update_user([
            'ID' => $user_id,
            'display_name' => $display_name
        ]);
    }
}
```

### Registration Features
- Email validation
- Password strength requirements
- Display name customization
- Welcome email sending
- Cross-site user creation

## Password Reset System

### Reset Request Handler
```php
// Process password reset request
if (isset($_POST['extrachill_reset_password'])) {
    $email = sanitize_email(wp_unslash($_POST['email']));
    $user = get_user_by('email', $email);
    
    if ($user) {
        $reset_key = get_password_reset_key($user);
        wp_mail($email, 'Password Reset', $reset_url);
    }
}
```

### Reset Validation
- Secure token generation
- Time-limited reset links
- Email verification
- Cross-domain reset support

## Browser Handoff Authentication

### Handoff Token Generation
```php
// Generate browser handoff token
function extrachill_generate_handoff_token($user_id) {
    $payload = [
        'user_id' => $user_id,
        'exp' => time() + (5 * 60), // 5 minutes
        'domain' => $_SERVER['HTTP_HOST']
    ];
    
    return jwt_encode($payload, get_option('extrachill_handoff_secret'));
}
```

### Handoff Process
- JWT-based token exchange
- Cross-domain authentication
- Secure token validation
- Automatic user session creation

## Security Features

### Input Validation
- Email sanitization
- Password strength validation
- Nonce verification
- CSRF protection

### Authentication Security
- Brute force protection
- Rate limiting
- Secure session handling
- Password hashing

## API Endpoints

### Authentication Endpoints
- `POST /extrachill/v1/auth/login` - User login
- `POST /extrachill/v1/auth/login/continue` - Finish a login that returned a challenge
- `POST /extrachill/v1/auth/register` - User registration
- `POST /extrachill/v1/auth/logout` - User logout
- `POST /extrachill/v1/auth/reset-password` - Password reset

### Token Management
- `POST /extrachill/v1/auth/refresh` - Refresh authentication token
- `POST /extrachill/v1/auth/validate` - Validate token
- `POST /extrachill/v1/auth/handoff` - Browser handoff

## Integration Points

### Multisite Integration
- Cross-site user authentication
- Network-wide session management
- Shared authentication state

### Google OAuth Integration
- Social authentication
- Profile data import
- Account linking

### Theme Integration
- Login form templates
- Registration form templates
- Password reset templates

## Error Handling

### Login Errors
- Invalid credentials
- Account locked
- Email not verified
- Network issues

### Registration Errors
- Email already exists
- Weak password
- Invalid display name
- Rate limiting

### Reset Errors
- Email not found
- Invalid token
- Token expired
- Rate limiting