# Events WP Codebox Personas

## Chris Gardner: venue booking operations

`gardner-venue-booking.json` walks one nontechnical venue manager through a
realistic booking week for a single neighborhood room, then judges the product
by whether he could actually do his work and understand what happened.

It consumes the canonical identity contract `extra-chill-users/chris-gardner`
version `1.0.0`, owned by `Extra-Chill/extrachill-users`. Events owns only the
booking scenario, its actions, and its findings. It does not redefine Gardner's
identity, traits, or oracle vocabulary.

### Why this exists alongside the Booking Network E2E

`tests/NetworkE2E/booking` is the backend invariant gate. It proves idempotency,
optimistic concurrency, authorization, conversion, and a real two-connection
compare-and-swap race. Those invariants are necessary and they pass.

This journey asks the different question the persona contract exists to ask:
**can a nontechnical operator complete the task and understand the outcome?**

The two are complementary, and the gap between them is real. In the first clean
run every backend invariant held while nine persona oracles failed — competing
booking requests for the same room and night were never surfaced, booking history
could not say who made a change, and operator errors leaked "idempotency key"
while omitting the recovery action. None of that is visible to an invariant gate,
because nothing is technically broken.

### The journey

Gardner manages Lo-Fi Brewing, a two-space room (taproom and back patio). He
opens a Monday inbox holding three inquiries, two of which want the same Friday
in the same room, and works one of them to a published show:

1. Open the inbox and read the weekend requests.
2. Move a request to review, and try the illegal shortcut straight to confirmed.
3. Negotiate, set a performance time, and hold the date.
4. Email the offer, double-click Send, then edit and resend.
5. Reload and check the work survived, and that history credits him.
6. Confirm a teammate without membership cannot read the room's bookings.
7. Agree deal terms, confirm the show, publish it, and click publish twice.

Impatient behaviors from the contract — double submission, stale tabs,
backtracking, changing his mind — are part of the journey, not accidents.

### Runtime boundary

Real: Data Machine, Data Machine Events, Extra Chill Network, Extra Chill API,
Extra Chill Users, and the full Extra Chill Events booking domain — schema,
authorization, feature gating, abilities, versioning, and idempotency.

Staged: the arrival of the three inbound inquiries is written through
`BookingRepository` rather than the public `create-booking-inquiry` ability. That
ability's admission saga serializes on the MySQL-only `GET_LOCK` primitive, which
this runtime's database layer does not provide. Public intake is already covered
by the MySQL-backed Booking Network E2E gate. **Every action Gardner takes as the
operator — the surface this journey evaluates — runs through the real registered
abilities.**

Nothing is stubbed. There are no live external writes.

### Honest results

The journey distinguishes three outcomes, and this distinction is load-bearing:

- **pass** — the persona expectation held.
- **finding** — a real product usability defect.
- **skipped** — the runtime could not fairly judge it, reported via
  `gardner_skip()` and never counted as a pass or a finding.

Canonical event publication is currently skipped for exactly this reason: the
upsert serializes on `GET_LOCK`.

Two guards exist because both failure modes actually occurred while building
this. The seed asserts venue configuration validity and persona feature access
before the journey runs, and fails loudly if either is wrong. Without them, an
unsupported intake field type and an unsatisfied `team` feature ceiling each
produced a wall of *false* usability findings that looked exactly like product
defects.

### Run it

```bash
npm run build
wp-codebox recipe-run \
  --recipe tests/wp-codebox/gardner-venue-booking.json \
  --artifacts <directory-outside-this-checkout> \
  --timeout 20m --json
```

The artifacts directory must sit outside the checkout, because the repository is
mounted into the runtime and nested artifacts would recurse.

Dependency checkouts (`data-machine`, `data-machine-events`, `extrachill-api`,
`extrachill-network`, `extrachill-users`) are expected beside this repository.

Results are emitted as `GARDNER_JOURNEY_RESULT:<base64>` containing every case,
its oracle, the task in Gardner's words, and its evidence.

### Adding to the journey

Add tasks Gardner would actually attempt, and judge them with an oracle from the
canonical contract. A new case should describe a real operator intention in
plain language, not an internal state transition. If a product path cannot be
evaluated in this runtime, skip it explicitly — never let it read as a pass.
