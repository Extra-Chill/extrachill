# Avatar Upload Endpoint

## Route
`POST /wp-json/extrachill/v1/users/avatar`

## Purpose
Lets logged-in users upload custom avatars that replace the default WordPress profile image. The endpoint delegates storage, validation, and image processing to `extrachill_process_avatar_upload()` so avatar rules remain centralized in the extrachill-users plugin.

## Authentication
- Requires an authenticated WordPress session. Anonymous requests fail the permission callback.

## Request Format
- Multipart form-data containing the file input expected by the extrachill-users helper (typically `avatar` or `file`).
- No JSON body is parsed; rely on native browser `FormData` uploads.

## Processing Flow
1. Validates the session using `is_user_logged_in()` and captures `get_current_user_id()`.
2. Ensures `extrachill_process_avatar_upload()` exists. Missing helper returns `500 function_missing`.
3. Passes the user ID plus `$request->get_file_params()` to the helper, which handles type checks, size limits, cropping, and media library insertion.
4. Propagates helper errors as `WP_Error` responses (status `400` by default) so callers can surface detailed validation issues.

## Success Response
```json
{
  "success": true,
  "url": "https://extrachill.com/uploads/avatars/user123.png",
  "attachment_id": 987
}
```
- `url` is the new avatar image URL.
- `attachment_id` references the media library record, useful for caches or subsequent updates.

## Error Conditions
| Code | Status | Description |
| --- | --- | --- |
| `function_missing` | 500 | Avatar helper unavailable (plugin inactive). |
| Helper-defined codes | 400 | Validation/storage failures (file too large, unsupported type, etc.). |

## Client Guidance
- Always send WordPress REST cookies/nonces when uploading via the block editor or front-end settings page.
- Display helper error messages verbatim; they include actionable instructions (e.g., "Image must be under 2MB").
- After a successful upload, refresh any cached avatar URLs to reflect the new image immediately.
