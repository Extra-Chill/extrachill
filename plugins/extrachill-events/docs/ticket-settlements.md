# Ticket Sales And Settlements

## Storage

`ec_booking_sales_reports` is append-only evidence scoped to a booking, event,
venue, provider, and provider report ID. Provider/report identity is globally
unique within the Events site. An exact retry returns the prior observation;
reuse with different immutable content is a conflict. Corrections are new
signed observations and may identify the same-booking, same-currency report
they correct. Money and counts are signed integers in minor units.

`ec_booking_settlements` stores at most one frozen settlement per booking. It
captures basis, basis-point rate, currency, formula version, ordered evidence
IDs and canonical `(id, request_hash)` content hash, frozen booking version,
basis amount, signed adjustment, amount due, finalizer, and a second integrity
hash over the complete immutable financial snapshot. The snapshot hash binds
booking/event/venue identity, frozen booking revision, basis, rate, currency,
formula, evidence IDs/hash, and every calculated minor-unit amount. The row also
retains the terminal paid or void audit. Finalized evidence and terms are never
rewritten.

Schema version 12 creates or repairs both InnoDB tables through the existing
`dbDelta` installer, verifies exact columns/indexes/engines, and stamps the
version only after health checks pass.

## Abilities

- `extrachill/record-booking-ticket-sales`
- `extrachill/list-booking-ticket-sales`
- `extrachill/calculate-booking-settlement`
- `extrachill/finalize-booking-settlement`
- `extrachill/mark-booking-settlement-paid`
- `extrachill/void-booking-settlement`

All operations authorize internally. Read/evidence operations require exact
venue access. Finalize, paid, and void require active venue ownership through
`manage_finances`; transport permission callbacks repeat the same policy.
Transactions lock venue membership before booking, evidence, and settlement
rows. Finalization requires both the calculated booking version and ordered
evidence IDs, so stale and concurrent writes fail with a conflict.
An exact lost-response retry is resolved against the already-frozen booking
version, terms, formula, and evidence before current mutable booking/evidence
is considered, so later reports or booking revisions do not break idempotency.
Every evidence read recomputes its immutable request hash; frozen retries also
recompute the ordered settlement evidence hash. All settlement reads, retries,
paid writes, and void writes authenticate the complete frozen financial terms.
Finalize is declared idempotent because an exact retry returns that authenticated
frozen settlement while any changed terms conflict.

Paid and void transitions require both the settlement version and current
booking version while holding the booking row lock. Payment additionally
requires a `completed` booking, making cancellation and payment mutually
exclusive lifecycle outcomes rather than independent stale writes.

## Formula

Formula version 1 supports `gross_ticket_sales` and `net_ticket_sales` evidence.
Evidence is filtered to one explicit ISO-style three-letter currency. The share
is `basis_amount_minor * basis_points / 10000`, rounded to the nearest minor
unit with exact halves away from zero. The signed adjustment is then added.
Integer overflow is rejected rather than converted to floating point.

Manual and CSV-certified observations are supported. Provider adapters,
attribution/import/reconciliation from #316, and full show expenses or artist
payouts from #318 are explicitly outside this foundation.
