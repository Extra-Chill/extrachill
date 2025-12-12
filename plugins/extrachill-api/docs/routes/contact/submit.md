# Contact Form Submission

REST API endpoint for handling contact form submissions with security verification and email integration.

## Endpoint

### Submit Contact Form

**Endpoint**: `POST /wp-json/extrachill/v1/contact/submit`

**Purpose**: Process contact form submissions with Cloudflare Turnstile verification, send notification emails, and manage newsletter subscriptions.

**Parameters**:
- `name` (string, required) - Contact's full name
- `email` (string, required) - Contact's email address (validated)
- `subject` (string, required) - Email subject line
- `message` (string, required) - Contact message content
- `turnstile_response` (string, required) - Cloudflare Turnstile token

**Response** (HTTP 200):
```json
{
  "success": true,
  "message": "Your message has been sent successfully. We'll get back to you soon."
}
```

**Permission**: Public (no authentication required)

**File**: `inc/routes/contact/submit.php`

## Processing Flow

1. **Security Verification**: Validates Cloudflare Turnstile token using `ec_verify_turnstile_response()`
2. **Input Validation**: Sanitizes and validates all form fields
3. **Email Notifications**:
   - Sends admin notification email via `ec_contact_send_admin_email()`
   - Sends user confirmation email via `ec_contact_send_user_confirmation()`
4. **Newsletter Integration**: Adds email to Sendy list via `ec_contact_sync_to_sendy()`

## Error Responses

**Turnstile Missing** (HTTP 500):
```json
{
  "code": "turnstile_missing",
  "message": "Security verification unavailable.",
  "data": { "status": 500 }
}
```

**Turnstile Failed** (HTTP 403):
```json
{
  "code": "turnstile_failed",
  "message": "Security verification failed. Please try again.",
  "data": { "status": 403 }
}
```

**Contact Unavailable** (HTTP 500):
```json
{
  "code": "contact_unavailable",
  "message": "Contact form processing unavailable.",
  "data": { "status": 500 }
}
```

## Dependencies

- **extrachill-multisite**: For Turnstile verification (`ec_verify_turnstile_response()`)
- **Contact Functions**: Requires `ec_contact_send_admin_email()`, `ec_contact_send_user_confirmation()`, and `ec_contact_sync_to_sendy()` functions

## Integration

Used by contact forms across the platform to provide secure, spam-protected communication channels with automated email handling and newsletter integration.