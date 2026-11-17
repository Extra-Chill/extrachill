# Booking abilities

Extra Chill Booking's abilities cover public inquiry admission and artist follow-through, venue-scoped booking operations and edits, holds, configuration, correspondence, private attachments, privacy, marketing, ticket-sales and show settlements, and reporting. All registrations use category `extrachill-events`; historical ability namespaces are retained after extraction from Events.

## Contract conventions

Inputs below are objects; fields marked **required** must be present, and all other fields are optional. Schemas reject extra properties unless noted. IDs and expected versions are positive integers unless stated otherwise. Datetimes use `YYYY-MM-DD HH:MM:SS`. Errors return `WP_Error`, not the success shape described below.

**Venue authorization** below means `TeamCapabilities::authorize('ec_access_venue', venue_term_id, get_current_user_id())`: the WordPress feature gate and exact active venue scope, not general post-edit authority. **Booking authorization** first loads the booking internally, then applies that check to its stored venue; missing bookings receive the same forbidden response. See [venue booking authorization](venue-booking-authorization.md).

**Booking object** means the strict `VenueBookingAbilities::booking_schema()` projection: integer `id`, `venue_term_id`, `version`; UUID `public_id`; nullable integer `artist_term_id`, `artist_profile_id`, `submitter_user_id`, `event_id`; string `artist_name`, `status`, `created_at`, `updated_at`; nullable strings `contact_name`, `contact_email`, `contact_phone`, `requested_space_key`, `space_key`; nullable datetimes `requested_start_at`, `requested_end_at`, `performance_start_at`, `performance_end_at`; versioned `{version, data}` envelopes `intake`, `production`, `deal`, `confirmed_deal` (the last three nullable). All fields are required in output. Storage-only inquiry idempotency, request-hash, and admission-owner material is removed.

**Hold object** means the strict hold projection: integer `id`, `booking_id`, `venue_term_id`, `version`, `created_by_user_id`; string `space_key`, `start_at`, `end_at`, `expires_at`, `status`, `created_at`, `updated_at`; nullable strings `released_at`, `release_reason`, `expired_at`, `converted_at`; nullable integers `released_by_user_id`, `converted_by_user_id`. All are required; status is `active`, `released`, `expired`, or `converted`.

API paths below are relative to `/wp-json/extrachill/v1`. No `wp extrachill` caller was found in `extrachill-cli/inc/CommandRegistry.php` for these abilities. “None found” refers to explicit API routes and CLI commands, not core ability execution when `show_in_rest` is true.

## Inquiry admission and availability

### `extrachill/create-booking-inquiry`

- **Purpose:** Admit an idempotent inquiry without creating a WordPress user. See [inquiry attachment admission](booking-attachments.md#inquiry-admission-contract).
- **Input:** **Required:** `idempotency_key` (string, 1–191), `venue_term_id` (integer), `intake` (object, arbitrary properties). Optional: `artist_term_id`, `artist_profile_id` (integer|null); `artist_name` (string, 1–255); `contact_name`, `contact_email`, `contact_phone`, `requested_space_key` (string|null; email format for email); `requested_start_at`, `requested_end_at` (datetime|null); `attachments` (array of objects, each requiring string `name`, `tmp_name`, `purpose`, integer `error`, positive integer `size`; purpose from `BookingAttachmentPolicy::PURPOSES`, count/size bounded by admission/policy constants).
- **Output:** Object requiring UUID `public_id`, integer `venue_term_id`, string `submitted_at`; optional 64-character string `capability` for anonymous admission.
- **Permission:** `__return_true`; admission service validates domain policy and binds the current user server-side. Public transport owns challenge and rate admission.
- **Exposure:** `show_in_rest: false`; API `POST /venues/{venue}/booking-inquiries`; CLI none found.
- **Side effects:** Persists inquiry, admission/idempotency and activity state, stages/claims optional private attachments, and arranges receipt notifications. Exact retries return the immutable receipt.

### `extrachill/check-booking-availability`

- **Purpose:** Return only whether an interval is filled by a confirmed booking or canonical published event, without disclosing private booking details.
- **Input:** **Required:** `venue_term_id` (integer), `requested_space_key` (string, 1–64), `requested_start_at`, `requested_end_at` (datetime strings in the venue's local interval contract).
- **Output:** Strict object requiring `available` (boolean).
- **Permission:** `__return_true`; callback delegates to the privacy-safe `public_interval_availability` projection.
- **Exposure:** `show_in_rest: false`; API `POST /venues/{venue}/booking-availability`; CLI none found.

## Venue booking lifecycle

### `extrachill/list-venue-bookings`

- **Purpose:** List a bounded page for one authorized venue.
- **Input:** **Required:** `venue_term_id` (integer). Optional: `status` (booking-status string|null), `requested_from`, `requested_to`, `range_start`, `range_end` (datetime|null), `limit` (integer, 1–100, callback default 50), `offset` (integer, 0–10000, callback default 0).
- **Output:** Array of booking objects, at most 100.
- **Permission:** Venue authorization on the supplied venue.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** The hold-aware authorized read reconciles elapsed selected holds before filtering/pagination, potentially persisting expiration and booking/activity changes.

### `extrachill/get-venue-booking`

- **Purpose:** Read one hydrated booking in its exact venue scope.
- **Input:** **Required:** `booking_id` (integer).
- **Output:** Booking object.
- **Permission:** Booking authorization.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** The hold-aware authorized read can persist elapsed selected-hold expiration and refresh the booking.

### `extrachill/get-venue-booking-activity`

- **Purpose:** Read sanitized authoritative activity and conversion/synchronization state.
- **Input:** **Required:** `booking_id` (integer).
- **Output:** Strict object requiring `activity` (array, at most 200 entries with integer `id`, string `kind`, `occurred_at`, actor `{type, name}`, optional string `artist_request_detail`), `conversion` (`status`, integer `attempt`, nullable string `failure_code`, boolean `retryable`), and `sync` (`status`, nullable string `code`, boolean `retryable`). Actor type is `user`, `artist`, or `system`; only venue-team users are named.
- **Permission:** Booking authorization.
- **Exposure:** `show_in_rest: true`; API/CLI none found.

### `extrachill/transition-venue-booking`

- **Purpose:** Apply an explicit lifecycle transition at an expected booking version.
- **Input:** **Required:** `booking_id`, `expected_version` (integers), `to_status` (string from `BookingLifecycle::STATUSES`). Optional: `note` (string|null, at most 1000).
- **Output:** Booking object.
- **Permission:** Booking authorization.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Updates lifecycle/version and activity; suppresses pending reminders on status change. Cancelling a booking already linked to an event delegates to `extrachill/reconcile-booking-event`, then reloads the booking. A reminder-suppression failure can be returned after the booking committed.

### `extrachill/bind-venue-booking-artist`

- **Purpose:** Bind an unresolved booking to existing canonical artist identities.
- **Input:** **Required:** `booking_id`, `expected_version` (integers). Optional: `artist_term_id`, `artist_profile_id` (integer|null).
- **Output:** Booking object.
- **Permission:** Booking authorization; lifecycle service validates the identity binding.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Updates artist identity/version and records activity.

## Booking edits

Registration: [`VenueBookingMutationAbilities.php`](../inc/Abilities/VenueBookingMutationAbilities.php).

All four share one contract:

- **Input:** **Required:** `booking_id`, `expected_version` (integers), plus the fields listed per ability.
- **Output:** Booking object.
- **Permission:** Booking authorization.
- **Exposure:** `show_in_rest: true` (annotations: not read-only, not idempotent, destructive); API/CLI none found.
- **Side effects:** `BookingMutationService` locks venue authority and the booking, rejects a stale `expected_version` with `booking_version_conflict` (409), bumps the booking version and records activity. Each edit is limited to the listed statuses.

### `extrachill/correct-venue-booking-intake`

- **Purpose:** Correct the submitted contact, requested slot, or intake document.
- **Input:** At least one of `contact_name` (string|null, ≤255), `contact_email` (email|null, ≤255), `contact_phone` (string|null, ≤64), `requested_space_key` (string|null, ≤64), `requested_start_at`, `requested_end_at` (datetime|null), `intake` (object, arbitrary properties).
- **Side effects:** Allowed in `submitted`, `needs_info`, `under_review`, `negotiating`. A requested end not after its start fails. When the contact email changes, pending reminders are suppressed (`booking_recipient_changed`); a suppression failure is returned with `booking_committed: true`.

### `extrachill/select-venue-booking-performance`

- **Purpose:** Choose the performance space and time.
- **Input:** **Required:** `space_key` (string, 1–64), `start_at`, `end_at` (datetime strings).
- **Side effects:** Before an event exists, `BookingHoldRepository::select_performance()` (status `under_review` or `negotiating`) checks the space is configured and the interval free of conflicts, then persists the selection. Once the booking has an event, the change is applied through `BookingEventSyncService::reconcile()`, which also updates the canonical event. The Booking console in the venue-settings block calls it.

### `extrachill/update-venue-booking-production`

- **Purpose:** Replace the whole production document.
- **Input:** **Required:** `production`: `{version: 1, support_requirements, support_offers (string arrays, ≤50 items each), production_notes (string|null)}`.
- **Side effects:** Allowed in `under_review`, `negotiating`, `held`; records `production_updated`. The Booking console calls it.

### `extrachill/update-venue-booking-deal`

- **Purpose:** Replace the whole draft deal document.
- **Input:** **Required:** `deal`: version 1 with `type`, `guarantee_cents`, `revenue_share_basis_points` (0–10000), `revenue_share_basis` (`gross_ticket_sales`, `net_ticket_sales`, `door_receipts`), `currency` (`^[A-Z]{3}$`), `capacity`, `advance_ticket_price_cents`, `door_ticket_price_cents`, `ticket_fee_cents`, `tickets_on_sale_at`, `ticket_url`, `additional_terms` (all required, most nullable).
- **Side effects:** Allowed in `negotiating`, `held`; records `deal_draft_updated`; fails with `booking_deal_already_confirmed` (409) once terms are confirmed. After an event exists, only `ticket_url` may differ from the confirmed deal, and that change is synced to the event. The Booking console calls it.

## Holds

### `extrachill/create-booking-hold`

- **Purpose:** Create a venue-space hold from the booking's persisted performance selection.
- **Input:** **Required:** `booking_id`, `expected_booking_version` (integers).
- **Output:** Strict object requiring `hold` (hold object), `booking_version` (integer).
- **Permission:** Booking authorization.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Serializes venue-space conflict checks, persists hold and held booking/version/activity, and schedules expiration through Action Scheduler.

### `extrachill/release-booking-hold`

- **Purpose:** Release an active hold at its expected hold version.
- **Input:** **Required:** `hold_id`, `expected_version` (integers), `reason` (string, 1–255).
- **Output:** Hold object.
- **Permission:** Loads the hold internally, then checks venue authorization against its stored venue; missing holds are forbidden.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Persists release attribution/reason, version and activity, and updates the selected booking's held state when applicable.

### `extrachill/list-booking-holds`

- **Purpose:** List a bounded page of holds for one venue.
- **Input:** **Required:** `venue_term_id` (integer). Optional: `booking_id` (integer|null), `status` (hold-status string|null), `range_start`, `range_end` (datetime|null), `limit` (integer, 1–100), `offset` (integer, 0–10000).
- **Output:** Array of hold objects, at most 100.
- **Permission:** Venue authorization on the supplied venue.
- **Exposure:** `show_in_rest: true`; API/CLI none found.

## Configuration and correspondence templates

Configuration is the strict `VenueBookingConfigAbilities::config_schema()` document: required integer `version`, boolean `enabled`, objects `intake`, `consent`, `attachment_policy`, `embed`, `default_deal`, `correspondence`, arrays `spaces`, `marketing_channels`, `marketing_triggers`, nullable string `ticket_provider_reference`, and integer `hold_ttl_minutes` (5 through `HOLD_TTL_MAX_MINUTES`). Intake requires `version` and `fields` (up to 50 field definitions: required `key`, `label`, `type`, `required`, `options`, optional nullable `visible_when` with required `field`/`value`). Consent requires `id`, `version`, `label`, `required`; attachment policy requires `version`, `enabled`, `purposes` (`key`/`requirement`); embed requires `allowed_parent_origins`; spaces require `key`, `name`, `is_default`. Default deal requires `version`, `type`, `guarantee_cents`, `revenue_share_basis_points`, `revenue_share_basis`, `currency`. Correspondence requires `version`, nullable email `booking_address`/`cc_address`, `from_name`, `footer`, `variables`, `templates` (version/subject/body), and `reminder_policies` (version/enabled/delay_minutes/expected_statuses). Marketing triggers require `key`, `event: event_converted`, and `channels` with `key`, `action`, `approval`, `delay_seconds`, nullable `social` and `newsletter` documents. The registration source defines nested enums and bounds; see also [correspondence](booking-correspondence.md).

### `extrachill/get-venue-booking-config`

- **Purpose:** Read the complete authorized venue configuration.
- **Input:** **Required:** `venue_term_id` (integer).
- **Output:** Configuration document described above, plus required `revision` (integer ≥0), `updated_by_user_id` (integer|null), `updated_at` (string|null).
- **Permission:** Venue authorization.
- **Exposure:** `show_in_rest: true`; API/CLI none found.

### `extrachill/update-venue-booking-config`

- **Purpose:** Atomically replace the complete document at an expected revision, not patch selected fields.
- **Input:** **Required:** `venue_term_id` (integer), `expected_revision` (integer ≥0), `config` (configuration object described above, without read metadata). The `oneOf` input also accepts historical version-specific shapes, including retired guide, requirements, appearance and presentation fields, for normalization; these are not current output fields.
- **Output:** Current configuration document plus revision/update metadata, as for get.
- **Permission:** Venue authorization.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Revision-locked atomic configuration replacement and audit write; legacy documents normalize to the current version.

### `extrachill/preview-booking-correspondence-template`

- **Purpose:** Render an authorized plain-text template without sending it. See [correspondence](booking-correspondence.md).
- **Input:** **Required:** `venue_term_id` (integer), `template` (string from `CORRESPONDENCE_TEMPLATES`), `expected_template_version` (integer), `variables` (object of optional strings, each at most 10000 characters, keys restricted to `CORRESPONDENCE_VARIABLES` plus `message`).
- **Output:** Strict object requiring string `template`, `subject`, `body`, integer `template_version`, integer `config_revision` (≥0).
- **Permission:** Venue authorization.
- **Exposure:** `show_in_rest: true`; API/CLI none found.

## Communications

### `extrachill/send-booking-message`

- **Purpose:** Record and delegate an immediate message or lifecycle-aware reminder, optionally through approval. See [booking notifications](booking-notifications.md) and [correspondence](booking-correspondence.md).
- **Input:** **Required:** `booking_id` (integer), `idempotency_key` (string, 1–120), `template` (string from `BookingCommunicationService::TEMPLATES`), `recipient`, `reply_to` (email strings, ≤255), `message` (string, 1–10000). Optional: `subject` (string, 1–200), `template_version` (integer), `send_at` (datetime|null), `expected_statuses` (unique array of booking-status strings), `approval` (`direct` or `required`, default `direct`).
- **Output:** Open object: communication service receipt/state, or Data Machine pending-action receipt when approval is required. Queue/schedule acceptance is not delivery proof.
- **Permission:** Booking authorization; approval resolution re-resolves the booking and freshly checks venue authority.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Writes durable communication intent/activity and delegates mail scheduling/queuing; approval mode stages a Data Machine pending action instead of immediately sending.

### `extrachill/list-booking-communications`

- **Purpose:** Read one booking's durable correspondence ledger.
- **Input:** **Required:** `booking_id` (integer).
- **Output:** Array (at most 200) of strict objects requiring integer `activity_id`, `booking_id`; nullable string `idempotency_key`; strings `kind`, `occurred_at`; `direction: outbound`, `channel: email`; nullable `message` (template/recipient/subject/message/reply_to/nullable send_at); nullable `state` (integer intent_id, string status, nullable string reason).
- **Permission:** Booking authorization.
- **Exposure:** `show_in_rest: true`; API/CLI none found.

### `extrachill/reconcile-booking-message`

- **Purpose:** Recover a missing queued/scheduled receipt from exact Action Scheduler evidence without claiming delivery.
- **Input:** **Required:** `intent_id` (integer).
- **Output:** Open object returned by `BookingCommunicationService::reconcile`, representing the recovered/current message state, or an error.
- **Permission:** Loads the activity; requires kind `booking_message_requested`, then booking authorization for that intent's booking. Invalid/missing intents are forbidden.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Reads scheduler evidence and can append recovered durable queue/schedule activity; does not establish provider delivery.

## Canonical events

### `extrachill/convert-booking-to-event`

- **Purpose:** Idempotently convert a confirmed booking into its canonical event. The nested event write is authorized only for the exact booking source identity.
- **Input:** **Required:** `booking_id`, `expected_version` (integers).
- **Output:** Strict object requiring integer `booking_id`, `booking_version`, `event_id`; string `event_url`; `event_action` (`created`, `updated`, `no_change`, `existing`); boolean `already_converted`.
- **Permission:** Booking authorization; nested Data Machine Events upsert grants are scoped to the active conversion and exact source/public ID.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Persists conversion attempts/activity and event linkage/version, delegates canonical event upsert, and emits conversion hooks that can start configured marketing.

### `extrachill/reconcile-booking-event`

- **Purpose:** Apply approved booking-authoritative corrections and reconcile the linked canonical event idempotently.
- **Input:** **Required:** `booking_id`, `expected_version` (integers). Optional: `changes` (strict object with optional integer `venue_term_id`, string `space_key` (1–64), datetime strings `performance_start_at`, `performance_end_at`, string `performer` (1–255), URI string|null `ticket_url`, boolean `cancelled`).
- **Output:** Strict object requiring integer `booking_id`, `booking_version`, `event_id`; `status` (`succeeded` or `no_change`); string `code`; boolean `retryable`; array `conflicts` (at most 20).
- **Permission:** Booking authorization; nested source-owned event update additionally checks source/public ID, event ID, source identity, fingerprint, active update context, and venue authority.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Persists booking corrections/version and synchronization activity, invokes the source-owned canonical event update, and can cancel the linked event.

## Artist follow-through

These registrations expand the issue's `extrachill-events/` prefix into four exact names. Permission callbacks are deliberately public; the execute service verifies ownership/capability rather than granting general booking access. Account-owned inquiries require their submitting user; anonymous inquiries require their receipt capability. See [privacy and retention](booking-privacy-retention.md).

### `extrachill-events/get-artist-booking-inquiry`

- **Purpose:** Read a privacy-safe artist inquiry status.
- **Input:** **Required:** `public_id` (UUID string), `venue_term_id` (integer). Optional: `capability` (64-character string).
- **Output:** Strict object requiring `public_id`, `venue_term_id`, `venue` (`name`), `submitted_at`, `updated_at`, `status`, `status_label`, integer `version`, `requested_interval` (nullable start_at/end_at), `requested_space` (nullable key/string label), and `permitted_actions` (up to two of request_correction/withdraw/request_cancellation).
- **Permission:** `__return_true`; service verifies exact venue plus submitting user or anonymous capability.
- **Exposure:** `show_in_rest: false`; API `POST /venues/{venue}/booking-inquiries/follow-through/status`; CLI none found.

### `extrachill-events/request-artist-booking-correction`

- **Purpose:** Record a correction request, not silently replace operator-owned booking fields.
- **Input:** **Required:** `public_id` (UUID string), `venue_term_id`, `expected_version` (integers), `idempotency_key` (string, 1–120), `correction` (string, 1–2000). Optional: `capability` (64-character string).
- **Output:** Strict object requiring UUID `public_id`, integer `venue_term_id`, `operation: correction_requested`, integer `version`.
- **Permission:** `__return_true`; service verifies exact venue and submitting user/anonymous capability, expected version and allowed action.
- **Exposure:** `show_in_rest: false`; API `POST /venues/{venue}/booking-inquiries/follow-through/correction`; CLI none found.
- **Side effects:** Persists idempotent correction-request activity and version consequences.

### `extrachill-events/withdraw-artist-booking-inquiry`

- **Purpose:** Withdraw an eligible inquiry or request cancellation when direct withdrawal is no longer permitted.
- **Input:** **Required:** `public_id` (UUID string), `venue_term_id`, `expected_version` (integers), `idempotency_key` (string, 1–120). Optional: `capability` (64-character string).
- **Output:** Object requiring UUID `public_id`, integer `venue_term_id`, integer `version`, `operation` (`withdrawn` or `cancellation_requested`); optional `status: withdrawn` and string `status_label`.
- **Permission:** `__return_true`; service verifies venue, submitting user/anonymous capability, version and lifecycle eligibility.
- **Exposure:** `show_in_rest: false`; API `POST /venues/{venue}/booking-inquiries/follow-through/withdrawal`; CLI none found.
- **Side effects:** Persists withdrawal/status/version or cancellation-request activity with idempotency protection.

### `extrachill-events/recover-artist-booking-inquiry-receipt`

- **Purpose:** Request receipt resend to the persisted booking recipient; transport owns challenge/rate admission.
- **Input:** **Required:** `public_id` (UUID string), `venue_term_id` (integer), `contact_email` (email string, ≤255), `idempotency_key` (string, 1–120).
- **Output:** Strict object requiring UUID `public_id`, integer `venue_term_id`, `operation: receipt_resend_requested`; public transport returns neutral acceptance rather than disclosing matches.
- **Permission:** `__return_true`; service verifies the claimed email against the persisted recipient and enforces recovery eligibility.
- **Exposure:** `show_in_rest: false`; API `POST /venues/{venue}/booking-inquiries/follow-through/receipt-recovery`; CLI none found.
- **Side effects:** Arranges idempotent receipt resend for eligible matches and records recovery state; does not return a new capability to the caller.

## Private attachments

These expand the issue's attachment `extrachill/` prefix. See [booking attachments](booking-attachments.md) for storage isolation, claim/finalization, sanitization and retention policy. Attachment output schemas are intentionally broad (object or array of objects); sanitized metadata removes `storage_reference`, `content_hash`, `idempotency_key`, `request_hash`, and `uploader_reference`.

### `extrachill/list-booking-attachments`

- **Purpose:** List sanitized private attachment metadata for a booking.
- **Input:** **Required:** `booking_id` (integer).
- **Output:** Array of sanitized attachment objects.
- **Permission:** Booking authorization.
- **Exposure:** `show_in_rest: true`; API/CLI none found.

### `extrachill/attach-booking-file`

- **Purpose:** Attach a staged private object as the authenticated operator.
- **Input:** **Required:** `booking_id` (integer), `storage_reference` (string, 24–191), `idempotency_key` (string, 1–191), `purpose` (string from `BookingAttachmentPolicy::PURPOSES`). Optional: `artist_term_id`, `artist_profile_id` (integer|null).
- **Output:** Sanitized attachment object (schema: object).
- **Permission:** Booking authorization; service applies object/claim and artist reuse policy. Uploader is forced to the current user.
- **Exposure:** `show_in_rest: false`; API/CLI none found.
- **Side effects:** Claims private bytes and writes attachment metadata/activity with idempotency protection.

### `extrachill/replace-booking-attachment`

- **Purpose:** Attach replacement bytes and retire the prior attachment logically.
- **Input:** **Required:** `booking_id`, `attachment_id` (integers), `storage_reference` (string, 24–191), `idempotency_key` (string, 1–191), `purpose` (policy enum string). Optional: `artist_term_id`, `artist_profile_id` (integer|null).
- **Output:** Sanitized replacement attachment object (schema: object).
- **Permission:** Booking authorization; service requires the attachment to belong to that booking and forces current-user uploader attribution.
- **Exposure:** `show_in_rest: false`; API/CLI none found.
- **Side effects:** Claims replacement bytes, writes replacement metadata/activity, and logically retires the prior reference; physical byte cleanup is separate.

### `extrachill/delete-booking-attachment`

- **Purpose:** Logically delete one private booking attachment.
- **Input:** **Required:** `booking_id`, `attachment_id` (integers).
- **Output:** Sanitized attachment object (schema: object).
- **Permission:** Booking authorization plus service booking/attachment ownership checks.
- **Exposure:** `show_in_rest: false`; API/CLI none found.
- **Side effects:** Updates attachment lifecycle state and activity; does not immediately purge bytes.

### `extrachill/download-booking-attachment`

- **Purpose:** Issue a secure, short-lived stream handoff rather than a storage URL.
- **Input:** **Required:** `booking_id`, `attachment_id` (integers).
- **Output:** Object descriptor with opaque `stream_token` and `correlation_id`; never expose storage identities.
- **Permission:** Booking authorization plus service attachment ownership/current venue checks.
- **Exposure:** `show_in_rest: false`; API `GET /events/bookings/{booking_id}/attachments/{attachment_id}/download` and signed internal `POST /events/internal/booking-attachment-handoff`; CLI none found.
- **Side effects:** Issues a one-time private handoff and persists delivery correlation/issuance evidence.

### `extrachill/record-booking-attachment-delivery`

- **Purpose:** Record a terminal API byte-transport outcome against its exact handoff.
- **Input:** **Required:** `booking_id`, `attachment_id` (integers), `correlation_id` (36-character hex/hyphen string), `outcome` (`completed`, `failed`, `interrupted`, `partial`), `bytes_sent` (integer ≥0).
- **Output:** Object returned by the delivery service (registration leaves object properties unspecified).
- **Permission:** Booking authorization; service validates the exact delivery/attachment/user binding and expected byte count.
- **Exposure:** `show_in_rest: false`; API signed internal `POST /events/internal/booking-attachment-delivery`, also called by the public download transport's terminal reporter; CLI none found.
- **Side effects:** Persists terminal delivery outcome/byte count and safe activity; a completed outcome must match expected length.

### `extrachill/inspect-booking-attachment-deliveries`

- **Purpose:** Read bounded delivery diagnostics without attachment, actor or storage identifiers.
- **Input:** **Required:** `booking_id` (integer).
- **Output:** Array of diagnostic objects, callback bounded to 100; schema leaves item properties unspecified.
- **Permission:** Booking authorization; service rechecks venue access.
- **Exposure:** `show_in_rest: false`; API/CLI none found.

## Marketing

The dynamic marketing `extrachill/` registration expands to get/retry/cancel operation abilities below; these are not literal prefix-only ability names.

### `extrachill/trigger-booking-marketing`

- **Purpose:** Recover or start configured marketing for a confirmed booking with a canonical event.
- **Input:** **Required:** `booking_id` (integer).
- **Output:** Open object; callback returns integer `booking_id`, `event_id`, and `channels` keyed by configured channel, containing delegated/staged receipts or errors.
- **Permission:** Booking authorization; service rechecks venue access and confirmed/canonical-event eligibility.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Freezes operation bindings, writes marketing activity, and delegates configured social/newsletter operations or stages approval; downstream work may publish or schedule external marketing.

### `extrachill/get-booking-marketing-operation`

- **Purpose:** Inspect/reconcile one previously submitted delegated marketing operation.
- **Input:** **Required:** `booking_id` (integer), `operation_ref` (string matching `^dop_[a-f0-9]{64}$`).
- **Output:** Open object containing sanitized delegated operation response/binding receipt.
- **Permission:** Booking authorization; service requires a matching `marketing_operation_submitted` receipt for that booking.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Although annotated readonly, the wrapper calls `record_response` after delegated get and can persist reconciled marketing activity.

### `extrachill/retry-booking-marketing-operation`

- **Purpose:** Retry one booking-bound delegated marketing operation.
- **Input:** **Required:** `booking_id` (integer), `operation_ref` (string matching `^dop_[a-f0-9]{64}$`).
- **Output:** Open object containing sanitized delegated operation response/binding receipt.
- **Permission:** Booking authorization; service requires a submitted receipt and revalidates current booking/configuration context before retry.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Calls `datamachine/retry-delegated-operation` and records response/failure activity; downstream work may schedule/publish marketing.

### `extrachill/cancel-booking-marketing-operation`

- **Purpose:** Cancel one booking-bound delegated marketing operation.
- **Input:** **Required:** `booking_id` (integer), `operation_ref` (string matching `^dop_[a-f0-9]{64}$`).
- **Output:** Open object containing sanitized delegated operation response/binding receipt.
- **Permission:** Booking authorization; service requires the exact submitted receipt for that booking.
- **Exposure:** `show_in_rest: true`; API/CLI none found.
- **Side effects:** Calls `datamachine/cancel-delegated-operation` and records resulting activity.

## Privacy and retention

### `extrachill/operate-venue-booking-privacy`

- **Purpose:** Diagnose operations or dry-run/apply a bounded venue retention batch. See [privacy and retention](booking-privacy-retention.md).
- **Input:** **Required:** `operation` (`diagnose` or `cleanup`), `venue_term_id` (integer), `before` (datetime string). Optional: `status` (booking-status string), `after_id` (integer ≥0, default 0), `limit` (integer 1 through `BookingPrivacyService::MAX_LIMIT`, default `BATCH_SIZE`), `apply` (boolean, default false). Service normalization adds operation-specific eligibility checks.
- **Output:** Open object with bounded diagnostics or cleanup/dry-run results; no unrestricted row export.
- **Permission:** `BookingPrivacyService::authorize_operation` normalizes the request and checks `ec_access_venue` for the supplied venue/current user; normalization and authorization errors propagate.
- **Exposure:** `show_in_rest: false`; API/CLI none found.
- **Side effects:** Dry run is the default; applied cleanup redacts eligible retained personal data and records privacy consequences under retention/legal-hold policy.

## Ticket sales and commission settlement

Registration: [`TicketSettlementAbilities.php`](../inc/Abilities/TicketSettlementAbilities.php). Storage, reconciliation and the formula are described in [ticket sales and settlements](../../extrachill-events/docs/ticket-settlements.md). This settlement is the frozen Extra Chill commission for one booking; it is separate from the show settlement below.

Shared rules:

- **Permission:** Booking authorization (`ec_access_venue`) for reads, sales recording and CSV import. **Finance authorization** below means the same check with `ec_manage_venue_finances`. The services repeat the check internally.
- **Exposure:** `show_in_rest: true`; annotations mark reads read-only and writes destructive. API/CLI none found.
- **Report object:** the strict sales-report projection: integer `id`, `booking_id`, `event_id`, `venue_term_id`; nullable `ticket_source_id`, `evidence_attachment_id`, `corrects_report_id`; `provider`, `external_report_id`, `source_type` (`manual`, `csv_certified`, `extrachill_checkout`), `period_start`, `period_end`; signed integers `tickets_sold`, `tickets_refunded`, `gross_minor`, `fees_minor`, `tax_minor`, `refunds_minor`, `net_minor`; `currency`; `source` redacted to `{redacted, attachment_id?, row?}`; `request_hash`, `created_by_user_id`, `created_at`.
- **Settlement object:** the frozen settlement: ids, `status` (`finalized`, `paid`, `void`), `version`, `booking_version`, `basis`, `basis_points`, `currency`, `formula_version` (1–3), `included_report_ids`, `evidence_hash`, `integrity_hash`, `basis_amount_minor`, `adjustment_minor`, `amount_due_minor`, finalizer, nullable paid/void audit fields, timestamps.
- **Paging input:** **Required:** `booking_id`. Optional: `limit` (1–200), `offset` (0–100000).

### `extrachill/record-checkout-ticket-sales`

- **Purpose:** Record an Extra Chill checkout sale or refund against the booking linked to an event.
- **Input:** **Required:** `event_id` (integer), `external_report_id`, `order_reference` (strings, 1–191), `period_start` (string), `currency` (three letters), `tickets_sold`, `gross_minor`, `fees_minor` (integers).
- **Output:** `{recorded: boolean}`; `false` when no booking is linked to the event.
- **Permission:** Verified cross-site ticket service grant (`extrachill_events_ticket_has_service_authority()`), not a user capability.
- **Exposure:** `show_in_rest: true`; called by the shop's Stripe ticket handlers through `extrachill_shop_execute_ticket_owner_ability()`.
- **Side effects:** Appends an `extrachill_checkout` report (provider `extrachill`, net = gross − fees). Exact retries return the prior report.

### `extrachill/register-booking-ticket-source`

- **Purpose:** Register an immutable ticket-source identity for a booking.
- **Input:** **Required:** `booking_id`, `provider` (`^[a-z0-9_-]+$`, ≤64), `source_key` (1–191), `ticket_url` (http(s), 8–2048).
- **Output:** Source object `{id, public_id, booking_id, event_id, venue_term_id, provider, source_key, display_url, created_at}`; `display_url` is the URL origin only.
- **Permission:** Finance authorization.
- **Side effects:** Inserts a source row; exact retries are idempotent.

### `extrachill/list-booking-ticket-sources`

- **Purpose:** List a booking's registered ticket sources.
- **Input:** Paging input.
- **Output:** Array of source objects, at most 200.
- **Permission:** Booking authorization.

### `extrachill/record-booking-ticket-sales`

- **Purpose:** Record a manual sales observation or correction.
- **Input:** **Required:** `booking_id`, `provider`, `external_report_id`, `source_type` (`manual` only), `period_start`, `period_end` (datetimes), `tickets_sold`, `tickets_refunded`, `gross_minor`, `fees_minor`, `tax_minor`, `refunds_minor`, `net_minor` (integers), `currency`, `source` (object, 1–32 properties). Optional: `ticket_source_id`, `evidence_attachment_id`, `corrects_report_id` (integer|null).
- **Output:** Report object.
- **Permission:** Booking authorization.
- **Side effects:** Appends an immutable report. An exact retry returns the prior report; reusing the report ID with different content conflicts.

### `extrachill/import-booking-ticket-sales-csv`

- **Purpose:** Import certified sales rows from a private CSV booking attachment.
- **Input:** **Required:** `booking_id`, `attachment_id`, `ticket_source_id` (integers).
- **Output:** Array of report objects, at most 1000.
- **Permission:** Booking authorization.
- **Side effects:** Streams and re-hashes the attachment through the private download handoff, validates the fixed header and rows, then appends one `csv_certified` report per row.

### `extrachill/list-booking-ticket-sales`

- **Purpose:** List a booking's sales reports.
- **Input:** Paging input (callback defaults `limit` 100, `offset` 0).
- **Output:** Array of report objects, at most 200.
- **Permission:** Booking authorization.

### `extrachill/diagnose-booking-ticket-sales`

- **Purpose:** Show which reports are admitted, excluded or unresolved before settlement.
- **Input:** Paging input (only `booking_id` is used).
- **Output:** `{booking_id, reports: array<{report_id, report_index, ticket_source_id, reconciliation_version, decision, issues, state}>, counts: {admitted, excluded, unresolved}, ready_for_settlement}`. Issues are `unattributed`, `file_missing`, `currency_mismatch`, `duplicate`, `contradictory`, `overlap`.
- **Permission:** Booking authorization.

### `extrachill/resolve-booking-ticket-sales`

- **Purpose:** Admit or exclude one report, optionally correcting its source attribution.
- **Input:** **Required:** `booking_id`, `report_id`, `expected_version` (integer ≥0), `decision` (`admit` or `exclude`), `reason` (1–1000). Optional: `ticket_source_id` (integer|null).
- **Output:** Resolution object `{id, public_id, booking_id, report_id, venue_term_id, version, decision, ticket_source_id, supersedes_resolution_id, reason, request_hash, created_by_user_id, created_at}`.
- **Permission:** Finance authorization.
- **Side effects:** Appends a resolution; a stale version conflicts, and resolutions are rejected after the settlement is finalized.

### `extrachill/calculate-booking-settlement`

- **Purpose:** Preview the commission from currently admitted evidence without saving it.
- **Input:** **Required:** `booking_id`. Optional: `basis` (`gross_ticket_sales`, `net_ticket_sales`), `basis_points` (0–10000), `currency`, `adjustment_minor`.
- **Output:** `{booking_id, booking_version, event_id, venue_term_id, basis, basis_points, currency, formula_version: 3, included_report_ids, evidence_hash, basis_amount_minor, share_amount_minor, adjustment_minor, amount_due_minor}`.
- **Permission:** Booking authorization.
- **Side effects:** None persisted; re-authenticates private CSV evidence bytes.

### `extrachill/finalize-booking-settlement`

- **Purpose:** Freeze the booking's commission settlement.
- **Input:** **Required:** `booking_id`, `expected_booking_version`, `expected_report_ids` (1–10000 unique IDs), `basis`, `basis_points`, `currency`, `formula_version` (1, 2 or 3), `adjustment_minor`. `expected_evidence_hash` is required unless `formula_version` is 1.
- **Output:** Settlement object.
- **Permission:** Finance authorization.
- **Side effects:** Inserts the single frozen settlement row. Stale booking versions, evidence or decisions conflict; an exact retry returns the frozen settlement.

### `extrachill/mark-booking-settlement-paid`

- **Purpose:** Record payment of the commission.
- **Input:** **Required:** `booking_id`, `expected_booking_version`, `expected_version`, `payment_reference` (1–191).
- **Output:** Settlement object.
- **Permission:** Finance authorization.
- **Side effects:** Moves a finalized settlement to `paid`; requires a `completed` booking.

### `extrachill/void-booking-settlement`

- **Purpose:** Void the commission settlement.
- **Input:** **Required:** `booking_id`, `expected_booking_version`, `expected_version`, `reason` (1–1000).
- **Output:** Settlement object.
- **Permission:** Finance authorization.
- **Side effects:** Moves the settlement to `void` with the reason and actor.

## Show settlements

Registration: [`ShowSettlementAbilities.php`](../inc/Abilities/ShowSettlementAbilities.php). The model, formula, evidence and acknowledgement rules are described in [complete show settlements](../../extrachill-events/docs/show-settlements.md). A show settlement builds on the frozen commission settlement and calculates the artist payout.

Shared rules:

- **Permission:** Finance authorization (`ec_manage_venue_finances` on the booking's venue), except acknowledgement.
- **Exposure:** `show_in_rest: true`; all operations are annotated idempotent, and writes destructive. API/CLI none found.
- **Output:** Show settlement revision `{id, public_id, booking_id, event_id, venue_term_id, revision, corrects_revision_id, commission_settlement_id, commission_integrity_hash, currency, formula_version, terms, evidence, calculation, integrity_hash, created_by_user_id, created_at, status, version, actions}`. Status is `draft`, `finalized`, `acknowledged`, `disputed`, `corrected`, `paid` or `void`. Evidence exposes only attachment ID, public UUID, MIME and size.
- **Revision input:** **Required:** `booking_id`, `commission_settlement_id`, `currency`, `ticket_gross_minor`, `door_gross_minor`, `fees_minor`, `taxes_minor`, `refunds_minor`, `venue_expenses_minor`, `production_expenses_minor`, `artist_guarantee_minor` (integers; deductions and guarantee ≥0), `artist_split_basis_points` (0–10000), `adjustments` (≤100 `{amount_minor, reason}`), `door_report_attachment_ids` (≤20 unique IDs), `idempotency_key` (1–191).
- **Transition input:** **Required:** `booking_id`, `revision_id`, `expected_version`, `idempotency_key`.
- **Side effects (writes):** Revisions and lifecycle actions are append-only rows. Exact retries return the prior result; reusing an idempotency key with changed input conflicts.

### `extrachill/draft-booking-show-settlement`

- **Purpose:** Create the first draft revision.
- **Input:** Revision input.
- **Side effects:** Verifies the commission settlement and private evidence bytes, then appends revision 1 as a draft.

### `extrachill/read-booking-show-settlement`

- **Purpose:** Read the latest revision, or a specific one.
- **Input:** **Required:** `booking_id`. Optional: `revision` (integer).
- **Output:** The verified revision; `show_settlement_not_found` (404) when none exists.

### `extrachill/revise-booking-show-settlement`

- **Purpose:** Replace a draft with a new draft revision.
- **Input:** Revision input plus required `expected_revision_id`.
- **Side effects:** Appends the successor draft; the prior draft stays readable.

### `extrachill/finalize-booking-show-settlement`

- **Purpose:** Finalize a draft revision.
- **Input:** Transition input.
- **Side effects:** Records `finalized` after re-verifying evidence and the commission.

### `extrachill/acknowledge-booking-show-settlement`

- **Purpose:** Record that the artist accepted a finalized settlement.
- **Input:** Transition input plus required `acknowledgement_type` (`counterparty_verified` or `venue_recorded`) and `acknowledgement_evidence_attachment_ids` (≤20). Optional: `note` (≤1000).
- **Permission:** `counterparty_verified` requires a manager of the booking's canonical artist; `venue_recorded` requires finance authorization and at least one evidence attachment.
- **Side effects:** Records `acknowledged` with the canonical artist IDs; the attesting user is stored only for direct counterparty acknowledgement.

### `extrachill/dispute-booking-show-settlement`

- **Purpose:** Dispute a finalized or acknowledged settlement.
- **Input:** Transition input plus required `reason` (1–1000).
- **Side effects:** Records `disputed`.

### `extrachill/correct-booking-show-settlement`

- **Purpose:** Start a correction of a finalized, acknowledged or disputed revision.
- **Input:** Revision input plus required `expected_revision_id`, `expected_version` and `reason` (1–1000).
- **Side effects:** Appends a linked correction draft and marks the prior revision `corrected`.

### `extrachill/mark-booking-artist-payout-paid`

- **Purpose:** Record the artist payout.
- **Input:** Transition input plus required `payment_reference` (1–191), `payment_date` (`YYYY-MM-DD`), `payout_evidence_attachment_ids` (1–20).
- **Side effects:** Records `paid` for a finalized or acknowledged revision; requires a `completed` booking. Reads return only a `payment_reference_recorded` marker.

### `extrachill/void-booking-show-settlement`

- **Purpose:** Void a draft, finalized, acknowledged or disputed revision.
- **Input:** Transition input plus required `reason` (1–1000).
- **Side effects:** Records `void`.

## Reporting

Registration: [`BookingReportingAbilities.php`](../inc/Abilities/BookingReportingAbilities.php). Both reports are read-only snapshots over canonical booking records.

Shared rules:

- **Input:** **Required:** `venue_term_ids` (1–20 unique integers), `from`, `to` (datetimes; a positive half-open UTC period of at most 366 days). Optional: `limit` (1–200, default 100; the revenue report caps it at 25).
- **Permission:** The action below on every listed venue. Requesting more than one venue also requires a team administrator.
- **Exposure:** `show_in_rest: true` (read-only, idempotent); API/CLI none found.
- **Output scope:** `scope` echoes the venues and period with `timezone: UTC`, `interval: half_open`, `basis` and `outcomes_as_of`; `bounded` reports any limits applied.

### `extrachill/get-venue-booking-performance-report`

- **Purpose:** Report the booking funnel and operational outcomes.
- **Permission:** `ec_access_venue`.
- **Output:** `{scope, state (empty|incomplete|complete), bounded, availability, funnel, operations, marketing}`.

### `extrachill/get-venue-booking-revenue-report`

- **Purpose:** Report commission, show-settlement and artist-payout totals.
- **Permission:** `ec_manage_venue_finances`.
- **Output:** `{scope, state (empty|incomplete|corrected|finalized), bounded, coverage, commission_statuses, show_settlement_statuses, currencies}`; `currencies` maps each currency to integer totals.
