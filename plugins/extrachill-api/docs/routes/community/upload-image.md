# Community Image Upload Endpoint

## Route
`POST /wp-json/extrachill/v1/community/upload-image`

## Purpose
Allows authenticated forum users to upload images from the TinyMCE editor. The endpoint forwards each file to `extrachill_process_tinymce_image_upload()` inside the community plugin, so existing moderation limits, storage rules, and thumbnail workflows stay centralized.

## Authentication
- Requires a logged-in WordPress session. Requests from anonymous visitors return `401`.

## Request Requirements
Multipart form-data with a single `image` file field. The REST controller reads directly from `$_FILES['image']`, so the client must send the payload as a standard browser upload rather than JSON.

## Processing Workflow
1. Verifies the user session via `is_user_logged_in()`.
2. Confirms the community plugin’s helper `extrachill_process_tinymce_image_upload()` exists; otherwise returns `500 function_missing` with an activation hint.
3. Validates that `$_FILES['image']` is present, returning `400 no_file` if absent.
4. Passes the file array and current user ID to the helper, which performs validation, storage, and media library integration.
5. Returns `{ "success": true, "url": "https://..." }` on success, where `url` is the public asset URL provided by the helper.

## Error Responses
| Code | HTTP | Description |
| --- | --- | --- |
| `no_file` | 400 | Request did not include an `image` upload field. |
| `function_missing` | 500 | The extrachill-community plugin (or helper) is inactive. |
| `upload_failed` | 400 | Helper reported a validation or storage failure; message mirrors the helper response. |

## Consumer Guidance
- Use `FormData` when calling from TinyMCE or any AJAX uploader: append `image` with the `File` object and send with credentials.
- No additional metadata is required; the downstream helper attaches alt text, captions, and moderation attributes.
- The endpoint intentionally exposes no resizing/optimization toggles; all processing is centralized within the community plugin.
