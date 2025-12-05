# Event Submission Flow Endpoint

## Route
`POST /wp-json/extrachill/v1/event-submissions`

## Purpose
Accepts public event submissions from blocks and forms, verifies Cloudflare Turnstile tokens, uploads optional flyers, and spins up a Data Machine job so the configured automation flow can review and publish the event.

## Authentication
- Public endpoint; security relies on the required Turnstile verification token plus server-side validation inside extrachill-multisite helpers.

## Request Requirements
### Core Fields
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `flow_id` | integer | Yes | Data Machine flow configured by the block. |
| `turnstile_response` | string | Yes | Token issued by Cloudflare Turnstile. |
| `contact_name` | string | Conditionally | Optional when logged in; required for anonymous users. |
| `contact_email` | string | Conditionally | Auto-filled for logged-in users; required + validated for anonymous users. |
| `event_title` | string | Yes | Sanitized text. |
| `event_date` | string | Yes | Stored as provided; format enforcement happens downstream. |
| `event_time`, `venue_name`, `event_city`, `event_lineup`, `event_link`, `notes` | string | No | Optional context fields accepted as sanitized text/URLs. |

### File Uploads
- Accepts a single `flyer` file via multipart form-data.
- File is uploaded through WordPress (`wp_handle_upload`) and then persisted to Data Machine’s FileStorage repository so AI/flow steps can read it later.

## Processing Workflow
1. Validates `flow_id` and Turnstile token using `ec_verify_turnstile_response()`.
2. Extracts and sanitizes submission fields via `extrachill_api_extract_submission_fields()`; leverages logged-in identity when available.
3. Confirms the requested Data Machine flow exists (`\DataMachine\Core\Database\Flows\Flows`).
4. Stores the optional flyer file and attaches its storage path to the submission payload.
5. Creates a Data Machine job through `\DataMachine\Services\JobManager`, merges submission data into the engine via `datamachine_merge_engine_data()`, and schedules execution with `as_schedule_single_action( 'datamachine_run_flow_now', ... )`.
6. Fires `do_action( 'extrachill_event_submission', $submission, $context )` so platform plugins can react (notifications, analytics, moderation).

## Response
```json
{
  "success": true,
  "message": "Thanks! We queued your submission for review.",
  "job_id": 1234
}
```
- `job_id` references the Data Machine job created for the submission.

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `invalid_flow_id` | 400 | Missing or non-numeric `flow_id`. |
| `turnstile_missing` / `turnstile_failed` | 500 / 403 | Security helper unavailable or token invalid. |
| `missing_fields` / `invalid_email` | 400 | Required contact or event fields missing/invalid. |
| `datamachine_missing` / `flow_not_found` | 500 / 404 | Data Machine dependency missing or flow ID not found. |
| `flyer_upload_failed` / `flyer_store_failed` | 400 / 500 | File handling failed at upload or storage stage. |
| `job_creation_failed` / `execution_failed` | 500 | Could not create or queue the Data Machine job. |
| `scheduler_unavailable` | 500 | Action Scheduler functions missing. |

## Integration Guidance
- Always include a valid `turnstile_response`; the request fails before any data is stored if verification is missing.
- Submit multipart form-data when sending a flyer; JSON-only bodies should omit the `flyer` field entirely.
- Use the returned `job_id` to display status or poll downstream systems for completion updates.
- Listen for the `extrachill_event_submission` action on other network plugins to trigger Slack/Email alerts or moderation workflows.
