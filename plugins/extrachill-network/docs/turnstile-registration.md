# Cloudflare Turnstile Registration Integration

## Overview

Cloudflare Turnstile is integrated throughout the Extra Chill Platform as a network-wide bot prevention system. This document explains how Turnstile is implemented in registration forms and other user-facing input forms.

## Integration Flow

### 1. Network Configuration

Turnstile is configured once at the network level and accessible to all sites:

**Storage Location**: Network options (managed by extrachill-network)
- `ec_turnstile_site_key` - Client-side identifier
- `ec_turnstile_secret_key` - Server-side verification token

**Configuration UI**: Network Admin → Extra Chill Settings → Turnstile

**Availability**: Functions defined in `extrachill-network` and available to all plugins

### 2. Frontend Registration Form Flow

**Location**: User registration forms across all sites (extrachill-users plugin)

**Step 1: Check Configuration**
```php
if ( ec_is_turnstile_configured() ) {
    // Turnstile available, render widget
}
```

**Step 2: Enqueue Turnstile JavaScript**
```php
add_action( 'wp_enqueue_scripts', function() {
    ec_enqueue_turnstile_script();
});
```

**Step 3: Render Turnstile Widget in Form**
```php
<form id="registration-form" method="post">
    <?php wp_nonce_field( 'register_user', 'register_nonce' ); ?>
    
    <!-- Form fields (email, password, etc.) -->
    <input type="email" name="email" placeholder="Email address" required>
    <input type="password" name="password" placeholder="Password" required>
    
    <!-- Turnstile widget -->
    <?php echo ec_render_turnstile_widget(); ?>
    
    <button type="submit">Register</button>
</form>
```

**Widget Options**: Default rendering shows:
- Size: normal (full-width)
- Theme: auto (matches system dark/light mode)
- Appearance: interaction-only (minimal visual intrusion)

**Step 4: Customize Widget Appearance (if needed)**
```php
echo ec_render_turnstile_widget( array(
    'data-size' => 'compact',
    'data-theme' => 'dark'
) );
```

### 3. Backend Verification

**Location**: Registration AJAX handler (extrachill-users plugin)

**Step 1: Retrieve Response Token**
```php
if ( empty( $_POST['cf-turnstile-response'] ) ) {
    return wp_send_json_error( 'Bot check required' );
}
$response_token = $_POST['cf-turnstile-response'];
```

**Step 2: Verify with Cloudflare**
```php
if ( ! ec_verify_turnstile_response( $response_token ) ) {
    // Verification failed
    return wp_send_json_error( 'Verification failed, please try again' );
}
```

**Step 3: Proceed with Registration**
```php
// Turnstile verified, safe to create user
$user_id = wp_create_user( $username, $password, $email );
```

## Verification Architecture

### Server-Side Verification

**Endpoint**: Cloudflare API at `https://challenges.cloudflare.com/turnstile/v0/siteverify`

**Request Format**:
```
POST https://challenges.cloudflare.com/turnstile/v0/siteverify

Parameters:
- secret: ec_get_turnstile_secret_key()
- response: Token from frontend widget
- remoteip: $_SERVER['REMOTE_ADDR'] (optional)
```

**Response Format**:
```json
{
    "success": true,
    "challenge_ts": "2024-01-15T10:30:00Z",
    "hostname": "extrachill.com",
    "error-codes": []
}
```

### Verification Success Criteria

**Passes If**:
- HTTP response code = 200
- JSON `success` field = true
- No error codes returned

**Fails If**:
- HTTP response code ≠ 200
- JSON `success` field = false
- Error codes present (expired token, invalid token, etc.)
- Network error connecting to Cloudflare

### Error Handling

**Logged Errors** (for debugging):
- Empty response token
- Missing secret key in network options
- HTTP errors from Cloudflare
- JSON decode failures
- Verification failure with specific error codes

**User-Facing Errors** (safe messages):
- "Verification failed, please try again"
- "Bot check required"
- Generic error messages (no technical details)

**Timeout**: 15 second timeout for Cloudflare API calls

## Multiple Form Contexts

### Turnstile Used In

1. **User Registration** (extrachill-users)
   - Main registration form
   - Also used for password reset if configured

2. **Contact Forms** (extrachill-contact)
   - Optional on contact form
   - Prevents spam submissions

3. **Newsletter Subscription** (extrachill-newsletter)
   - Optional on subscription forms
   - Additional bot prevention for email signups

4. **Comment Forms** (Core WordPress + extrachill theme)
   - Optional on post comments
   - Prevents spam comments

### Context-Specific Configuration

All forms use the same network-wide Turnstile configuration. Per-context customization via widget arguments:

```php
// Registration form: normal size
ec_render_turnstile_widget();

// Contact form: compact size
ec_render_turnstile_widget( array( 'data-size' => 'compact' ) );

// Comment form: interaction-only appearance
ec_render_turnstile_widget( array( 'data-appearance' => 'interaction-only' ) );
```

## Graceful Degradation

### If Turnstile Unavailable

**1. Configuration Missing**:
```php
if ( ! ec_is_turnstile_configured() ) {
    // Skip widget rendering
    return;
}
```

**2. Verification Failure**:
```php
if ( ! ec_verify_turnstile_response( $token ) ) {
    // Reject submission
    return wp_send_json_error( 'Verification failed' );
}
```

**3. No Fallback Method**: Forms do NOT submit without Turnstile verification if widget was rendered

**Prevention of Bypass**: AJAX submissions verify token presence and validity, no fallback to captcha or email confirmation

## Security Considerations

### Token Expiration

**Valid Duration**: Tokens expire after 5 minutes

**Handling**: Expired tokens fail verification, user must resubmit form

**User Experience**: Token automatically refreshed if widget visible for extended time

### IP Address Capture

**Optional Tracking**: `remoteip` parameter sent to Cloudflare for additional context

**Privacy**: IP used only for Cloudflare analysis, not stored by WordPress

### No Fallback Tokens

**Security Principle**: Turnstile tokens cannot be reused or spoofed

**One-Time Use**: Each submission requires fresh widget interaction

**Network Requests**: Token verified in real-time against Cloudflare (not local validation)

## Client-Side JavaScript Integration

### Frontend Widget Behavior

**Automatic Rendering**: Widget renders in specified DOM element

**Browser Compatibility**: Works on all modern browsers, fallback for older browsers

**Mobile Support**: Adaptive sizing for mobile devices

**Accessibility**: WCAG 2.1 compliant, works with screen readers

### Form Submission Handling

**Form Serialization**: Turnstile automatically adds token to form data

**AJAX Integration**: JavaScript captures form submission, adds token before AJAX call

**Redirect Flow**: Works with traditional form submissions or AJAX handlers

## Testing Turnstile Locally

### Development Testing

**Test Account Required**: Cloudflare account with Turnstile configured

**Test Mode**: Cloudflare provides special test keys

**Test Keys**:
- Site Key: `1x00000000000000000000AA` (always passes)
- Secret Key: `1x0000000000000000000000000000000000000000` (always passes)

**Local Testing Process**:
1. Configure test keys in network admin
2. Register/submit forms locally
3. Verification always succeeds (test mode)
4. Verify form submission works end-to-end

### Staging/Production Testing

**Real Keys Required**: Use actual Cloudflare Turnstile keys

**Testing Workflow**:
1. Deploy to staging with real Turnstile keys
2. Test multiple browsers and devices
3. Verify token verification works
4. Monitor Cloudflare dashboard for metrics
5. Deploy to production when satisfied

## Cloudflare Dashboard Monitoring

### Metrics Available

**Turnstile Dashboard** (https://dash.cloudflare.com):
- Widget interactions
- Verification success rate
- Bot score distribution
- Detailed analytics by domain

### Recommended Monitoring

**Track**:
- Legitimate user rejection rates
- Bot detection accuracy
- Geographic distribution of attempts
- Browser compatibility issues

**Alert Conditions**:
- Sudden increase in failures
- Unusual geographic patterns
- High bot score submissions
- API errors or timeouts

## Integration Patterns in Other Plugins

### How Plugins Implement Turnstile

**Step 1: Import Functions**
- Functions automatically available (extrachill-network loaded first)
- No require_once needed

**Step 2: Check Configuration**
```php
if ( function_exists( 'ec_is_turnstile_configured' ) && ec_is_turnstile_configured() ) {
    // Safe to use Turnstile
}
```

**Step 3: Enqueue and Render**
```php
// In wp_enqueue_scripts hook
ec_enqueue_turnstile_script();

// In template
echo ec_render_turnstile_widget( $args );
```

**Step 4: Verify on Backend**
```php
if ( function_exists( 'ec_verify_turnstile_response' ) ) {
    if ( ! ec_verify_turnstile_response( $token ) ) {
        wp_send_json_error( 'Verification failed' );
    }
}
```

## Customization and Overrides

### Widget Appearance Options

**Size Options**:
- `'normal'` (default) - Full-width widget
- `'compact'` - Smaller widget for constrained spaces

**Theme Options**:
- `'auto'` (default) - Match system dark/light mode
- `'light'` - Always light theme
- `'dark'` - Always dark theme

**Appearance Options**:
- `'always'` - Widget always visible
- `'interaction-only'` (default) - Widget only shows on user interaction

**CSS Classes**: Custom classes can be added via `'class'` parameter

### Per-Context Customization Example

```php
// Registration form with custom styling
echo ec_render_turnstile_widget( array(
    'data-size' => 'normal',
    'data-theme' => 'dark',
    'data-appearance' => 'always',
    'class' => 'registration-turnstile'
) );

// CSS styling
// .registration-turnstile { margin: 20px 0; }
```

## Disabling Turnstile (Advanced)

### If Turnstile Causes Issues

**Option 1: Remove Configuration**
- Delete Turnstile keys from network options
- All `ec_is_turnstile_configured()` checks return false
- Forms submit without bot prevention

**Option 2: Modify Form Template**
- Add conditional around widget rendering
- Only render for certain user types or contexts
- Keep verification in backend

**Option 3: Use Alternative Provider**
- Extend verification logic to support other providers
- Maintain same verification pattern
- Requires code modification

## Related Documentation

- [extrachill-network CLAUDE.md - Turnstile Functions](../CLAUDE.md#cloudflare-turnstile-integration)
- [extrachill-users CLAUDE.md - Registration Form](../extrachill-users/CLAUDE.md) - Uses Turnstile
- [Root CLAUDE.md - Security Implementation](../../CLAUDE.md#security-first)
- [Cloudflare Turnstile Docs](https://developers.cloudflare.com/turnstile/)
