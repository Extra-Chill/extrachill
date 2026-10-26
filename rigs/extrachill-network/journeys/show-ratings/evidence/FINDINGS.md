# Show ratings review: findings

Run on 2026-10-26 on the full 11-site rig: `homeboy rig up extrachill-network` with `extrachill_journeys=["show-ratings"]`, WP Codebox 0.33.1, PHP 8.4, theme from Extra-Chill/extrachill main. This was run 4, artifact `runtime-mvpa0sh6-hl588l`. The journey was re-run four times; runs 1–3 fixed journey bugs, not product bugs: strict-mode selectors, `crypto.randomUUID` on http, and a copy assertion that only matched one branch.

> Caveat: the theme checkout is unbuilt (`assets/css/root.css` is a build artifact). Theme CSS is missing from the screenshots, so pixel sizes reflect unstyled layout. Links, hrefs, behavior and persistence are valid.

## Per-step results

| # | Step | Result |
|---|---|---|
| 1 | Anonymous user on a past event: login CTA with "Log in or register to rate this show" | **pass** |
| 2 | Logged in but not attended: gate message, then "I Was There" unlocks the rating field **without a reload**, rate 4, reload, rating persists (DB = 4) | **pass** |
| 3 | My Shows (own, past): prompt copy shown, then tap star 3 inline | **FAIL**: the star highlights (optimistic) but **the rating is never stored** (DB = 0 for that show) and the prompt stays |
| 3b | My Shows shows ratings made on the event page | **FAIL**: the show rated 4 in step 2 renders as **unrated** ("Rate it") on My Shows |
| 4 | Card links measured (phone, 390px) | recorded below |
| 5 | Another user's My Shows: no rating controls | **pass** |
| 6 | Future show rating rejected (`event_not_rateable`) | **pass** |
| 6 | Non-attendee rating rejected (`rating_not_allowed`) | **pass** |
| 6 | Double submit creates one review | **pass** |
| 6 | Changing a rating updates the same review (3 → 5) | **pass** |
| 6 | Logged-out write rejected (primitive `rating_not_allowed`, REST 401) | **pass** |

## Ranked problems

1. **Inline My Shows rating doesn't persist, and My Shows doesn't show existing ratings** (steps 3/3b).
   - Both inline paths go through core `/wp-abilities/v1` (`get-my-event-ratings` GET, `rate-event` POST) from `extrachill-events/blocks/concert-stats/src/hooks/useMyRatings.js`. The event-page path, which works, uses the comment form instead.
   - Server-side, both abilities behave correctly on production: `rest_do_request` as a logged-in user gives a 200 read and a write that reaches the business rules.
   - So the failure is in the client call or auth (nonce, root URL, or request timing in the sandbox, where requests take 4–8s). The read error is swallowed silently by design (`.catch(() => {})`), which hides it.
   - Tracked in Extra-Chill/extrachill-events#1017.
   - **Next:** a real-browser check on production (rate one show inline on My Shows, then reload). Separately, log the read error instead of swallowing it.
2. **My Shows card, links measured on a phone** (supports the founder feedback "everything is a hyperlink"):

   | Element | Target | Size (unstyled) |
   |---|---|---|
   | Date "Oct 6" | event page | 44×16 |
   | Artist name | artist archive `/artist/<slug>/` (tour dates) | 87×17 |
   | Venue badge | venue archive | 70×16 |
   | City badge | location archive | 61×16 |
   | 5 star buttons | inline rate (no href) | 20×20 each |
   | "Rate it" | plain text hint, not a link | n/a |

   - The card container (422×74) is not a link, so the only way to reach the event is the date.
   - Every tap target is under the 44×44 guideline.
3. The "Rate it" hint looks tappable but isn't.

## Screenshots
See `screenshots/`: anonymous-phone, locked-phone, card-targets-{phone,desktop}, inline-unrated-desktop, inline-after-click-desktop, other-user-phone.
