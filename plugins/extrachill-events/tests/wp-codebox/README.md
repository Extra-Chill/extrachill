# Events WP Codebox Personas

## Chris Gardner: attendee RSVP journey

`gardner-event-rsvp-journey.json` walks the real, currently-live "Extra Chill
& WordPress Meetup" (events.extrachill.com post 486727, Lo-Fi Brewing,
Charleston, Oct 21 2026) as three attendees and judges whether the RSVP
experience actually works and is understandable.

It consumes the canonical identity contract `extra-chill-users/chris-gardner`
version `1.0.0`, owned by `Extra-Chill/extrachill-users`, for Gardner. The
other two attendees in this scenario are **not** versioned persona contracts
-- see "Why only Gardner is a canonical persona" below.

### The event

Reproduced from PUBLIC production content only (title, description, dates,
venue, ticket URL), read from events.extrachill.com post 486727 on
2026-09-23. No production user account, email, or personal data is copied.
Seeded through the real `data-machine-events/upsert-event` and
`extrachill/set-priority-event` abilities so the event-dates table, venue and
location taxonomy derivation, and content hashing all populate exactly the
way production content does -- not through raw SQL.

### Why only Gardner is a canonical persona

The task this scenario originated from asked for four attendee "personas":
a team member, a brand-new registrant, an existing subscriber, and a
logged-out visitor. Only the team member maps to an existing, reusable,
cross-product reference identity with its own traits and oracle vocabulary
-- that is Gardner. The other three are single-scenario test fixtures, not
reusable reference personas:

- **Gardner** (`auth-user-id=201`) -- the canonical identity, explicit
  `extra_chill_team` role, real `manage_brand_socials` grant. This scenario
  additionally opts him into **public** event-attendance visibility
  (`_extrachill_event_attendance_visibility = 'public'`) -- that setting is
  Events-scenario state, not part of the canonical contract, exactly the way
  `gardner-venue-booking-seed.php` layers venue membership on top of the
  shared identity.
- **Returning subscriber** (`auth-user-id=202`) -- a plain `subscriber`
  fixture user created directly by this file, with its visibility meta left
  untouched, to exercise the real, current private-by-default behavior
  (extrachill-users#415) and the rapid double-click duplicate-prevention
  case. Same pattern as the "outsider" boundary user in
  `gardner-venue-booking-seed.php`: a one-shot Events-owned fixture, not a
  persona contract.
- **New Charleston creative** (no fixture user -- registers live,
  best-effort) -- walks the real logged-out → register → RSVP path.

A first-time registrant and a logged-out visitor are not *behaviors with
oracles worth versioning and reusing across products* -- they are "has no
account yet," which is the absence of a persona, not one. Per
`extrachill-users/docs/gardner-persona.md`: "If the journey genuinely needs
[a persona], add it to Extra Chill Users as a new versioned persona
contract." This journey's registration/private-default findings did not
need a second reusable identity to produce real findings -- ordinary
Events-owned fixtures were sufficient, exactly as they already were for
Gardner's own boundary user in the venue-booking journey. If a *second*
product journey later wants to reuse "brand-new registrant" or "private
subscriber" as a named, traited, oracle-bearing persona, that is the trigger
to promote one into Extra Chill Users -- not a guess made here.

### The journey

1. **Discovery** -- the event page directly, and the Charleston location
   archive's promoted-event callout (`.promoted-events .promoted-event-card`).
2. **Comprehension** -- is it obvious this is free, when (6:30 start; the
   9pm end time), where, and that it isn't WordPress-only.
3. **Account** -- the new creative registers live and RSVPs; the runtime
   boundary this needs is documented below.
4. **RSVP** -- Gardner marks Going once and it holds through a reload; the
   returning subscriber double-clicks Going rapidly (a real double-submit).
5. **The free beer** -- verified empirically: marking Going produces only an
   in-app milestone notification and a ~2-day-out reminder. Nothing else
   states how the beer is claimed at the door.
6. **Door-list reality** -- the returning subscriber's private-by-default
   RSVP is correctly excluded from the public attendee list while the count
   still includes him. This is the *already-shipped, already-decided*
   outcome of extrachill-users#414/#415 (whose own PR description cites this
   exact production event as its example). Verified here, not re-filed.
7. **Sharing** -- the share dropdown's canonical URL and Facebook/Bluesky/etc
   links, read directly off the DOM rather than assumed.
8. **Mobile / weird / broken** -- a 390×844 viewport pass with a horizontal-
   overflow check and a captured browser console-error count.

### Runtime boundary

Real: Data Machine, Data Machine Events, Extra Chill Network, Extra Chill
API, Extra Chill Users, Extra Chill Events, the Extra Chill theme, and the
real `extrachill/set-event-mark`, `extrachill/toggle-event-mark`,
`extrachill/get-event-attendance`, `data-machine-events/upsert-event`, and
`extrachill/set-priority-event` abilities.

Not claimed: real cross-subdomain SSO through `auth.extrachill.com` /
wp-native Auth -- this is a single-origin WP Codebox fixture, and
`crossDomainCookieParity` is `"not-claimed"` by the underlying runtime
(extrachill-network#223's own spike). The task that originated this
scenario assumed the RSVP login path crosses subdomains through
`auth.extrachill.com`. **Verified read-only against production and found
not to be the case for this flow**: `events.extrachill.com/wp-login.php`
redirects same-origin to `events.extrachill.com/login`, which mounts
Extra Chill Users' own `login-register` React block and authenticates
same-origin (Google OAuth is the one path that bounces through
`community.extrachill.com`, not used here). So this boundary does not
block the RSVP journey the way the original brief assumed, and this
single-site fixture is a faithful model of it. The one path this fixture
genuinely cannot exercise is a **Cloudflare Turnstile** challenge embedded
in the real registration form; see the findings report for how that case
resolved in the actual run.

Also not claimed: the "Local Scene" homepage card lives on the main site
(blog 1, extrachill-blog), not on events.extrachill.com. A single-site
Events fixture cannot boot two blogs' worth of distinct plugin sets at
once. Verified read-only against production instead (see the findings
report); not exercised here.

Nothing product-relevant is stubbed. There are no live external writes.

### Honest results

Same three outcomes as `gardner-venue-booking-journey.php`: **pass**,
**finding** (real product usability defect), **skipped** (the runtime could
not fairly judge it). The registration browser-actions step is marked
`allowFailure: true` in the recipe because it depends on a hydrated,
previously-unobserved React form and a live Cloudflare Turnstile widget;
its outcome (successful, partial, or blocked) is reported honestly in the
findings report either way -- never silently converted into a pass.

### Run it

```bash
npm run build
wp-codebox recipe-run \
  --recipe tests/wp-codebox/gardner-event-rsvp-journey.json \
  --artifacts <directory-outside-this-checkout> \
  --timeout 20m --json
```

The artifacts directory must sit outside the checkout, because the repository is
mounted into the runtime and nested artifacts would recurse.

Dependency checkouts (`data-machine`, `data-machine-events`, `extrachill-api`,
`extrachill-network`, `extrachill-users`, `extrachill` theme) are expected
beside this repository.

Results are emitted as `GARDNER_JOURNEY_RESULT:<base64>` containing every case,
its oracle, the task in plain words, and its evidence. Screenshots and browser
artifacts (console, network, DOM snapshots) land under `artifacts/wp-codebox/gardner-event-rsvp-journey/`.

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
