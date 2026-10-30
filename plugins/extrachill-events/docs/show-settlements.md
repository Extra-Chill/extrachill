# Complete Show Settlements

## Ownership Boundary

The complete show settlement extends, and never replaces, the frozen commission
contract documented in `ticket-settlements.md`. Ticket attribution, imported
sales reports, report corrections, reconciliation, and certified CSV evidence
remain owned by #316. `ec_booking_settlements` remains the sole #294 record of
the Extra Chill share, including formula versions 1, 2, and 3. A show revision
binds that row's ID and integrity hash and revalidates it through
`TicketSettlementService` on every read, draft, finalization, and payment.
The show service pages through the exact frozen `included_report_ids`, rechecks
their immutable report hashes and currency through #316, and requires recorded
ticket gross to equal their overflow-safe signed gross sum.

The artist payout is a separate calculated field. It is never written to or
read from `amount_due_minor`, which remains the distinct Extra Chill share.

## Immutable Model

Schema version 15 adds two site-stamped InnoDB tables:

- `ec_booking_show_settlements` contains append-only calculation revisions.
  Each row binds booking, event, exact venue, revision number, optional corrected
  revision, frozen commission, currency, formula, normalized terms, private
  evidence fingerprints, calculation, request hash, integrity hash, actor, and
  timestamp. Unique booking/revision and booking/idempotency keys serialize
  revision creation.
- `ec_booking_show_settlement_actions` is an append-only optimistic lifecycle.
  Every action binds one revision, its exact expected lifecycle version, action
  payload, request hash, idempotency key, actor, and timestamp. A unique
  revision/version key prevents concurrent transitions from both winning.

Draft revisions are immutable too. Revising a draft appends its successor.
Correcting a finalized, acknowledged, or disputed revision appends a linked
correction draft and atomically marks the prior revision `corrected`. The prior
financial data remains readable. Finalization, acknowledgement, dispute,
payment, and void are actions, never silent updates to frozen terms.

## Formula Version 1

All money is signed integer minor units in one explicit three-letter currency.
Rates are integer basis points. Floating point is never used, and every addition
and multiplication rejects integer overflow.

Definitions:

1. `total_gross = ticket_gross + door_gross`
2. `deductions = fees + taxes + refunds + venue_expenses + production_expenses`
3. `adjustment_total = sum(signed_adjustments)`
4. `artist_split_basis = total_gross - deductions + adjustment_total - extra_chill_share`
5. `artist_split = max(0, artist_split_basis) * artist_split_basis_points / 10000`
6. `artist_payout = max(artist_guarantee, artist_split)`
7. `venue_net_after_payout = artist_split_basis - artist_payout`

Basis-point products use the #294 integer helper: nearest minor unit, with exact
halves away from zero. Deductions and artist guarantee cannot be negative.
Adjustments are signed by the authenticated finance actor and bind amount,
reason, actor, and a canonical signature hash.

## Evidence And Privacy

Non-zero door gross requires at least one active booking attachment admitted as
`other_private_evidence`. Payment requires at least one payout-evidence
attachment under the same existing private policy. The service uses the existing
opaque download handoff, streams and hashes every byte, checks exact byte size,
and binds attachment public ID, request hash, content hash, size, MIME, and
purpose. Exact booking and actor authorization always precede private stream
issuance or delivery audit. The service authenticates bytes before draft
calculation, finalization, and payment, then rechecks the exact metadata under
the financial transaction lock.

Historical reads compare the frozen metadata rather than rerunning current MIME
or filename admission policy, so later policy changes do not invalidate valid
history. Missing, inactive, moved, or tampered evidence fails closed. Ability
projections expose only attachment ID, public UUID, MIME, and byte size. They do
not expose content hashes, storage references, paths, stream tokens, recipient
data, bank or tax identity, payment secrets, or database diagnostics.

## Authorization And Concurrency

Every venue operation requires the existing `manage_finances` action. That
action already combines the booking finance capability/feature gate with active
owner membership for the exact venue. Direct counterparty acknowledgement is
the sole exception and uses the existing canonical artist-manager authority.
There is no administrator bypass. Cross-venue reads and writes fail closed.

Transactions use the existing lock order: exact venue membership, booking,
commission, revision, then lifecycle rows. The exact commission row, integrity
hash, currency, and non-void status are reloaded under that transaction and on
every revision verification. Exact retries return the prior authenticated
result. Reusing an idempotency key with changed input conflicts. Concurrent
commission void versus revision creation, finalization, or payment and all show
lifecycle transitions serialize through row locks plus unique revision/version
keys. Payment additionally revalidates the booking as `completed` while its row
is locked.

## Acknowledgement Attribution

An `acknowledged` action never treats a venue operator's statement as direct
counterparty assent. `counterparty_verified` is accepted only from a currently
authorized manager of the canonical artist term/profile bound to the booking;
the server records that authenticated user and canonical artist IDs in the
immutable action. `venue_recorded` remains a venue-finance operation and
requires authenticated immutable private evidence of the acknowledgement. It
records the same canonical counterparty IDs but leaves the attesting user null.

Clients cannot submit names, email addresses, account details, tax identity, or
arbitrary counterparty IDs. Artist authority revocation blocks new direct
attestations. Later disputes and correction revisions preserve the original
attribution and evidence rather than rewriting it. Ability projections redact
evidence hashes and storage metadata.

## Abilities

- `extrachill/draft-booking-show-settlement`
- `extrachill/read-booking-show-settlement`
- `extrachill/revise-booking-show-settlement`
- `extrachill/finalize-booking-show-settlement`
- `extrachill/acknowledge-booking-show-settlement`
- `extrachill/dispute-booking-show-settlement`
- `extrachill/correct-booking-show-settlement`
- `extrachill/mark-booking-artist-payout-paid`
- `extrachill/void-booking-show-settlement`

Payment records a bounded opaque reference, explicit `Y-m-d` payment date,
authenticated payout evidence, and actor. Account numbers, routing data,
recipient identity, and tax records are intentionally outside this contract.
The raw payment reference remains private at rest; ability reads return only a
`payment_reference_recorded` marker.
