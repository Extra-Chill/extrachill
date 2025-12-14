# Newsletter Campaign Push

Push newsletter posts to Sendy email service for campaign distribution.

## Endpoint

### Push Campaign to Sendy

**Endpoint**: `POST /wp-json/extrachill/v1/newsletter/campaign/push`

**Purpose**: Prepare and send a newsletter post as an email campaign via Sendy.

**Permission**: Requires `edit_posts` capability

**Parameters**:
- `post_id` (integer, required) - Newsletter post ID to push

**Request Example**:
```json
{
  "post_id": 456
}
```

**Response** (HTTP 200):
```json
{
  "message": "Successfully pushed to Sendy!",
  "campaign_id": "c1234567890abc"
}
```

**File**: `inc/routes/newsletter/campaign.php`

## Processing Flow

1. **Post Validation**: Verifies post exists and is of type `newsletter`
2. **Email Template**: Calls `prepare_newsletter_email_content()` to format the post for email delivery
3. **Sendy Push**: Calls `send_newsletter_campaign_to_sendy()` to submit campaign
4. **Campaign ID**: Retrieves Sendy campaign ID from post meta `_sendy_campaign_id`

## Error Responses

**Post Not Found** (HTTP 404):
```json
{
  "code": "invalid_post",
  "message": "Newsletter post not found.",
  "data": { "status": 404 }
}
```

**Template Function Missing** (HTTP 500):
```json
{
  "code": "function_missing",
  "message": "Email template function not available. Please ensure extrachill-newsletter plugin is activated.",
  "data": { "status": 500 }
}
```

**Sendy Function Missing** (HTTP 500):
```json
{
  "code": "function_missing",
  "message": "Sendy API function not available. Please ensure extrachill-newsletter plugin is activated.",
  "data": { "status": 500 }
}
```

**Sendy Push Failed** (HTTP 500):
```json
{
  "code": "sendy_failed",
  "message": "Error message from Sendy API",
  "data": { "status": 500 }
}
```

## Permission Model

- Requires `edit_posts` capability for the current site
- Typical WordPress editor role or administrator

## Implementation Details

- Delegates email content preparation to extrachill-newsletter plugin
- Delegates Sendy API communication to extrachill-newsletter plugin
- Stores campaign ID on post for tracking and reference
- Returns campaign ID for client-side confirmation or tracking

## Dependencies

- **extrachill-newsletter**: Provides email template and Sendy API functions
  - `prepare_newsletter_email_content()` - Formats post content for email delivery
  - `send_newsletter_campaign_to_sendy()` - Submits campaign to Sendy service

## Integration

Used by newsletter management interfaces:
- Admin dashboard campaign scheduler
- Bulk email distribution
- Campaign tracking and reporting
- Newsletter archive management
