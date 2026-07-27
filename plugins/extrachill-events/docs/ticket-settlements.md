# Ticket Sales And Settlements

## Storage

`ec_booking_ticket_sources` stores immutable provider-neutral source identities.
Each identity binds a booking/event/venue, provider, provider-owned source key,
and canonical trackable ticket URL. API projections expose only the URL origin;
paths, query parameters, URL hashes, request hashes, and credentials are never
returned.

`ec_booking_sales_reports` is append-only evidence scoped to a booking, event,
venue, provider, and provider report ID. Provider/report identity is scoped to
the booking so account-local IDs can recur at other venues. An exact retry returns the prior observation;
reuse with different immutable content is a conflict. Corrections are new
signed observations and may identify the same-booking, same-currency report
they correct. Money and counts are signed integers in minor units. Reports may
bind a ticket-source identity and, for CSV imports, the approved private booking
attachment containing the exact evidence bytes. Arbitrary source provenance
remains private; ability projections expose only a redaction marker plus
server-owned attachment identity and CSV row when present.

`ec_booking_sales_resolutions` is an append-only optimistic decision history.
Every admit/exclude decision records a per-report version, optional corrected
source attribution, reason, actor, superseded resolution, and immutable request
hash. A stale expected version conflicts rather than overwriting history. Once
a settlement is finalized, further resolutions are rejected so frozen report
hashes cannot be reinterpreted by a later operator decision.

`ec_booking_settlements` stores at most one frozen settlement per booking. It
captures basis, basis-point rate, currency, formula version, ordered evidence
IDs and canonical report/reconciliation content hash, frozen booking version,
basis amount, signed adjustment, amount due, finalizer, and a second integrity
hash over the complete immutable financial snapshot. The snapshot hash binds
booking/event/venue identity, frozen booking revision, basis, rate, currency,
formula, evidence IDs/hash, and every calculated minor-unit amount. Formula
version 2 binds each included report's immutable hash and latest reconciliation
decision; version 1 settlements retain their legacy report-only verification.
The row also
retains the terminal paid or void audit. Finalized evidence and terms are never
rewritten.

Schema version 13 preserves the version 12 report and settlement tables, adds
nullable provenance references to existing observations, and creates the source
and resolution tables. The existing `dbDelta` installer verifies exact
columns/indexes/engines and stamps each multisite site only after health checks
pass. Existing reports remain immutable and become explicitly unattributed
until an operator resolves them.

## Abilities

- `extrachill/register-booking-ticket-source`
- `extrachill/list-booking-ticket-sources`
- `extrachill/record-booking-ticket-sales`
- `extrachill/import-booking-ticket-sales-csv`
- `extrachill/list-booking-ticket-sales`
- `extrachill/diagnose-booking-ticket-sales`
- `extrachill/resolve-booking-ticket-sales`
- `extrachill/calculate-booking-settlement`
- `extrachill/finalize-booking-settlement`
- `extrachill/mark-booking-settlement-paid`
- `extrachill/void-booking-settlement`

All operations authorize internally. Read/evidence operations require exact
venue access. Source registration, reconciliation resolution, finalize, paid,
and void require active venue ownership through `manage_finances`; transport
permission callbacks repeat the same policy.
Transactions lock venue membership before booking, settlement, report, and
resolution rows. Finalization requires the calculated booking version, ordered
evidence IDs, and formula-2 evidence hash, so stale reconciliation decisions and
concurrent writes fail with a conflict. Legacy formula-1 settlements retain
exact retry and terminal-transition compatibility.
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

## Import And Reconciliation

Manual and CSV-certified paths both call the same immutable report recorder.
CSV import accepts only an active `text/csv` booking attachment admitted under
the existing `other_private_evidence` policy. It obtains bytes through the
opaque one-time private stream handoff, re-hashes and counts every streamed byte
against immutable attachment metadata before parsing, requires an exact fixed
header, forbids multiline records, limits rows and physical line lengths,
rejects non-canonical integers, and never exposes bytes or storage references.
Exact report IDs make a crash/retry replay deterministic.

Diagnostics are bounded to 1,000 observations and derive unattributed, missing
file, currency mismatch, duplicate, overlapping, and contradictory evidence.
Clean attributed evidence is admitted deterministically. Any issue remains
unresolved until a finance operator appends an admit/exclude resolution. Both
calculation and finalization run this admission boundary across every currency
before selecting the requested settlement currency; unresolved evidence cannot
silently contribute to commission, and excluded evidence remains visible
in diagnostics and immutable history.

Provider adapters and provider-specific parsing remain outside this generic
booking domain. Full show expenses or artist payouts from #318 are also outside
this contract.
