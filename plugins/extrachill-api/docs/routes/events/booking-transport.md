# Booking Transport

The API plugin owns only protected HTTP admission and byte transport. Extra Chill Events owns booking persistence, venue policy, authorization, idempotency, attachment storage and policy, one-time handoffs, delivery activity, and durable cleanup.

## Submit An Inquiry

`POST /wp-json/extrachill/v1/venues/{venue}/booking-inquiries`

Anonymous, cookie-authenticated, and bearer-authenticated callers use the same Turnstile and atomic fixed-window rate-limit admission. Authentication is optional. When WordPress has validated a caller, Events reads that canonical current user from request context. Multipart and JSON affinity hops use the same signed internal-user transport. Form fields such as `user_id`, `submitter_user_id`, and `uploader_user_id` are rejected and never become authority.

JSON requests provide the inquiry fields directly. Multipart requests provide `intake` and `attachment_purposes` as JSON strings plus up to five `attachments[]` files. Transport limits are 20 MiB per file and 50 MiB in aggregate. Events applies the authoritative filename, MIME, purpose, scan, storage, and venue policy.

An exact retry with the same `idempotency_key`, fields, and ordered attachment bytes returns the same immutable receipt. Reusing the key with changed input returns `booking_idempotency_conflict` (`409`). The receipt contains only `public_id`, `venue_term_id`, and `submitted_at`.

## Download An Attachment

`GET /wp-json/extrachill/v1/events/bookings/{booking_id}/attachments/{attachment_id}/download`

The caller must be authenticated and currently authorized by Events for the booking's exact venue. The API issues and consumes an Events-owned one-time handoff, supports one byte range, and streams at most 20 MiB. `HEAD` and REST `_envelope` requests cannot consume a handoff.

Success is `200` or `206` with `Content-Disposition`, `Content-Length`, `Content-Type`, `Accept-Ranges`, and private `no-store` headers. The Events correlation is never exposed publicly or accepted from clients. For an affinity hop, nonce-bound internal response metadata transfers it to the outer worker and is stripped before the client response. Only that client-facing worker records `completed`, `failed`, `interrupted`, or `partial` after the actual stream outcome. Route-affinity spools are mode `0600`, bounded, and removed after serving, failure, interruption, or shutdown.

## Stable Errors

| Situation | Code | Status | Client action |
|---|---|---:|---|
| REST field validation | `rest_missing_callback_param` or `rest_invalid_param` | 400 | Use `data.params` and `data.details` as field paths. |
| Submitted user authority | `booking_identity_not_allowed` | 400 | Remove all submitted user ID fields. |
| Invalid canonical identity | `booking_authentication_invalid` | 401 | Refresh or clear authentication, then retry. |
| Exact duplicate inquiry | Successful immutable receipt | 201 | Treat the returned `public_id` as the existing receipt. |
| Changed idempotent retry | `booking_idempotency_conflict` | 409 | Generate a new key only for an intentionally new inquiry. |
| Missing Turnstile token | `turnstile_missing_token` | 403 | Render and submit a challenge. |
| Expired or invalid Turnstile token | `turnstile_failed` | 403 | Refresh the challenge and retry. |
| Inquiry rate limit | `public_write_rate_limited` | 429 | Wait for the `Retry-After` seconds. |
| Stale booking configuration | `booking_inquiry_stale_config` | 409 | Refresh configuration before resubmitting. |
| Intake disabled or unavailable | `booking_inquiry_unavailable` | 503 | Keep the draft and retry later. |
| Attachment count/upload mismatch | `booking_attachment_count_invalid`, `booking_attachment_upload_failed`, or `booking_attachment_purpose_mismatch` | 400 | Correct the multipart request. |
| Attachment too large | `booking_attachment_size_invalid` or `booking_attachment_aggregate_size_invalid` | 413 | Remove or reduce files. |
| Attachment policy rejection | `booking_attachment_rejected` | 400 or 413 | Show the safe Events message next to the attachment. |
| Attachment storage or scan unavailable | `booking_inquiry_unavailable` | 503 | Keep the draft and retry later; internals remain hidden. |
| Uncertain inquiry attachment outcome | `booking_inquiry_reconciliation_required` | 503 | Do not change the idempotency key; retry only when reconciliation allows it. |
| Download unauthenticated | `booking_attachment_download_unavailable` | 401 | Authenticate. |
| Download unauthorized, revoked, missing, expired, replayed, or tampered | `booking_attachment_download_unavailable` | 404 | Do not reveal which condition occurred. |
| Download rate limit | `booking_attachment_download_rate_limited` | 429 | Wait for the `Retry-After` seconds. |
| Invalid or unsatisfiable range | `booking_attachment_range_unsatisfiable` | 416 | Retry with one valid range; inspect `Content-Range`. |
| Unknown inquiry failure | `booking_inquiry_unavailable` | 503 | Keep the draft and retry later. |
| Unknown download failure | `booking_attachment_download_unavailable` | 502 or 503 | Retry later without exposing transport internals. |

Only the explicit code/message/status/field contracts above are forwarded. An unknown domain error is always `booking_inquiry_unavailable` (`503`) regardless of its original status. Errors and logs must never contain temporary paths, storage roots, hashes, storage or object references, handoff tokens, delivery correlations, internal identities, or private bytes.
