# Newsletter API Reference

The Extra Chill Newsletter system exposes REST API endpoints for both public subscription workflows and administrative campaign management. These endpoints are registered in the `extrachill-api` plugin and consumed by the `extrachill-newsletter` plugin.

## Endpoints

### 1. Newsletter Subscription (Public)

Subscribes an email address to a specific Sendy list based on the provided context.

- **URL**: `/wp-json/extrachill/v1/newsletter/subscribe`
- **Method**: `POST`
- **Authentication**: None (Public)
- **Security**: Requires `X-WP-Nonce` header (standard WordPress REST nonce) for CSRF protection.

#### Parameters

| Parameter | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `email` | `string` | Yes | Valid email address to subscribe. |
| `context` | `string` | Yes | The integration context (e.g., `homepage`, `navigation`, `content`, `archive`, `contact`). |

#### Response Examples

**Success (200 OK)**:
```json
{
  "message": "Successfully subscribed to newsletter"
}
```

**Error (400 Bad Request)**:
```json
{
  "code": "subscription_failed",
  "message": "Email already subscribed",
  "data": {
    "status": 400
  }
}
```

**Error (500 Internal Server Error)**:
```json
{
  "code": "function_missing",
  "message": "Newsletter subscription function not available.",
  "data": {
    "status": 500
  }
}
```

---

### 2. Push Campaign to Sendy (Admin)

Pushes a newsletter post to Sendy, creating or updating an email campaign.

- **URL**: `/wp-json/extrachill/v1/newsletter/campaign/push`
- **Method**: `POST`
- **Authentication**: Required (`current_user_can('edit_posts')`)
- **Security**: Requires `X-WP-Nonce` header.

#### Parameters

| Parameter | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `post_id` | `integer` | Yes | The ID of the `newsletter` post to push. |

#### Response Examples

**Success (200 OK)**:
```json
{
  "message": "Successfully pushed to Sendy!",
  "campaign_id": "123"
}
```

**Error (404 Not Found)**:
```json
{
  "code": "invalid_post",
  "message": "Newsletter post not found.",
  "data": {
    "status": 404
  }
}
```

**Error (500 Internal Server Error)**:
```json
{
  "code": "sendy_failed",
  "message": "Failed to send campaign to Sendy",
  "data": {
    "status": 500
  }
}
```

## Implementation Details

The API handlers are located in the `extrachill-api` plugin under `inc/routes/newsletter/`. They delegate the business logic to the following functions in the `extrachill-newsletter` plugin:

- `extrachill_multisite_subscribe($email, $context)`
- `prepare_newsletter_email_content($post)`
- `send_newsletter_campaign_to_sendy($post_id, $email_data)`
