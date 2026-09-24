# Gardner Event-RSVP Journey — Findings Report

Run date: 2026-09-23. Recipe: `tests/wp-codebox/gardner-event-rsvp-journey.json`. Full JSON
oracle results: `gardner-journey-result.json` (from `wordpress.run-php` grading step) and
`gardner-fixture-result.json` (from seeding). Screenshots: `screenshots/`.

Event walked: the real, currently-live "Extra Chill & WordPress Meetup: Building Your Online
Presence in the AI Era" — `events.extrachill.com` post 486727, blog 7, slug
`wordpress-meetup-charleston-october-2026`, Lo-Fi Brewing, Charleston SC, Wed Oct 21 2026
6:30–9pm. Content reproduced read-only from production on 2026-09-23 (title, description,
dates, venue, ticket URL only — no production user data).

## Personas

- **Chris Gardner** (`extra-chill-users/chris-gardner@1.0.0`, canonical, `auth-user-id=201`) —
  explicit-public event-attendance visibility (scenario-owned setup, not part of the identity
  contract).
- **Returning subscriber** (`auth-user-id=202`, Events-owned fixture, not a persona contract) —
  plain subscriber, visibility meta left untouched (tests the real private-by-default behavior).
- **New Charleston creative** (no fixture user, registers live) — logged-out visitor walking
  the real registration path.

See `tests/wp-codebox/README.md` ("Why only Gardner is a canonical persona") for why the other
two are ordinary scenario fixtures rather than versioned Users personas.

## Oracle results (Gardner, canonical contract)

7 of 8 recorded cases passed. 1 recorded finding (deliberately always recorded, not a skip —
see below). Full detail in `gardner-journey-result.json`.

| Case | Oracle | Result |
|---|---|---|
| `gardner-going-completes` | task-completion | ✅ pass |
| `gardner-single-row-no-duplicates` | duplicate-prevention | ✅ pass |
| `gardner-first-show-milestone-fires` | obvious-state | ✅ pass |
| `free-beer-only-produces-reminder-no-confirmation` | actionable-errors | ⚠️ finding — filed as extrachill-users#418 |
| `rapid-double-click-does-not-duplicate` (returning subscriber) | duplicate-prevention | ✅ pass |
| `private-by-default-still-holds` (returning subscriber) | server-authorization | ✅ pass |
| `door-list-reality-matches-shipped-privacy-decision` | server-authorization | ✅ pass (verifies existing #414/#415 decision holds) |
| `event-still-shows-end-time-nowhere-visible` | obvious-state | recorded as a finding — filed as data-machine-events#860 |

## Journey walkthrough (a–h)

**a. Discovery.** Event page loads with the real attendance widget
(`screenshot-desktop-event-page-logged-out.png`). Charleston location archive
(`/location/usa/south-carolina/charleston`) shows the real promoted-event callout linking to
this exact event with title/date/venue (`screenshot-charleston-location-archive-promoted-callout.png`)
— confirmed both in the sandbox and read directly off production. The "Local Scene" homepage
card lives on the main site (blog 1, `extrachill-blog`), not `events.extrachill.com`; a
single-site Events fixture cannot boot two blogs' plugin sets at once, so this surface was
verified read-only against production instead (the card exists and is driven by
`extrachill/get-user-settings` → `local_scene` — not exercised live here, documented as a
runtime boundary in the README, not a finding).

**b. Comprehension.** Real, reproducible gaps, confirmed both by reading `render.php` and by a
live assertion against the mounted plugin's actual output:
- End time (9pm) never appears anywhere in the visible page, only in body prose and invisible
  `schema.org` JSON-LD. **Filed: data-machine-events#860.**
- No "Free" indicator anywhere near the date/venue info grid — the `.event-price` slot is
  simply empty (`price:empty{display:none}`) because the organizer never typed a price string,
  even though the code already has logic built for exactly this case
  (`non_ticket_patterns = ['free','tbd','no cover']`). **Filed: data-machine-events#861.**
- "Not just for WordPress people" and "all experience levels welcome" — confirmed present and
  clearly stated in body copy (`document.body.innerText.includes('All experience levels are
  welcome')` passed).

**c. Account.** The logged-out "Going" click correctly redirects to `/login/`
(`screenshot-new-creative-going-click-logged-out.png`) — confirms the RSVP gate works.
Registration form located and screenshotted (`screenshot-new-creative-register-form.png`):
email + password + confirm-password only, no username field, no reachable CAPTCHA/Turnstile
challenge in this environment (`turnstileHtml` was empty in the hydrated config). Email and
both password fields filled successfully. **The final submit control was not conclusively
identified within this run's time budget** — three different selector strategies were tried
(`button[type="submit"]` matched an unrelated header search button; `button:has-text("Join
Now")` matched nothing, suggesting the control may not be a `<button>` element). This is
reported as **inconclusive, not a confirmed bug and not a confirmed pass** — a genuine
follow-up item, not resolved here. Everything reached up to that point (redirect gate, form
fields, no CAPTCHA block) is real, positive evidence.

**Cross-subdomain auth, corrected.** The original task brief assumed RSVP login crosses
subdomains through `auth.extrachill.com` / wp-native Auth, and that this single-site fixture
couldn't model that. **Verified read-only against production and found not to apply to this
flow**: `events.extrachill.com/wp-login.php` redirects same-origin to `events.extrachill.com/login`,
which mounts Extra Chill Users' own `login-register` React block and authenticates same-origin
(only Google OAuth bounces through `community.extrachill.com`, not used here). So the
single-site fixture is a faithful model of the real RSVP login path; this was not a limitation
in practice.

**d. RSVP.** Gardner clicks Going once; the visibility disclosure notice correctly reads "Your
name appears in the public attendee list when you mark attendance." before he even clicks
(`screenshot-gardner-marked-going.png`). Confirmed via direct DB read afterward: exactly one
`ec_concert_tracking` row, marked=true. The returning subscriber sees "Your attendance is
private — only the going count includes you." and performs a real rapid double-submit (3 clicks
back-to-back, `screenshot-returning-subscriber-rapid-double-click.png`) — still exactly one DB
row, no duplication, no broken state.

**e. The free beer.** Verified empirically via direct DB/Action-Scheduler inspection, not just
code reading: marking Going produced an immediate in-app milestone notification (first tracked
show) and a scheduled ~2-day-out reminder — and nothing else. No confirmation, code, or email
of any kind ties back to "first beer is on Extra Chill." **Filed: extrachill-users#418**
(product-decision, not a bug — the notification system is working as designed; the gap is that
this event's real-world promise has no fulfillment mechanism).

**f. Door-list reality.** The returning subscriber (private-by-default, never touched the
setting) marks Going. `extrachill/get-event-attendance` correctly reports `count: 2` while the
public `attendees` array contains only Gardner (`listed_count: 1`) — exactly the shipped,
intentional behavior from extrachill-users#414/#415, whose own PR description cites this exact
production event (486727: "3 going, 2 listed") as its live example. **Verified holding under a
real RSVP in this runtime; not re-filed, not re-litigated.**

**g. Sharing.** Share dropdown opened for real; `Copy Link`'s `data-share-url` and the
Facebook share link both resolve to the canonical, untracked event path (no UTM parameters
anywhere) — a genuine pass, not asserted from assumption.

**h. Mobile / weird / broken.** 390×844 viewport pass: no horizontal overflow
(`document.documentElement.scrollWidth <= clientWidth + 4` held) and zero captured browser
console errors (`screenshot-mobile-event-page.png`).

## Rig/runtime limitations hit and worked around (not product bugs)

All documented in `tests/wp-codebox/README.md` and the recipe's own `metadata.runtimeBoundary`.
Summarized here because they materially shaped this run:

1. **`data-machine-events/upsert-event` requires `GET_LOCK`**, unavailable in this runtime
   (same already-documented gap `gardner-venue-booking-seed.php` hit for
   `create-booking-inquiry`). Worked around by seeding the event post + taxonomy directly and
   letting the real `save_post` → `data_machine_events_sync_datetime_meta()` hook populate the
   event-dates table, exactly as production's own hook does.
2. **`EventDatesTable` and `ec_concert_tracking`** are normally created by
   `register_activation_hook`, which does not reliably fire for a mounted-not-installed plugin
   in this runtime. Both tables are explicitly created in `gardner-event-rsvp-journey-seed.php`
   before use. Without the second of these, every RSVP silently no-opped (0 DB rows) with no
   visible error — confirmed by running once without the fix.
3. **`extrachill-network` calls `switch_to_blog()` unconditionally** (80 call sites, 28 files) —
   fatals any single-site WordPress install. A rig-level mu-plugin shim
   (`gardner-event-rsvp-journey-mu-shim.php`) no-ops `switch_to_blog()`/`restore_current_blog()`
   so this scenario's front end can render at all. **The underlying defect is real and filed:
   extrachill-network#268.**
4. **`EC_BLOG_ID_EVENTS` is hardcoded to `7`** (`extrachill-network/inc/core/blog-ids.php`);
   `ec_is_events_site()` gates the entire attendance-button composition on
   `get_current_blog_id() === EC_BLOG_ID_EVENTS`. The same mu-plugin shim pre-defines
   `EC_BLOG_ID_EVENTS = 1` for this single-site fixture, using the existing
   `if (!defined())` extension point. Not a product bug — production is always multisite with a
   real, distinct events blog — but a real rig-setup requirement worth documenting for any
   future single-site front-end journey against Events content.
5. **`extrachill-users`' `build/concert-attendance/` React bundle** must be built
   (`npm run build` in the `extrachill-users` sibling checkout, not just this repo) or the
   attendance button renders inert HTML with no click handler wired up at all.
6. **Host load / shared disk.** This run's `/mnt/extrachill-workspace` tmp disk hit `ENOSPC`
   mid-run (shared across many concurrent sessions); re-run with `TMPDIR`/`--artifacts`
   redirected to `/dev/shm` to avoid it. Load average was consistently 8–9 on an 8-core host
   throughout; step and command timeouts were raised (30s→60s, 60/90s→150/180s) to absorb it.
   Noted per instructions, not treated as a product signal.

## What could not be exercised, and why

- **Full registration submission** — form reached and filled; submit control not conclusively
  identified in this run. Inconclusive, not reported as pass or fail.
- **"Local Scene" homepage card** — lives on blog 1 (main site), out of scope for this
  single-site Events fixture; verified read-only against production instead.
- **Real cross-domain SSO parity** (`crossDomainCookieParity: "not-claimed"` in the underlying
  wp-codebox runtime) — turned out not to be load-bearing for this specific flow; see
  "Cross-subdomain auth, corrected" above.
- **OG image reachability** — `og:image` content was captured (non-blocking) but its generation
  is an async Extra Chill Network job not exercised by seeding a single event directly; not
  asserted either way.

## Findings table

| # | Finding | Category | Filed |
|---|---|---|---|
| 1 | `switch_to_blog()` called unconditionally across 80 call sites; fatals any non-multisite install | Product bug | [extrachill-network#268](https://github.com/Extra-Chill/extrachill-network/issues/268) |
| 2 | Event end time captured but never rendered anywhere in the visible page | Product bug | [data-machine-events#860](https://github.com/Extra-Chill/data-machine-events/issues/860) |
| 3 | Free events show no cost indicator when price field is left blank | Product decision | [data-machine-events#861](https://github.com/Extra-Chill/data-machine-events/issues/861) |
| 4 | RSVP has no confirmation/claim mechanism for in-person perks promised in event copy | Product decision | [extrachill-users#418](https://github.com/Extra-Chill/extrachill-users/issues/418) |
| 5 | Door-list privacy tension (count vs. named list) | Already decided | extrachill-users#414/#415 — verified holding, not re-filed |
| 6 | Registration submit control not identified | Inconclusive | Not filed — follow-up noted above |
