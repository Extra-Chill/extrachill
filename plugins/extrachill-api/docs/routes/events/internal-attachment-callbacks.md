# Internal booking attachment callbacks

These are private transport endpoints, not browser APIs. Source: `inc/routes/events/booking-attachment-download.php`. See [booking transport](booking-transport.md) for the public download lifecycle. Paths are relative to `/wp-json/extrachill/v1`.

## POST /events/internal/booking-attachment-handoff

**Permission callback:** `extrachill_api_booking_attachment_handoff_internal_permission`. Requires localhost, a verified signed internal user equal to the WordPress current user, and `X-EC-Handoff-Signature`: a short-lived (60-second) token bound to the normalized JSON body and current user. Ordinary login alone is insufficient.

**Input:** JSON `booking_id`, `attachment_id` (cast to integers). No REST argument schema is registered; the Events ability owns validation and venue authorization.

**Owner:** `extrachill/download-booking-attachment` (Events); permissions are explicitly checked before execution.

**Response:** private descriptor `{stream_token, correlation_id}` (64-character lowercase hex token and UUID v4). This is only for the signed affinity hop; never expose it to clients or logs. Permission rejection is privacy-safe `booking_attachment_download_unavailable` (403); descriptor failure maps to the same safe code (502).

## POST /events/internal/booking-attachment-delivery

**Permission callback:** `extrachill_api_booking_attachment_delivery_internal_permission`. Same localhost and signed-user checks, with body-bound `X-EC-Delivery-Signature` instead.

**Input:** JSON `booking_id`, `attachment_id`, `correlation_id`, `outcome`, `bytes_sent`. The sender clamps bytes to a nonnegative integer. Terminal outcomes are `completed`, `failed`, `interrupted`, `partial`. No REST argument schema is registered.

**Owner:** hidden Events ability `extrachill/record-booking-attachment-delivery`; the local helper requires `show_in_rest` exactly false and checks ability permissions before execution.

**Response:** `{ "recorded": true }` (200) or privacy-safe `booking_attachment_download_unavailable` (502). Admission rejection is 403. Only the client-facing worker reports the actual delivery outcome; internal correlation data must not be surfaced publicly.
