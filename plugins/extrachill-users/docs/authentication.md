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