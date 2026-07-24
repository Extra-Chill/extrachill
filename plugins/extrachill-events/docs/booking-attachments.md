# Booking Attachments

Booking attachment metadata, policy, and private local bytes belong to Extra Chill Events.

## Existing Primitives Audited

- WordPress `wp_handle_upload()` and `wp_upload_bits()` write beneath public uploads and return public URLs. `wp_check_filetype_and_ext()` is useful for admission-time extension/content validation, but WordPress media is not a private authorization boundary.
- Data Machine `FileStorage` owns flow/job files beneath `datamachine-files`, intentionally exposes `get_public_url()`, and deletes old flow files by mtime. It has no opaque private object identity, claim/finalization, authorized stream handoff, reference-aware deletion, or domain retention hold.
- Event submission flyer handling temporarily uses WordPress upload handling and copies the flyer into the Data Machine flow-file repository. That is appropriate for public event artwork, not contracts, insurance, riders, or tax documents.
- Booking metadata, activity, lifecycle, and exact venue authorization already live in site-scoped transactional tables in this plugin.

Data Machine declined a generic private-object capability in `Extra-Chill/data-machine#2966`. The byte provider therefore belongs to the Events booking domain. Production provisioning, backups, retention approval, malware policy, capacity monitoring, and restore evidence remain blocked on `Extra-Chill/extrachill-events#336`.

## Privacy Model

`ec_booking_attachments` stores an opaque storage reference plus trusted filename, detected MIME type, byte size, SHA-256 hash, uploader attribution, optional canonical artist references, lifecycle state, and timestamps. Public paths, public URLs, download tokens, and filesystem paths are never persisted.

The built-in local provider activates only when `EXTRACHILL_EVENTS_PRIVATE_STORAGE_ROOT` names an existing writable directory outside `ABSPATH`, the document root, WordPress uploads, and coding workspaces. The current process must own the root; group/world write bits, world access, symlinked roots, symlink escapes, root inode swaps, and public-path overlap fail closed. Provider operations re-resolve containment and compare opened blob inodes to reduce directory/symlink swap exposure. The `.handoffs` parent device/inode, ownership, mode, symlink status, and real path are pinned and revalidated immediately before handoff creation, rename, read, and unlink. PHP cannot portably enumerate POSIX ACLs, so provisioning must separately prove ACL isolation and monitor ownership/mode drift under ops #336. Ordinary WordPress uploads and Data Machine flow files are never used as a fallback.

Objects use random 256-bit IDs and restrictive directories/files. Admission copies into a provider temporary file, derives size, SHA-256, and MIME from those copied bytes, validates filename/content agreement, and atomically renames the blob and metadata sidecar. Filenames never determine object paths. Download handoffs are random opaque 256-bit values backed by private, short-lived sidecars bound to attachment, user, purpose, and exact active claim. They disclose no storage identity, are consumed once, and are opened only after the service reauthorizes current venue membership under lock.

The provider must atomically claim provisional bytes using the supplied claim key, release failed claims, issue short-lived opaque stream tokens, and retire exact objects. Booking abilities always load the booking first and authorize its stored venue. Attachment operations then require the attachment to belong to that same booking.

## Classification And Limits

- Public/publishable assets: promo images, EPKs, and press releases may intentionally use public media elsewhere. References attached to a booking through this private contract are still treated as private.
- Private operational evidence: stage plots, technical riders, hospitality riders, insurance, contracts, and other private evidence require the private provider.
- Tax identity documents: W-9/tax/TIN/EIN/SSN-like filenames and unsupported tax purposes are default-denied. These forms require a separately approved secure vault, access policy, and retention policy.
- Maximum object size: the lower of 20 MiB and WordPress's effective `wp_max_upload_size()`. Production currently reports 2 MiB; ops #336 must align the Nginx/PHP/WordPress chain before raising it.
- Allowed content: JPEG, PNG, WebP, PDF, DOCX, XLSX, CSV, and plain text. Provider-detected MIME must agree with the filename extension.
- Filenames must already equal WordPress's sanitized basename. SHA-256 metadata is required.
- Malware scanning: PDF, DOCX, and XLSX require an explicit clean result from `extrachill_events_booking_private_file_scan`. A missing scanner fails closed; a rejected scan is not stored. Images and plain text are marked `not_required`, not falsely described as scanned. Ops #336 still owns approval of the production scanner policy.

## Lifecycle

Attachment creation is booking-scoped and idempotent. Every operation follows one global lock order: all venue membership ranges in numeric order, all booking rows in numeric order, then one advisory lock scoped by blog ID, attachment table, and opaque reference. No path holds one reference lock while acquiring another. Claim keys are likewise scoped by blog and table identity. If `RELEASE_LOCK()` cannot be confirmed after commit, the committed result is modeled as committed-but-unlock-uncertain: claim compensation is forbidden, the entire database connection is retired request-wide across service instances, and operator output requires request termination plus reconciliation. No later reference, including a different object, may reuse that connection. Reference reads propagate database failures instead of treating uncertainty as zero references. The private object claim is marked `abandoned` only after a confirmed non-commit failure; failed compensation is surfaced explicitly and abandoned claims become bounded provisional-cleanup candidates. Cross-booking reuse is disabled until a non-null canonical artist identity and current artist authority can both be proven; unresolved identity always fails closed.

Replacement creates the new reference first, then transactionally retires the previous metadata as `replaced` with its activity entry; failed replacement compensation leaves an explicit `abandoned` reference for recovery. `BookingAttachmentService::reconcile()` explicitly inspects old active claims without database references and active replacements whose prior reference remains active. Dry runs return only non-reversible fingerprints/public attachment IDs; repair mode marks proven orphan claims abandoned or completes logical prior retirement under the global lock order. Missing bookings and corrupt provider records are counted as uncertain without blocking later candidates. Claim and replacement scans use stable keyset continuation, cap each page at 250, and report opaque continuation plus truncation without exposing storage references or claim keys. Reconciliation never deletes bytes. Delete remains logical and transactional. Physical cleanup uses two independently ordered transactions: mark all references `purging`, then re-lock and reload booking/legal-hold state immediately before retirement. A new hold, policy-read failure, booking retention change, or reference change restores the prior inactive state in the phase-two transaction and retains bytes; only then may the provider tombstone/delete bytes and rows become `purged`.

No download token or storage reference is written to booking activity. `BookingAttachmentService::cleanup()` has no default destructive policy: an operator must explicitly supply an actor, approved retention days, and a legal-hold callback. Provider provisional cleanup also has no destructive default and rejects policy below the minimum abandoned-claim age or without a legal-hold callback. No destructive cleanup is scheduled until #336 and #317 approve retention, legal holds, backups, and operational ownership.

## MySQL Concurrency Verification

`phpunit-booking-mysql.xml.dist` is a dedicated application-level integration gate for a disposable WordPress test install backed by MySQL. It invokes the production `BookingAttachmentService` attach, locked authorization, delete, and two-phase cleanup paths with `$wpdb` as one session and an independent `mysqli` connection as the contender. The contender must block while revoking the locked membership range and while acquiring the cleanup reference lock, then complete after the production transaction commits.

This gate must not run against a production database. WP Codebox's existing `wordpress.phpunit` recipe with `databaseType: mysql` is the intended isolated surface and refuses SQLite substitution. If a managed MySQL service is unavailable, the integration result is unavailable rather than passing or being replaced with SQL-only evidence.

## Future Admission Seam

The local provider's `stage()` method accepts a server-controlled temporary source, filename, and purpose, then returns only an opaque reference. `attach()` accepts that reference plus explicit `anonymous`, `user`, `email`, or `system` attribution and an optional inbound reference. This supports future public inquiry and inbound-email adapters without teaching the booking domain about REST or mail transport. Extra-Chill/extrachill-api#123 owns multipart inquiry admission, Turnstile, and provisional upload orchestration. Extra-Chill/extrachill-api#125 separately owns protected download requests, mapping into `download_descriptor()` plus `open_download_stream()`, byte-response transport, replay-safe HTTP behavior, and download rate limiting. Until #125 lands, the handoff is intentionally not REST-exposed.
