# REST API Endpoints

## Event Submissions

### POST /wp-json/extrachill/v1/event-submissions

Processes event submission forms and routes data to Data Machine flows.

**Location**: Defined in theme or separate plugin (not in extrachill-events)

**Parameters**:
- `flow_id` (string, required): Data Machine flow identifier
- `contact_name` (string, conditional): Submitter's name (required for anonymous users)
- `contact_email` (string, conditional): Submitter's email (required for anonymous users)
- `event_title` (string, required): Event title
- `event_date` (string, required): Event date (YYYY-MM-DD format)
- `event_time` (string, optional): Event time (HH:MM format)
- `venue_name` (string, optional): Venue name
- `event_city` (string, optional): City or region
- `event_lineup` (string, optional): Headliners or lineup
- `event_link` (string, optional): Ticket or information URL
- `notes` (string, optional): Additional details
- `flyer` (file, optional): Flyer upload (image/*,.pdf)
- `cf-turnstile-response` (string, required): Cloudflare Turnstile token

**Response**:
```json
{
  "success": true,
  "message": "Submission processed successfully"
}
```

**Error Response**:
```json
{
  "success": false,
  "message": "Error description"
}
```

**Validation**:
- Required fields validated server-side
- Email format verification
- File type and size restrictions
- Cloudflare Turnstile token verification
- Data sanitization and security checks

**Integration**:
- Routes submission data to specified Data Machine flow
- Handles file uploads securely
- Provides user feedback via AJAX responses
- Supports both authenticated and anonymous submissions