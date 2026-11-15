# Journey: venue-claim-and-discovery

Walks the real venue archive page as four roles (logged-out, non-member,
active venue member, administrator) to verify the contextual claim/manage
disclosure the product actually renders
(`extrachill-events` `inc/core/booking-console.php`
`ec_events_render_venue_archive_workspace_action()`), then walks a real
location archive with several venues to verify the collapsed
venue-directory disclosure.

Ported from `extrachill-events/tests/browser/venue-claim-acquisition.evidence.js`
+ `venue-claim-archive-fixture.php` and
`tests/browser/city-calendar-priority.evidence.js` (extrachill-network#292).

## What changed from the mocked-fixture originals

The originals ran against a fully mocked PHP runtime
(`venue-claim-archive-fixture.php` stubbed every WordPress function by
hand, including the venue authorization facade and `VenueBookingConfig` themselves) that
reproduced `inc/templates/archive.php` and
`inc/templates/location-venue-badges.php` by hand-copying their markup.
Reading the real current source revealed the workspace-action markup moved
since that fixture was authored (`#847`: the disclosure now renders below
the description via `venue-workspace-disclosure` class, not the fixture's
assumed structure) — this journey exercises the **real** templates on a
real seeded venue (with real membership/authorization state per role) and
a real seeded Charleston location instead.

- **Real roles, not stubbed authorization**: `logged_out` is genuinely
  anonymous; `non_member` and `active_member` are real users differing only
  by whether `extrachill/create-venue-membership` was called for them;
  `administrator` is the rig's real network super admin.
- **City-calendar venue count**: the original used an arbitrary 22/97-venue
  count purely to prove the collapsed-disclosure pattern scales. This seed
  creates 5 venues (each with 3 real published, dated events, matching the
  plugin's own `extrachill_events_venue_badge_min_count` filter default) —
  enough to prove the same collapse/reveal behavior for real without the
  cost of seeding dozens of venues × 3 events apiece. A deliberate,
  documented simplification, not a silently reduced test.

## Honest results

**All 7 browser steps passed** on a real `homeboy rig up extrachill-network`
run: the workspace disclosure's label and href are correct for all four
roles (`Sign in to claim or manage` → `wp-login.php`, `Claim or request
access`, `Manage Venue`, `Review venue claims` → `#tab-claims`), the
disclosure starts collapsed and opens on click, both viewports are
overflow-free, and the location archive's venue directory lists all 5
seeded venues and reveals them on open. Zero open findings.

## Run it

```bash
HOMEBOY_SETTINGS_JSON='{
  "extrachill_theme_source": "/path/to/extrachill-theme-checkout",
  "extrachill_journeys": ["venue-claim-and-discovery"]
}' homeboy rig up extrachill-network
```
