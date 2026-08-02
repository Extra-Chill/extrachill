# Booking Privacy And Retention

Extra Chill Events uses WordPress Core's personal-data exporter and eraser framework. It does not create a parallel privacy request or identity-verification system.

## Defaults

| Category | Statuses | Contact and intake | Correspondence | Booking record |
| --- | --- | ---: | ---: | ---: |
| Rejected | `declined`, `withdrawn` | 180 days | 365 days | 730 days |
| Active | `submitted`, `needs_info`, `under_review`, `negotiating`, `held` | 730 days | 730 days | 2555 days |
| Confirmed | `confirmed`, `cancelled`, `completed` | 730 days | 1095 days | 2555 days |

Notification receipts default to 365 days. Operational and financial audit facts default to 2555 days. Cleanup currently anonymizes personal fields rather than deleting aggregate records because holds, event handoffs, ticket evidence, and frozen settlements retain booking references.

These are operational defaults, not legal conclusions. Policy owners can replace the complete validated matrix with the `extrachill_events_booking_retention_policy` filter. WordPress suggested privacy-policy text exposes the active defaults for site policy review. Venue consent wording and versions remain venue-configured.

## Anonymization

Verified Core privacy requests match account-owned inquiries by WordPress user ID. Anonymous inquiries match only an exact verified contact email. Account-owned rows never fall back to contact email, which avoids erasing another account's inquiry when identity is uncertain.

Anonymization clears the submitter link and contact fields, replaces intake and production content with a redaction marker, redacts free-form deal terms while preserving structured deal facts, and replaces correspondence content with a marker plus minimum non-personal delivery evidence. Ticket, settlement, event, hold, and referential facts remain intact. The immutable activity ledger receives a non-sensitive redaction marker.

## Operator Surface

`extrachill/operate-venue-booking-privacy` is venue-authorized and intentionally not public over REST. It supports:

- `diagnose`: stale holds, failed or unresolved correspondence state, stuck event handoffs, and overdue retained inquiries.
- `cleanup`: one required status and UTC `before` boundary, with keyset `after_id` and a maximum batch of 100.
- Dry-run by default. Mutation requires `apply: true`.

Output contains only booking public references, statuses, timestamps, and internal operation IDs. Contact, intake, correspondence, and delivery payloads are never included.

Private attachment storage, malware scanning, backups, retention, and orphan cleanup remain disabled and deferred to [issue #336](https://github.com/Extra-Chill/extrachill-events/issues/336).
